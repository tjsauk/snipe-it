<?php

namespace App\Http\Controllers\Assets;

use App\Exceptions\CheckoutNotAllowed;
use App\Helpers\Helper;
use App\Http\Controllers\CheckInOutRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssetCheckoutRequest;
use App\Models\Asset;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Session;
use \Illuminate\Contracts\View\View;
use \Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\AssetReservation;
use Carbon\Carbon;

class AssetCheckoutController extends Controller
{
    use CheckInOutRequest;

    /**
     * Returns a view that presents a form to check an asset out to a
     * user.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $assetId
     * @since [v1.0]
     * @return \Illuminate\Contracts\View\View
     */
    public function create(Request $request, Asset $asset) : View | RedirectResponse
    {

        // Detect reservation mode from route or query
        $reserveMode = $request->boolean('reserve') || $request->routeIs('hardware.reserve.create');

        if ($reserveMode) {
            // For reservations, only require permission to view the asset
            $this->authorize('view', $asset);
        } else {
            // For normal checkout, use existing checkout policy
            $this->authorize('checkout', $asset);
        }

        if (!$asset->model) {
            return redirect()->route('hardware.show', $asset)
                ->with('error', trans('admin/hardware/general.model_invalid_fix'));
        }

        // Invoke the validation to see if the audit will complete successfully
        $asset->setRules($asset->getRules() + $asset->customFieldValidationRules());

        if ($asset->isInvalid()) {
            return redirect()->route('hardware.edit', $asset)->withErrors($asset->getErrors());
        }

        // NEW: auto-convert due reservations into checkouts
        $asset->autoCheckoutActiveReservationIfDue();
        $asset->refresh(); // reload latest assigned_to etc
        
        if ($reserveMode || $asset->availableForCheckout()) {
            

            return view('hardware/checkout', compact('asset'))
                ->with('statusLabel_list', Helper::deployableStatusLabelList())
                ->with('table_name', 'Assets')
                ->with('item', $asset)
                ->with('reserve_mode', $reserveMode);
        }

        return redirect()->route('hardware.index')
            ->with('error', trans('admin/hardware/message.checkout.not_available'));
    }

