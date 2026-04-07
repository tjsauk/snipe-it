<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetReservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

class AssetReservationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
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
            $reservations = $asset->activeReservations()
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

        if ($myCount < 2) {
            return redirect($returnTo)
                ->with('error', 'You do not have multiple active reservations to manage.');
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
