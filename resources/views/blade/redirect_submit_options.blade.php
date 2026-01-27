<!-- begin redirect submit options -->
@props([
    'index_route',
    'button_label',
    'disabled_select' => false,
    'options' => [],
    'id' => 'submit_button',
    'return_to' => null,  {{-- NEW --}}
])

@php
    // Priority:
    // 1) explicit prop
    // 2) old input (after validation errors)
    // 3) session stored in controller
    // 4) index route fallback
    // 5) previous url fallback
    $cancelUrl =
        $return_to
        ?? old('return_to')
        ?? session('return_to')
        ?? ($index_route ? route($index_route) : null)
        ?? url()->previous();
@endphp

<div class="box-footer">
    <div class="row">

        <div class="col-md-3">
            <a class="btn btn-link" href="{{ $cancelUrl }}">{{ trans('button.cancel') }}</a>
        </div>

        <div class="col-md-9 text-right">
            <div class="btn-group text-left">

                @if (($options) && (count($options) > 0))
                <select class="redirect-options form-control select2"
                        data-minimum-results-for-search="Infinity"
                        name="redirect_option"
                        style="min-width: 250px"
                        {{ ($disabled_select ? ' disabled' : '') }}>
                    @foreach ($options as $key => $value)
                        <option value="{{ $key }}"{{ Session::get('redirect_option') == $key ? ' selected' : ''}}>
                            {{ $value }}
                        </option>
                    @endforeach
                </select>
                @endif

                <button type="submit"
                        id="{{ $id }}"
                        class="btn btn-success pull-right{{ ($disabled_select ? ' disabled' : '') }}"
                        style="margin-left:5px; border-radius: 3px;"
                        {!! ($disabled_select ? ' data-tooltip="true" title="'.trans('admin/hardware/general.edit').'" disabled' : '') !!}>
                    <x-icon type="checkmark" />
                    {{ $button_label }}
                </button>

            </div>
        </div>
    </div>
</div>
<!-- end redirect submit options -->
