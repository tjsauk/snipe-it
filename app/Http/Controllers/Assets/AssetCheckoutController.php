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
            // Filter out open-ended ranges (to: null) — these occur when an asset is checked out
            // to another asset with no expected_checkin. The calendar JS cannot handle null end
            // dates and would crash. These ranges also don't block reservations (overlapsOngoingCheckout
            // returns false for them), so excluding them from the calendar is consistent.
            $calendarRanges = array_values(array_filter(
                $asset->calendarBlockedRanges(),
                fn($r) => $r['to'] !== null
            ));

            // When the asset is checked out to a parent asset, also include the parent's blocked
            // ranges so the reserve calendar shows the parent's schedule as occupied.
            if ($asset->assigned_type === \App\Models\Asset::class && $asset->assignedTo) {
                $parentName = $asset->assignedTo->name ?? $asset->assignedTo->asset_tag;
                foreach ($asset->assignedTo->calendarBlockedRanges() as $pr) {
                    if ($pr['to'] === null) continue;
                    $pr['_source_asset_name'] = $parentName;
                    $calendarRanges[] = $pr;
                }
            }

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

            // Determine if this checkout is to another asset (no date selection needed)
            $isCheckoutToAsset = ($request->get('checkout_to_type') === 'asset');

            // For checkout-to-asset, set default dates immediately (before reserveMode is determined)
            if ($isCheckoutToAsset) {
                $nowTz = Carbon::now(config('app.timezone'));
                if (!$request->filled('checkout_at')) {
                    $request->merge(['checkout_at' => $nowTz->toDateString(), 'checkout_hour' => $nowTz->hour]);
                }
                if (!$request->filled('expected_checkin')) {
                    $request->merge(['expected_checkin' => $nowTz->toDateString(), 'expected_checkin_hour' => $nowTz->hour]);
                }
                $request->merge(['periods_json' => null]);
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
                // For checkout to asset, also allow if checked out to current user
                if ($checkoutToType !== 'asset' || ! ($asset->assigned_to == auth()->id() && $asset->assigned_type == 'App\Models\User')) {
                    return redirect()->route('hardware.index')
                        ->with('error', trans('admin/hardware/message.checkout.not_available'));
                }
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

                    // Check if this period can merge with own ongoing checkout (same user).
                    $ownCheckoutActive = !is_null($asset->assigned_to)
                        && $asset->assigned_type == \App\Models\User::class
                        && $asset->assigned_to == $reservationUserId
                        && $asset->last_checkout;

                    if ($ownCheckoutActive) {
                        $coStart = Carbon::parse($asset->last_checkout, $tz);
                        $coEnd   = $asset->expected_checkin ? Carbon::parse($asset->expected_checkin, $tz) : null;

                        // Adjacent or overlapping → merge into checkout by extending its end
                        if (!$coEnd || $pStartDT->lte($coEnd)) {
                            $mergedEnd = $coEnd ? ($pEndDT->gt($coEnd) ? $pEndDT : $coEnd) : $pEndDT;
                            $asset->expected_checkin = $mergedEnd->format('Y-m-d H:i:s');
                            $asset->save();

                            // Keep fulfilled reservation in sync
                            $fulfilledRes = $asset->reservations()
                                ->where('status', 'fulfilled')
                                ->where('user_id', $reservationUserId)
                                ->where('reserved_until', '>', now()->format('Y-m-d H:i:s'))
                                ->orderByDesc('reserved_from')
                                ->first();
                            if ($fulfilledRes) {
                                $fulfilledRes->reserved_until = $mergedEnd->format('Y-m-d H:i:s');
                                $fulfilledRes->save();
                            }

                            // Chain-merge any reservations now adjacent to the extended checkout
                            $asset->absorbAdjacentReservations($reservationUserId, $mergedEnd, $tz);

                            $successCount++;
                            continue;
                        }
                        // New period starts after checkout ends — allow it as a normal future reservation
                    } else {
                        // Not own checkout: block overlaps with any ongoing checkout
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
                    }

                    // Block overlap with other users' reservations
                    if ($asset->overlapsReservations($pStartDT, $pEndDT, $reservationUserId)) {
                        $periodErrors[] = 'Period ' . ($idx + 1) . ': overlaps existing reservation.';
                        continue;
                    }

                    // Check if new period is adjacent to or overlaps own existing reservation → merge
                    $ownAdjacent = $asset->reservations()
                        ->where('status', 'active')
                        ->where('user_id', $reservationUserId)
                        ->where(function ($q) use ($pStartDT, $pEndDT) {
                            // Existing ends exactly where new starts (adjacent, boundary-to-boundary)
                            $q->where('reserved_until', $pStartDT->format('Y-m-d H:i:s'))
                              // New ends exactly where existing starts (adjacent, other direction)
                              ->orWhere('reserved_from', $pEndDT->format('Y-m-d H:i:s'))
                              // Overlapping
                              ->orWhere(function ($q2) use ($pStartDT, $pEndDT) {
                                  $q2->where('reserved_from', '<', $pEndDT->format('Y-m-d H:i:s'))
                                     ->where('reserved_until', '>', $pStartDT->format('Y-m-d H:i:s'));
                              });
                        })
                        ->first();

                    if ($ownAdjacent) {
                        // Merge: extend to the earliest start and latest end
                        $mergedStart = min($pStartDT->timestamp, Carbon::parse($ownAdjacent->reserved_from)->timestamp) === $pStartDT->timestamp
                            ? $pStartDT : Carbon::parse($ownAdjacent->reserved_from);
                        $mergedEnd = Carbon::parse($ownAdjacent->reserved_until)->gt($pEndDT)
                            ? Carbon::parse($ownAdjacent->reserved_until) : $pEndDT;
                        $ownAdjacent->reserved_from  = $mergedStart->format('Y-m-d H:i:s');
                        $ownAdjacent->reserved_until = $mergedEnd->format('Y-m-d H:i:s');
                        $ownAdjacent->save();

                        $period = $mergedStart->format('Y-m-d H:i') . ' – ' . $mergedEnd->copy()->subMinute()->format('Y-m-d H:i');
                        $userNote = $request->input('note', '');
                        $log = new \App\Models\Actionlog;
                        $log->item_type   = \App\Models\Asset::class;
                        $log->item_id     = $asset->id;
                        $log->created_by  = auth()->id();
                        $log->target_type = \App\Models\User::class;
                        $log->target_id   = $reservationUserId;
                        $log->note = $period . "\x00" . $userNote;
                        $log->logaction('reserved');

                        $successCount++;
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

            // Check if this checkout should merge with / fulfill the user's own reservation.
            // Merge condition: checkout end boundary == reservation start (adjacent, 1-min UI gap)
            // OR checkout overlaps the reservation window.
            // When merged, extend expected_checkin to the reservation's end time.
            $userReservation = $checkoutUserId ? $asset->activeReservationForUser($checkoutUserId) : null;
            $fulfillReservation = false;

            if ($userReservation) {
                $resFrom  = Carbon::parse($userReservation->reserved_from);
                $resUntil = $userReservation->reserved_until
                    ? Carbon::parse($userReservation->reserved_until)
                    : $resFrom->copy();

                // Adjacent: checkout boundary end == reservation start (e.g. both 14:00)
                // Overlapping: checkout start < reservation end AND checkout end > reservation start
                $adjacent = $checkoutEnd->eq($resFrom);
                $overlapping = $checkoutStart < $resUntil && $checkoutEnd > $resFrom;

                if ($adjacent || $overlapping) {
                    $fulfillReservation = true;
                    // Extend the checkout window to cover the reservation period
                    $checkoutEnd   = $resUntil->copy();
                    $windowEndDT   = $resUntil->copy();
                    $expected_checkin = $resUntil->format('Y-m-d H:i:s');
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

                    // Absorb any reservations that are adjacent to or overlapping the checkout end.
                    // Without this, a user with adjacent reservations (A: now→Apr20, B: Apr20→Apr25)
                    // would see two items in manage: fulfilled A + active B instead of one merged item.
                    if ($checkoutUserId && $checkoutEnd) {
                        $asset->absorbAdjacentReservations($checkoutUserId, $checkoutEnd, $tz);
                    }
                }

                // Process any additional future periods from periods_json as reservations.
                // This handles the case where the user selects the current hour (→ checkout)
                // AND one or more future periods (→ reservations) in the same calendar action.
                $periodsJson = $request->input('periods_json');
                if ($periodsJson && !$isCheckoutToAsset) {
                    $decoded = json_decode($periodsJson, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $p) {
                            $sParts = explode(' ', $p['start'] ?? '');
                            $eParts = explode(' ', $p['end'] ?? '');
                            if (count($sParts) < 2 || count($eParts) < 2) continue;

                            $pStartDT = Carbon::parse($sParts[0], $tz)->setTime((int) explode(':', $sParts[1])[0], 0, 0);
                            $pEndDT   = Carbon::parse($eParts[0], $tz)->setTime((int) explode(':', $eParts[1])[0], 0, 0)->addHour();

                            // Skip the primary checkout period (any period overlapping the checkout window)
                            if ($pStartDT->lt($checkoutEnd) && $pEndDT->gt($checkoutStart)) {
                                continue;
                            }
                            // Skip past or invalid periods
                            if ($pStartDT->lt(Carbon::now($tz)->startOfHour()) || $pEndDT->lte($pStartDT)) {
                                continue;
                            }
                            // Skip if it conflicts with another user's reservation
                            if ($asset->overlapsReservations($pStartDT, $pEndDT, $checkoutUserId)) {
                                continue;
                            }

                            AssetReservation::create([
                                'asset_id'       => $asset->id,
                                'user_id'        => $checkoutUserId,
                                'reserved_from'  => $pStartDT->format('Y-m-d H:i:s'),
                                'reserved_until' => $pEndDT->format('Y-m-d H:i:s'),
                                'status'         => 'active',
                            ]);

                            $log = new \App\Models\Actionlog;
                            $log->item_type   = \App\Models\Asset::class;
                            $log->item_id     = $asset->id;
                            $log->created_by  = auth()->id();
                            $log->target_type = \App\Models\User::class;
                            $log->target_id   = $checkoutUserId;
                            $userNote = $request->input('note', '');
                            $log->note = $pStartDT->format('Y-m-d H:i') . ' – ' . $pEndDT->copy()->subMinute()->format('Y-m-d H:i') . "\x00" . $userNote;
                            $log->logaction('reserved');
                        }
                    }
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

    /**
     * Show the form for editing a checkout (only expected checkin can be changed).
     */
    public function edit(Request $request, Asset $asset): View | RedirectResponse
    {
        // Check if the asset is checked out
        if (!$asset->assigned_to) {
            return redirect()->route('hardware.show', $asset)
                ->with('error', 'Asset is not checked out.');
        }

        $user = auth()->user();
        $isSuper = $user && method_exists($user, 'isSuperUser') && $user->isSuperUser();

        // For checkout to asset, allow editing if the user is superuser or has checkout rights
        $isCheckoutToAsset = $asset->assigned_type === 'App\Models\Asset';
        if ($isCheckoutToAsset) {
            if (!$isSuper) {
                $this->authorize('checkout', $asset);
            }
        } else {
            // For user/location checkouts, only allow if checked out to current user or admin
            if (!$isSuper && $asset->assigned_to != $user->id) {
                abort(403);
            }
        }

        $returnTo = $request->input('return_to')
            ?: session('return_to_after_edit')
            ?: session('return_to')
            ?: route('hardware.show', $asset->id);

        // Get calendar blocked ranges
        $calendarRanges = $asset->calendarBlockedRanges();

        // Create pseudo reservation for using reservation edit view
        $pseudoReservation = (object) [
            'id' => 'checkout',
            'user_id' => $asset->assigned_to,
            'user' => $asset->assignedTo,
            'reserved_from' => Carbon::parse($asset->last_checkout),
            'reserved_until' => $asset->expected_checkin ? Carbon::parse($asset->expected_checkin) : null,
            'status' => 'fulfilled',
        ];

        return view('hardware/reservation_edit', [
            'asset' => $asset,
            'reservation' => $pseudoReservation,
            'can_change_start' => false,
            'return_to' => $returnTo,
            'calendarRanges' => $calendarRanges,
            'is_super' => $isSuper,
        ]);
    }

    /**
     * Update the expected checkin date for a checkout.
     */
    public function update(Request $request, Asset $asset): RedirectResponse
    {
        // Check if the asset is checked out
        if (!$asset->assigned_to) {
            return redirect()->route('hardware.show', $asset)
                ->with('error', 'Asset is not checked out.');
        }

        $user = auth()->user();
        $isSuper = $user && method_exists($user, 'isSuperUser') && $user->isSuperUser();

        // For checkout to asset, allow editing if the user is superuser or has checkout rights
        $isCheckoutToAsset = $asset->assigned_type === 'App\Models\Asset';
        if ($isCheckoutToAsset) {
            if (!$isSuper) {
                $this->authorize('checkout', $asset);
            }
        } else {
            // For user/location checkouts, only allow if checked out to current user or admin
            if (!$isSuper && $asset->assigned_to != $user->id) {
                abort(403);
            }
        }

        $returnTo = $request->input('return_to')
            ?: session('return_to')
            ?: route('hardware.show', $asset->id);

        $tz = config('app.timezone');

        // Handle periods_json if provided (from calendar selection)
        $newExpectedCheckin = null;
        if ($request->filled('periods_json')) {
            $decoded = json_decode($request->input('periods_json'), true);
            if (is_array($decoded) && count($decoded) > 0) {
                // Take the end of the first (and only) period
                $endDT = Carbon::parse($decoded[0]['end'], $tz);
                $newExpectedCheckin = $endDT;
            }
        }

        if (!$newExpectedCheckin) {
            // Build new expected checkin datetime from form fields
            $endDate = $request->input('expected_checkin');
            $endHour = $request->filled('expected_checkin_hour') ? (int) $request->input('expected_checkin_hour') : 0;
            if (!$endDate) {
                return back()->withInput()->with('error', 'Expected checkin date is required.');
            }
            // End slot start + 1 hour = boundary end
            $endSlot = Carbon::parse($endDate, $tz)->setTime($endHour, 0, 0);
            $newExpectedCheckin = $endSlot->copy()->addHour();
        }

        if ($newExpectedCheckin->isPast()) {
            return back()->withInput()->with('error', 'Expected checkin must be in the future.');
        }

        // Check for overlaps with OTHER users' reservations only.
        // overlapsOngoingCheckout is intentionally skipped: we ARE the ongoing checkout,
        // so checking against ourselves would always return true.
        // Use now() as the overlap start: stale past active reservations (reserved_until already
        // passed) must not block extending an overdue checkout — only future conflicts matter.
        $overlapCheckFrom = Carbon::now();
        if ($asset->overlapsReservations($overlapCheckFrom, $newExpectedCheckin, $asset->assigned_to)) {
            return back()->withInput()->with('error', 'The new expected checkin overlaps an existing reservation.');
        }

        // Update the expected checkin
        $asset->expected_checkin = $newExpectedCheckin->format('Y-m-d H:i:s');
        $asset->save();

        // Chain-merge any own active reservations now adjacent to / overlapping the checkout window.
        $checkoutUserId = $asset->assigned_to;
        if ($checkoutUserId) {
            $finalEnd = $asset->absorbAdjacentReservations($checkoutUserId, $newExpectedCheckin, $tz);
            if ($finalEnd->gt($newExpectedCheckin)) {
                return redirect($returnTo)->with('success', 'Checkout extended and merged with reservation(s).');
            }
        }

        return redirect($returnTo)->with('success', 'Checkout updated successfully.');
    }

}