    /**
     * Validate and process the form data to check out an asset to a user.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param AssetCheckoutRequest $request
     * @since [v1.0]
     */
    public function store(AssetCheckoutRequest $request, $assetId) : RedirectResponse
    {


        try {
            if (! $asset = Asset::find($assetId)) {
                return redirect()->route('hardware.index')
                    ->with('error', trans('admin/hardware/message.does_not_exist'));
            }

            // Detect reservation mode
            $reserveMode = $request->boolean('reserve_mode')
                || $request->routeIs('hardware.reserve.store');

            // Only block availability for normal checkout
            if (! $reserveMode && ! $asset->availableForCheckout()) {
                return redirect()->route('hardware.index')
                    ->with('error', trans('admin/hardware/message.checkout.not_available'));
            }

            // Fallback for reservations: if checkout_to_type is missing, enforce user/self
            if (!$request->filled('checkout_to_type')) {
                $request->merge([
                    'checkout_to_type' => 'user',
                    'assigned_user'    => auth()->id(),
                ]);
            }

            

            // Authorize differently for reserve vs normal checkout
            if ($reserveMode) {
                // Reservation only needs view permission (so restricted users can still reserve)
                $this->authorize('view', $asset);
            } else {
                // Normal checkout uses existing checkout policy
                $this->authorize('checkout', $asset);
            }

            if (!$asset->model) {
                return redirect()->route('hardware.show', $asset)
                    ->with('error', trans('admin/hardware/general.model_invalid_fix'));
            }

            $admin = auth()->user();

            $target = $this->determineCheckoutTarget();
            session()->put(['checkout_to_type' => $target]);

            $asset = $this->updateAssetLocation($asset, $target);

            

            // Checkout / reservation start date (as string)
            $checkout_at = $request->filled('checkout_at')
                ? $request->get('checkout_at')
                : date('Y-m-d');

            // Expected checkin (may be null, we will default later)
            $expected_checkin = $request->filled('expected_checkin')
                ? $request->get('expected_checkin')
                : null;

            // Convert to Carbon
            $checkoutDate = Carbon::parse($checkout_at)->startOfDay();

            // Prevent future dates for NORMAL checkout
            if (!$reserveMode && $checkoutDate->isFuture()) {
                return redirect()->route('hardware.checkout.create', $asset)
                    ->withInput()
                    ->with('error', 'Checkout date cannot be in the future. Use a reservation instead.');
            }

            // Reservation must start in the future
            if ($reserveMode && !$checkoutDate->isFuture()) {
                return redirect()->route('hardware.reserve.create', $asset)
                    ->withInput()
                    ->with('error', 'Reservations must start in the future.');
            }

            // Expected checkin date validation
            $expectedDate = null;
            if ($expected_checkin) {
                $expectedDate = Carbon::parse($expected_checkin)->startOfDay();

                // Must not be before checkout/reservation start
                if ($expectedDate->lt($checkoutDate)) {
                    return back()
                        ->withInput()
                        ->with('error', 'Expected checkin date must be on or after the checkout date.');
                }

                // For reservations, end must be in the future as well
                if ($reserveMode && !$expectedDate->isFuture()) {
                    return back()
                        ->withInput()
                        ->with('error', 'Reservation end date must be in the future.');
                }
            }

            // Effective end date: explicit expected end, or +2 weeks from start.
            $windowEnd = $expectedDate
                ? $expectedDate->copy()->endOfDay()
                : $checkoutDate->copy()->addWeeks(2)->endOfDay();

            if ($request->filled('status_id')) {
                $asset->status_id = $request->get('status_id');
            }

            // License seats should only follow actual checkouts, not reservations
            if(! $reserveMode && !empty($asset->licenseseats->all())){
                if(request('checkout_to_type') == 'user') {
                    foreach ($asset->licenseseats as $seat){
                        $seat->assigned_to = $target->id;
                        $seat->save();
                    }
                }
            }

            // Add any custom fields that should be included in the checkout
            $asset->customFieldsForCheckinCheckout('display_checkout');

            $settings = \App\Models\Setting::getSettings();

            // We have to check whether $target->company_id is null here since locations don't have a company yet
            // Company restriction: this applies to both checkout and reservation
            if (($settings->full_multiple_companies_support)
                && (!is_null($target->company_id))
                && (!is_null($asset->company_id))) {

                if ($target->company_id != $asset->company_id) {
                    return redirect()->route('hardware.checkout.create', $asset)
                        ->with('error', trans('general.error_user_company'));
                }
            }

            session()->put([
                'redirect_option' => $request->get('redirect_option'),
                 'checkout_to_type' => $request->get('checkout_to_type')]);


            // NEW: Reservation flow
            if ($reserveMode) {
                $startDate = $checkoutDate;
                $endDate   = $windowEnd;

                // Target user for reservation:
                // - Super users can choose any user via the normal checkout-to-user selector
                // - Restricted users will effectively be forced to themselves
                $reservationUserId = null;
                if ($request->get('checkout_to_type') === 'user' && $target) {
                    $reservationUserId = $target->id;
                } else {
                    $reservationUserId = auth()->id();
                }

                // Enforce: one active reservation per user per asset
                $existing = $asset->activeReservationForUser($reservationUserId);
                if ($existing) {
                    return redirect()->route('hardware.show', $asset)
                        ->with('error', 'You already have an active reservation for this asset.');
                }

                // NO overlap with ongoing checkout period
                if ($asset->overlapsOngoingCheckout($startDate, $endDate)) {
                    return back()
                        ->withInput()
                        ->with('error', 'Reservation overlaps an ongoing checkout period.');
                }
                
                // ---- NO OVERLAP WITH CURRENT CHECKOUT ----
                // If the asset is currently checked out (assigned_to) and has an expected_checkin,
                // you can only reserve starting AFTER that expected_checkin date.
                if (!is_null($asset->assigned_to) && $asset->expected_checkin) {
                    $currentEnd = Carbon::parse($asset->expected_checkin)->endOfDay();
                    if ($startDate->lte($currentEnd)) {
                        return back()
                            ->withInput()
                            ->with('error', 'Reservation overlaps an ongoing checkout period.');
                    }
                }

                // NO overlap with ANY other reservation
                if ($asset->overlapsReservations($startDate, $endDate)) {
                    return back()
                        ->withInput()
                        ->with('error', 'Reservation overlaps an existing reservation.');
                }

                AssetReservation::create([
                    'asset_id'       => $asset->id,
                    'user_id'        => $reservationUserId,
                    'reserved_from'  => $startDate->toDateString(),
                    'reserved_until' => $endDate->toDateString(),
                    'status'         => 'active',
                ]);

                return Helper::getRedirectOption($request, $asset->id, 'Assets')
                    ->with('success', 'Reservation created successfully.');
            }

            /***************************************************************
             * NORMAL CHECKOUT FLOW
             **************************************************************/

            $checkoutStart = $checkoutDate;
            $checkoutEnd   = $windowEnd;

            // User for checkout (could be someone else if super user)
            $checkoutUserId = null;
            if ($request->get('checkout_to_type') === 'user' && $target) {
                $checkoutUserId = $target->id;
            } else {
                $checkoutUserId = auth()->id();
            }

            // Check if this checkout should fulfill an existing reservation for the same user
            $userReservation = $checkoutUserId
                ? $asset->activeReservationForUser($checkoutUserId)
                : null;

            $fulfillReservation = false;

            if ($userReservation) {
                $resFrom  = Carbon::parse($userReservation->reserved_from)->startOfDay();
                $resUntil = $userReservation->reserved_until
                    ? Carbon::parse($userReservation->reserved_until)->endOfDay()
                    : $resFrom->copy()->endOfDay();

                // Basic overlap check with the user's own reservation
                $overlap = $checkoutStart <= $resUntil && $checkoutEnd >= $resFrom;
                if ($overlap) {
                    $fulfillReservation = true;
                }
            }

            // Disallow overlap with other users' reservations
            if ($asset->overlapsReservations($checkoutStart, $checkoutEnd, $checkoutUserId)) {
                return back()
                    ->withInput()
                    ->with('error', 'Checkout overlaps an existing reservation.');
            }

            // Disallow overlap with any current checkout period as well
            if ($asset->overlapsOngoingCheckout($checkoutStart, $checkoutEnd)) {
                return back()
                    ->withInput()
                    ->with('error', 'Checkout overlaps an ongoing checkout period.');
            }

            // Perform the actual checkout
            if ($asset->checkOut(
                $target,
                $admin,
                $checkout_at,
                $expected_checkin,
                $request->get('note'),
                $request->get('name')
            )) {
                // If this checkout fulfills the user's reservation, mark it so
                if ($fulfillReservation && $userReservation) {
                    $userReservation->status = 'fulfilled';
                    $userReservation->save();
                }

                return Helper::getRedirectOption($request, $asset->id, 'Assets')
                    ->with('success', trans('admin/hardware/message.checkout.success'));
            }

            // Redirect to the asset management page with error
            return redirect()->route('hardware.checkout.create', $asset)
                ->with('error', trans('admin/hardware/message.checkout.error').$asset->getErrors());

        } catch (ModelNotFoundException $e) {
            return redirect()->back()
            ->with('error', trans('admin/hardware/message.checkout.error'))
            ->withErrors($asset->getErrors());
        } catch (CheckoutNotAllowed $e) {
            return redirect()->back()
            ->with('error', $e->getMessage());
        }
    }
}
