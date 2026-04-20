<?php

namespace App\Http\Transformers;

use App\Helpers\Helper;
use App\Models\Accessory;
use App\Models\AccessoryCheckout;
use App\Models\Asset;
use App\Models\Setting;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Collection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class AssetsTransformer
{
    // Prevent running transitions multiple times for the same asset in one request
    protected static array $processedTransitionAssetIds = [];

    protected function runTimeTransitionsIfNeeded(Asset $asset): void
    {
        if (!$asset || isset(self::$processedTransitionAssetIds[$asset->id])) {
            return;
        }
        self::$processedTransitionAssetIds[$asset->id] = true;

        try {
            // 1) auto checkin first
            $asset->autoCheckinIfDue();
            $asset->refresh();

            // 2) then auto checkout any due reservation
            $asset->autoCheckoutActiveReservationIfDue();
            $asset->refresh();
        } catch (\Throwable $e) {
            \Log::error('Asset list transition processing failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function transformAssets(Collection $assets, $total)
    {
        $array = [];
        foreach ($assets as $asset) {
            $array[] = self::transformAsset($asset);
        }

        return (new DatatablesTransformer)->transformDatatables($array, $total);
    }

    public function transformAsset(Asset $asset)
    {
        // Ensure list view triggers time-based transitions too
        $this->runTimeTransitionsIfNeeded($asset);
        // This uses the getSettings() method so we're pulling from the cache versus querying the settings on single asset
        $setting = Setting::getSettings();

        $array = [
            'id' => (int) $asset->id,
            'name' => e($asset->name),
            'asset_tag' => e($asset->asset_tag),
            'serial' => e($asset->serial),
            'model' => ($asset->model) ? [
                'id' => (int) $asset->model->id,
                'name'=> e($asset->model->name),
            ] : null,
            'byod' => ($asset->byod ? true : false),
            'requestable' => ($asset->requestable ? true : false),
            'model_number' => (($asset->model) && ($asset->model->model_number)) ? e($asset->model->model_number) : null,
            'eol' => (($asset->asset_eol_date != '') && ($asset->purchase_date != '')) ? (int) Carbon::parse($asset->asset_eol_date)->diffInMonths($asset->purchase_date, true) . ' months' : null,
            'asset_eol_date' => ($asset->asset_eol_date != '') ? Helper::getFormattedDateObject($asset->asset_eol_date, 'date') : null,
            'status_label' => ($asset->assetstatus) ? [
                'id' => (int) $asset->assetstatus->id,
                'name'=> e($asset->assetstatus->name),
                'status_type'=> e($asset->assetstatus->getStatuslabelType()),
                'status_meta' => e($asset->present()->statusMeta),
            ] : null,
            'category' => (($asset->model) && ($asset->model->category)) ? [
                'id' => (int) $asset->model->category->id,
                'name'=> e($asset->model->category->name),
                'tag_color'=> ($asset->model->category->tag_color) ? e($asset->model->category->tag_color) : null,
            ] : null,
            'manufacturer' => (($asset->model) && ($asset->model->manufacturer)) ? [
                'id' => (int) $asset->model->manufacturer->id,
                'name'=> e($asset->model->manufacturer->name),
                'tag_color'=> ($asset->model->manufacturer->tag_color) ? e($asset->model->manufacturer->tag_color) : null,
            ] : null,
            'depreciation' => (($asset->model) && ($asset->model->depreciation)) ? [
                'id' => (int) $asset->model->depreciation->id,
                'name'=> e($asset->model->depreciation->name),
                'months'=> (int) $asset->model->depreciation->months,
                'type'=>  e($asset->model->depreciation->depreciation_type),
                'minimum'=> ($asset->model->depreciation->depreciation_min) ? (int) $asset->model->depreciation->depreciation_min : null,
            ] : null,
            'supplier' => ($asset->supplier) ? [
                'id' => (int) $asset->supplier->id,
                'name'=> e($asset->supplier->name),
                'tag_color'=> ($asset->supplier->tag_color) ? e($asset->supplier->tag_color) : null,
            ] : null,
            'notes' => ($asset->notes) ? Helper::parseEscapedMarkedownInline($asset->notes) : null,
            'order_number' => ($asset->order_number) ? e($asset->order_number) : null,
            'company' => ($asset->company) ? [
                'id' => (int) $asset->company->id,
                'name'=> e($asset->company->name),
                'tag_color'=> ($asset->company->tag_color) ? e($asset->company->tag_color) : null,
            ] : null,
            'location' => ($asset->location) ? [
                'id' => (int) $asset->location->id,
                'name'=> e($asset->location->name),
                'tag_color'=> ($asset->location->tag_color) ? e($asset->location->tag_color) : null,
            ] : null,
            'rtd_location' => ($asset->defaultLoc) ? [
                'id' => (int) $asset->defaultLoc->id,
                'name'=> e($asset->defaultLoc->name),
                'tag_color'=> ($asset->defaultLoc->tag_color) ? e($asset->defaultLoc->tag_color) : null,
            ] : null,
            'image' => ($asset->getImageUrl()) ? $asset->getImageUrl() : null,
            'qr' => ($setting->qr_code=='1') ? config('app.url').'/uploads/barcodes/qr-'.str_slug($asset->asset_tag).'-'.str_slug($asset->id).'.png' : null,
            'alt_barcode' => ($setting->alt_barcode_enabled=='1') ? config('app.url').'/uploads/barcodes/'.str_slug($setting->alt_barcode).'-'.str_slug($asset->asset_tag).'.png' : null,
            'assigned_to' => $this->transformAssignedTo($asset),
            'warranty_months' =>  ($asset->warranty_months > 0) ? e($asset->warranty_months.' '.trans('admin/hardware/form.months')) : null,
            'warranty_expires' => ($asset->warranty_months > 0) ? Helper::getFormattedDateObject($asset->warranty_expires, 'date') : null,
            'created_by' => ($asset->adminuser) ? [
                'id' => (int) $asset->adminuser->id,
                'name'=> e($asset->adminuser->display_name),
            ] : null,
            'created_at' => Helper::getFormattedDateObject($asset->created_at, 'datetime'),
            'updated_at' => Helper::getFormattedDateObject($asset->updated_at, 'datetime'),
            'last_audit_date' => Helper::getFormattedDateObject($asset->last_audit_date, 'datetime'),
            'next_audit_date' => Helper::getFormattedDateObject($asset->next_audit_date, 'date'),
            'deleted_at' => Helper::getFormattedDateObject($asset->deleted_at, 'datetime'),
            'purchase_date' => Helper::getFormattedDateObject($asset->purchase_date, 'date'),
            'age' => $asset->purchase_date ? $asset->purchase_date->locale(app()->getLocale())->diffForHumans() : '',
            'last_checkout' => Helper::getFormattedDateObject($asset->last_checkout, 'datetime'),
            'last_checkin' => Helper::getFormattedDateObject($asset->last_checkin, 'datetime'),
            'expected_checkin' => Helper::getFormattedDateObject($asset->expected_checkin, 'datetime'),
            'purchase_cost' => Helper::formatCurrencyOutput($asset->purchase_cost),
            'checkin_counter' => (int) $asset->checkin_counter,
            'checkout_counter' => (int) $asset->checkout_counter,
            'requests_counter' => (int) $asset->requests_counter,
            'user_can_checkout' => (bool) $asset->availableForCheckout(),
            'book_value' => Helper::formatCurrencyOutput($asset->getDepreciatedValue()),
        ];


        if (($asset->model) && ($asset->model->fieldset) && ($asset->model->fieldset->fields->count() > 0)) {
            $fields_array = [];

            foreach ($asset->model->fieldset->fields as $field) {
                if ($field->isFieldDecryptable($asset->{$field->db_column})) {
                    $decrypted = Helper::gracefulDecrypt($field, $asset->{$field->db_column});
                    $value = (Gate::allows('assets.view.encrypted_custom_fields')) ? $decrypted : strtoupper(trans('admin/custom_fields/general.encrypted'));

                    if ($field->format == 'DATE'){
                        if (Gate::allows('assets.view.encrypted_custom_fields')){
                            $value = Helper::getFormattedDateObject($value, 'date', false);
                        } else {
                           $value = strtoupper(trans('admin/custom_fields/general.encrypted'));
                        }
                    }

                    $fields_array[$field->name] = [
                            'field' => e($field->db_column),
                            'value' => e($value),
                            'field_format' => $field->format,
                            'element' => $field->element,
                        ];

                } else {
                    $value = $asset->{$field->db_column};

                    if (($field->format == 'DATE') && (!is_null($value)) && ($value!='')){
                        $value = Helper::getFormattedDateObject($value, 'date', false);
                    }
                    
                    $fields_array[$field->name] = [
                        'field' => e($field->db_column),
                        'value' => e($value),
                        'field_format' => $field->format,
                        'element' => $field->element,
                    ];
                }

                $array['custom_fields'] = $fields_array;
            }
        } else {
            $array['custom_fields'] = new \stdClass; // HACK to force generation of empty object instead of empty list
        }

        $permissions_array['available_actions'] = [
            //'checkout'      => ($asset->deleted_at=='' && Gate::allows('checkout', Asset::class)) ? true : false,
            //'checkin'       => ($asset->deleted_at=='' && Gate::allows('checkin', Asset::class)) ? true : false,
            'checkout'      => ($asset->deleted_at=='' && Gate::allows('checkout', $asset)) ? true : false,
            'checkin'       => ($asset->deleted_at=='' && Gate::allows('checkin', $asset)) ? true : false,
            'clone'         => Gate::allows('create', Asset::class) ? true : false,
            'restore'       => ($asset->deleted_at!='' && Gate::allows('create', Asset::class)) ? true : false,
            //'update'        => ($asset->deleted_at=='' && Gate::allows('update', Asset::class)) ? true : false,
            'update'        => ($asset->deleted_at=='' && Gate::allows('update', $asset)) ? true : false,
            'audit'        => Gate::allows('audit', Asset::class) ? true : false,
            'delete'        => ($asset->deleted_at=='' && $asset->assigned_to =='' && Gate::allows('delete', Asset::class) && ($asset->deleted_at == '')) ? true : false,
        ];      


        if (request('components')=='true') {
        
            if ($asset->components) {
                $array['components'] = [];
    
                foreach ($asset->components as $component) {
                    $array['components'][] = [
                        
                            'id' => $component->id,
                            'pivot_id' => $component->pivot->id,
                            'name' => e($component->name),
                            'qty' => $component->pivot->assigned_qty,
                            'price_cost' => $component->purchase_cost,
                            'purchase_total' => $component->purchase_cost * $component->pivot->assigned_qty,
                            'checkout_date' => Helper::getFormattedDateObject($component->pivot->created_at, 'datetime') ,
                        
                    ];
                }
            }

        }

        // --- Reservation icon & actions for list view ---

        $currentUser = auth()->user();
        $userId      = $currentUser ? $currentUser->id : null;

        // Icon: show if any active reservation exists
        $hasReservations = $asset->activeReservations()->count() > 0;

        $array['reservation_icon'] = $hasReservations
            ? '<i class="fa fa-calendar text-warning" title="Has active reservations"></i>'
            : '';

        // Actions column HTML:
        $reserveHtml = '';
        $cancelHtml  = '';
        $manageHtml  = '';

        // Reserve button (same rule as in view.blade)
        if (
            ($asset->assetstatus) &&
            ($asset->assetstatus->deployable == '1') &&
            ($asset->deleted_at == null)
        ) {
            $reserveUrl  = route('hardware.reserve.create', $asset->id) . '?reserve=1';
            $reserveHtml = '<a href="'.$reserveUrl.'" class="btn btn-xs btn-warning">Reserve</a>';
        }

        // Cancel / Manage based on user type
        if ($currentUser) {

            // User's own reservation, if any
            $userReservation = $userId ? $asset->activeReservationForUser($userId) : null;

            // Is superuser?
            $isSuper = method_exists($currentUser, 'isSuperUser') && $currentUser->isSuperUser();

            if ($userReservation && ! $isSuper) {
                // Normal user with reservation: Manage button leading to manage page
                $manageUrl  = route('hardware.reserve.manage', $asset->id);
                $manageHtml = '<a href="'.$manageUrl.'" class="btn btn-xs btn-default"><i class="fa fa-calendar-times-o"></i> Manage</a>';
            } elseif ($isSuper && $hasReservations) {
                // Superuser: one "Manage" button
                $manageUrl  = route('hardware.reserve.manage', $asset->id);
                $manageHtml = '<a href="'.$manageUrl.'" class="btn btn-xs btn-default"><i class="fa fa-calendar-times-o"></i> Manage</a>';
            }
        }

        $array['reservation_actions'] = trim($reserveHtml.' '.$cancelHtml.' '.$manageHtml);
        
        $array += $permissions_array;

        return $array;
    }

    public function transformAssetsDatatable($assets)
    {
        return (new DatatablesTransformer)->transformDatatables($assets);
    }

    public function transformAssignedTo($asset)
    {
        if ($asset->checkedOutToUser()) {
            $user = $asset->assignedTo ?? \App\Models\User::withoutGlobalScopes()->find((int) $asset->assigned_to);
            if ($user) {
                return [
                    'id' => (int) $user->id,
                    'username' => e($user->username),
                    'name' => e($user->getFullNameAttribute()),
                    'first_name' => e($user->first_name),
                    'last_name' => $user->last_name ? e($user->last_name) : null,
                    'email' => $user->email ? e($user->email) : null,
                    'employee_number' => $user->employee_num ? e($user->employee_num) : null,
                    'jobtitle' => $user->jobtitle ? e($user->jobtitle) : null,
                    'type' => 'user',
                ];
            }
            return null;
        }

        if ($asset->assignedTo) {
            $assignedTo = $asset->assignedTo;
            $name = $assignedTo->asset_tag
                ? trim(($assignedTo->name ? $assignedTo->name . ' ' : '') . '#' . $assignedTo->asset_tag)
                : ($assignedTo->name ?? null);
            return [
                'id' => $assignedTo->id,
                'name' => $name ? e($name) : null,
                'type' => $asset->assignedType()
            ];
        }

        // Relationship failed to load (e.g. company scope), but asset IS assigned - return fallback so checkin button shows
        if ($asset->assigned_to && $asset->assigned_type) {
            $name = null;
            if ($asset->assigned_type === \App\Models\Asset::class) {
                $target = \App\Models\Asset::withoutGlobalScopes()->select(['id', 'name', 'asset_tag'])->find((int) $asset->assigned_to);
                if ($target) {
                    $name = $target->asset_tag
                        ? trim(($target->name ? $target->name . ' ' : '') . '#' . $target->asset_tag)
                        : $target->name;
                }
            }
            return [
                'id' => (int) $asset->assigned_to,
                'name' => $name,
                'type' => $asset->assignedType()
            ];
        }

        return null;
    }


    public function transformRequestedAssets(Collection $assets, $total)
    {
        $array = [];
        foreach ($assets as $asset) {
            // Ensure list view triggers time-based transitions too
            $this->runTimeTransitionsIfNeeded($asset);
            $array[] = self::transformRequestedAsset($asset);
        }

        return (new DatatablesTransformer)->transformDatatables($array, $total);
    }

    public function transformRequestedAsset(Asset $asset)
    {
        $array = [
            'id' => (int)$asset->id,
            'name' => e($asset->name),
            'asset_tag' => e($asset->asset_tag),
            'serial' => e($asset->serial),
            'image' => ($asset->getImageUrl()) ? $asset->getImageUrl() : null,
            'model' => ($asset->model) ? e($asset->model->name) : null,
            'model_number' => (($asset->model) && ($asset->model->model_number)) ? e($asset->model->model_number) : null,
            'expected_checkin' => Helper::getFormattedDateObject($asset->expected_checkin, 'date'),
            'location' => ($asset->location) ? e($asset->location->name) : null,
            'status' => ($asset->assetstatus) ? $asset->present()->statusMeta : null,
            'assigned_to_self' => ($asset->assigned_to == auth()->id()),
        ];

        if (($asset->model) && ($asset->model->fieldset) && ($asset->model->fieldset->fields->count() > 0)) {
            $fields_array = [];

            foreach ($asset->model->fieldset->fields as $field) {

                // Only display this if it's allowed via the custom field setting
                if (($field->field_encrypted == '0') && ($field->show_in_requestable_list == '1')) {

                    $value = $asset->{$field->db_column};
                    if (($field->format == 'DATE') && (!is_null($value)) && ($value != '')) {
                        $value = Helper::getFormattedDateObject($value, 'date', false);
                    }

                    $fields_array[$field->db_column] = e($value);
                }

                $array['custom_fields'] = $fields_array;
            }
        } else {
            $array['custom_fields'] = new \stdClass; // HACK to force generation of empty object instead of empty list
        }


        $permissions_array['available_actions'] = [
            'cancel' => ($asset->isRequestedBy(auth()->user())) ? true : false,
            'request' => ($asset->isRequestedBy(auth()->user())) ? false : true,
        ];

        $array += $permissions_array;
        return $array;
    }

    public function transformAssetCompact(Asset $asset)
    {
        $array = [
            'id' => (int) $asset->id,
            'image' => ($asset->getImageUrl()) ? $asset->getImageUrl() : null,
            'type' => 'asset',
            'name' => e($asset->display_name),
            'model' => ($asset->model) ? e($asset->model->name) : null,
            'model_number' => (($asset->model) && ($asset->model->model_number)) ? e($asset->model->model_number) : null,
            'asset_tag' => e($asset->asset_tag),
            'serial' => e($asset->serial),
        ];

        return $array;
    }

    public function transformCheckedoutAccessories($accessory_checkouts, $total)
    {

        $array = [];
        foreach ($accessory_checkouts as $checkout) {
            $array[] = self::transformCheckedoutAccessory($checkout);
        }

        return (new DatatablesTransformer)->transformDatatables($array, $total);
    }


    public function transformCheckedoutAccessory(AccessoryCheckout $accessory_checkout)
    {
        if ($accessory_checkout->accessory) {
            $array = [
                'id' => $accessory_checkout->id,
                'accessory' => [
                    'id' => $accessory_checkout->accessory->id,
                    'name' => $accessory_checkout->accessory->name,
                ],
                'assigned_to' => $accessory_checkout->assigned_to,
                'image' => ($accessory_checkout->accessory->image) ? Storage::disk('public')->url('accessories/' . e($accessory_checkout->accessory->image)) : null,
                'note' => $accessory_checkout->note ? e($accessory_checkout->note) : null,
                'created_by' => $accessory_checkout->adminuser ? [
                    'id' => (int)$accessory_checkout->adminuser->id,
                    'name' => e($accessory_checkout->adminuser->present()->fullName),
                ] : null,
                'created_at' => Helper::getFormattedDateObject($accessory_checkout->created_at, 'datetime'),
                'deleted_at' => Helper::getFormattedDateObject($accessory_checkout->deleted_at, 'datetime'),
            ];

            $permissions_array['available_actions'] = [
                'checkout' => false,
                'checkin' => Gate::allows('checkin', Accessory::class),
            ];

            $array += $permissions_array;
            return $array;
        }
    }

}
