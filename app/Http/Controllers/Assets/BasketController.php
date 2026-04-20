<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\CheckInOutRequest;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetReservation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BasketController extends Controller
{
    use CheckInOutRequest;

    /**
     * Add an asset to the session basket.
     */
    public function add(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('view', $asset);

        $current = session('asset_basket', []);
        session()->put('asset_basket', array_values(array_unique(array_merge($current, [$asset->id]))));

        $returnTo = $request->input('return_to');
        if ($returnTo) {
            return redirect()->to($returnTo)
                ->with('success', trans('general.basket_added', ['name' => $asset->present()->fullName]));
        }

        return redirect()->back()->with('success', trans('general.basket_added', ['name' => $asset->present()->fullName]));
    }

    /**
     * Remove an asset from the session basket.
     */
    public function remove(Request $request, Asset $asset): RedirectResponse
    {
        $current = session('asset_basket', []);
        session()->put('asset_basket', array_values(array_filter(
            $current,
            fn ($id) => $id != $asset->id
        )));

        return redirect()->back()->with('success', trans('general.basket_removed', ['name' => $asset->present()->fullName]));
    }

    /**
     * Clear the entire basket.
     */
    public function clear(Request $request): RedirectResponse
    {
        session()->forget('asset_basket');

        return redirect()->back()->with('success', trans('general.basket_cleared'));
    }

    /**
     * Show the basket reserve page.
     */
    public function showReserve(Request $request): View|RedirectResponse
    {
        $this->authorize('view', Asset::class);

        $ids = session('asset_basket', []);

        if (empty($ids)) {
            return redirect()->route('hardware.index')
                ->with('warning', trans('general.basket_empty'));
        }

        $assets = Asset::with('model')->whereIn('id', $ids)->get();
        $mode   = 'basket';

        return view('hardware.bulk-reserve', compact('assets', 'mode'));
    }

    /**
     * Store reservations for selected basket assets.
     *
     * After successful reservation the reserved assets are removed from the basket.
     * If the basket still contains assets the user is redirected back to
     * continue reserving the remainder; otherwise they go to hardware.index.
     */
    public function storeReserve(Request $request): RedirectResponse
    {
        $this->authorize('view', Asset::class);

        $selectedIds    = array_filter((array) $request->get('selected_assets', []));
        $periodsRaw     = $request->input('periods_json');
        $checkoutToType = $request->get('checkout_to_type', 'user');

        if (empty($selectedIds)) {
            return redirect()->route('hardware.basket.reserve.show')
                ->with('error', trans('admin/hardware/message.update.no_assets_selected'));
        }

        $tz    = config('app.timezone');
        $admin = auth()->user();

        // Determine checkout target safely (findOrFail would throw on missing value)
        try {
            $target = $this->determineCheckoutTarget();
        } catch (\Exception $e) {
            $target = $admin;
            $checkoutToType = 'user';
        }

        // Asset checkout: no calendar needed — inherit dates from target asset
        if ($checkoutToType === 'asset') {
            $successCount = 0;
            $reservedIds  = [];
            foreach ($selectedIds as $assetId) {
                $asset = Asset::find($assetId);
                if (!$asset || !$asset->availableForCheckout()) {
                    continue;
                }
                $checkoutAt = Carbon::now($tz)->format('Y-m-d H:i:s');
                if ($asset->checkOut($target, $admin, $checkoutAt, null, null, $asset->name)) {
                    $successCount++;
                    $reservedIds[] = $assetId;
                }
            }
            if (!empty($reservedIds)) {
                $remaining = array_values(array_filter(
                    session('asset_basket', []),
                    fn ($id) => !in_array($id, $reservedIds)
                ));
                session()->put('asset_basket', $remaining);
            }
            if ($successCount === 0) {
                return redirect()->route('hardware.basket.reserve.show')
                    ->with('error', 'No assets could be checked out.');
            }
            $basketRemaining = session('asset_basket', []);
            if (!empty($basketRemaining)) {
                return redirect()->route('hardware.basket.reserve.show')
                    ->with('success', trans('general.bulk_reserve_success', ['count' => $successCount]));
            }
            return redirect()->route('hardware.index')
                ->with('success', trans('general.bulk_reserve_success', ['count' => $successCount]));
        }

        if (empty($periodsRaw)) {
            return redirect()->route('hardware.basket.reserve.show')
                ->withInput()
                ->with('error', 'No periods selected. Please drag a period in the calendar.');
        }

        $periodsData = json_decode($periodsRaw, true);

        if (!is_array($periodsData) || empty($periodsData)) {
            return redirect()->route('hardware.basket.reserve.show')
                ->withInput()
                ->with('error', 'Invalid period data. Please try again.');
        }

        $reservationUserId = ($checkoutToType === 'user' && $target)
            ? $target->id
            : $admin->id;

        $successCount = 0;
        $skippedCount = 0;
        $reservedIds  = [];
        $errors       = [];

        // Index periods by asset ID
        $assetPeriodMap = [];
        foreach ($periodsData as $assetData) {
            $aid = (string) ($assetData['id'] ?? '');
            if ($aid) {
                $assetPeriodMap[$aid] = $assetData['selectedPeriods'] ?? [];
            }
        }

        DB::transaction(function () use (
            $selectedIds, $assetPeriodMap, $tz, $reservationUserId, $target, $admin, $checkoutToType,
            &$successCount, &$skippedCount, &$reservedIds, &$errors
        ) {
            foreach ($selectedIds as $assetId) {
                $asset = Asset::find($assetId);
                if (!$asset) {
                    continue;
                }

                $periods = $assetPeriodMap[(string) $assetId] ?? [];

                if (empty($periods)) {
                    $skippedCount++;
                    continue;
                }

                $assetReserved = false;

                foreach ($periods as $period) {
                    $startStr = $period['start'] ?? null;
                    $endStr   = $period['end'] ?? null;

                    if (!$startStr || !$endStr) {
                        continue;
                    }

                    $startDT  = Carbon::parse($startStr, $tz);
                    $endBound = Carbon::parse($endStr, $tz)->addMinute();

                    if ($endBound->lte($startDT)) {
                        continue;
                    }

                    // Past/current hour → immediate checkout
                    if ($startDT->lte(Carbon::now($tz)->startOfHour())) {
                        if (!$asset->availableForCheckout()) {
                            $errors[] = "Asset #{$assetId}: not available for checkout, skipped.";
                            continue;
                        }
                        $checkoutAt      = Carbon::now($tz)->format('Y-m-d H:i:s');
                        $expectedCheckin = $endBound->copy()->subMinute()->format('Y-m-d H:i:s');
                        if ($asset->checkOut($target, $admin, $checkoutAt, $expectedCheckin, null, $asset->name)) {
                            $asset->absorbAdjacentReservations($reservationUserId, $endBound, $tz);
                            $assetReserved = true;
                        } else {
                            $errors[] = "Asset #{$assetId}: checkout failed.";
                        }
                        continue;
                    }

                    // --- Merge with own ongoing checkout ---
                    $ownCheckoutActive = !is_null($asset->assigned_to)
                        && $asset->assigned_type === User::class
                        && $asset->assigned_to == $reservationUserId
                        && $asset->last_checkout;

                    if ($ownCheckoutActive) {
                        $coEnd = $asset->expected_checkin
                            ? Carbon::parse($asset->expected_checkin, $tz)
                            : null;

                        if (!$coEnd || $startDT->lte($coEnd)) {
                            $mergedEnd = (!$coEnd || $endBound->gt($coEnd)) ? $endBound : $coEnd;
                            $asset->expected_checkin = $mergedEnd->format('Y-m-d H:i:s');
                            $asset->save();
                            $asset->absorbAdjacentReservations($reservationUserId, $mergedEnd, $tz);
                            $assetReserved = true;
                            continue;
                        }
                    }

                    // --- Merge with own existing active reservations ---
                    $existingOwn = $asset->reservations()
                        ->where('status', 'active')
                        ->where('user_id', $reservationUserId)
                        ->where('reserved_from', '<', $endBound->format('Y-m-d H:i:s'))
                        ->where('reserved_until', '>=', $startDT->format('Y-m-d H:i:s'))
                        ->get();

                    if ($existingOwn->isNotEmpty()) {
                        $mergeStart = $startDT->copy();
                        $mergeEnd   = $endBound->copy();
                        foreach ($existingOwn as $own) {
                            $ownFrom  = Carbon::parse($own->reserved_from, $tz);
                            $ownUntil = Carbon::parse($own->reserved_until, $tz);
                            if ($ownFrom->lt($mergeStart)) $mergeStart = $ownFrom;
                            if ($ownUntil->gt($mergeEnd))  $mergeEnd   = $ownUntil;
                        }
                        foreach ($existingOwn as $own) {
                            $own->update(['status' => 'cancelled']);
                        }
                        AssetReservation::create([
                            'asset_id'       => $asset->id,
                            'user_id'        => $reservationUserId,
                            'reserved_from'  => $mergeStart->format('Y-m-d H:i:s'),
                            'reserved_until' => $mergeEnd->format('Y-m-d H:i:s'),
                            'status'         => 'active',
                        ]);
                        $assetReserved = true;
                        continue;
                    }

                    // --- Create new reservation ---
                    AssetReservation::create([
                        'asset_id'       => $asset->id,
                        'user_id'        => $reservationUserId,
                        'reserved_from'  => $startDT->format('Y-m-d H:i:s'),
                        'reserved_until' => $endBound->format('Y-m-d H:i:s'),
                        'status'         => 'active',
                    ]);
                    $assetReserved = true;
                }

                if ($assetReserved) {
                    $successCount++;
                    $reservedIds[] = $assetId;
                } else {
                    $skippedCount++;
                }
            }
        });

        if (!empty($errors)) {
            Log::warning('Basket reserve partial errors', $errors);
        }

        // Remove successfully reserved assets from basket
        if (!empty($reservedIds)) {
            $remaining = array_values(array_filter(
                session('asset_basket', []),
                fn ($id) => !in_array($id, $reservedIds)
            ));
            session()->put('asset_basket', $remaining);
        }

        // Still assets in basket → return to basket reserve to handle them
        $basketRemaining = session('asset_basket', []);

        if ($successCount === 0) {
            return redirect()->route('hardware.basket.reserve.show')
                ->withInput()
                ->with('error', 'No reservations were created. Assets may have no available slots.');
        }

        if (!empty($basketRemaining)) {
            return redirect()->route('hardware.basket.reserve.show')
                ->with('success', trans('general.bulk_reserve_success', ['count' => $successCount]));
        }

        return redirect()->route('hardware.index')
            ->with('success', trans('general.bulk_reserve_success', ['count' => $successCount]));
    }
}
