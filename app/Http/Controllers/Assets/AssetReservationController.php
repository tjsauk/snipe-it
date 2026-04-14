<?php

namespace App\Http\Controllers\Assets;

use App\Events\CheckoutableCheckedIn;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetReservation;
use App\Models\CheckoutAcceptance;
use App\Models\LicenseSeat;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
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

        // Allow editing:
        //   - active reservations whose window hasn't ended yet
        //   - fulfilled reservations that are ongoing AND the asset is still checked out to this user
        $assetStillCheckedOutToUser = $asset->assigned_to == $reservation->user_id
            && $asset->assigned_type == \App\Models\User::class;

        // Allow editing active reservations regardless of date (overdue active reservations
        // should still be editable so they can be extended or converted to checkouts).
        $isEditableStatus = $reservation->status === 'active'
            || ($reservation->status === 'fulfilled' && $assetStillCheckedOutToUser);
        if (!$isEditableStatus) {
            return back()->with('error', 'Only active or ongoing reservations can be edited.');
        }

        $returnTo = $request->input('return_to')
            ?: session('return_to_after_edit')
            ?: session('return_to')
            ?: route('hardware.reserve.manage', ['asset' => $asset->id]);

        // If reservation hasn't started yet, both dates can be changed.
        // If it's already ongoing or overdue, only the end date can be changed.
        $canChangeStart = $reservation->isFutureWindow();

        // Get calendar blocked ranges, excluding this reservation so the user
        // can select overlapping dates (they're editing the same slot).
        $calendarRanges = $asset->calendarBlockedRanges($reservation->id);

        // Mark own checkout as non-blocking so it stays visible in the calendar
        // but doesn't prevent the user from dragging over it (they can merge into it).
        if ($asset->assigned_to == $reservation->user_id && $asset->assigned_type == \App\Models\User::class && $asset->last_checkout) {
            $calendarRanges = array_map(function ($range) {
                if (($range['type'] ?? '') === 'checkout') {
                    $range['own_checkout'] = true;
                }
                return $range;
            }, $calendarRanges);
        }

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

        $assetStillCheckedOutToUser = $asset->assigned_to == $reservation->user_id
            && $asset->assigned_type == \App\Models\User::class;

        $isEditableStatus = $reservation->status === 'active'
            || ($reservation->status === 'fulfilled' && $assetStillCheckedOutToUser);
        if (!$isEditableStatus) {
            return back()->with('error', 'Only active or ongoing reservations can be edited.');
        }

        $returnTo = $request->input('return_to')
            ?: session('return_to')
            ?: route('hardware.reserve.manage', ['asset' => $asset->id]);

        $tz = config('app.timezone');
        $canChangeStart = $reservation->isFutureWindow();

        // Process periods_json if provided (for multi-period selection)
        $periodsJson = $request->input('periods_json');
        $newStart = null;
        $newEnd = null;
        if ($periodsJson) {
            $decoded = json_decode($periodsJson, true);
            if (is_array($decoded) && count($decoded) > 0) {
                // Sort periods by start
                usort($decoded, function ($a, $b) {
                    return strcmp($a['start'], $b['start']);
                });
                // Check if continuous and merge
                $mergedStart = Carbon::parse($decoded[0]['start'], $tz);
                $mergedEnd = Carbon::parse($decoded[0]['end'], $tz);
                for ($i = 1; $i < count($decoded); $i++) {
                    $pStart = Carbon::parse($decoded[$i]['start'], $tz);
                    $pEnd = Carbon::parse($decoded[$i]['end'], $tz);
                    if ($pStart->eq($mergedEnd)) {
                        $mergedEnd = $pEnd;
                    } else {
                        return back()->withInput()->with('error', 'Selected periods must be adjacent to merge.');
                    }
                }
                $newStart = $mergedStart;
                $newEnd = $mergedEnd;
            }
        }

        // If no periods_json, use single period from form
        if (!$newStart) {
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
        }

        // Allow changing start for fulfilled reservations if merging with checkout
        if (!$canChangeStart && $newStart->ne($reservation->reserved_from)) {
            if ($asset->last_checkout && $newStart->eq(Carbon::parse($asset->last_checkout, $tz))) {
                $canChangeStart = true; // Allow merging
            } else {
                return back()->withInput()->with('error', 'Cannot change start time for ongoing reservations.');
            }
        }

        if ($newEnd->lte($newStart)) {
            return back()->withInput()->with('error', 'End time must be after start time.');
        }

        if (!$newEnd->isFuture()) {
            return back()->withInput()->with('error', 'End time must be in the future.');
        }

        // If the asset is currently checked out to this reservation's user, the checkout IS this
        // reservation — skip the checkout-overlap check (otherwise it always blocks).
        $assetCheckedOutToReservationUser = $asset->assigned_to == $reservation->user_id
            && $asset->assigned_type == \App\Models\User::class;

        // Conflict check: exclude this reservation itself and own user (own overlaps become merges)
        if (!$assetCheckedOutToReservationUser && $asset->overlapsOngoingCheckout($newStart, $newEnd)) {
            return back()->withInput()->with('error', 'The new period overlaps an ongoing checkout.');
        }

        // Own-user overlaps are allowed — they will be merged below.
        // Only block overlaps with OTHER users' reservations.
        if ($asset->overlapsReservations($newStart, $newEnd, $reservation->user_id, $reservation->id)) {
            // overlapsReservations already excludes own user (user_id param), so this is a foreign overlap
            return back()->withInput()->with('error', 'The new period overlaps an existing reservation.');
        }

        // Check if times have changed
        $timesChanged = $reservation->reserved_from->ne($newStart) || $reservation->reserved_until->ne($newEnd);

        // Convert to checkout only when asset is NOT already checked out to this user.
        // When it IS checked out, the merge-with-checkout logic below handles the case.
        if ($timesChanged && !$newStart->isFuture() && !$assetCheckedOutToReservationUser) {
            $reservation->status = 'cancelled';
            $reservation->save();
            $asset->logReservationEvent($reservation, 'reservation_cancelled', 'Reservation converted to checkout via edit');
            $asset->checkOut($reservation->user, $user, $newStart->format('Y-m-d H:i:s'), $newEnd->format('Y-m-d H:i:s'), 'Converted from reservation edit', $asset->name);
            return redirect($returnTo)->with('success', 'Reservation converted to checkout.');
        }

        if ($timesChanged) {
            // Already checked-out + fulfilled: just update reservation end and asset expected_checkin in-place
            if ($assetCheckedOutToReservationUser && $reservation->status === 'fulfilled') {
                $reservation->reserved_until = $newEnd->format('Y-m-d H:i:s');
                $reservation->save();
                $asset->expected_checkin = $newEnd->format('Y-m-d H:i:s');
                $asset->save();
                $asset->logReservationEvent($reservation, 'reservation_updated', 'Reservation extended (checkout end updated)');

                return redirect($returnTo)->with('success', 'Reservation extended successfully.');
            }

            // Active reservation overlaps/adjacent with own ongoing checkout → extend checkout
            if ($assetCheckedOutToReservationUser && $reservation->status === 'active' && $asset->last_checkout) {
                $checkoutStart = Carbon::parse($asset->last_checkout, $tz);
                $checkoutEnd = $asset->expected_checkin ? Carbon::parse($asset->expected_checkin, $tz) : null;

                // Merge if new period overlaps checkout or starts exactly when checkout ends (adjacent)
                $mergeCondition = (!$checkoutEnd || $newStart->lte($checkoutEnd)) && $newEnd->gt($checkoutStart);

                if ($mergeCondition) {
                    $mergedEnd = $checkoutEnd ? ($newEnd->gt($checkoutEnd) ? $newEnd : $checkoutEnd) : $newEnd;

                    $asset->expected_checkin = $mergedEnd->format('Y-m-d H:i:s');
                    $asset->save();

                    // Also update fulfilled reservation's end if one exists
                    $fulfilledRes = $asset->reservations()
                        ->where('status', 'fulfilled')
                        ->where('user_id', $reservation->user_id)
                        ->where('reserved_until', '>', now()->format('Y-m-d H:i:s'))
                        ->orderByDesc('reserved_from')
                        ->first();
                    if ($fulfilledRes) {
                        $fulfilledRes->reserved_until = $mergedEnd->format('Y-m-d H:i:s');
                        $fulfilledRes->save();
                    }

                    $reservation->status = 'cancelled';
                    $reservation->save();
                    $asset->logReservationEvent($reservation, 'reservation_cancelled', 'Reservation merged into active checkout');

                    // Chain-merge any reservations now adjacent to the extended checkout
                    $asset->absorbAdjacentReservations($reservation->user_id, $mergedEnd, $tz);

                    return redirect($returnTo)->with('success', 'Reservation merged with active checkout.');
                }
            }

            // For active (future) reservations, always cancel and recreate.
            // We do NOT touch an ongoing checkout here — that case is handled above.
            $reservation->status = 'cancelled';
            $reservation->save();
            $asset->logReservationEvent($reservation, 'reservation_cancelled', 'Reservation updated (old period cancelled, new period created)');

            // Create a new reservation with updated times, merging into adjacent/overlapping own reservation if present.
            // Only merge with future reservations (reserved_until > now) to avoid pulling in stale ones.
            $ownAdjacent = $asset->reservations()
                ->where('status', 'active')
                ->where('user_id', $reservation->user_id)
                ->where('reserved_until', '>', now()->format('Y-m-d H:i:s'))
                ->where(function ($q) use ($newStart, $newEnd) {
                    $q->where('reserved_until', $newStart->format('Y-m-d H:i:s'))
                      ->orWhere('reserved_from', $newEnd->format('Y-m-d H:i:s'))
                      ->orWhere(function ($q2) use ($newStart, $newEnd) {
                          $q2->where('reserved_from', '<', $newEnd->format('Y-m-d H:i:s'))
                             ->where('reserved_until', '>', $newStart->format('Y-m-d H:i:s'));
                      });
                })
                ->first();

            if ($ownAdjacent) {
                $mergedStart = Carbon::parse($ownAdjacent->reserved_from)->lt($newStart)
                    ? Carbon::parse($ownAdjacent->reserved_from) : $newStart;
                $mergedEnd   = Carbon::parse($ownAdjacent->reserved_until)->gt($newEnd)
                    ? Carbon::parse($ownAdjacent->reserved_until) : $newEnd;
                $ownAdjacent->reserved_from  = $mergedStart->format('Y-m-d H:i:s');
                $ownAdjacent->reserved_until = $mergedEnd->format('Y-m-d H:i:s');
                $ownAdjacent->save();
            } else {
                AssetReservation::create([
                    'asset_id'       => $asset->id,
                    'user_id'        => $reservation->user_id,
                    'reserved_from'  => $newStart->format('Y-m-d H:i:s'),
                    'reserved_until' => $newEnd->format('Y-m-d H:i:s'),
                    'status'         => 'active',
                ]);
            }
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
                    if ($asset->overlapsReservations($aStart, $aEnd, $reservation->user_id)) continue;

                    // Merge with adjacent/overlapping own reservation if present (future only)
                    $aOwnAdj = $asset->reservations()
                        ->where('status', 'active')
                        ->where('user_id', $reservation->user_id)
                        ->where('reserved_until', '>', now()->format('Y-m-d H:i:s'))
                        ->where(function ($q) use ($aStart, $aEnd) {
                            $q->where('reserved_until', $aStart->format('Y-m-d H:i:s'))
                              ->orWhere('reserved_from', $aEnd->format('Y-m-d H:i:s'))
                              ->orWhere(function ($q2) use ($aStart, $aEnd) {
                                  $q2->where('reserved_from', '<', $aEnd->format('Y-m-d H:i:s'))
                                     ->where('reserved_until', '>', $aStart->format('Y-m-d H:i:s'));
                              });
                        })
                        ->first();

                    if ($aOwnAdj) {
                        $mStart = Carbon::parse($aOwnAdj->reserved_from)->lt($aStart) ? Carbon::parse($aOwnAdj->reserved_from) : $aStart;
                        $mEnd   = Carbon::parse($aOwnAdj->reserved_until)->gt($aEnd)   ? Carbon::parse($aOwnAdj->reserved_until) : $aEnd;
                        $aOwnAdj->reserved_from  = $mStart->format('Y-m-d H:i:s');
                        $aOwnAdj->reserved_until = $mEnd->format('Y-m-d H:i:s');
                        $aOwnAdj->save();
                    } else {
                        AssetReservation::create([
                            'asset_id'       => $asset->id,
                            'user_id'        => $reservation->user_id,
                            'reserved_from'  => $aStart->format('Y-m-d H:i:s'),
                            'reserved_until' => $aEnd->format('Y-m-d H:i:s'),
                            'status'         => 'active',
                        ]);
                    }
                    $additionalCreated++;
                }
            }
        }

        $successMsg = 'Reservation updated successfully.';
        if ($additionalCreated > 0) {
            $successMsg .= ' ' . $additionalCreated . ' additional period' . ($additionalCreated > 1 ? 's' : '') . ' created.';
        }

        $asset->logReservationEvent($reservation, 'reservation_updated', 'Reservation updated');

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
            $asset->logReservationEvent($reservation, 'reservation_cancelled', 'Reservation cancelled by admin');

            return redirect($returnTo)->with('success', 'Reservation cancelled.');
        }

        // Normal users: only cancel their own reservation
        if (!$user || $reservation->user_id !== $user->id) {
            return redirect($returnTo)->with('error', 'You can only cancel your own reservations.');
        }

        $reservation->status = 'cancelled';
        $reservation->save();
        $asset->logReservationEvent($reservation, 'reservation_cancelled', 'Reservation cancelled by user');

        return redirect($returnTo)->with('success', 'Reservation cancelled.');
    }

    /**
     * Bulk cancel reservations (and checkin fulfilled ones).
     * Called via AJAX from the manage reservations page.
     * Returns JSON – never redirects.
     *
     * POST body: { reservation_ids: [1, 2, 3], asset_id: 5 }
     */
    public function bulkCancel(Request $request, Asset $asset): JsonResponse
    {
        $user   = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        $isSuper = method_exists($user, 'isSuperUser') && $user->isSuperUser();

        $ids = $request->input('reservation_ids', []);
        if (empty($ids) || !is_array($ids)) {
            return response()->json(['error' => 'No reservation IDs provided'], 422);
        }

        $cancelled = 0;
        $checkedIn = 0;
        $errors    = [];

        foreach ($ids as $reservationId) {
            $reservation = AssetReservation::where('id', $reservationId)
                ->where('asset_id', $asset->id)
                ->first();

            if (!$reservation) {
                $errors[] = "Reservation #{$reservationId} not found for this asset.";
                continue;
            }

            // Non-super users may only cancel their own reservations
            if (!$isSuper && $reservation->user_id !== $user->id) {
                $errors[] = "Reservation #{$reservationId}: not authorized.";
                continue;
            }

            if ($reservation->status === 'fulfilled') {
                // Fulfilled = asset is currently checked out through this reservation.
                // Do a proper checkin instead of just canceling.
                if ($asset->assignedTo && $asset->assigned_to == $reservation->user_id) {
                    $target         = $asset->assignedTo;
                    $originalValues = $asset->getRawOriginal();
                    $checkin_at     = now()->format('Y-m-d H:i:s');

                    $asset->expected_checkin = null;
                    $asset->last_checkin     = $checkin_at;
                    $asset->accepted         = null;
                    $asset->assignedTo()->disassociate($asset);
                    $asset->location_id = $asset->rtd_location_id;

                    // Clear pending license seat assignments
                    $asset->licenseseats->each(fn(LicenseSeat $seat) => $seat->update(['assigned_to' => null]));

                    // Delete pending acceptances
                    CheckoutAcceptance::pending()
                        ->whereHasMorph('checkoutable', [Asset::class], fn(Builder $q) => $q->where('id', $asset->id))
                        ->get()
                        ->each(fn($a) => $a->delete());

                    if ($asset->save()) {
                        event(new CheckoutableCheckedIn(
                            $asset,
                            $target,
                            $user,
                            'Checked in via bulk reservation cancel',
                            $checkin_at,
                            $originalValues
                        ));
                        $reservation->status = 'cancelled';
                        $reservation->save();
                        $asset->logReservationEvent($reservation, 'reservation_cancelled', 'Reservation cancelled (asset checked in)');
                        $asset->refresh();
                        $checkedIn++;
                    } else {
                        $errors[] = "Checkin failed for reservation #{$reservationId}.";
                    }
                } else {
                    // Fulfilled but asset is no longer checked out to that user – just cancel
                    $reservation->status = 'cancelled';
                    $reservation->save();
                    $asset->logReservationEvent($reservation, 'reservation_cancelled', 'Reservation cancelled (bulk)');
                    $cancelled++;
                }
            } else {
                // active / overdue reservation – just cancel
                $reservation->status = 'cancelled';
                $reservation->save();
                $asset->logReservationEvent($reservation, 'reservation_cancelled', 'Reservation cancelled (bulk)');
                $cancelled++;
            }
        }

        return response()->json([
            'cancelled'  => $cancelled,
            'checked_in' => $checkedIn,
            'errors'     => $errors,
        ]);
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
                ->where(function ($q) use ($asset) {
                    // Active: future/ongoing or overdue-but-still-checked-out-to-that-user
                    $q->where(function ($q2) use ($asset) {
                        $q2->where('status', 'active')
                           ->where(function ($q3) use ($asset) {
                               $q3->where('reserved_until', '>', now())
                                  ->orWhereNull('reserved_until');
                               if ($asset->assigned_to && $asset->assigned_type == \App\Models\User::class) {
                                   $q3->orWhere(function ($q4) use ($asset) {
                                       $q4->where('reserved_until', '<=', now())
                                          ->where('user_id', $asset->assigned_to);
                                   });
                               }
                           });
                    });
                    // Fulfilled: asset is still checked out (covers current checkout cycle)
                    if ($asset->assigned_to && $asset->assigned_type == \App\Models\User::class && $asset->last_checkout) {
                        $q->orWhere(function ($q2) use ($asset) {
                            $q2->where('status', 'fulfilled')
                               ->where(function ($q3) use ($asset) {
                                   $q3->whereNull('reserved_until')
                                      ->orWhere('reserved_until', '>=', $asset->last_checkout);
                               });
                        });
                    }
                })
                ->with('user')
                ->orderBy('reserved_from')
                ->get();

            $checkouts = collect();
            if ($asset->assigned_to && $asset->assigned_type == \App\Models\User::class && $asset->last_checkout) {
                $assignedUser = $asset->assigned;
                $hasFulfilled = $reservations->contains(function ($res) use ($assignedUser) {
                    return $res->status === 'fulfilled' && $assignedUser && $res->user_id === $assignedUser->id;
                });
                if (!$hasFulfilled) {
                    $checkout = (object) [
                        'id' => 'checkout_' . $asset->id,
                        'user' => $assignedUser,
                        'reserved_from' => $asset->last_checkout,
                        'reserved_until' => $asset->expected_checkin,
                        'status' => 'checked_out',
                        'type' => 'checkout',
                        'asset' => $asset,
                    ];
                    $checkouts->push($checkout);
                }
            }

            return view('hardware/reservations_manage', [
                'asset'        => $asset,
                'reservations' => $reservations,
                'checkouts'    => $checkouts,
                'return_to'    => $returnTo,
                'is_super'     => true,
            ]);
        }

        // Normal user path — include active (future) and fulfilled (ongoing) reservations.
        // Fulfilled reservations are only shown if the asset is still checked out to this user.
        $assetCheckedOutToUser = $asset->assigned_to == $user->id
            && $asset->assigned_type == \App\Models\User::class;

        $reservations = AssetReservation::where('asset_id', $asset->id)
            ->where('user_id', $user->id)
            ->where(function ($q) use ($assetCheckedOutToUser, $asset) {
                // Active future/ongoing reservations
                $q->where(function ($q2) {
                    $q2->where('status', 'active')
                       ->where(function ($q3) {
                           $q3->where('reserved_until', '>', now())
                              ->orWhereNull('reserved_until');
                       });
                });
                // Past active reservations only if asset is still checked out to this user
                // (checkout without matching reservation fulfillment — needs attention)
                if ($assetCheckedOutToUser) {
                    $q->orWhere(function ($q2) use ($asset) {
                        $q2->where('status', 'active')
                           ->where('reserved_until', '<=', now());
                    });
                }
                // Fulfilled (ongoing checkout) — only reservations whose window covers the current
                // checkout. reserved_until >= last_checkout ensures we skip old fulfilled rows
                // from previous checkout cycles (their end was before this checkout started).
                if ($assetCheckedOutToUser && $asset->last_checkout) {
                    $q->orWhere(function ($q3) use ($asset) {
                        $q3->where('status', 'fulfilled')
                           ->where(function ($q4) use ($asset) {
                               $q4->whereNull('reserved_until')
                                  ->orWhere('reserved_until', '>=', $asset->last_checkout);
                           });
                    });
                }
            })
            ->orderBy('reserved_from')
            ->get();

        $checkouts = collect();
        if ($asset->assigned_to && $asset->assigned_type == \App\Models\User::class && $asset->assigned_to == $user->id && $asset->last_checkout) {
            $hasFulfilled = $reservations->contains(function ($res) use ($user) {
                return $res->status === 'fulfilled' && $res->user_id === $user->id;
            });
            if (!$hasFulfilled) {
                $checkout = (object) [
                    'id' => 'checkout_' . $asset->id,
                    'user' => $user,
                    'reserved_from' => $asset->last_checkout,
                    'reserved_until' => $asset->expected_checkin,
                    'status' => 'checked_out',
                    'type' => 'checkout',
                    'asset' => $asset,
                ];
                $checkouts->push($checkout);
            }
        }

        if ($reservations->isEmpty() && $checkouts->isEmpty()) {
            return redirect($returnTo);
        }

        return view('hardware/reservations_manage', [
            'asset'        => $asset,
            'reservations' => $reservations,
            'checkouts'    => $checkouts,
            'return_to'    => $returnTo,
            'is_super'     => false,
        ]);
    }
}
