@extends('layouts/default')

@php
    /** @var \App\Models\User $authUser */
    $authUser = auth()->user();
    $onlySelfCheckout = $authUser && method_exists($authUser, 'mustSelfCheckout') && $authUser->mustSelfCheckout();
    
    $checkoutType = old('checkout_to_type', session('checkout_to_type') ?: 'user');
    

    // Use the actual variable name used in this view:
    // if it's $item instead of $asset, swap accordingly.
    $assetModel   = optional($asset->model ?? null);
    $assetCategory = optional($assetModel->category ?? null);

    $catAllowUser = $assetCategory->allow_checkout_to_user ?? true;
    $catAllowAsset = $assetCategory->allow_checkout_to_asset ?? true;
    $catAllowLocation = $assetCategory->allow_checkout_to_location ?? true;

    $modAllowUser = $assetModel->allow_checkout_to_user ?? true;
    $modAllowAsset = $assetModel->allow_checkout_to_asset ?? true;
    $modAllowLocation = $assetModel->allow_checkout_to_location ?? true;

    // Category + model must BOTH allow it
    $allowCheckoutToUser     = $catAllowUser     && $modAllowUser;
    $allowCheckoutToAsset    = $catAllowAsset    && $modAllowAsset;
    $allowCheckoutToLocation = $catAllowLocation && $modAllowLocation;
@endphp

@php
    $isReserve = !empty($reserve_mode) && $reserve_mode;
    
    // For reservations, allow checkout to asset and location regardless of category/model settings
    if ($isReserve) {
        $allowCheckoutToAsset = true;
        $allowCheckoutToLocation = true;
    }
@endphp


{{-- Page title --}}
@section('title')
    {{ trans('admin/hardware/general.checkout') }}
    @parent
@stop

