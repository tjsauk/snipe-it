@extends('layouts/default')

@section('title')
    Edit Reservation - {{ $asset->asset_tag }}
    @parent
@stop

@section('content')
    @php
        $tz = config('app.timezone');

        $startDT = \Carbon\Carbon::parse($reservation->reserved_from)->setTimezone($tz);
        // Stored end is exclusive boundary; display = last occupied hour start (boundary − 1 h)
        $endBoundary = \Carbon\Carbon::parse($reservation->reserved_until)->setTimezone($tz);
        $endDT = $endBoundary->copy()->subHour();

        $defaultStartDate = $startDT->format('Y-m-d');
        $defaultStartHour = (int) $startDT->format('H');
        $defaultEndDate   = $endDT->format('Y-m-d');
        $defaultEndHour   = (int) $endDT->format('H');

        $pad = fn($n) => str_pad($n, 2, '0', STR_PAD_LEFT);
        $currentPeriodText = $defaultStartDate . ' ' . $pad($defaultStartHour) . ':00'
            . ' – '
            . $defaultEndDate   . ' ' . $pad($defaultEndHour)   . ':00';
    @endphp

    <div class="row">
        <div class="col-md-12">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">
                        Edit Reservation &mdash; {{ $asset->asset_tag }}
                        @if($asset->name) / {{ $asset->name }} @endif
                    </h2>
                </div>

                <form id="reservationEditForm" class="form-horizontal"
                      method="POST"
                      action="{{ route('hardware.reserve.update', [$asset, $reservation]) }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="return_to" value="{{ $return_to }}">

                    {{-- Primary period – pre-filled with current values so "Save changes" works without calendar interaction --}}
                    <input type="hidden" id="checkout_at"           name="checkout_at"           value="{{ old('checkout_at',           $defaultStartDate) }}">
                    <input type="hidden" id="checkout_hour"         name="checkout_hour"         value="{{ old('checkout_hour',         $defaultStartHour) }}">
                    <input type="hidden" id="expected_checkin"      name="expected_checkin"      value="{{ old('expected_checkin',      $defaultEndDate) }}">
                    <input type="hidden" id="expected_checkin_hour" name="expected_checkin_hour" value="{{ old('expected_checkin_hour', $defaultEndHour) }}">

                    {{-- Additional periods the user may have added via the calendar --}}
                    <input type="hidden" id="periods_json" name="periods_json" value="">

                    <div class="box-body">

                        @if (session('success'))
                            <div class="alert alert-success">
                                <i class="fa fa-check"></i> {{ session('success') }}
                            </div>
                        @endif

                        @if ($errors->any())
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if (session('error'))
                            <div class="alert alert-danger">{{ session('error') }}</div>
                        @endif

                        <div class="form-group">
                            <label class="col-md-2 control-label">Reserved for</label>
                            <div class="col-md-10">
                                <p class="form-control-static">
                                    {{ optional($reservation->user)->present()->fullName
                                        ?? optional($reservation->user)->email
                                        ?? ('User #' . $reservation->user_id) }}
                                </p>
                            </div>
                        </div>

                        @if (!$can_change_start)
                            <div class="alert alert-info" style="margin: 0 15px 15px;">
                                <i class="fa fa-info-circle"></i>
                                This reservation has already started — only the end time can be changed.
                            </div>
                        @endif

                        <div class="form-group">
                            <label class="col-md-2 control-label">Period</label>
                            <div class="col-md-10">
                                <p class="form-control-static" id="selected-period-text">{{ $currentPeriodText }}</p>
                                <p class="help-block" style="margin-top:4px;">
                                    Select a new period in the calendar below, then click <strong>Confirm</strong> to save.<br>
                                    The orange block shows the current reservation — you can drag over it to replace it.
                                </p>
                            </div>
                        </div>

                        {{-- Calendar (full width) --}}
                        <div class="form-group">
                            <div class="col-md-12">
                                <div id="asset-calendar-root"></div>
                            </div>
                        </div>

                    </div>{{-- /.box-body --}}

                    <div class="box-footer">
                        <a href="{{ $return_to }}" class="btn btn-default">
                            <i class="fa fa-arrow-left"></i> Back
                        </a>
                    </div>

                </form>
            </div>
        </div>
    </div>
