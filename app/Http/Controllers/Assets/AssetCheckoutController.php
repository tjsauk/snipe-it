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

        //if ($asset->isInvalid()) {
        //    return redirect()->route('hardware.edit', $asset)->withErrors($asset->getErrors());
        //}
        if ($asset->isInvalid()) {
            \Log::error('Asset invalid on checkout/reserve create()', [
                'asset_id' => $asset->id,
                'errors' => $asset->getErrors() ? $asset->getErrors()->toArray() : null,
                'expected_checkin_raw' => $asset->getRawOriginal('expected_checkin'),
                'expected_checkin_cast' => $asset->expected_checkin,
                'last_checkout_raw' => $asset->getRawOriginal('last_checkout'),
                'last_checkout_cast' => $asset->last_checkout,
                'model_id' => $asset->model_id,
            ]);

            return redirect()->route('hardware.edit', $asset)->withErrors($asset->getErrors());
        }


        // NEW: auto-convert due reservations into checkouts
        //$asset->autoCheckoutActiveReservationIfDue();
        try {
            $asset->autoCheckinIfDue();
            $asset->refresh();

            $asset->autoCheckoutActiveReservationIfDue();
            $asset->refresh();
        } catch (\Throwable $e) {
            \Log::error('autoCheckoutActiveReservationIfDue failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Reload safely
        $asset = $asset->fresh();
        
        

        // -------------------------
        // Compute date constraints
        // -------------------------

        if ($reserveMode || $asset->availableForCheckout()) {

            $today = Carbon::today();

            //Get reservations of this asset
            $reservations = AssetReservation::where('asset_id', $asset->id)
                ->where('status', 'active')
                ->get();

            //Check witch days are reserved
            $isReservedDay = function (Carbon $day) use ($reservations): bool {
                foreach ($reservations as $r) {
                    $from = Carbon::parse($r->reserved_from)->startOfDay();
                    $to   = Carbon::parse($r->reserved_until ?? $r->reserved_from)->startOfDay();
                    if ($day->betweenIncluded($from, $to)) {
                        return true;
                    }
                }
                return false;
            };

            // -------------------------
            // Default start date
            // -------------------------
            if ($reserveMode) {
                $minStart = $today->copy()->addDay(); // tomorrow

                // if currently checked out into today/future, start must be day after expected_checkin
                if (!empty($asset->expected_checkin)) {
                    $expected = Carbon::parse($asset->expected_checkin)->startOfDay();
                    if ($expected->gte($today)) {
                        $minStart = $expected->copy()->addDay();
                    }
                }

                $start = $minStart->copy();
                for ($i = 0; $i < 366; $i++) {
                    if (!$isReservedDay($start)) {
                        break;
                    }
                    $start->addDay();
                }
            } else {
                // checkout always starts today (and checkout is only possible if availableForCheckout)
                $start = $today->copy();
            }

            // -------------------------
            // Default end date (continuous free window, max 14 days)
            // -------------------------
            $idealEnd = $start->copy()->addWeeks(2);
            $end = $idealEnd->copy();

            $cursor = $start->copy();
            while ($cursor->lte($idealEnd)) {
                if ($isReservedDay($cursor)) {
                    $end = $cursor->copy()->subDay();
                    break;
                }
                $cursor->addDay();
            }

            if ($end->lt($start)) {
                $end = $start->copy();
            }

            $defaultCheckoutAt = $start->toDateString();
            $defaultExpectedCheckin = $end->toDateString();
            $calendarRanges = $asset->calendarBlockedRanges();

            return view('hardware/checkout', compact('asset'))
                ->with('statusLabel_list', Helper::deployableStatusLabelList())
                ->with('table_name', 'Assets')
                ->with('item', $asset)
                ->with('calendarRanges', $calendarRanges)
                ->with('reserve_mode', $reserveMode)
                ->with('defaultCheckoutAt', $defaultCheckoutAt)
                ->with('defaultExpectedCheckin', $defaultExpectedCheckin);
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
                $this->authorize('view', $asset);
            } else {
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

            // ---- REQUIRE DATES ----
            if (!$request->filled('checkout_at')) {
                return back()->withInput()->with('error', 'Checkout date is required.');
            }
            if (!$request->filled('expected_checkin')) {
                return back()->withInput()->with('error', 'Expected checkin is required.');
            }

            $checkout_at_date = $request->get('checkout_at');         // Y-m-d
            $expected_date    = $request->get('expected_checkin');    // Y-m-d

            $checkoutHour = $request->filled('checkout_hour')
                ? (int) $request->get('checkout_hour')
                : 0;

            $expectedHour = $request->filled('expected_checkin_hour')
                ? (int) $request->get('expected_checkin_hour')
                : 0;

            // Build hour-accurate datetimes
            $tz = config('app.timezone');

            $checkoutDT = Carbon::parse($checkout_at_date, $tz)->setTime($checkoutHour, 0, 0);

            // UI end is "last occupied hour start" -> store boundary as +1 hour
            $expectedSlotStart = Carbon::parse($expected_date, $tz)->setTime($expectedHour, 0, 0);
            $expectedDT = $expectedSlotStart->copy()->addHour();  // <-- critical


            // Normal checkout cannot be in the future (hour-accurate)
            if (!$reserveMode && $checkoutDT->isFuture()) {
                return back()->withInput()->with('error', 'Checkout time cannot be in the future. Use a reservation instead.');
            }

            // Reservation must start in the future (hour-accurate)
            if ($reserveMode && !$checkoutDT->isFuture()) {
                return back()->withInput()->with('error', 'Reservations must start in the future.');
            }

            // Expected must be >= start
            if ($expectedDT->lte($checkoutDT)) {
                return back()->withInput()->with('error', 'Expected checkin must be after the checkout time.');
            }


            // For reservations, end must also be in the future
            if ($reserveMode && !$expectedDT->isFuture()) {
                return back()->withInput()->with('error', 'Reservation end time must be in the future.');
            }

            // This is the actual window we use for overlap checks
            $windowStartDT = $checkoutDT->copy();
            $windowEndDT   = $expectedDT->copy();

            // Strings stored into asset/checkOut()
            $checkout_at      = $checkoutDT->format('Y-m-d H:i:s');
            $expected_checkin = $expectedDT->format('Y-m-d H:i:s');

            if ($request->filled('status_id')) {
                $asset->status_id = $request->get('status_id');
            }

            // License seats should only follow actual checkouts, not reservations
            if (! $reserveMode && !empty($asset->licenseseats->all())) {
                if ($request->get('checkout_to_type') == 'user') {
                    foreach ($asset->licenseseats as $seat) {
                        $seat->assigned_to = $target->id;
                        $seat->save();
                    }
                }
            }

            $asset->customFieldsForCheckinCheckout('display_checkout');

            $settings = \App\Models\Setting::getSettings();

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
                'checkout_to_type' => $request->get('checkout_to_type')
            ]);

            /***************************************************************
             * RESERVATION FLOW
             **************************************************************/
            if ($reserveMode) {

                $reservationUserId = null;
                if ($request->get('checkout_to_type') === 'user' && $target) {
                    $reservationUserId = $target->id;
                } else {
                    $reservationUserId = auth()->id();
                }

                // one active reservation per user per asset
                $existing = $asset->activeReservationForUser($reservationUserId);
                if ($existing) {
                    return redirect()->route('hardware.show', $asset)
                        ->with('error', 'You already have an active reservation for this asset.');
                }

                // NO overlap with ongoing checkout period (hour-accurate)
                if ($asset->overlapsOngoingCheckout($windowStartDT, $windowEndDT)) {
                    return back()->withInput()->with('error', 'Reservation overlaps an ongoing checkout period.');
                }

                // If currently checked out, reservation must start AFTER expected_checkin moment
                if (!is_null($asset->assigned_to) && $asset->expected_checkin) {
                    $currentEnd = Carbon::parse($asset->expected_checkin);
                    if ($windowStartDT->lt($currentEnd)) {
                        return back()->withInput()->with('error', 'Reservation overlaps an ongoing checkout period.');
                    }
                }

                // NO overlap with ANY other reservation (hour-accurate)
                if ($asset->overlapsReservations($windowStartDT, $windowEndDT)) {
                    return back()->withInput()->with('error', 'Reservation overlaps an existing reservation.');
                }

                AssetReservation::create([
                    'asset_id'       => $asset->id,
                    'user_id'        => $reservationUserId,
                    'reserved_from'  => $windowStartDT->format('Y-m-d H:i:s'),
                    'reserved_until' => $windowEndDT->format('Y-m-d H:i:s'),
                    'status'         => 'active',
                ]);

                return Helper::getRedirectOption($request, $asset->id, 'Assets')
                    ->with('success', 'Reservation created successfully.');
            }

            /***************************************************************
             * NORMAL CHECKOUT FLOW
             **************************************************************/
            $checkoutStart = $windowStartDT;
            $checkoutEnd   = $windowEndDT;

            $checkoutUserId = null;
            if ($request->get('checkout_to_type') === 'user' && $target) {
                $checkoutUserId = $target->id;
            } else {
                $checkoutUserId = auth()->id();
            }

            // Check if this checkout should fulfill the user's reservation (hour-accurate)
            $userReservation = $checkoutUserId ? $asset->activeReservationForUser($checkoutUserId) : null;
            $fulfillReservation = false;

            if ($userReservation) {
                $resFrom  = Carbon::parse($userReservation->reserved_from);
                $resUntil = $userReservation->reserved_until
                    ? Carbon::parse($userReservation->reserved_until)
                    : $resFrom->copy();

                $overlap = $checkoutStart <= $resUntil && $checkoutEnd >= $resFrom;
                if ($overlap) {
                    $fulfillReservation = true;
                }
            }

            // Disallow overlap with other users' reservations (hour-accurate)
            if ($asset->overlapsReservations($checkoutStart, $checkoutEnd, $checkoutUserId)) {
                return back()->withInput()->with('error', 'Checkout overlaps an existing reservation.');
            }

            // Disallow overlap with an ongoing checkout window (hour-accurate)
            if ($asset->overlapsOngoingCheckout($checkoutStart, $checkoutEnd)) {
                return back()->withInput()->with('error', 'Checkout overlaps an ongoing checkout period.');
            }

            if ($asset->checkOut(
                $target,
                $admin,
                $checkout_at,
                $expected_checkin,
                $request->get('note'),
                $request->get('name')
            )) {
                if ($fulfillReservation && $userReservation) {
                    $userReservation->status = 'fulfilled';
                    $userReservation->save();
                }

                return Helper::getRedirectOption($request, $asset->id, 'Assets')
                    ->with('success', trans('admin/hardware/message.checkout.success'));
            }

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
