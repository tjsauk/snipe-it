@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/hardware/general.checkin') }}
    @parent
@stop

{{-- Page content --}}
@section('content')
    <style>

        .input-group {
            padding-left: 0px !important;
        }
    </style>


    <div class="row"><!-- .row -->
        <!-- left column -->
        <div class="col-md-7 col-sm-11 col-xs-12 col-md-offset-2">
            <div class="box box-default"><!-- .box-default -->
                <div class="box-header with-border"><!-- .box-header -->
                    <h2 class="box-title">
                        {{ trans('admin/hardware/form.tag') }}
                        {{ $asset->asset_tag }}
                    </h2>
                </div><!-- /.box-header -->

                <div class="box-body"><!-- .box-body -->
                    <div class="col-md-12"><!-- .col-md-12 -->

                        @if ($backto == 'user')
                            <form class="form-horizontal" method="post"
                                  action="{{ route('hardware.checkin.store', array('assetId'=> $asset->id, 'backto'=>'user')) }}"
                                  autocomplete="off">
                                @else
                                    <form class="form-horizontal" method="post"
                                          action="{{ route('hardware.checkin.store', array('assetId'=> $asset->id)) }}"
                                          autocomplete="off">
                                        @endif
                                        {{csrf_field()}}

                                        <input type="hidden" name="return_to" value="{{ old('return_to', request('return_to') ?? ($return_to ?? session('return_to'))) }}">

                                        
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
                                            <label for="model" class="col-sm-3 control-label">
                                                {{ trans('admin/hardware/form.model') }}
                                            </label>
                                            <div class="col-md-8">

                                                <p class="form-control-static">
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

                                        <!-- Asset Name (read-only) -->
                                        <div class="form-group">
                                            <label for="name" class="col-sm-3 control-label">
                                                {{ trans('general.name') }}
                                            </label>
                                            <div class="col-md-8">
                                                <p class="form-control-static">
                                                    {{ $asset->name }}
                                                </p>
                                                <input type="hidden" name="name" value="{{ $asset->name }}">
                                            </div>
                                        </div>

                                        <!-- Status -->
                                        <div class="form-group {{ $errors->has('status_id') ? 'error' : '' }}">
                                            <label for="status_id" class="col-sm-3 control-label">
                                                {{ trans('admin/hardware/form.status') }}
                                            </label>
                                            <div class="col-md-8 required">
                                                <x-input.select
                                                    name="status_id"
                                                    id="modal-statuslabel_types"
                                                    :options="$statusLabel_list"
                                                    style="width: 100%"
                                                    aria-label="status_id"
                                                />
                                                {!! $errors->first('status_id', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                                <small class="help-block">Select <strong>Maintenance</strong> if the asset is broken.</small>
                                            </div>
                                        </div>

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
                                                'show_custom_fields_type' => 'checkin'
                                        ])


                    </div> <!--/.box-body-->
                </div> <!--/.box-body-->

                <x-redirect_submit_options
                    index_route="hardware.index"
                    :return_to="old('return_to', request('return_to') ?? ($return_to ?? session('return_to')))"
                    :button_label="trans('general.checkin')"
                    :disabled_select="!$asset->model"
                    :options="[
                        'index' => trans('admin/hardware/form.redirect_to_all', ['type' => trans('general.assets')]),
                        'item' => trans('admin/hardware/form.redirect_to_type', ['type' => trans('general.asset')]),
                        'target' => $target_option,
                    ]"
                />

                </form>

            </div>
        </div>
    </div>

@stop