{{-- Page content --}}
@section('content')

    <style>

        .input-group {
            padding-left: 0px !important;
        }
        

    </style>

    <div class="row">
        <!-- left column — expands to col-md-12 when the calendar tab is active -->
        <div class="col-md-7" id="checkout-left-col">
            <div class="box box-default">
                @php
                    $storeRoute = route('hardware.reserve.store', $asset->id);
                @endphp
                <form id="assetCheckoutForm" class="form-horizontal" method="post" action="{{ $storeRoute }}" autocomplete="off">
                    {{csrf_field()}}
                    <input type="hidden" name="return_to" value="{{ old('return_to', $return_to ?? session('return_to')) }}">
                    {{-- Hidden date fields - populated by calendar onConfirm --}}
                    <input type="hidden" id="checkout_at" name="checkout_at" value="{{ old('checkout_at') }}">
                    <input type="hidden" id="checkout_hour" name="checkout_hour" value="{{ old('checkout_hour') }}">
                    <input type="hidden" id="expected_checkin" name="expected_checkin" value="{{ old('expected_checkin') }}">
                    <input type="hidden" id="expected_checkin_hour" name="expected_checkin_hour" value="{{ old('expected_checkin_hour') }}">
                    <input type="hidden" id="periods_json" name="periods_json" value="">

                    <div class="nav-tabs-custom" style="margin-bottom: 0;">
                        <ul class="nav nav-tabs">
                            <li class="active"><a href="#reserve-details" data-toggle="tab">Details</a></li>
                            <li id="calendar-tab-li"@if($checkoutType === 'asset') style="display:none;"@endif><a href="#reserve-calendar" data-toggle="tab"><i class="fa fa-calendar"></i> Select dates</a></li>
                        </ul>
                        <div class="tab-content">

                        {{-- TAB 1: Details --}}
                        <div class="tab-pane active" id="reserve-details">
                    <div class="box-header with-border" style="border-top: none;">
                        <h2 class="box-title"> {{ trans('admin/hardware/form.tag') }} {{ $asset->asset_tag }}</h2>
                    </div>
                    <div class="box-body">


                        @if ($asset->company)
                            <!-- accessory name -->
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{ trans('general.company') }}</label>
                                <div class="col-md-6">
                                    <p class="form-control-static">{!! $asset->company->present()->formattedNameLink  !!}</p>
                                </div>
                            </div>
                        @endif


                        @if ($asset->model->category)
                            <!-- category name -->
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{ trans('general.category') }}</label>
                                <div class="col-md-6">
                                    <p class="form-control-static">{!! $asset->model->category->present()->formattedNameLink  !!}</p>
                                </div>
                            </div>
                        @endif

                        <!-- AssetModel name -->
                        <div class="form-group">
                            <label for="model" class="col-md-3 control-label">
                                {{ trans('admin/hardware/form.model') }}
                            </label>
                            <div class="col-md-8">
                                <p class="form-control-static" style="padding-top: 7px;">
                                    @if (($asset->model) && ($asset->model->name))
                                        {{ $asset->model->name }}
                                    @else
                                        <span class="text-danger text-bold">
                                              <x-icon type="warning" />
                                              {{ trans('admin/hardware/general.model_invalid')}}
                                        </span>

                                        {{ trans('admin/hardware/general.model_invalid_fix')}}
                                        <a href="{{ route('hardware.edit', $asset->id) }}">
                                            <strong>{{ trans('admin/hardware/general.edit') }}</strong>
                                        </a>
                                    @endif
                                </p>
                            </div>
                        </div>

                        <!-- Asset Name -->
                        @can('update', $asset)
                            {{-- Asset Name (editable for users with edit rights) --}}
                            <div class="form-group {{ $errors->has('name') ? 'error' : '' }}">
                                <label for="name" class="col-md-3 control-label">
                                    {{ trans('admin/hardware/form.name') }}
                                </label>

                                <div class="col-md-8">
                                    <input class="form-control"
                                        type="text"
                                        name="name"
                                        id="name"
                                        value="{{ old('name', $asset->name) }}"
                                        tabindex="1">
                                    {!! $errors->first('name', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                </div>
                            </div>
                        @else
                            {{-- Asset Name (read-only, but still posted as original value) --}}
                            <div class="form-group">
                                <label for="name" class="col-md-3 control-label">
                                    {{ trans('admin/hardware/form.name') }}
                                </label>

                                <div class="col-md-8">
                                    <p class="form-control-static">
                                        {{ $asset->name }}
                                    </p>

                                    {{-- Hidden input so the original name is still submitted --}}
                                    <input type="hidden" name="name" value="{{ $asset->name }}">
                                </div>
                            </div>
                        @endcan


                        <!-- Status -->
                        @can('update', $asset)
                            <div class="form-group {{ $errors->has('status_id') ? 'error' : '' }}">
                                <label for="status_id" class="col-md-3 control-label">
                                    {{ trans('admin/hardware/form.status') }}
                                </label>
                                <div class="col-md-7 required">
                                    <x-input.select
                                        name="status_id"
                                        :options="$statusLabel_list"
                                        :selected="$asset->status_id"
                                        style="width: 100%;"
                                        aria-label="status_id"
                                    />
                                    {!! $errors->first('status_id', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                </div>
                            </div>
                        @endcan


                        @include('partials.forms.checkout-selector', [
                            'user_select'     => $allowCheckoutToUser     ? 'true' : 'false',
                            'asset_select'    => $allowCheckoutToAsset    ? 'true' : 'false',
                            'location_select' => $allowCheckoutToLocation ? 'true' : 'false',
                        ])
                        
                        @if ($allowCheckoutToUser)
                            @if ($onlySelfCheckout)
                              
                              <input type="hidden" name="assigned_user" id="self_assigned_user" value="{{ $authUser->id }}">
                              {{-- Visible “assigned user” row (no inputs inside, only text) --}}
                              <div id="assigned_user"
                                  class="form-group{{ $errors->has('assigned_user') ? ' has-error' : '' }}"
                                  style="{{ $checkoutType == 'user' ? '' : 'display: none;' }}">

                                  <label class="col-md-3 control-label">{{ trans('general.user') }}</label>

                                  <div class="col-md-7">
                                      <p class="form-control-static" style="padding-top: 7px;">
                                          {{ $authUser->present()->fullName ?? $authUser->name ?? $authUser->email }}
                                      </p>
                                  </div>

                                  {!! $errors->first('assigned_user', '<div class="col-md-8 col-md-offset-3"><span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span></div>') !!}
                              </div>
                            @else
                                {{-- Normal users: show full user selector --}}
                                @include('partials.forms.edit.user-select', [
                                    'translated_name' => trans('general.user'),
                                    'fieldname'       => 'assigned_user',
                                    'style' => $checkoutType == 'user' ? '' : 'display: none;',

                                ])
                            @endif
                        @endif

                        <!-- We have to pass unselect here so that we don't default to the asset that's being checked out. We want that asset to be pre-selected everywhere else. -->
                        @if ($allowCheckoutToAsset)
                        @include ('partials.forms.edit.asset-select', ['translated_name' => trans('general.select_asset'), 'fieldname' => 'assigned_asset', 'company_id' => $asset->company_id, 'unselect' => 'true', 'style' => session('checkout_to_type') == 'asset' ? '' : 'display: none;'])
                        @endif
                        @if ($allowCheckoutToLocation)
                        @include ('partials.forms.edit.location-select', ['translated_name' => trans('general.location'), 'fieldname' => 'assigned_location', 'style' => session('checkout_to_type') == 'location' ? '' : 'display: none;'])
                        @endif                



                        {!! $errors->first('checkout_at', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                        {!! $errors->first('expected_checkin', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}

                        <!-- Note -->
                        <div class="form-group {{ $errors->has('note') ? 'error' : '' }}">
                            <label for="note" class="col-md-3 control-label">
                                {{ trans('general.notes') }}
                            </label>

                            <div class="col-md-8">
                                <textarea class="col-md-6 form-control" id="note" @required($snipeSettings->require_checkinout_notes)
                                name="note">{{ old('note', $asset->note) }}</textarea>
                                {!! $errors->first('note', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                            </div>
                        </div>
                        
                        <!-- Custom fields -->
                        @include("models/custom_fields_form", [
                                'model' => $asset->model,
                                'show_custom_fields_type' => 'checkout'
                        ])



                        @if ($asset->requireAcceptance() || $asset->getEula() || ($snipeSettings->webhook_endpoint!=''))
                            <div class="form-group notification-callout">
                                <div class="col-md-8 col-md-offset-3">
                                    <div class="callout callout-info">

                                        @if ($asset->requireAcceptance())
                                            <x-icon type="email" />
                                            {{ trans('admin/categories/general.required_acceptance') }}
                                            <br>
                                        @endif

                                        @if ($asset->getEula())
                                            <x-icon type="email" />
                                            {{ trans('admin/categories/general.required_eula') }}
                                            <br>
                                        @endif

                                        @if ($snipeSettings->webhook_endpoint!='')
                                            <i class="fab fa-slack" aria-hidden="true"></i>
                                            {{ trans('general.webhook_msg_note') }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endif

                    </div> <!--/.box-body-->

                    <div class="box-footer">
                        {{-- Shown when checkout_to_type = asset (no dates needed) --}}
                        <button id="btn-submit-asset" type="submit"
                            class="btn btn-warning{{ (!$asset->model ? ' disabled' : '') }}"
                            style="{{ $checkoutType === 'asset' ? '' : 'display:none;' }}">
                            <i class="fa fa-check"></i> {{ trans('general.checkout') }}
                        </button>
                        {{-- Shown for user/location (needs calendar) --}}
                        <a id="btn-open-calendar" href="#reserve-calendar" data-toggle="tab"
                            class="btn btn-warning{{ (!$asset->model ? ' disabled' : '') }}"
                            style="{{ $checkoutType === 'asset' ? 'display:none;' : '' }}">
                            <i class="fa fa-calendar"></i> {{ trans('general.checkout') }}
                        </a>
                        <a href="{{ old('return_to', $return_to ?? session('return_to') ?? route('hardware.show', $asset)) }}"
                           class="btn btn-default" style="margin-left:5px;">
                            <i class="fa fa-times"></i> {{ trans('button.cancel') }}
                        </a>
                    </div>

                    </div>{{-- /.tab-pane#reserve-details --}}

                    {{-- TAB 2: Calendar (hidden for checkout-to-asset) --}}
                    <div class="tab-pane" id="reserve-calendar">
                        <div style="padding: 15px;">
                            <div id="asset-calendar-root"></div>
                        </div>
                    </div>{{-- /.tab-pane#reserve-calendar --}}

                        </div>{{-- /.tab-content --}}
                    </div>{{-- /.nav-tabs-custom --}}

                </form>
            </div>
        </div> <!--/.col-md-7-->

        <!-- right column -->
        <div class="col-md-5" id="current_assets_box" style="display:none;">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h2 class="box-title">{{ trans('admin/users/general.current_assets') }}</h2>
                </div>
                <div class="box-body">
                    <div id="current_assets_content">
                    </div>
                </div>
            </div>
        </div>
    </div>
@stop

@section('moar_scripts')
    @include('partials/assets-assigned')

    @php
    $calendarExistingPeriods = collect($calendarRanges ?? [])->map(function($r) {
        return [
            'start'    => \Carbon\Carbon::parse($r['from'])->format('Y-m-d H:i'),
            'end'      => $r['to'] ? \Carbon\Carbon::parse($r['to'])->format('Y-m-d H:i') : null,
            'userName' => (string)($r['type'] ?? 'blocked'),
        ];
    })->values()->toArray();
    @endphp

    <script>
    window.assetCalendarInput = {
        mode: 'reserve',
        currentUser: @json(Auth::user()->username ?? (string)Auth::id()),
        continuousCutMode: true,
        assets: [{
            id: @json((string)$asset->id),
            name: @json($asset->name),
            existingPeriods: {!! json_encode($calendarExistingPeriods) !!}
        }]
    };

    window.assetCalendarOnConfirm = function(output) {
        var periods = output && output.assets && output.assets[0] && output.assets[0].selectedPeriods;
        if (!periods || periods.length === 0) {
            alert('Please select a time period in the calendar.');
            return;
        }
        var period = periods[0];
        var startParts = period.start.split(' ');
        var endParts   = period.end.split(' ');
        var startHour  = parseInt(startParts[1].split(':')[0], 10);
        var endHour    = parseInt(endParts[1].split(':')[0], 10);

        // Send all periods via periods_json; primary fields are populated for
        // single-period backward-compat fallback (backend ignores them when periods_json present).
        document.getElementById('checkout_at').value           = startParts[0];
        document.getElementById('checkout_hour').value         = String(startHour);
        document.getElementById('expected_checkin').value      = endParts[0];
        document.getElementById('expected_checkin_hour').value = String(endHour);
        document.getElementById('periods_json').value          = JSON.stringify(periods);

        document.getElementById('assetCheckoutForm').submit();
    };
    </script>

    {{-- Load calendar bundle but mount lazily when the calendar tab is first clicked --}}
    <script>
    (function() {
        var calendarMounted = false;

        function checkoutType() {
            const form = document.getElementById('assetCheckoutForm');
            // Primary: native checked state
            const checked = form?.querySelector('input[name="checkout_to_type"]:checked')?.value;
            if (checked) return checked;
            // Fallback: Bootstrap 3 active label
            const activeInput = form?.querySelector('[data-toggle="buttons"] label.active input[name="checkout_to_type"]');
            if (activeInput) return activeInput.value;
            return (
                form?.querySelector('select[name="checkout_to_type"]')?.value ||
                form?.querySelector('input[name="checkout_to_type"][type="hidden"]')?.value ||
                'user'
            );
        }

        document.addEventListener('shown.bs.tab', mountIfCalendar);
        // Bootstrap 3 fires 'shown.bs.tab' on the <a>, not the <li>
        document.querySelectorAll('a[href="#reserve-calendar"]').forEach(function(el) {
            el.addEventListener('shown.bs.tab', mountIfCalendar);
            // Bootstrap 3 uses jQuery events — also handle via jQuery if available
        });
        if (window.jQuery) {
            jQuery('a[href="#reserve-calendar"]').on('shown.bs.tab', mountIfCalendar);
        }
        function mountIfCalendar() {
            if (calendarMounted) return;
            if (checkoutType() === 'asset') return;
            calendarMounted = true;
            var script = document.createElement('script');
            script.src = '{{ asset('vendor/asset-calendar/asset-calendar.js') }}';
            document.body.appendChild(script);
        }
    })();
    </script>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // ---- Self-checkout enforcement ----
        const form       = document.getElementById('assetCheckoutForm');
        const isSelfOnly = {{ $onlySelfCheckout ? 'true' : 'false' }};
        const selfUserId = {{ (int)($authUser?->id ?? 0) }};

        // Bootstrap 3 data-toggle="buttons" sets input.checked programmatically,
        // which does NOT fire native change events. Read the active label as fallback.
        function checkoutType() {
            // Primary: native checked state
            const checked = form?.querySelector('input[name="checkout_to_type"]:checked')?.value;
            if (checked) return checked;
            // Fallback: Bootstrap 3 active label
            const activeInput = form?.querySelector('[data-toggle="buttons"] label.active input[name="checkout_to_type"]');
            if (activeInput) return activeInput.value;
            return (
                form?.querySelector('select[name="checkout_to_type"]')?.value ||
                form?.querySelector('input[name="checkout_to_type"][type="hidden"]')?.value ||
                'user'
            );
        }

        function enforceSelfCheckout() {
            if (!form || !isSelfOnly || !selfUserId) return;
            const t         = String(checkoutType()).toLowerCase();
            const selfInput = form.querySelector('#self_assigned_user');
            if (!selfInput) return;
            if (t === 'user') {
                selfInput.disabled = false;
                selfInput.value    = String(selfUserId);
            } else {
                selfInput.value    = String(selfUserId);
                selfInput.disabled = true;
            }
        }

        setTimeout(enforceSelfCheckout, 0);

        // ---- Toggle calendar tab vs direct submit based on checkout_to_type ----
        const btnSubmitAsset  = document.getElementById('btn-submit-asset');
        const btnOpenCalendar = document.getElementById('btn-open-calendar');
        const calendarTabLi   = document.getElementById('calendar-tab-li');

        // Disable hidden target fields before submit so they are not sent to the server.
        // This prevents leftover asset/location values from interfering when type=user.
        function disableHiddenTargetFields() {
            const t = checkoutType();
            ['assigned_user', 'assigned_asset', 'assigned_location'].forEach(function(name) {
                form?.querySelectorAll('[name="' + name + '"]').forEach(function(el) {
                    el.disabled = (name !== 'assigned_' + t);
                });
            });
            // Self-checkout hidden input must never be disabled
            const selfInput = form?.querySelector('#self_assigned_user');
            if (selfInput && t === 'user') selfInput.disabled = false;
        }

        function clearTargetField(name) {
            form?.querySelectorAll('select[name="' + name + '"]').forEach(function(el) {
                if (window.jQuery && jQuery(el).data('select2')) {
                    jQuery(el).val(null).trigger('change');
                } else {
                    el.value = '';
                }
            });
            form?.querySelectorAll('input[name="' + name + '"]').forEach(function(el) {
                if (el.id !== 'self_assigned_user') el.value = '';
            });
        }

        function clearDateFields() {
            ['checkout_at','checkout_hour','expected_checkin','expected_checkin_hour','periods_json'].forEach(function(id) {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
        }

        const leftCol = document.getElementById('checkout-left-col');

        function setCalendarWide(wide) {
            if (!leftCol) return;
            if (wide) {
                leftCol.classList.remove('col-md-7');
                leftCol.classList.add('col-md-12');
            } else {
                leftCol.classList.remove('col-md-12');
                leftCol.classList.add('col-md-7');
            }
        }

        // Only update button/tab visibility — never clears values
        function updateCheckoutVisibility() {
            const t = checkoutType();
            const isAsset = (t === 'asset');
            if (btnSubmitAsset)  btnSubmitAsset.style.display  = isAsset ? '' : 'none';
            if (btnOpenCalendar) btnOpenCalendar.style.display = isAsset ? 'none' : '';
            if (calendarTabLi)   calendarTabLi.style.display   = isAsset ? 'none' : '';

            // If calendar tab is hidden but still active, switch back to Details tab
            if (isAsset && window.jQuery) {
                jQuery('a[href="#reserve-details"]').tab('show');
                setCalendarWide(false);
            }
        }

        // Expand to full width when calendar tab is shown, shrink back on Details tab
        if (window.jQuery) {
            jQuery('a[href="#reserve-calendar"]').on('shown.bs.tab', function() { setCalendarWide(true); });
            jQuery('a[href="#reserve-details"]').on('shown.bs.tab',  function() { setCalendarWide(false); });
        }

        // On type switch: clear only the fields that are no longer relevant, then update visibility
        function onCheckoutTypeChange() {
            const t = checkoutType();
            // Clear the two target fields that are now irrelevant
            ['assigned_user', 'assigned_asset', 'assigned_location'].forEach(function(name) {
                if (name !== 'assigned_' + t) clearTargetField(name);
            });
            clearDateFields();
            updateCheckoutVisibility();
            enforceSelfCheckout();
        }

        // Initial page load: only update visibility, never clear existing values
        setTimeout(updateCheckoutVisibility, 0);

        // Bootstrap 3 button groups fire click on the <label>, not change on the <input>.
        // Attach directly to every label in the checkout_to_type button group.
        form?.querySelectorAll('[data-toggle="buttons"] label').forEach(function(label) {
            label.addEventListener('click', function() {
                // Delay so Bootstrap has time to set input.checked and add .active class
                setTimeout(onCheckoutTypeChange, 50);
            });
        });
        // Also handle plain selects/radios outside button groups (fallback)
        form?.querySelectorAll('select[name="checkout_to_type"]').forEach(function(el) {
            el.addEventListener('change', function() { setTimeout(onCheckoutTypeChange, 0); });
        });

        form?.addEventListener('submit', function() {
            enforceSelfCheckout();
            disableHiddenTargetFields();
        });
    });
    </script>
@stop
