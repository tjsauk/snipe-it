<?php

namespace App\Http\Controllers\Assets;

use App\Events\CheckoutableCheckedIn;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetReservation;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

class AssetReservationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function edit(Request $request, Asset $asset, AssetReservation $reservation): View | RedirectResponse
    {
        if ($reservation->asset_id !== $asset->id) {
            abort(404);
        }

        $user = auth()->user();
        if (!$user) {
            return redirect()->route('login');
        }

        $isSuper = method_exists($user, 'isSuperUser') && $user->isSuperUser();

        // Authorization: superuser can edit any, normal users only their own
        if (!$isSuper && $reservation->user_id !== $user->id) {
            abort(403);
        }

        if ($reservation->status !== 'active') {
            return back()->with('error', 'Only active reservations can be edited.');
        }

        $returnTo = $request->input('return_to')
            ?: session('return_to_after_edit')
            ?: session('return_to')
            ?: route('hardware.reserve.manage', ['asset' => $asset->id]);

        // If reservation hasn't started yet, both dates can be changed.
        // If it's already ongoing, only the end date can be changed.
        $canChangeStart = $reservation->isFutureWindow();

        // Get calendar blocked ranges, excluding this reservation so the user
        // can select overlapping dates (they're editing the same slot).
        $calendarRanges = $asset->calendarBlockedRanges($reservation->id);

        return view('hardware/reservation_edit', [
            'asset'           => $asset,
            'reservation'     => $reservation,
            'can_change_start' => $canChangeStart,
            'return_to'       => $returnTo,
            'calendarRanges'  => $calendarRanges,
            'is_super'        => $isSuper,
        ]);
    }

    public function update(Request $request, Asset $asset, AssetReservation $reservation): RedirectResponse
    {
        if ($reservation->asset_id !== $asset->id) {
            abort(404);
        }

        $user = auth()->user();
        if (!$user) {
            return redirect()->route('login');
        }

        $isSuper = method_exists($user, 'isSuperUser') && $user->isSuperUser();

        if (!$isSuper && $reservation->user_id !== $user->id) {
            abort(403);
        }

        if ($reservation->status !== 'active') {
            return back()->with('error', 'Only active reservations can be edited.');
        }

        $returnTo = $request->input('return_to')
            ?: session('return_to')
            ?: route('hardware.reserve.manage', ['asset' => $asset->id]);

        $tz = config('app.timezone');
        $canChangeStart = $reservation->isFutureWindow();

        // Build new start datetime
        if ($canChangeStart) {
            $startDate = $request->input('checkout_at');
            $startHour = $request->filled('checkout_hour') ? (int) $request->input('checkout_hour') : 0;
            if (!$startDate) {
                return back()->withInput()->with('error', 'Start date is required.');
            }
            $newStart = Carbon::parse($startDate, $tz)->setTime($startHour, 0, 0);
            // Allow the current hour (e.g. if it's 11:23, selecting 11:00 is valid)
            if ($newStart->lt(Carbon::now($tz)->startOfHour())) {
                return back()->withInput()->with('error', 'Start time must be the current hour or later.');
            }
        } else {
            // Keep original start
            $newStart = $reservation->reserved_from->copy();
        }

        // Build new end datetime
        $endDate = $request->input('expected_checkin');
        $endHour = $request->filled('expected_checkin_hour') ? (int) $request->input('expected_checkin_hour') : 0;
        if (!$endDate) {
            return back()->withInput()->with('error', 'End date is required.');
        }
        // End slot start + 1 hour = boundary end (same convention as store)
        $endSlot = Carbon::parse($endDate, $tz)->setTime($endHour, 0, 0);
        $newEnd  = $endSlot->copy()->addHour();

        if ($newEnd->lte($newStart)) {
            return back()->withInput()->with('error', 'End time must be after start time.');
        }

        if (!$newEnd->isFuture()) {
            return back()->withInput()->with('error', 'End time must be in the future.');
        }

        // Conflict check: exclude this reservation itself
        if ($asset->overlapsOngoingCheckout($newStart, $newEnd)) {
            return back()->withInput()->with('error', 'The new period overlaps an ongoing checkout.');
        }

        if ($asset->overlapsReservations($newStart, $newEnd, null, $reservation->id)) {
            return back()->withInput()->with('error', 'The new period overlaps an existing reservation.');
        }

        // Check if times have changed
        $timesChanged = $reservation->reserved_from->ne($newStart) || $reservation->reserved_until->ne($newEnd);

        if ($timesChanged) {
            // If the reservation has already started and the asset is checked out to the reservation's user,
            // we must do a real checkin instead of just cancelling the reservation record.
            $assetCheckedOutToReservationUser = !$reservation->isFutureWindow()
                && $asset->assigned_to == $reservation->user_id;

            if ($assetCheckedOutToReservationUser) {
                $target = $asset->assignedTo;
                $originalValues = $asset->getRawOriginal();

                $asset->assigned_to    = null;
                $asset->assigned_type  = null;
                $asset->accepted       = null;
                $asset->expected_checkin = null;
                $asset->last_checkin   = now()->format('Y-m-d H:i:s');
                $asset->save();

                if ($target) {
                    event(new CheckoutableCheckedIn(
                        $asset,
                        $target,
                        $user,
                        'Reservation edited',
                        now()->format('Y-m-d H:i:s'),
                        $originalValues
                    ));
                }

                $reservation->status = 'fulfilled';
            } else {
                $reservation->status = 'cancelled';
            }

            $reservation->save();

            // Create a new reservation with updated times
            AssetReservation::create([
                'asset_id'       => $asset->id,
                'user_id'        => $reservation->user_id,
                'reserved_from'  => $newStart->format('Y-m-d H:i:s'),
                'reserved_until' => $newEnd->format('Y-m-d H:i:s'),
                'status'         => 'active',
            ]);
        }

        // Process additional periods from calendar edit mode (periods_json)
        $additionalCreated = 0;
        $periodsJson = $request->input('periods_json');
        if ($periodsJson) {
            $decoded = json_decode($periodsJson, true);
            if (is_array($decoded)) {
                foreach ($decoded as $p) {
                    $sParts = explode(' ', $p['start'] ?? '');
                    $eParts = explode(' ', $p['end'] ?? '');
                    if (count($sParts) < 2 || count($eParts) < 2) continue;

                    $aStartHour = (int) explode(':', $sParts[1])[0];
                    $aEndHour   = (int) explode(':', $eParts[1])[0];
                    $aStart = Carbon::parse($sParts[0], $tz)->setTime($aStartHour, 0, 0);
                    $aEnd   = Carbon::parse($eParts[0], $tz)->setTime($aEndHour, 0, 0)->addHour();

                    if ($aEnd->lte($aStart) || !$aEnd->isFuture()) continue;
                    if ($asset->overlapsOngoingCheckout($aStart, $aEnd)) continue;
                    if ($asset->overlapsReservations($aStart, $aEnd)) continue;

                    AssetReservation::create([
                        'asset_id'       => $asset->id,
                        'user_id'        => $reservation->user_id,
                        'reserved_from'  => $aStart->format('Y-m-d H:i:s'),
                        'reserved_until' => $aEnd->format('Y-m-d H:i:s'),
                        'status'         => 'active',
                    ]);
                    $additionalCreated++;
                }
            }
        }

        $successMsg = 'Reservation updated successfully.';
        if ($additionalCreated > 0) {
            $successMsg .= ' ' . $additionalCreated . ' additional period' . ($additionalCreated > 1 ? 's' : '') . ' created.';
        }

        return redirect($returnTo)->with('success', $successMsg);
    }

    public function destroy(Request $request, Asset $asset, AssetReservation $reservation): RedirectResponse
    {
        // Ensure the reservation belongs to this asset
        if ($reservation->asset_id !== $asset->id) {
            abort(404);
        }

        $user = auth()->user();

        $returnTo = $request->input('return_to')
            ?: session('return_to')
            ?: route('hardware.show', $asset);

        $isSuper = $user && method_exists($user, 'isSuperUser') && $user->isSuperUser();

        // Super users: can cancel any reservation
        if ($isSuper) {
            $reservation->status = 'cancelled';
            $reservation->save();

            return redirect($returnTo)->with('success', 'Reservation cancelled.');
        }

        // Normal users: only cancel their own reservation
        if (!$user || $reservation->user_id !== $user->id) {
            return redirect($returnTo)->with('error', 'You can only cancel your own reservations.');
        }

        $reservation->status = 'cancelled';
        $reservation->save();

        return redirect($returnTo)->with('success', 'Reservation cancelled.');
    }

    /**
     * Manage reservations page:
     * - Superuser: sees all reservations for asset
     * - Normal user: allowed only if they have >= 2 active reservations for this asset,
     *   and they only see their own reservations.
     */
    public function manage(Request $request, Asset $asset): View | RedirectResponse
    {
        $user = auth()->user();
        if (!$user) {
            return redirect()->route('login');
        }

        $returnTo = $request->input('return_to')
            ?: session('return_to')
            ?: route('hardware.show', $asset);

        $isSuper = method_exists($user, 'isSuperUser') && $user->isSuperUser();

        if ($isSuper) {
            $reservations = $asset->reservations()
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->where('reserved_until', '>', now())
                      ->orWhereNull('reserved_until');
                })
                ->with('user')
                ->orderBy('reserved_from')
                ->get();

            return view('hardware/reservations_manage', [
                'asset'        => $asset,
                'reservations' => $reservations,
                'return_to'    => $returnTo,
                'is_super'     => true,
            ]);
        }

        // Normal user path
        $myCount = AssetReservation::where('asset_id', $asset->id)
            ->where('status', 'active')
            ->where('user_id', $user->id)
            ->where('reserved_until', '>', now())
            ->count();

        if ($myCount < 1) {
            return redirect($returnTo)
                ->with('error', 'You do not have any active reservations to manage.');
        }

        $reservations = AssetReservation::where('asset_id', $asset->id)
            ->where('status', 'active')
            ->where('user_id', $user->id)
            ->where('reserved_until', '>', now())
            ->orderBy('reserved_from')
            ->get();

        return view('hardware/reservations_manage', [
            'asset'        => $asset,
            'reservations' => $reservations,
            'return_to'    => $returnTo,
            'is_super'     => false,
        ]);
    }
}
