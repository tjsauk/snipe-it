@extends('layouts/default')

@section('title')
    Manage Reservations and Checkouts - {{ $asset->asset_tag }}
    @parent
@stop

@section('content')
    @php
        $return_to = $return_to ?? request('return_to') ?? session('return_to') ?? route('hardware.show', $asset);
    @endphp

    <div class="row">
        <div class="col-md-12">

            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">
                        Manage Reservations and Checkouts for Asset: {{ $asset->asset_tag }} - {{ $asset->name }}
                    </h2>
                </div>

                <div class="box-body">
                    @if (session('success'))
                        <div class="alert alert-success">
                            <i class="fa fa-check"></i> {{ session('success') }}
                        </div>
                    @endif
                    @if (session('error'))
                        <div class="alert alert-danger">{{ session('error') }}</div>
                    @endif

                    @if ($reservations->isEmpty() && $checkouts->isEmpty())
                        <p>No active reservations or checkouts for this asset.</p>
                    @else
                        {{-- Bulk cancel bar --}}
                        <div id="manageBulkCancelBar" style="display:none; margin-bottom:8px;">
                            <button type="button" id="manageBulkCancelBtn" class="btn btn-sm btn-danger">
                                <i class="fa fa-ban"></i> Cancel selected
                                (<span id="manageCancelCount">0</span>)
                            </button>
                            <button type="button" id="manageClearCancelBtn" class="btn btn-sm btn-default" style="margin-left:4px;">
                                Clear selection
                            </button>
                        </div>

                        <div class="table-responsive">
                        <table class="table table-striped" style="white-space: nowrap;">
                            <thead>
                            <tr>
                                <th class="hidden-print" style="width:30px;">
                                    <input type="checkbox" id="manageSelectAll" title="Select all cancellable">
                                </th>
                                <th>Type</th>
                                <th>User</th>
                                <th>From</th>
                                <th>Until</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($reservations as $reservation)
                                <tr>
                                    <td class="hidden-print">
                                        @if ($reservation->status !== 'fulfilled')
                                            <input type="checkbox"
                                                   class="manage-cancel-cb"
                                                   data-asset-id="{{ $asset->id }}"
                                                   data-reservation-id="{{ $reservation->id }}">
                                        @endif
                                    </td>
                                    <td>Reservation</td>
                                    <td>
                                        {{ optional($reservation->user)->present()->fullName
                                            ?? optional($reservation->user)->email
                                            ?? ('User #'.$reservation->user_id) }}
                                        @if ($reservation->status === 'fulfilled')
                                            <span class="label label-info" style="margin-left:4px;">Checked Out</span>
                                        @elseif ($reservation->reserved_until && $reservation->reserved_until->isPast())
                                            <span class="label label-warning" style="margin-left:4px;">Overdue</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{ \App\Helpers\Helper::getFormattedDateObject($reservation->reserved_from, 'datetime', false) }}
                                    </td>
                                    <td>
                                        @if ($reservation->reserved_until)
                                            {{ \App\Helpers\Helper::getFormattedDateObject($reservation->reserved_until_ui, 'datetime', false) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('hardware.reserve.edit', [$asset, $reservation]) }}?return_to={{ urlencode(request()->fullUrl()) }}"
                                           class="btn btn-xs btn-primary" style="margin-right: 4px;">
                                            <i class="fa fa-pencil"></i> Edit
                                        </a>
                                        @if ($reservation->status !== 'fulfilled')
                                        <form method="POST"
                                              action="{{ route('hardware.reserve.destroy', [$asset, $reservation]) }}"
                                              style="display:inline-block;">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="return_to" value="{{ route('hardware.reserve.manage', ['asset' => $asset->id, 'return_to' => $return_to]) }}">
                                            <button type="submit"
                                                    class="btn btn-xs btn-danger"
                                                    onclick="return confirm('Cancel this reservation?')">
                                                <i class="fa fa-ban"></i> Cancel
                                            </button>
                                        </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            @foreach ($checkouts as $checkout)
                                <tr>
                                    <td class="hidden-print"></td>
                                    <td>Checkout</td>
                                    <td>
                                        {{ optional($checkout->user)->present()->fullName
                                            ?? optional($checkout->user)->email
                                            ?? ('User #'.(optional($checkout->user)->id ?? $checkout->user_id ?? '?')) }}
                                        <span class="label label-info" style="margin-left:4px;">Checked Out</span>
                                    </td>
                                    <td>
                                        {{ \App\Helpers\Helper::getFormattedDateObject($checkout->reserved_from, 'datetime', false) }}
                                    </td>
                                    <td>
                                        @if ($checkout->reserved_until)
                                            {{ \App\Helpers\Helper::getFormattedDateObject($checkout->reserved_until, 'datetime', false) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('hardware.checkout.edit', $asset) }}?return_to={{ urlencode(request()->fullUrl()) }}"
                                           class="btn btn-xs btn-primary" style="margin-right: 4px;">
                                            <i class="fa fa-pencil"></i> Edit
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        </div>
                    @endif
                </div>

                <div class="box-footer">
                    <a href="{{ $return_to }}" class="btn btn-default">
                        <i class="fa fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>

        </div>
    </div>
@stop

@section('moar_scripts')
<script>
(function () {
    var csrfToken  = (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute('content') || '';
    var selectAll  = document.getElementById('manageSelectAll');
    var cancelBar  = document.getElementById('manageBulkCancelBar');
    var countEl    = document.getElementById('manageCancelCount');
    var cancelBtn  = document.getElementById('manageBulkCancelBtn');
    var clearBtn   = document.getElementById('manageClearCancelBtn');

    function updateBar() {
        var n = document.querySelectorAll('.manage-cancel-cb:checked').length;
        if (countEl)   countEl.textContent = n;
        if (cancelBar) cancelBar.style.display = n > 0 ? '' : 'none';
    }

    function syncSelectAll() {
        if (!selectAll) return;
        var all = document.querySelectorAll('.manage-cancel-cb');
        var chk = document.querySelectorAll('.manage-cancel-cb:checked');
        selectAll.indeterminate = chk.length > 0 && chk.length < all.length;
        selectAll.checked       = all.length > 0 && chk.length === all.length;
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.manage-cancel-cb').forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
            updateBar();
        });
    }

    document.querySelectorAll('.manage-cancel-cb').forEach(function (cb) {
        cb.addEventListener('change', function () {
            updateBar();
            syncSelectAll();
        });
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            document.querySelectorAll('.manage-cancel-cb').forEach(function (cb) { cb.checked = false; });
            if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
            updateBar();
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            var checked = Array.from(document.querySelectorAll('.manage-cancel-cb:checked'));
            if (checked.length === 0) return;
            if (!confirm('Cancel ' + checked.length + ' reservation(s)?')) return;

            Promise.all(checked.map(function (cb) {
                return fetch('/hardware/' + cb.getAttribute('data-asset-id') + '/reservations/' + cb.getAttribute('data-reservation-id'), {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                });
            })).then(function () {
                window.location.reload();
            }).catch(function () {
                window.location.reload();
            });
        });
    }
})();
</script>
@stop
