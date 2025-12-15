@extends('layouts/default')

@php
    /** @var \App\Models\User $authUser */
    $authUser = auth()->user();
    $onlySelfCheckout = $authUser && method_exists($authUser, 'mustSelfCheckout') && $authUser->mustSelfCheckout();

    // Use the actual variable name used in this view:
    // if it's $item instead of $asset, swap accordingly.
    $assetModel   = optional($asset->model ?? null);
    $assetCategory = optional($assetModel->category ?? null);

    $allowCheckoutToUser      = $assetCategory->allow_checkout_to_user      ?? true;
    $allowCheckoutToAsset     = $assetCategory->allow_checkout_to_asset     ?? true;
    $allowCheckoutToLocation  = $assetCategory->allow_checkout_to_location  ?? true;
@endphp

@php
    $isReserve = !empty($reserve_mode) && $reserve_mode;
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
        <!-- left column -->
        <div class="col-md-7">
            <div class="box box-default">
                @php
                    $isReserve  = !empty($reserve_mode) && $reserve_mode;
                    $storeRoute = $isReserve
                        ? route('hardware.reserve.store', $asset->id)
                        : route('hardware.checkout.store', $asset->id);
                @endphp
                <form class="form-horizontal" method="post" action="" autocomplete="off">
                    <div class="box-header with-border">
                        <h2 class="box-title"> {{ trans('admin/hardware/form.tag') }} {{ $asset->asset_tag }}</h2>
                    </div>
                    <div class="box-body">
                        {{csrf_field()}}
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
                                {{-- Self-checkout users: can only choose themselves --}}
                                <div class="form-group" style="{{ (session('checkout_to_type') ?: 'user') == 'user' ? '' : 'display: none;' }}">
                                    <label class="col-md-3 control-label">
                                        {{ trans('general.user') }}
                                    </label>
                                    <div class="col-md-7">
                                        <p class="form-control-static">
                                            {{ $authUser->present()->fullName ?? $authUser->name ?? $authUser->email }}
                                        </p>
                                        <input type="hidden" name="assigned_user" value="{{ $authUser->id }}">
                                    </div>
                                </div>
                            @else
                                {{-- Normal users: show full user selector --}}
                                @include('partials.forms.edit.user-select', [
                                    'translated_name' => trans('general.user'),
                                    'fieldname'       => 'assigned_user',
                                    'style'           => (session('checkout_to_type') ?: 'user') == 'user' ? '' : 'display: none;',
                                ])
                            @endif
                        @endif

                        <!-- We have to pass unselect here so that we don't default to the asset that's being checked out. We want that asset to be pre-selected everywhere else. -->
                        @include ('partials.forms.edit.asset-select', ['translated_name' => trans('general.select_asset'), 'fieldname' => 'assigned_asset', 'company_id' => $asset->company_id, 'unselect' => 'true', 'style' => session('checkout_to_type') == 'asset' ? '' : 'display: none;'])
                        @include ('partials.forms.edit.location-select', ['translated_name' => trans('general.location'), 'fieldname' => 'assigned_location', 'style' => session('checkout_to_type') == 'location' ? '' : 'display: none;'])



                        <!-- Checkout/Checkin Date -->
                        <div class="form-group {{ $errors->has('checkout_at') ? 'error' : '' }}">
                            <label for="checkout_at" class="col-md-3 control-label">
                                {{ trans('admin/hardware/form.checkout_date') }}
                            </label>
                            <div class="col-md-8">

                                @if (!empty($reserve_mode) && $reserve_mode)
                                    {{-- Reservation: start date must be in the future (from tomorrow) --}}
                                    <input
                                        type="text"
                                        id="checkout_at"
                                        name="checkout_at"
                                        class="form-control col-md-7"
                                        value="{{ old('checkout_at', $defaultCheckoutAt) }}"
                                    >
                                @else
                                    {{-- Normal checkout: checkout date is always today and NOT editable --}}
                                    <input 
                                        type="text" 
                                        class="form-control col-md-7" 
                                        value="{{ $defaultCheckoutAt }}" 
                                        readonly>
                                    <input 
                                        type="hidden" 
                                        id="checkout_at" 
                                        name="checkout_at" 
                                        value="{{ $defaultCheckoutAt }}"
                                    >

                                @endif
                                {!! $errors->first('checkout_at', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                            </div>
                        </div>

                        <!-- Expected Checkin Date -->
                        <div class="form-group {{ $errors->has('expected_checkin') ? 'error' : '' }}">
                            <label for="expected_checkin" class="col-md-3 control-label">
                                {{ trans('admin/hardware/form.expected_checkin') }}
                            </label>

                            <div class="col-md-8">
                                <input
                                    type="text"
                                    id="expected_checkin"
                                    name="expected_checkin"
                                    class="form-control col-md-7"
                                    value="{{ old('expected_checkin', $defaultExpectedCheckin) }}"
                                >
                                {!! $errors->first('expected_checkin', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                            </div>
                        </div>

                        <input type="hidden" name="reserve_mode" value="{{ !empty($reserve_mode) && $reserve_mode ? 1 : 0 }}">

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

                    <x-redirect_submit_options
                            index_route="hardware.index"
                            :button_label="trans('general.checkout')"
                            :disabled_select="!$asset->model"
                            :options="[
                                'index' => trans('admin/hardware/form.redirect_to_all', ['type' => trans('general.assets')]),
                                'item' => trans('admin/hardware/form.redirect_to_type', ['type' => trans('general.asset')]),
                                'target' => trans('admin/hardware/form.redirect_to_checked_out_to'),

                               ]"
                    />

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

    <style>
        /* Visualize blocked periods */
        .flatpickr-day.checkout-blocked {
            background: rgba(255, 0, 0, 0.25);
            color: #000;
        }

        .flatpickr-day.reservation-blocked {
            background: rgba(255, 165, 0, 0.25);
            color: #000;
        }

        /* Mark the last valid selectable end-date (day before next block starts) */
        .flatpickr-day.last-available-day {
            border: 2px solid #000;
        }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
    if (typeof flatpickr === 'undefined') return;

    const ranges = @json($calendarRanges);
    const reserveMode = {{ !empty($reserve_mode) && $reserve_mode ? 'true' : 'false' }};

    const checkoutEl  = document.getElementById('checkout_at');        // editable in reserve, hidden in checkout
    const expectedEl  = document.getElementById('expected_checkin');   // always editable
    if (!checkoutEl || !expectedEl) return;

    // ---------- helpers ----------
    function toISO(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const da = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${da}`;
    }

    function parseISO(iso) {
        const [y,m,d] = iso.split('-').map(Number);
        return new Date(y, m-1, d);
    }

    function addDays(dateObj, days) {
        const d = new Date(dateObj.getTime());
        d.setDate(d.getDate() + days);
        return d;
    }

    function normISO(iso) {
        // ensure YYYY-MM-DD
        if (!iso) return null;
        const d = parseISO(iso);
        return isNaN(d.getTime()) ? null : toISO(d);
    }

    // normalize ranges defensively
    const normRanges = (ranges || [])
        .map(r => ({
        from: normISO(r.from),
        to:   r.to ? normISO(r.to) : null,
        type: r.type || 'reservation'
        }))
        .filter(r => r.from)
        .map(r => {
        // swap if inverted
        if (r.to && r.to < r.from) {
            const tmp = r.from; r.from = r.to; r.to = tmp;
        }
        return r;
        });

    function isBlockedISO(iso) {
        return normRanges.some(r => {
        if (!r.to) return iso >= r.from;               // open-ended blocks future
        return iso >= r.from && iso <= r.to;           // inclusive
        });
    }

    function styleDay(dateObj) {
        const iso = toISO(dateObj);
        const r = normRanges.find(r => iso >= r.from && (!r.to || iso <= r.to));
        if (!r) return null;
        return r.type === 'checkout' ? 'checkout-blocked' : 'reservation-blocked';
    }

    // earliest blocked "from" >= startISO, or startISO if start falls inside a block
    function nextBlockedStartISO(startISO) {
        let best = null;

        for (const r of normRanges) {
        const from = r.from;
        const to   = r.to || r.from;

        if (startISO >= from && startISO <= to) return startISO;  // inside
        if (!r.to && startISO >= from) return startISO;           // open-ended and after start

        if (from >= startISO) {
            if (!best || from < best) best = from;
        }
        }
        return best;
    }

    function computeMaxEndISO(startISO) {
        const nb = nextBlockedStartISO(startISO);
        if (!nb) return null;
        return toISO(addDays(parseISO(nb), -1)); // day before next block starts
    }

    // ---------- establish the start date ----------
    // in checkout mode checkoutEl is hidden, but it contains the correct start ISO
    let startISO = normISO(checkoutEl.value) || toISO(new Date());

    // reservation: min start is tomorrow
    const tomorrowISO = toISO(addDays(new Date(), 1));

    // ---------- expected picker (always enabled) ----------
    let expectedPicker = flatpickr(expectedEl, {
        dateFormat: 'Y-m-d',
        defaultDate: normISO(expectedEl.value) || null,
        minDate: startISO,
        maxDate: computeMaxEndISO(startISO) || null,
        disable: [
        (date) => isBlockedISO(toISO(date))  // do not allow selecting blocked days as end
        ],
        onChange: function(selectedDates) {
        if (!selectedDates.length) return;
        const endISO = toISO(selectedDates[0]);

        // clamp: end >= start
        if (endISO < startISO) {
            this.setDate(startISO, true);
            return;
        }

        // clamp: end <= max (day before next block)
        const maxISO = computeMaxEndISO(startISO);
        if (maxISO && endISO > maxISO) {
            this.setDate(maxISO, true);
        }
        },
        onDayCreate(_, __, fp, dayElem) {
        const cls = styleDay(dayElem.dateObj);
        if (cls) dayElem.classList.add(cls);

        // mark last allowed end day
        const maxISO = computeMaxEndISO(startISO);
        if (maxISO && toISO(dayElem.dateObj) === maxISO) {
            dayElem.classList.add('last-available-day');
        }
        }
    });

    // ---------- checkout picker (only in reserve mode) ----------
    // Normal checkout: checkoutEl is hidden -> do NOT attach flatpickr to it.
    if (reserveMode && checkoutEl.type !== 'hidden') {

        // If backend gave us something illegal, move to first free day >= tomorrow
        if (startISO < tomorrowISO || isBlockedISO(startISO)) {
        let d = parseISO(tomorrowISO);
        for (let i = 0; i < 365; i++) {
            const iso = toISO(d);
            if (!isBlockedISO(iso)) { startISO = iso; break; }
            d = addDays(d, 1);
        }
        checkoutEl.value = startISO;
        }

        flatpickr(checkoutEl, {
        dateFormat: 'Y-m-d',
        defaultDate: startISO,
        minDate: tomorrowISO,
        disable: [
            (date) => isBlockedISO(toISO(date)) // cannot start on blocked days
        ],
        onChange: function(selectedDates) {
            if (!selectedDates.length) return;

            const newStartISO = toISO(selectedDates[0]);
            startISO = newStartISO;

            // update end constraints
            const maxISO = computeMaxEndISO(startISO);
            expectedPicker.set('minDate', startISO);
            expectedPicker.set('maxDate', maxISO || null);

            // DO NOT reset end unless it becomes invalid:
            const curEnd = expectedPicker.selectedDates[0];
            const curEndISO = curEnd ? toISO(curEnd) : null;

            if (!curEndISO) {
            // if empty, set to start
            expectedPicker.setDate(startISO, true);
            return;
            }

            if (curEndISO < startISO) {
            // only then snap end to start
            expectedPicker.setDate(startISO, true);
            return;
            }

            if (maxISO && curEndISO > maxISO) {
            // snap end down to max allowed
            expectedPicker.setDate(maxISO, true);
            return;
            }

            // otherwise keep end unchanged
        },
        onDayCreate(_, __, fp, dayElem) {
            const cls = styleDay(dayElem.dateObj);
            if (cls) dayElem.classList.add(cls);
        }
        });
    }
    });
    </script>

@stop




