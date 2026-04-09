@extends('layouts/default')

@section('title')
    Edit Checkout - {{ $asset->asset_tag }}
    @parent
@stop

@section('content')
    @php
        $tz = config('app.timezone');

        $startDT = \Carbon\Carbon::parse($asset->last_checkout)->setTimezone($tz);
        // Expected checkin is boundary; display = last occupied hour start (boundary − 1 h)
        $endBoundary = $asset->expected_checkin ? \Carbon\Carbon::parse($asset->expected_checkin)->setTimezone($tz) : null;
        $endDT = $endBoundary ? $endBoundary->copy()->subHour() : null;

        $defaultEndDate   = $endDT ? $endDT->format('Y-m-d') : '';
        $defaultEndHour   = $endDT ? (int) $endDT->format('H') : 0;

        $pad = fn($n) => str_pad($n, 2, '0', STR_PAD_LEFT);
        $currentPeriodText = $startDT->format('Y-m-d H:i') . ' – ' . ($endDT ? $endDT->format('Y-m-d H:i') : 'No end date');
    @endphp

    <div class="row">
        <div class="col-md-12">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">
                        Edit Checkout &mdash; {{ $asset->asset_tag }}
                        @if($asset->name) / {{ $asset->name }} @endif
                    </h2>
                </div>

                <form id="checkoutEditForm" class="form-horizontal"
                      method="POST"
                      action="{{ route('hardware.checkout.update', $asset) }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="return_to" value="{{ $return_to }}">

                    {{-- Expected checkin period – pre-filled with current values --}}
                    <input type="hidden" id="expected_checkin"      name="expected_checkin"      value="{{ old('expected_checkin',      $defaultEndDate) }}">
                    <input type="hidden" id="expected_checkin_hour" name="expected_checkin_hour" value="{{ old('expected_checkin_hour', $defaultEndHour) }}">

                    <div class="box-body">
                        <div class="form-group">
                            <label class="col-sm-3 control-label">Checkout Start</label>
                            <div class="col-md-6">
                                <p class="form-control-static">{{ $startDT->format('Y-m-d H:i') }}</p>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-3 control-label">Current Expected Checkin</label>
                            <div class="col-md-6">
                                <p class="form-control-static">{{ $currentPeriodText }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="box-footer">
                        <div id="checkout-calendar-root"></div>
                    </div>

                    <div class="box-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save"></i> Update Expected Checkin
                        </button>
                        <a href="{{ $return_to }}" class="btn btn-default">
                            <i class="fa fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@stop

@section('moar_scripts')
    <script>
    window.checkoutCalendarInput = {
        mode: 'edit_reservation',
        currentUser: @json(Auth::user()->username ?? (string)Auth::id()),
        continuousCutMode: false,
        assets: [{
            id: @json((string)$asset->id),
            name: @json($asset->name),
            existingPeriods: {!! json_encode($calendarRanges ?? []) !!}
        }],
        fixedStart: @json($startDT->format('Y-m-d H:i:s')),
        allowStartChange: false
    };

    window.checkoutCalendarOnConfirm = function(output) {
        var periods = output && output.assets && output.assets[0] && output.assets[0].selectedPeriods;
        if (!periods || periods.length === 0) {
            alert('Please select an expected checkin time in the calendar.');
            return;
        }
        var period = periods[0];
        var endParts = period.end.split(' ');
        var endHour = parseInt(endParts[1].split(':')[0], 10);

        document.getElementById('expected_checkin').value = endParts[0];
        document.getElementById('expected_checkin_hour').value = String(endHour);

        document.getElementById('checkoutEditForm').submit();
    };
    </script>

    {{-- Load calendar bundle --}}
    <script>
    (function() {
        var calendarMounted = false;
        function mountCalendar() {
            if (calendarMounted) return;
            calendarMounted = true;
            var script = document.createElement('script');
            script.src = '{{ asset('vendor/asset-calendar/asset-calendar.js') }}';
            document.body.appendChild(script);
        }
        // Mount immediately since this is edit mode
        mountCalendar();
    })();
    </script>
@stop