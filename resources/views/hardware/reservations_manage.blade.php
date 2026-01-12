@extends('layouts/default')

@section('title')
    Manage Reservations - {{ $asset->asset_tag }}
    @parent
@stop

@section('content')
    <div class="row">
        <div class="col-md-12">

            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">
                        Manage Reservations for Asset: {{ $asset->asset_tag }} - {{ $asset->name }}
                    </h2>
                </div>

                <div class="box-body">
                    @if ($reservations->isEmpty())
                        <p>No active reservations for this asset.</p>
                    @else
                        <table class="table table-striped">
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
                                            {{ \App\Helpers\Helper::getFormattedDateObject($reservation->reserved_until, 'datetime', false) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        <form method="POST"
                                              action="{{ route('hardware.reserve.destroy', [$asset, $reservation]) }}"
                                              style="display:inline-block;">
                                            @csrf
                                            @method('DELETE')
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
                    @endif
                </div>

                <div class="box-footer">
                    <a href="{{ route('hardware.show', $asset) }}" class="btn btn-default">
                        <i class="fa fa-arrow-left"></i> Back to Asset
                    </a>
                </div>
            </div>

        </div>
    </div>
@stop
