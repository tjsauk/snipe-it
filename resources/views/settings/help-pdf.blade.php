@extends('layouts/default')

@section('title')
    Help PDF
@parent
@stop

@section('header_right')
    <a href="{{ route('settings.index') }}" class="btn btn-default pull-right">
        {{ trans('general.back') }}
    </a>
@stop

@section('content')
<div class="row">
    <div class="col-md-6 col-md-offset-3">

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <x-icon type="help" class="fa-fw"/>
                    Help PDF
                </h3>
            </div>
            <div class="box-body">

                @if($hasPdf)
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        A Help PDF is currently uploaded.
                        <a href="{{ \App\Helpers\Helper::helpPdfUrl() }}" target="_blank" rel="noopener" class="alert-link">
                            View PDF
                        </a>
                    </div>
                @else
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        No Help PDF has been uploaded yet.
                    </div>
                @endif

                <form method="POST" action="{{ route('settings.help-pdf.upload') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="form-group {{ $errors->has('help_pdf') ? 'has-error' : '' }}">
                        <label for="help_pdf">Upload new Help PDF (max 50 MB)</label>
                        <input type="file" name="help_pdf" id="help_pdf" accept="application/pdf" class="form-control">
                        @if($errors->has('help_pdf'))
                            <span class="help-block">{{ $errors->first('help_pdf') }}</span>
                        @endif
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Upload
                    </button>
                </form>

                @if($hasPdf)
                    <hr>
                    <form method="POST" action="{{ route('settings.help-pdf.destroy') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger"
                            onclick="return confirm('Remove the Help PDF?')">
                            <i class="fas fa-trash"></i> Remove PDF
                        </button>
                    </form>
                @endif

            </div>
        </div>
    </div>
</div>
@stop
