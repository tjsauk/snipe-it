@extends('layouts/default')

@php
    /** @var \App\Models\User $authUser */
    $authUser = auth()->user();
    $onlySelfCheckout = $authUser && method_exists($authUser, 'mustSelfCheckout') && $authUser->mustSelfCheckout();

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
                <form class="form-horizontal" method="post" action="{{ $storeRoute }}" autocomplete="off">
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
                        @if ($allowCheckoutToAsset)
                        @include ('partials.forms.edit.asset-select', ['translated_name' => trans('general.select_asset'), 'fieldname' => 'assigned_asset', 'company_id' => $asset->company_id, 'unselect' => 'true', 'style' => session('checkout_to_type') == 'asset' ? '' : 'display: none;'])
                        @endif
                        @if ($allowCheckoutToLocation)
                        @include ('partials.forms.edit.location-select', ['translated_name' => trans('general.location'), 'fieldname' => 'assigned_location', 'style' => session('checkout_to_type') == 'location' ? '' : 'display: none;'])
                        @endif                



                        <!-- Checkout Date + Time -->
                        <div class="form-group {{ $errors->has('checkout_at') ? 'error' : '' }}">
                            <label for="checkout_at_dt" class="col-md-3 control-label">
                                {{ trans('admin/hardware/form.checkout_date') }}
                            </label>

                            <div class="col-md-8">
                                <input
                                    type="text"
                                    id="checkout_at_dt"
                                    class="form-control col-md-7"
                                    value=""
                                    {{ (!empty($reserve_mode) && $reserve_mode) ? '' : 'readonly' }}
                                >
                                <p class="help-block" style="margin:6px 0 0;">
                                  Select the start hour. Example: <strong>13:00</strong> means usage starts <strong>13:00–13:59</strong>.
                                </p>


                                {{-- Submitted values (ONLY ONCE) --}}
                                <input
                                    type="hidden"
                                    id="checkout_at"
                                    name="checkout_at"
                                    value="{{ old('checkout_at', $defaultCheckoutAt) }}"
                                >
                                <input
                                    type="hidden"
                                    id="checkout_hour"
                                    name="checkout_hour"
                                    value="{{ old('checkout_hour', '') }}"
                                >

                                {!! $errors->first('checkout_at', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                            </div>
                        </div>

                        <!-- Expected Checkin Date + Time -->
                        <div id="expected_checkin_group" class="form-group {{ $errors->has('expected_checkin') ? 'error' : '' }}">

                            <label for="expected_checkin_dt" class="col-md-3 control-label">
                                {{ trans('admin/hardware/form.expected_checkin') }}
                            </label>

                            <div class="col-md-8">
                                <input
                                    type="text"
                                    id="expected_checkin_dt"
                                    class="form-control col-md-7"
                                    value=""
                                >
                                <p class="help-block" style="margin:6px 0 0;">
                                  Select the <strong>last used hour</strong>. Example: end <strong>13:00</strong> means the item is used until <strong>13:59</strong> and becomes available at <strong>14:00</strong>.
                                </p>


                                {{-- Submitted values (ONLY ONCE) --}}
                                <input
                                    type="hidden"
                                    id="expected_checkin"
                                    name="expected_checkin"
                                    value="{{ old('expected_checkin', $defaultExpectedCheckin) }}"
                                >
                                <input
                                    type="hidden"
                                    id="expected_checkin_hour"
                                    name="expected_checkin_hour"
                                    value="{{ old('expected_checkin_hour', '') }}"
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

  const reserveMode = {{ !empty($reserve_mode) && $reserve_mode ? 'true' : 'false' }};
  const rangesRaw = @json($calendarRanges ?? []);

  const checkoutDTEl  = document.getElementById('checkout_at_dt');
  const expectedDTEl  = document.getElementById('expected_checkin_dt');

  const checkoutDateHidden = document.getElementById('checkout_at');
  const expectedDateHidden = document.getElementById('expected_checkin');

  const checkoutHourHidden = document.getElementById('checkout_hour');
  const expectedHourHidden = document.getElementById('expected_checkin_hour');
  
  if (!checkoutDTEl || !expectedDTEl || !checkoutDateHidden || !expectedDateHidden || !checkoutHourHidden || !expectedHourHidden) return;

  // Make sure the inputs can open flatpickr (themes sometimes kill pointer events)
  expectedDTEl.removeAttribute('disabled');
  expectedDTEl.removeAttribute('readonly');
  expectedDTEl.classList.remove('disabled');
  expectedDTEl.style.pointerEvents = 'auto';
  expectedDTEl.style.cursor = 'pointer';

  if (reserveMode) {
    checkoutDTEl.removeAttribute('disabled');
    checkoutDTEl.removeAttribute('readonly');
    checkoutDTEl.classList.remove('disabled');
    checkoutDTEl.style.pointerEvents = 'auto';
    checkoutDTEl.style.cursor = 'pointer';
  }

  // ---------------- helpers ----------------
  const now = new Date();

  function pad2(n){ return String(n).padStart(2,'0'); }
  function snapToHour(d){
    const x = new Date(d.getTime());
    x.setMinutes(0,0,0);
    return x;
  }
  function addHours(d, h){
    const x = new Date(d.getTime());
    x.setHours(x.getHours() + h);
    return x;
  }
  function startOfDay(d){
    return new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0,0,0,0);
  }
  function formatDateOnly(d){
    return `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`;
  }
  function roundUpToNextHour(d){
    const x = new Date(d.getTime());
    if (x.getMinutes() !== 0 || x.getSeconds() !== 0 || x.getMilliseconds() !== 0) {
      x.setHours(x.getHours() + 1);
    }
    x.setMinutes(0,0,0);
    return x;
  }
  function parseDT(str) {
    if (!str) return null;
    return flatpickr.parseDate(str, str.length >= 19 ? "Y-m-d H:i:S" : "Y-m-d H:i");
  }
  function writeHiddenFromDT(dt, dateHiddenEl, hourHiddenEl) {
    dt = snapToHour(dt);
    dateHiddenEl.value = formatDateOnly(dt);
    hourHiddenEl.value = String(dt.getHours());
  }
  function composeFromHidden(dateHiddenEl, hourHiddenEl, fallbackDT) {
    const iso = dateHiddenEl.value;
    const hRaw = hourHiddenEl.value;
    const h = (hRaw !== '' && !isNaN(parseInt(hRaw,10))) ? parseInt(hRaw,10) : fallbackDT.getHours();

    if (!iso) return snapToHour(fallbackDT);
    const base = flatpickr.parseDate(iso, "Y-m-d");
    if (!base) return snapToHour(fallbackDT);
    base.setHours(h,0,0,0);
    return base;
  }

  // ---------------- normalize blocked ranges to hour-starts ----------------
  // blocked interval convention: [from, to + 1h) blocks the hour-starts from..to inclusive
  const ranges = (rangesRaw || [])
    .map(r => ({
      from: parseDT(r.from),
      to:   parseDT(r.to || r.from),
      type: r.type || 'reservation'
    }))
    .filter(r => r.from && r.to)
    .map(r => {
      if (r.to < r.from) { const t = r.from; r.from = r.to; r.to = t; }
      r.from = snapToHour(r.from);
      r.to   = snapToHour(r.to);
      return r;
    });

  function isDTBlocked(dt){
    const h = snapToHour(dt);
    return ranges.some(r => {
      const toExclusive = addHours(r.to, 1);
      return h >= r.from && h < toExclusive;
    });
  }

  function nextBlockedStart(afterDT){
    const probe = snapToHour(afterDT);
    let best = null;
    for (const r of ranges){
      const toExclusive = addHours(r.to, 1);

      // inside a block -> next blocked "start" is this block's start
      if (probe >= r.from && probe < toExclusive) return new Date(r.from.getTime());

      // otherwise, next block that starts after probe
      if (r.from >= probe) {
        if (!best || r.from < best) best = r.from;
      }
    }
    return best ? new Date(best.getTime()) : null;
  }

  // User chooses END as "last occupied hour start".
  // If next block starts at 10:00, last selectable end is 09:00.
  function maxAllowedEnd(startDT){
    const next = nextBlockedStart(startDT);
    if (!next) return null;
    return snapToHour(addHours(next, -1));
  }

  function firstOpenHourFrom(dt, maxScanHours = 24*60){
    let x = snapToHour(dt);
    for (let i=0; i<maxScanHours; i++){
      if (!isDTBlocked(x)) return x;
      x = addHours(x, 1);
    }
    return snapToHour(dt);
  }

  function firstValidHourOnDay(dayDate, minDT, maxDT){
    const day0 = startOfDay(dayDate);
    for (let h=0; h<24; h++){
      const dt = new Date(day0.getFullYear(), day0.getMonth(), day0.getDate(), h,0,0,0);
      if (minDT && dt < minDT) continue;
      if (maxDT && dt > maxDT) continue;
      if (!isDTBlocked(dt)) return dt;
    }
    return null;
  }

  function lastValidHourOnDay(dayDate, minDT, maxDT){
    const day0 = startOfDay(dayDate);
    for (let h=23; h>=0; h--){
      const dt = new Date(day0.getFullYear(), day0.getMonth(), day0.getDate(), h,0,0,0);
      if (minDT && dt < minDT) continue;
      if (maxDT && dt > maxDT) continue;
      if (!isDTBlocked(dt)) return dt;
    }
    return null;
  }

  // ---------------- default rules ----------------
  function defaultCheckoutStart(){
    return new Date(now.getFullYear(), now.getMonth(), now.getDate(), now.getHours(), 0,0,0);
  }

  // Your requested default: tomorrow at current hour
  // (but still allow selecting today >= next full hour)
  function defaultReservationStart(){
    const tomorrowSameHour = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, now.getHours(), 0,0,0);
    return firstOpenHourFrom(tomorrowSameHour);
  }

  function chooseDefaultEnd(startDT){
    const plus2w = snapToHour(addHours(startDT, 24*14));
    const nextStart = nextBlockedStart(startDT);
    if (nextStart && nextStart <= plus2w) {
      const end = snapToHour(addHours(nextStart, -1));
      return end < startDT ? new Date(startDT.getTime()) : end;
    }
    return plus2w;
  }

  // Reservation earliest selectable moment = next full hour (today allowed)
  const minReservationStart = roundUpToNextHour(now);

  // ---------------- day disable (calendar cells) ----------------
  // Disable a day only if it has no valid hour in the allowed window.
  function disableStartDay(dayDate){
    if (!reserveMode) return false;
    // block past days entirely
    if (startOfDay(dayDate) < startOfDay(minReservationStart)) return true;
    return firstValidHourOnDay(dayDate, minReservationStart, null) === null;
  }

  function disableEndDay(dayDate){
    const minEnd = startDT;
    const maxEnd = maxAllowedEnd(startDT);
    return lastValidHourOnDay(dayDate, minEnd, maxEnd) === null;
  }

  // ---------------- snapping logic ----------------
  // Preserve previous hour when user clicks a day (flatpickr often gives 00:00).
  function preserveHourIfDayClick(chosen, prev){
    if (!prev) return chosen;
    const c = new Date(chosen.getTime());
    const dayChanged =
      c.getFullYear() !== prev.getFullYear() ||
      c.getMonth() !== prev.getMonth() ||
      c.getDate() !== prev.getDate();
    if (dayChanged && c.getHours() === 0) {
      c.setHours(prev.getHours(), 0,0,0);
    }
    return c;
  }

  function snapStartToValid(chosen, prevStart){
    const before = prevStart ? new Date(prevStart.getTime()) : null;

    chosen = snapToHour(preserveHourIfDayClick(chosen, prevStart));

    if (!reserveMode) {
      return defaultCheckoutStart();
    }

    // must be >= next full hour
    if (chosen < minReservationStart) chosen = new Date(minReservationStart.getTime());

    const dayClicked =
      before &&
      (chosen.getFullYear() !== before.getFullYear() ||
      chosen.getMonth() !== before.getMonth() ||
      chosen.getDate() !== before.getDate());

    // If user clicked a day cell (often yields 00:00), pick the FIRST valid hour that day
    if (dayClicked && chosen.getHours() === 0) {
      const first = firstValidHourOnDay(chosen, minReservationStart, null);
      if (first) return firstOpenHourFrom(first);
      return firstOpenHourFrom(chosen);
    }

    // If the chosen hour is blocked (e.g. user arrowed into 16:00),
    // jump FORWARD to the next open hour so user can reach 19:00 etc.
    if (isDTBlocked(chosen)) {
      return firstOpenHourFrom(chosen);
    }

    return chosen;
  }

  function snapEndToValid(chosen, prevEnd){
    chosen = snapToHour(preserveHourIfDayClick(chosen, prevEnd));

    const maxEnd = maxAllowedEnd(startDT);

    // window clamp
    if (chosen < startDT) chosen = new Date(startDT.getTime());
    if (maxEnd && chosen > maxEnd) chosen = new Date(maxEnd.getTime());

    // if blocked or day-click weirdness -> snap to LAST valid hour on that day
    if (chosen.getHours() === 0 || isDTBlocked(chosen)) {
      const last = lastValidHourOnDay(chosen, startDT, maxEnd);
      if (last) chosen = last;
    }

    // if still blocked, walk back hour-by-hour but not below start
    while (isDTBlocked(chosen) && chosen > startDT) {
      chosen = snapToHour(addHours(chosen, -1));
    }
    if (chosen < startDT) chosen = new Date(startDT.getTime());

    return chosen;
  }

  // ---------------- initial start/end ----------------
  let startDT;
  if (reserveMode) {
    startDT = composeFromHidden(checkoutDateHidden, checkoutHourHidden, defaultReservationStart());
    startDT = snapStartToValid(startDT, null);
  } else {
    startDT = defaultCheckoutStart();
  }
  writeHiddenFromDT(startDT, checkoutDateHidden, checkoutHourHidden);

  let endDT = composeFromHidden(expectedDateHidden, expectedHourHidden, chooseDefaultEnd(startDT));
  endDT = snapEndToValid(endDT, null);
  writeHiddenFromDT(endDT, expectedDateHidden, expectedHourHidden);

  // ---------------- recursion guards ----------------
  let syncingStart = false;
  let syncingEnd = false;
  let endWasAuto = true;           // starts as auto
  let changingEndFromStart = false; // guard when we set end programmatically

  function handleStartPicked(picker, selectedDates) {
    if (!selectedDates.length) return;
    if (syncingStart) return;
    syncingStart = true;

    const prev = startDT;
    let chosen = selectedDates[0];
    chosen = snapStartToValid(chosen, prev);

    startDT = chosen;
    picker.setDate(chosen, false);
    writeHiddenFromDT(chosen, checkoutDateHidden, checkoutHourHidden);

    // update end based on start change
    expectedPicker.set('disable', [disableEndDay]);

    changingEndFromStart = true;

    let newEnd;
    if (endWasAuto) newEnd = chooseDefaultEnd(startDT);
    else newEnd = expectedPicker.selectedDates[0] ? expectedPicker.selectedDates[0] : chooseDefaultEnd(startDT);

    newEnd = snapEndToValid(newEnd, endDT);
    endDT = newEnd;

    expectedPicker.setDate(newEnd, false);
    writeHiddenFromDT(newEnd, expectedDateHidden, expectedHourHidden);
    expectedPicker.redraw();

    changingEndFromStart = false;

    syncingStart = false;
  }
  // ---------------- init pickers ----------------
  const expectedPicker = flatpickr(expectedDTEl, {
    enableTime: true,
    time_24hr: true,
    minuteIncrement: 60,
    dateFormat: "Y-m-d H:i",
    allowInput: false,
    clickOpens: true,

    disable: [disableEndDay],
    defaultDate: endDT,
    defaultHour: endDT.getHours(),
    defaultMinute: 0,

    // catch hour-arrow changes too
    onValueUpdate: function(selectedDates){
      if (!selectedDates.length) return;
      if (syncingEnd) return;
      syncingEnd = true;
      if (!changingEndFromStart) endWasAuto = false;

      const prev = endDT;
      let chosen = selectedDates[0];
      chosen = snapEndToValid(chosen, prev);

      endDT = chosen;
      this.setDate(chosen, false);
      writeHiddenFromDT(chosen, expectedDateHidden, expectedHourHidden);

      syncingEnd = false;
    },

    onChange: function(selectedDates){
      if (!selectedDates.length) return;
      if (syncingEnd) return;
      syncingEnd = true;
      if (!changingEndFromStart) endWasAuto = false;

      const prev = endDT;
      let chosen = selectedDates[0];
      chosen = snapEndToValid(chosen, prev);

      endDT = chosen;
      this.setDate(chosen, false);
      writeHiddenFromDT(chosen, expectedDateHidden, expectedHourHidden);

      syncingEnd = false;
    }
  });

  const checkoutPicker = flatpickr(checkoutDTEl, {
    enableTime: true,
    time_24hr: true,
    minuteIncrement: 60,
    dateFormat: "Y-m-d H:i",
    allowInput: false,
    clickOpens: reserveMode,

    disable: reserveMode ? [disableStartDay] : [],
    defaultDate: startDT,
    defaultHour: startDT.getHours(),
    defaultMinute: 0,
    onValueUpdate: function(selectedDates){ handleStartPicked(this, selectedDates); },
    onChange: function(selectedDates){ handleStartPicked(this, selectedDates); },

    onValueUpdate: function(selectedDates){
      if (!selectedDates.length) return;
      if (syncingStart) return;
      syncingStart = true;

      const prev = startDT;
      let chosen = selectedDates[0];
      chosen = snapStartToValid(chosen, prev);

      startDT = chosen;
      this.setDate(chosen, false);
      writeHiddenFromDT(chosen, checkoutDateHidden, checkoutHourHidden);

      expectedPicker.set('disable', [disableEndDay]);

      changingEndFromStart = true;

      let newEnd;
      if (endWasAuto) {
        // recompute default whenever start changes (this is what you wanted)
        newEnd = chooseDefaultEnd(startDT);
      } else {
        // user manually set end earlier -> keep it if still valid, otherwise snap/clamp
        const cur = expectedPicker.selectedDates[0];
        newEnd = cur ? cur : chooseDefaultEnd(startDT);
      }

      newEnd = snapEndToValid(newEnd, endDT);
      endDT = newEnd;

      expectedPicker.setDate(newEnd, false);
      writeHiddenFromDT(newEnd, expectedDateHidden, expectedHourHidden);
      expectedPicker.redraw();

      changingEndFromStart = false;


      syncingStart = false;
    },

    onChange: function(selectedDates){
      if (!selectedDates.length) return;
      if (syncingStart) return;
      syncingStart = true;
      

      const prev = startDT;
      let chosen = selectedDates[0];
      chosen = snapStartToValid(chosen, prev);

      startDT = chosen;
      this.setDate(chosen, false);
      writeHiddenFromDT(chosen, checkoutDateHidden, checkoutHourHidden);

      expectedPicker.set('disable', [disableEndDay]);

      changingEndFromStart = true;

      let newEnd;
      if (endWasAuto) {
        // recompute default whenever start changes (this is what you wanted)
        newEnd = chooseDefaultEnd(startDT);
      } else {
        // user manually set end earlier -> keep it if still valid, otherwise snap/clamp
        const cur = expectedPicker.selectedDates[0];
        newEnd = cur ? cur : chooseDefaultEnd(startDT);
      }

      newEnd = snapEndToValid(newEnd, endDT);
      endDT = newEnd;

      expectedPicker.setDate(newEnd, false);
      writeHiddenFromDT(newEnd, expectedDateHidden, expectedHourHidden);
      expectedPicker.redraw();

      changingEndFromStart = false;


      syncingStart = false;
    }
  });

  // normal checkout should stay read-only start
  if (!reserveMode) {
    checkoutDTEl.setAttribute('readonly', 'readonly');
    // NOTE: do NOT add a class that kills pointer-events unless you want it.
  }
    // ---------------- expected_checkin show/hide based on checkout target ----------------
    const expectedGroup = document.getElementById('expected_checkin_group');

    function getCheckoutToType() {
      // radios
      const checked = document.querySelector('input[name="checkout_to_type"]:checked');
      if (checked) return checked.value;

      // select
      const sel = document.querySelector('select[name="checkout_to_type"]');
      if (sel) return sel.value;

      // fallback: hidden input (some setups use this)
      const hidden = document.querySelector('input[name="checkout_to_type"][type="hidden"]');
      if (hidden) return hidden.value;

      return null;
    }

    function setExpectedVisibility() {
      if (!expectedGroup) return;

      const t = getCheckoutToType();

      const shouldHide = (t === 'asset'); // <-- if your value differs, tell me what it is and I’ll adjust

      if (shouldHide) {
        expectedGroup.style.display = 'none';

        // disable visible input
        expectedDTEl.value = '';
        expectedDTEl.setAttribute('disabled', 'disabled');

        // clear submitted hidden values so backend doesn't see expected_checkin required
        expectedDateHidden.value = '';
        expectedHourHidden.value = '';
      } else {
        expectedGroup.style.display = '';

        expectedDTEl.removeAttribute('disabled');

        // If empty, reinitialize to a valid end based on current startDT
        if (!expectedDateHidden.value || expectedHourHidden.value === '') {
          let restored = chooseDefaultEnd(startDT);
          restored = snapEndToValid(restored, endDT);
          endDT = restored;

          expectedPicker.setDate(restored, false);
          writeHiddenFromDT(restored, expectedDateHidden, expectedHourHidden);
          expectedPicker.redraw();
        }
      }
    }

    // Run once on load
    setExpectedVisibility();

    // Event delegation: catches radio changes, select changes, dynamic DOM swaps
    document.addEventListener('change', function(e) {
      if (
        e.target &&
        (e.target.matches('input[name="checkout_to_type"]') ||
        e.target.matches('select[name="checkout_to_type"]'))
      ) {
        setExpectedVisibility();
      }
    });



});
</script>




@stop