@stop

@section('moar_scripts')
    @php
    // Blocked ranges from other reservations/checkouts — the editing reservation is excluded.
    $calendarBlockedPeriods = collect($calendarRanges ?? [])->map(function($r) {
        return [
            'start'    => \Carbon\Carbon::parse($r['from'])->format('Y-m-d H:i'),
            'end'      => $r['to'] ? \Carbon\Carbon::parse($r['to'])->format('Y-m-d H:i') : null,
            'userName' => (string)($r['type'] ?? 'blocked'),
        ];
    })->values()->toArray();

    // Orange block = the reservation being edited (visual reference, noBlock so user can drag over it).
    // End = last occupied minute (boundary − 1 min), matching the format calendarBlockedRanges uses.
    $orangeBlock = [
        'start'    => $startDT->format('Y-m-d H:i'),
        'end'      => $endBoundary->copy()->subMinute()->format('Y-m-d H:i'),
        'userName' => 'current_reservation',
        'color'    => '#f97316',
        'noBlock'  => true,
    ];

    $allCalendarPeriods = array_merge($calendarBlockedPeriods, [$orangeBlock]);
    @endphp

    <script>
    var canChangeStart  = @json($can_change_start);
    var lockedStartDate = @json($defaultStartDate);
    var lockedStartHour = @json($defaultStartHour);

    window.assetCalendarInput = {
        mode: 'reserve',
        currentUser: @json(optional(auth()->user())->username ?? (string) auth()->id()),
        assets: [{
            id: @json((string)$asset->id),
            name: @json($asset->name ?? $asset->asset_tag),
            existingPeriods: {!! json_encode($allCalendarPeriods) !!}
        }]
    };

    // Called by the calendar's Confirm button.
    // Updates hidden form fields with the selected period, then submits the form.
    // If nothing was selected in the calendar, the pre-filled original values are kept.
    window.assetCalendarOnConfirm = function(output) {
        var assetOutput = output && output.assets && output.assets[0];
        var periods = (assetOutput && assetOutput.selectedPeriods) || [];

        if (periods.length > 0) {
            var primary    = periods[0];
            var startParts = primary.start.split(' ');
            var endParts   = primary.end.split(' ');
            var startHour  = parseInt(startParts[1].split(':')[0], 10);
            var endHour    = parseInt(endParts[1].split(':')[0], 10);

            if (!canChangeStart) {
                startParts[0] = lockedStartDate;
                startHour     = lockedStartHour;
            }

            document.getElementById('checkout_at').value           = startParts[0];
            document.getElementById('checkout_hour').value         = String(startHour);
            document.getElementById('expected_checkin').value      = endParts[0];
            document.getElementById('expected_checkin_hour').value = String(endHour);

            var additional = periods.slice(1);
            document.getElementById('periods_json').value = additional.length > 0
                ? JSON.stringify(additional)
                : '';

            var pad = function(n) { return String(n).padStart(2, '0'); };
            var text = document.getElementById('selected-period-text');
            if (text) {
                text.textContent = startParts[0] + ' ' + pad(startHour) + ':00'
                    + ' \u2013 '
                    + endParts[0] + ' ' + pad(endHour) + ':00';
                if (additional.length > 0) {
                    text.textContent += ' (+' + additional.length + ' new period' + (additional.length > 1 ? 's' : '') + ')';
                }
            }
        }

        document.getElementById('reservationEditForm').submit();
    };

    </script>

    <script>
    (function() {
        var script = document.createElement('script');
        script.src = '{{ asset('vendor/asset-calendar/asset-calendar.js') }}?v={{ filemtime(public_path('vendor/asset-calendar/asset-calendar.js')) }}';
        document.body.appendChild(script);
    })();
    </script>
@stop
