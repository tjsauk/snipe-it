<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Asset;
use App\Models\Location;

class AssetPolicy extends CheckoutablePermissionsPolicy
{
    protected function columnName()
    {
        return 'assets';
    }

    public function viewRequestable(User $user, Asset $asset = null)
    {
        return $user->hasAccess('assets.view.requestable');
    }

    public function audit(User $user, Asset $asset = null)
    {
        return $user->hasAccess('assets.audit');
    }

    public function uploadFiles(User $user, Asset $asset = null): bool
    {
        // 1) If user has normal edit rights, always allow
        if ($user->hasAccess($this->columnName().'.edit')) { // 'assets.edit'
            return true;
        }

        // 2) Otherwise, allow if user is in a group that has only_edit_placeholders = 1
        //    (you can refine this later to also check asset category if you want)
        return $user->groups()
            ->where('only_edit_placeholders', 1)
            ->exists();
    }

    /**
     * Override default update logic so that:
     * - Users with assets.edit can update any asset
     * - Users in groups with only_edit_placeholders = 1 can update
     *   assets whose category is marked as placeholder
     */
    public function update(User $user, $item = null): bool
    {
        // 0) If no specific asset is passed (class-level checks),
        //    keep old behavior: only full editors.
        if (! $item instanceof Asset) {
            return $user->hasAccess($this->columnName().'.edit'); // 'assets.edit'
        }

        // 1) Full editors can always edit everything (unchanged behavior)
        if ($user->hasAccess($this->columnName().'.edit')) {
            return true;
        }

        // 2) Determine if this asset belongs to a placeholder category
        // Asset -> model -> category -> is_placeholder
        $isPlaceholderCategory = optional(optional($item->model)->category)->is_placeholder;

        // 3) If asset is in a placeholder category AND
        //    user is in a group that has only_edit_placeholders enabled,
        //    allow editing.
        if ($isPlaceholderCategory) {
            $belongsToPlaceholderGroup = $user->groups()
                ->where('only_edit_placeholders', 1)
                ->exists();

            if ($belongsToPlaceholderGroup) {
                return true;
            }
        }

        // 4) Everyone else: no edit rights
        return false;
    }

    /**
     * Determine whether the user can checkout this asset.
     */
    public function checkout(User $user, $item = null): bool
    {
        // Class-level check (e.g. Gate::allows('checkout', Asset::class))
        if (! $item instanceof Asset) {
            // keep old behavior: just use permission
            return $user->hasAccess($this->columnName().'.checkout'); // typically 'assets.checkout'
        }

        // If user is marked as "only self checkout"
        if ($user->mustSelfCheckout()) {

            // They may not checkout assets that are already assigned
            if (! is_null($item->assigned_to)) {
                return false;
            }

            // They are allowed to checkout this asset (to themselves) – the actual
            // “to whom” check we enforce in controller / form.
            return true;
        }

        // Fallback for normal users: old behavior
        return $user->hasAccess($this->columnName().'.checkout');
    }

        /**
     * Determine whether the user can checkin this asset.
     */
    public function checkin(User $user, $item = null): bool
    {
        // Class-level checks (Gate::allows('checkin', Asset::class))
        if (! $item instanceof Asset) {
            return $user->hasAccess($this->columnName().'.checkin');
        }

        // Superuser can always check in anything
        if (method_exists($user, 'isSuperUser') && $user->isSuperUser()) {
            return true;
        }

        // If it's not currently assigned to anything, fall back to permission
        if (is_null($item->assigned_to) || empty($item->assigned_type)) {
            return $user->hasAccess($this->columnName().'.checkin');
        }

        $mustSelf = method_exists($user, 'mustSelfCheckout') && $user->mustSelfCheckout();
        $checkinPerm = $user->hasAccess($this->columnName().'.checkin'); // 'assets.checkin'

        // Case 1: checked out to USER
        if ($item->assigned_type === \App\Models\User::class) {

            if ($mustSelf) {
                // Restricted users: only check in assets assigned to themselves
                return (int) $item->assigned_to === (int) $user->id;
            }

            // Non-restricted users: use normal checkin permission
            return $checkinPerm;
        }

        // Case 2: checked out to LOCATION
        if ($item->assigned_type === \App\Models\Location::class) {
            // Anyone with checkin permission can check in, regardless of "only_self_checkout"
            return $checkinPerm;
        }

        // Case 3: checked out to another ASSET (parent/child)
        if ($item->assigned_type === \App\Models\Asset::class) {
            $parent = Asset::find($item->assigned_to);

            if (! $parent) {
                // If the parent is missing for some reason, anyone can checkin
                return true;
            }

            // Parent NOT deployed to anything -> ANY user can check in the child
            if (empty($parent->assigned_to) || empty($parent->assigned_type)) {
                return true;
            }

            // Parent deployed to a USER
            if ($parent->assigned_type === \App\Models\User::class) {
                // Only the user who has the parent deployed can check in the child
                return (int) $parent->assigned_to === (int) $user->id;
            }

            // Parent is checked out to some other type; be conservative
            return false;
        }

        // Fallback for any other assignment type
        return $checkinPerm;
    }
}
