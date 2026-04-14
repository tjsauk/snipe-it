@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('general.bulk_reserve') }}
    @parent
@stop

@section('content')

@php
    $isBasket   = ($mode ?? 'bulk') === 'basket';
    $formAction = $isBasket
        ? route('hardware.basket.reserve.store')
        : route('hardware.bulkreserve.store');
    $formId = 'bulkReserveForm';

    /** @var \App\Models\User $authUser */
    $authUser    = auth()->user();
    $isSuperUser = $authUser && method_exists($authUser, 'isSuperUser') && $authUser->isSuperUser();
    $checkoutType = old('checkout_to_type', session('checkout_to_type') ?: 'user');
@endphp

<style>
    #asset-calendar-root { min-height: 500px; }
    .reserve-asset-row { display:flex; align-items:center; padding:6px 0; border-bottom:1px solid #f0f0f0; }
    .reserve-asset-row:last-child { border-bottom:none; }
    .reserve-asset-check { margin-right:10px; width:18px; height:18px; cursor:pointer; flex-shrink:0; }
    .reserve-asset-info { flex:1; }
</style>

<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">{{ trans('general.bulk_reserve') }}</h2>
            </div>

            <form id="{{ $formId }}" class="form-horizontal"
                  method="POST" action="{{ $formAction }}" autocomplete="off">
                {{ csrf_field() }}
                <input type="hidden" id="periods_json" name="periods_json" value="">

                {{-- Bulk mode: asset IDs are fixed hidden inputs --}}
                @if(!$isBasket)
                    @foreach($assets as $asset)
                        <input type="hidden" name="selected_assets[]" value="{{ $asset->id }}">
                    @endforeach
                @endif

                <div class="box-body" style="padding-bottom:0;">

                    <ul class="nav nav-tabs" role="tablist">
                        <li role="presentation" class="active">
                            <a href="#tab-assets" role="tab" data-toggle="tab">
                                <i class="fa fa-list"></i> {{ trans('general.assets') }}
                                <span class="badge">{{ $assets->count() }}</span>
                            </a>
                        </li>
                        <li role="presentation">
                            <a href="#tab-calendar" role="tab" data-toggle="tab" id="tab-calendar-link">
                                <i class="fa fa-calendar"></i> {{ trans('general.select_dates') }}
                            </a>
                        </li>
                    </ul>

                    <div class="tab-content" style="padding-top:20px;">

                        {{-- Tab 1: Assets + target --}}
                        <div role="tabpanel" class="tab-pane active" id="tab-assets">

                            <div class="form-group">
                                <label class="col-md-3 control-label">{{ trans('general.assets') }}</label>
                                <div class="col-md-8">
                                    <div id="reserve-asset-list">
                                        @foreach($assets as $asset)
                                            <div class="reserve-asset-row">
                                                @if($isBasket)
                                                    <input type="checkbox"
                                                           class="reserve-asset-check"
                                                           name="selected_assets[]"
                                                           value="{{ $asset->id }}"
                                                           id="reserve-asset-{{ $asset->id }}"
                                                           checked>
                                                @endif
                                                <div class="reserve-asset-info">
                                                    @if($isBasket)
                                                        <label for="reserve-asset-{{ $asset->id }}" style="margin:0; font-weight:normal; cursor:pointer;">
                                                    @endif
                                                            <strong>{{ $asset->asset_tag }}</strong>
                                                            @if($asset->name) &ndash; {{ $asset->name }}@endif
                                                    @if($isBasket)
                                                        </label>
                                                    @endif
                                                    @if($asset->model)
                                                        <br><small class="text-muted">{{ $asset->model->name }}</small>
                                                    @endif
                                                </div>
                                                @if($isBasket)
                                                    <button type="button"
                                                            class="btn btn-xs btn-link text-danger basket-remove-btn"
                                                            data-asset-id="{{ $asset->id }}"
                                                            data-remove-url="{{ route('hardware.basket.remove', $asset->id) }}"
                                                            title="{{ trans('general.basket_remove') }}">
                                                        <i class="fa fa-times"></i>
                                                    </button>
                                                @else
                                                    <a href="{{ route('hardware.show', $asset->id) }}" target="_blank" class="text-muted" style="margin-left:8px;" title="{{ trans('button.view') }}">
                                                        <i class="fa fa-external-link"></i>
                                                    </a>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                    @if($isBasket)
                                        <p id="no-assets-selected" class="text-warning" style="display:none; margin-top:8px;">
                                            <i class="fa fa-exclamation-triangle"></i> Select at least one asset.
                                        </p>
                                    @endif
                                </div>
                            </div>

                            {{-- Checkout target selector --}}
                            {{-- Both superuser and non-superuser see all three options.
                                 Non-superusers get the "user" field auto-set to themselves (no dropdown). --}}
                            @include('partials.forms.checkout-selector', [
                                'user_select'     => 'true',
                                'asset_select'    => 'true',
                                'location_select' => 'true',
                            ])

                            {{-- User field --}}
                            @if($isSuperUser)
                                @include('partials.forms.edit.user-select', [
                                    'translated_name' => trans('general.user'),
                                    'fieldname'       => 'assigned_user',
                                    'style'           => $checkoutType === 'user' ? '' : 'display:none;',
                                ])
                            @else
                                <input type="hidden" name="assigned_user" id="self_assigned_user" value="{{ $authUser->id }}">
                                <div id="assigned_user"
                                     class="form-group"
                                     style="{{ $checkoutType === 'user' ? '' : 'display:none;' }}">
                                    <label class="col-md-3 control-label">{{ trans('general.user') }}</label>
                                    <div class="col-md-8">
                                        <p class="form-control-static" style="padding-top:7px;">
                                            <x-icon type="user" />
                                            {{ $authUser->present()->fullName ?? $authUser->name ?? $authUser->email }}
                                        </p>
                                    </div>
                                </div>
                            @endif

                            {{-- Asset field --}}
                            @include('partials.forms.edit.asset-select', [
                                'translated_name'       => trans('general.asset'),
                                'fieldname'             => 'assigned_asset',
                                'asset_selector_div_id' => 'assigned_asset',
                                'unselect'              => 'true',
                                'style'                 => $checkoutType === 'asset' ? '' : 'display:none;',
                            ])

                            {{-- Location field --}}
                            @include('partials.forms.edit.location-select', [
                                'translated_name' => trans('general.location'),
                                'fieldname'       => 'assigned_location',
                                'style'           => $checkoutType === 'location' ? '' : 'display:none;',
                            ])

                            <div class="form-group" style="margin-top:20px;">
                                <div class="col-md-9 col-md-offset-3">
                                    <button type="button" class="btn btn-primary" id="btn-go-to-calendar">
                                        <i class="fa fa-calendar"></i> {{ trans('general.select_dates') }} &raquo;
                                    </button>
                                    <button type="submit" class="btn btn-primary" id="btn-asset-checkout-submit" style="display:none;">
                                        <i class="fa fa-sign-out"></i> {{ trans('admin/hardware/general.checkout') }}
                                    </button>
                                </div>
                            </div>

                        </div>{{-- /#tab-assets --}}

                        {{-- Tab 2: Calendar --}}
                        <div role="tabpanel" class="tab-pane" id="tab-calendar">
                            <div id="cal-loading" class="text-muted" style="padding:20px 0;">
                                <i class="fa fa-spinner fa-spin"></i> Loading calendar&hellip;
                            </div>
                            {{-- Must use id="asset-calendar-root" for the calendar bundle auto-mount --}}
                            <div id="asset-calendar-root"></div>
                        </div>

                    </div>{{-- /.tab-content --}}
                </div>{{-- /.box-body --}}

                <div class="box-footer">
                    <a class="btn btn-link" href="{{ URL::previous() }}">{{ trans('button.cancel') }}</a>
                    @if($isBasket)
                        <button type="button" class="btn btn-default" id="btn-basket-clear"
                                data-clear-url="{{ route('hardware.basket.clear') }}">
                            <i class="fa fa-trash"></i> {{ trans('general.basket_clear') }}
                        </button>
                    @endif
                </div>

            </form>
        </div>
    </div>
</div>

@stop

@section('moar_scripts')
@if($isBasket)
<script>
(function () {
    var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute('content') || '';

    // Basket: remove individual asset via fetch (avoids nested form / _method conflict)
    document.querySelectorAll('.basket-remove-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var url = btn.getAttribute('data-remove-url');
            fetch(url, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
            }).then(function () { window.location.reload(); });
        });
    });

    // Basket: clear all via fetch
    var clearBtn = document.getElementById('btn-basket-clear');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            var url = clearBtn.getAttribute('data-clear-url');
            fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
            }).then(function () { window.location.href = '{{ route('hardware.index') }}'; });
        });
    }
})();
</script>
@endif
<script>
(function () {
    var isBasket    = @json($isBasket);
    var isSuperUser = @json($isSuperUser);
    var currentUser = @json(Auth::user()->username ?? (string)Auth::id());
    var formId      = @json($formId);
    var csrfToken   = (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute('content') || '';

    var calRoot   = document.getElementById('asset-calendar-root');
    var loadingEl = document.getElementById('cal-loading');
    var form      = document.getElementById(formId);

    var scriptLoaded = false;

    // ---- Target type show/hide ----
    function getCheckoutType() {
        var checked = form.querySelector('input[name="checkout_to_type"]:checked');
        if (checked) return checked.value;
        var active = form.querySelector('[data-toggle="buttons"] label.active input[name="checkout_to_type"]');
        if (active) return active.value;
        return 'user';
    }

    function syncTargetDivs() {
        var t = getCheckoutType();
        ['assigned_user', 'assigned_asset', 'assigned_location'].forEach(function (name) {
            var div = document.getElementById(name);
            if (div) div.style.display = (name === 'assigned_' + t) ? '' : 'none';
        });
        // Disable fields not matching current type so they are not sent with the form
        ['assigned_user', 'assigned_asset', 'assigned_location'].forEach(function (name) {
            form.querySelectorAll('[name="' + name + '"]').forEach(function (el) {
                // Never disable the self_assigned_user hidden input
                if (el.id === 'self_assigned_user') { el.disabled = false; return; }
                el.disabled = (name !== 'assigned_' + t);
            });
        });
        // Asset checkout: no calendar needed — hide calendar tab and swap buttons
        var isAsset      = t === 'asset';
        var calTabLink   = document.getElementById('tab-calendar-link');
        var btnCalendar  = document.getElementById('btn-go-to-calendar');
        var btnSubmit    = document.getElementById('btn-asset-checkout-submit');
        if (calTabLink)  calTabLink.parentElement.style.display = isAsset ? 'none' : '';
        if (btnCalendar) btnCalendar.style.display = isAsset ? 'none' : '';
        if (btnSubmit)   btnSubmit.style.display   = isAsset ? '' : 'none';
        // If currently on calendar tab and switching to asset, go back to assets tab
        if (isAsset && document.getElementById('tab-calendar').classList.contains('active')) {
            jQuery('a[href="#tab-assets"]').tab('show');
        }
    }

    jQuery(form).on('click', '[data-toggle="buttons"] label', function () {
        setTimeout(syncTargetDivs, 0);
    });
    syncTargetDivs();

    // ---- Calendar ----
    function getSelectedIds() {
        if (isBasket) {
            return Array.from(document.querySelectorAll('input[name="selected_assets[]"]:checked'))
                .map(function (el) { return el.value; }).filter(Boolean);
        }
        return Array.from(document.querySelectorAll('input[name="selected_assets[]"]'))
            .map(function (el) { return el.value; }).filter(Boolean);
    }

    function buildOnConfirm() {
        return function (output) {
            if (!output || !output.assets) return;
            var hasAny = output.assets.some(function (a) {
                return a.selectedPeriods && a.selectedPeriods.length > 0;
            });
            if (!hasAny) {
                alert('No available periods found for the selected assets in this time range.');
                return;
            }
            document.getElementById('periods_json').value = JSON.stringify(output.assets);
            form.submit();
        };
    }

    function mountCalendarWithData(assets) {
        if (loadingEl) loadingEl.style.display = 'none';

        window.assetCalendarInput = {
            mode: 'reserve',
            currentUser: currentUser,
            continuousCutMode: false,
            assets: assets,
        };
        window.assetCalendarOnConfirm = buildOnConfirm();

        if (typeof window.assetCalendarMount === 'function') {
            // Script already loaded — mount directly
            window.assetCalendarMount(calRoot, window.assetCalendarInput, window.assetCalendarOnConfirm);
        } else {
            // First time — load script; it will auto-mount using window.assetCalendarInput
            var s = document.createElement('script');
            s.src = '{{ asset('vendor/asset-calendar/asset-calendar.js') }}';
            document.body.appendChild(s);
            scriptLoaded = true;
        }
    }

    function loadCalendar() {
        var ids = getSelectedIds();

        var noSelEl = document.getElementById('no-assets-selected');
        if (noSelEl) noSelEl.style.display = ids.length === 0 ? '' : 'none';

        if (ids.length === 0) {
            if (calRoot) calRoot.innerHTML = '';
            if (loadingEl) loadingEl.style.display = 'none';
            return;
        }

        if (loadingEl) loadingEl.style.display = '';
        if (calRoot) calRoot.innerHTML = '';

        fetch('/api/v1/hardware/calendar-ranges?ids=' + ids.join(','), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            }
        })
        .then(function (r) { return r.json(); })
        .then(mountCalendarWithData)
        .catch(function (err) {
            console.error('Failed to load calendar ranges', err);
            if (loadingEl) loadingEl.textContent = 'Failed to load calendar data.';
        });
    }

    // Basket: refresh calendar when checkboxes change (only if calendar tab is active)
    if (isBasket) {
        document.querySelectorAll('input[name="selected_assets[]"]').forEach(function (el) {
            el.addEventListener('change', function () {
                if (document.getElementById('tab-calendar').classList.contains('active')) {
                    loadCalendar();
                }
            });
        });
    }

    // Load calendar when switching to calendar tab
    jQuery(document).on('shown.bs.tab', 'a[href="#tab-calendar"]', loadCalendar);

    // "Select Dates" button → switch to calendar tab
    document.getElementById('btn-go-to-calendar').addEventListener('click', function () {
        jQuery('a[href="#tab-calendar"]').tab('show');
    });
})();
</script>
@stop
