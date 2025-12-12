<?php

namespace App\Http\Controllers\Assets;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetReservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

class AssetReservationController extends Controller
{
    public function destroy(Asset $asset, AssetReservation $reservation): RedirectResponse
    {
        $this->middleware('auth');

        // Ensure the reservation belongs to this asset
        if ($reservation->asset_id !== $asset->id) {
            abort(404);
        }

        $user = auth()->user();

        // Super users: can cancel any reservation
        if ($user && method_exists($user, 'isSuperUser') && $user->isSuperUser()) {
            $reservation->status = 'cancelled';
            $reservation->save();

            return redirect()->route('hardware.show', $asset)
                ->with('success', 'Reservation cancelled.');
        }

        // Normal users: only cancel their own reservation
        if (!$user || $reservation->user_id !== $user->id) {
            return redirect()->route('hardware.show', $asset)
                ->with('error', 'You can only cancel your own reservations.');
        }

        $reservation->status = 'cancelled';
        $reservation->save();

        return redirect()->route('hardware.show', $asset)
            ->with('success', 'Reservation cancelled.');
    }

    /**
     * Show a "check-in style" page where a superuser can select
     * which reservation(s) to cancel for this asset.
     */
    public function manage(Request $request, Asset $asset) : View | RedirectResponse
    {
        $user = auth()->user();

        // Only superusers are allowed to manage OTHER people's reservations here
        if (! $user || ! method_exists($user, 'isSuperUser') || ! $user->isSuperUser()) {
            return redirect()->route('hardware.show', $asset)
                ->with('error', 'Only superusers can manage all reservations.');
        }

        $reservations = $asset->activeReservations()
            ->with('user')
            ->orderBy('reserved_from')
            ->get();

        return view('hardware/reservations_manage', [
            'asset'        => $asset,
            'reservations' => $reservations,
        ]);
    }
}
