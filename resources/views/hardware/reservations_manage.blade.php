@extends('layouts/default')

@section('title')
    Manage Reservations - {{ $asset->asset_tag }}
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
                        Manage Reservations for Asset: {{ $asset->asset_tag }} - {{ $asset->name }}
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

                    @if ($reservations->isEmpty())
                        <p>No active reservations for this asset.</p>
                    @else
                        <div class="table-responsive">
                        <table class="table table-striped" style="white-space: nowrap;">
                            <thead>
                            <tr>
                                <th>User</th>
                                <th>Reserved From</th>
                                <th>Reserved Until</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($reservations as $reservation)
                                <tr>
                                    <td>
                                        {{ optional($reservation->user)->present()->fullName
                                            ?? optional($reservation->user)->email
                                            ?? ('User #'.$reservation->user_id) }}
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
