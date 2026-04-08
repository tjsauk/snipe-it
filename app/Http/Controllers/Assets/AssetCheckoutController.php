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

            // Store where the user came from (only once)
            if (!session()->has('return_to')) {
                $prev = url()->previous();
                if (!str_contains($prev, '/hardware/' . $asset->id . '/checkout')) {
                    session()->put('return_to', $prev);
                }
            }



            return view('hardware/checkout', compact('asset'))
                ->with('statusLabel_list', Helper::deployableStatusLabelList())
                ->with('table_name', 'Assets')
                ->with('item', $asset)
                ->with('calendarRanges', $calendarRanges)
                ->with('reserve_mode', $reserveMode)
                ->with('defaultCheckoutAt', $defaultCheckoutAt)
                ->with('defaultExpectedCheckin', $defaultExpectedCheckin)
                ->with('return_to', session('return_to'));
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

            // Reserve mode is auto-detected from the start datetime:
            // start > current hour → future reservation; start <= current hour → immediate checkout.
            $tzEarly         = config('app.timezone');
            $checkoutRawDate = $request->get('checkout_at');
            $checkoutRawHour = $request->filled('checkout_hour') ? (int) $request->get('checkout_hour') : 0;
            $checkoutDTEarly = $checkoutRawDate
                ? Carbon::parse($checkoutRawDate, $tzEarly)->setTime($checkoutRawHour, 0, 0)
                : Carbon::now($tzEarly);
            $currentHour = Carbon::now($tzEarly)->setTime(Carbon::now($tzEarly)->hour, 0, 0);
            $reserveMode = $checkoutDTEarly->isAfter($currentHour);

            // Prefer explicit posted return_to, fall back to session
            $returnTo = $request->input('return_to') ?: session('return_to');

            // Normalize checkout target + required assigned_* fields
            $checkoutToType = $request->get('checkout_to_type', 'user');

            // Update session immediately so that any early error redirect reloads
            // the page with the correct checkout_to_type radio selected.
            session()->put('checkout_to_type', $checkoutToType);

            // If user switched targets and came back, assigned_user may be missing because the input was disabled/hidden.
            // When checkout_to_type=user and no asset/location is provided, default to current user.
            if (
                $checkoutToType === 'user'
                && !$request->filled('assigned_user')
                && !$request->filled('assigned_asset')
                && !$request->filled('assigned_location')
            ) {
                $request->merge(['assigned_user' => auth()->id()]);
            }


            // Only block availability for normal checkout
            if (! $reserveMode && ! $asset->availableForCheckout()) {
                return redirect()->route('hardware.index')
                    ->with('error', trans('admin/hardware/message.checkout.not_available'));
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

            

            // Determine if this checkout is to another asset (no date selection needed)
            $isCheckoutToAsset = ($request->get('checkout_to_type') === 'asset');

            $target = $this->determineCheckoutTarget();

            if (!$target) {
                return back()->withInput()->with('error', 'Checkout target is missing. Please select a user, asset, or location.');
            }


            $asset = $this->updateAssetLocation($asset, $target);

            // ---- REQUIRE DATES ----
            // Checkout-to-asset has no date picker — default to now
            if ($isCheckoutToAsset && !$request->filled('checkout_at')) {
                $nowTz = Carbon::now(config('app.timezone'));
                $request->merge(['checkout_at' => $nowTz->toDateString(), 'checkout_hour' => $nowTz->hour]);
            }
            if (!$isCheckoutToAsset && !$request->filled('checkout_at')) {
                return back()->withInput()->with('error', 'Checkout date is required.');
            }

            // Expected checkin is required for reservations and normal checkouts EXCEPT checkout-to-asset
            if (!$isCheckoutToAsset && !$request->filled('expected_checkin')) {
                return back()->withInput()->with('error', 'Expected checkin is required.');
            }

            $checkout_at_date = $request->get('checkout_at'); // Y-m-d

            $checkoutHour = $request->filled('checkout_hour')
                ? (int) $request->get('checkout_hour')
                : 0;

            // Build hour-accurate datetime for checkout start
            $tz = config('app.timezone');
            $checkoutDT = Carbon::parse($checkout_at_date, $tz)->setTime($checkoutHour, 0, 0);

            // Build expected end datetime
            $expectedDT = null;

            if (!$isCheckoutToAsset) {

                $expected_date = $request->get('expected_checkin'); // Y-m-d

                $expectedHour = $request->filled('expected_checkin_hour')
                    ? (int) $request->get('expected_checkin_hour')
                    : 0;

                // UI end is "last occupied hour start" -> store boundary as +1 hour
                $expectedSlotStart = Carbon::parse($expected_date, $tz)->setTime($expectedHour, 0, 0);
                $expectedDT = $expectedSlotStart->copy()->addHour(); // <-- keep your existing rule
            }



            // Normal checkout cannot be in the future (hour-accurate)
            if (!$reserveMode && $checkoutDT->isFuture()) {
                return back()->withInput()->with('error', 'Checkout time cannot be in the future. Use a reservation instead.');
            }

            // Reservation start must be the current hour or later (allow e.g. 11:00 when it's 11:23)
            if ($reserveMode && $checkoutDT->lt(Carbon::now($tz)->startOfHour())) {
                return back()->withInput()->with('error', 'Reservations must start in the current hour or later.');
            }

            // Expected must be > start (only if we have an expected end)
            if (!$isCheckoutToAsset) {
                if ($expectedDT->lte($checkoutDT)) {
                    return back()->withInput()->with('error', 'Expected checkin must be after the checkout time.');
                }

                // For reservations, end must also be in the future
                if ($reserveMode && !$expectedDT->isFuture()) {
                    return back()->withInput()->with('error', 'Reservation end time must be in the future.');
                }
            }

            // This is the actual window we use for overlap checks
            $windowStartDT = $checkoutDT->copy();

            // For checkout-to-asset, there is no time window end.
            // Use start as placeholder; overlap checks for normal checkout rely on expectedDT anyway.
            $windowEndDT = $isCheckoutToAsset ? $checkoutDT->copy() : $expectedDT->copy();

            $checkout_at = $checkoutDT->format('Y-m-d H:i:s');

            $expected_checkin = null;
            if (!$isCheckoutToAsset) {
                $expected_checkin = $expectedDT->format('Y-m-d H:i:s');
            }


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

            session()->put('redirect_option', $request->get('redirect_option'));

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
                //$existing = $asset->activeReservationForUser($reservationUserId);
                //if ($existing) {
                //    return redirect()->route('hardware.show', $asset)
                //        ->with('error', 'You already have an active reservation for this asset.');
                //}

                // Build list of all periods to reserve (multi-period support)
                $periodsJson = $request->input('periods_json');
                $allPeriodInputs = [];
                if ($periodsJson) {
                    $decoded = json_decode($periodsJson, true);
                    if (is_array($decoded) && count($decoded) > 0) {
                        foreach ($decoded as $p) {
                            $sParts = explode(' ', $p['start'] ?? '');
                            $eParts = explode(' ', $p['end'] ?? '');
                            if (count($sParts) < 2 || count($eParts) < 2) continue;
                            $allPeriodInputs[] = [
                                'start_date' => $sParts[0],
                                'start_hour' => (int) explode(':', $sParts[1])[0],
                                'end_date'   => $eParts[0],
                                'end_hour'   => (int) explode(':', $eParts[1])[0],
                            ];
                        }
                    }
                }
                // Fall back to single period from primary form fields
                if (empty($allPeriodInputs)) {
                    $allPeriodInputs[] = [
                        'start_date' => $checkoutRawDate,
                        'start_hour' => $checkoutRawHour,
                        'end_date'   => $request->get('expected_checkin'),
                        'end_hour'   => $request->filled('expected_checkin_hour') ? (int) $request->get('expected_checkin_hour') : 0,
                    ];
                }

                $successCount = 0;
                $periodErrors = [];

                foreach ($allPeriodInputs as $idx => $pi) {
                    $pStartDT = Carbon::parse($pi['start_date'], $tz)->setTime($pi['start_hour'], 0, 0);
                    $pEndSlot = Carbon::parse($pi['end_date'], $tz)->setTime($pi['end_hour'], 0, 0);
                    $pEndDT   = $pEndSlot->copy()->addHour();

                    // Allow current hour (calendar hour precision): reject only if start is before current hour
                    if ($pStartDT->lt(Carbon::now()->minute(0)->second(0))) {
                        $periodErrors[] = 'Period ' . ($idx + 1) . ': must start in the future.';
                        continue;
                    }
                    if ($pEndDT->lte($pStartDT)) {
                        $periodErrors[] = 'Period ' . ($idx + 1) . ': end must be after start.';
                        continue;
                    }
                    if ($asset->overlapsOngoingCheckout($pStartDT, $pEndDT)) {
                        $periodErrors[] = 'Period ' . ($idx + 1) . ': overlaps ongoing checkout.';
                        continue;
                    }
                    if (!is_null($asset->assigned_to) && $asset->expected_checkin) {
                        $currentEnd = Carbon::parse($asset->expected_checkin);
                        if ($pStartDT->lt($currentEnd)) {
                            $periodErrors[] = 'Period ' . ($idx + 1) . ': overlaps ongoing checkout.';
                            continue;
                        }
                    }
                    if ($asset->overlapsReservations($pStartDT, $pEndDT)) {
                        $periodErrors[] = 'Period ' . ($idx + 1) . ': overlaps existing reservation.';
                        continue;
                    }

                    AssetReservation::create([
                        'asset_id'       => $asset->id,
                        'user_id'        => $reservationUserId,
                        'reserved_from'  => $pStartDT->format('Y-m-d H:i:s'),
                        'reserved_until' => $pEndDT->format('Y-m-d H:i:s'),
                        'status'         => 'active',
                    ]);

                    // Log to asset history
                    // note format: "PERIOD\x00USER_NOTE" — split on null byte in presenter/transformer
                    $log = new \App\Models\Actionlog;
                    $log->item_type  = \App\Models\Asset::class;
                    $log->item_id    = $asset->id;
                    $log->created_by = auth()->id();
                    $log->target_type = \App\Models\User::class;
                    $log->target_id   = $reservationUserId;
                    $period = $pStartDT->format('Y-m-d H:i') . ' – ' . $pEndDT->copy()->subMinute()->format('Y-m-d H:i');
                    $userNote = $request->input('note', '');
                    $log->note = $period . "\x00" . $userNote;
                    $log->logaction('reserved');

                    $successCount++;
                }

                if ($successCount === 0) {
                    return back()->withInput()->with('error',
                        implode(' | ', $periodErrors) ?: 'No reservations could be created.');
                }

                $msg = $successCount > 1
                    ? "$successCount reservations created successfully."
                    : 'Reservation created successfully.';
                if (!empty($periodErrors)) {
                    $msg .= ' Some periods skipped: ' . implode(' | ', $periodErrors);
                }

                if ($returnTo) {
                    session()->forget('return_to');
                    return redirect($returnTo)->with('success', $msg);
                }

                return Helper::getRedirectOption($request, $asset->id, 'Assets')
                    ->with('success', $msg);

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

            if (!$isCheckoutToAsset) {
                // Disallow overlap with other users' reservations (hour-accurate)
                if ($asset->overlapsReservations($checkoutStart, $checkoutEnd, $checkoutUserId)) {
                    return back()->withInput()->with('error', 'Checkout overlaps an existing reservation.');
                }

                // Disallow overlap with an ongoing checkout window (hour-accurate)
                if ($asset->overlapsOngoingCheckout($checkoutStart, $checkoutEnd)) {
                    return back()->withInput()->with('error', 'Checkout overlaps an ongoing checkout period.');
                }
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

                if ($returnTo) {
                    session()->forget('return_to');
                    return redirect($returnTo)->with('success', trans('admin/hardware/message.checkout.success'));
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
