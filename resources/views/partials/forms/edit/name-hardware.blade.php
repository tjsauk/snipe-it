<div class="form-group {{ $errors->has('name') ? ' has-error' : '' }}">
    <label for="name" class="col-md-3 control-label">{{ $translated_name }}</label>
    <div class="col-md-8 col-sm-12">
        <input class="form-control" style="width:100%;" type="text"
               name="name" aria-label="name" id="name"
               value="{{ old('name', $item->name) }}"
               {!!  (Helper::checkIfRequired($item, 'name')) ? ' required' : '' !!}
               maxlength="191" autocomplete="off" />

        {{-- hidden field for selected template asset id --}}
        <input type="hidden" name="template_asset_id" id="template_asset_id" value="">

        <ul id="name_suggestions"
            class="list-group"
            style="position:absolute; z-index:1000; width:100%; display:none;"></ul>

        {!! $errors->first('name', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
        <p class="help-block">
            Start typing to reuse an existing asset as a template. Leave as-is for manual entry.
        </p>
    </div>
</div>
