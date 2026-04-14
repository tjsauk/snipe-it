@extends('layouts/default')

@section('title')
    {{ trans('general.bulk_checkin') }}
    @parent
@stop

@section('content')

<div class="row">
    <div class="col-md-7">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">{{ trans('general.bulk_checkin') }}</h2>
            </div>
            <div class="box-body">
                <form class="form-horizontal" method="POST"
                      action="{{ route('hardware.bulkcheckin.store') }}" autocomplete="off">
                    {{ csrf_field() }}

                    {{-- Hidden asset IDs --}}
                    @foreach($assets as $asset)
                        <input type="hidden" name="selected_assets[]" value="{{ $asset->id }}">
                    @endforeach

                    {{-- Asset list --}}
                    <div class="form-group">
                        <label class="col-md-3 control-label">{{ trans('general.assets') }}</label>
                        <div class="col-md-8">
                            <table class="table table-condensed" style="margin-bottom:0;">
                                <thead>
                                    <tr>
                                        <th>{{ trans('admin/hardware/table.asset_tag') }}</th>
                                        <th>{{ trans('general.name') }}</th>
                                        <th>{{ trans('general.checkedout_to') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($assets as $asset)
                                        <tr>
                                            <td>
                                                <a href="{{ route('hardware.show', $asset->id) }}" target="_blank">
                                                    {{ $asset->asset_tag }}
                                                </a>
                                            </td>
                                            <td>{{ $asset->name }}</td>
                                            <td>
                                                @if($asset->assignedTo)
                                                    {{ $asset->assignedTo->present()->fullName() ?? $asset->assignedTo->name ?? '—' }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Optional note --}}
                    <div class="form-group">
                        <label for="note" class="col-sm-3 control-label">{{ trans('general.notes') }}</label>
                        <div class="col-md-8">
                            <textarea class="form-control" id="note" name="note" rows="3">{{ old('note') }}</textarea>
                        </div>
                    </div>

                </div>{{-- /.box-body --}}

                <div class="box-footer">
                    <a class="btn btn-link" href="{{ URL::previous() }}">{{ trans('button.cancel') }}</a>
                    <button type="submit" class="btn btn-primary pull-right">
                        <x-icon type="checkmark" /> {{ trans('general.bulk_checkin') }}
                        ({{ $assets->count() }})
                    </button>
                </div>

                </form>
            </div>
        </div>
    </div>
</div>

@stop
