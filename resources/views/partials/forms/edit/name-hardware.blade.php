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

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const input       = document.getElementById('name');
    const list        = document.getElementById('name_suggestions');
    const hiddenId    = document.getElementById('template_asset_id');
    const searchUrl   = "{{ route('hardware.template-asset') }}"; // <-- uses this route

    let currentResults = [];

    if (!input) return;

    function clearSuggestions() {
        list.style.display = 'none';
        list.innerHTML = '';
        currentResults = [];
    }

    input.addEventListener('input', function () {
        const q = input.value.trim();

        // Reset template selection if user edits again
        hiddenId.value = '';

        if (q.length < 2) {
            clearSuggestions();
            return;
        }

        fetch(searchUrl + '?q=' + encodeURIComponent(q), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            }
        })
        .then(r => r.ok ? r.json() : [])
        .then(data => {
            currentResults = Array.isArray(data) ? data : [];
            if (!currentResults.length) {
                clearSuggestions();
                return;
            }

            list.innerHTML = '';
            currentResults.forEach(asset => {
                const li = document.createElement('li');
                li.className = 'list-group-item list-group-item-action';
                li.textContent = asset.name + ' (' + asset.asset_tag + ')';
                li.addEventListener('click', function () {
                    input.value        = asset.name;
                    hiddenId.value     = asset.id;

                    // You can later use template_asset_id server-side to copy
                    // model, supplier, image, files, etc. on save.
                    clearSuggestions();
                });
                list.appendChild(li);
            });

            list.style.display = 'block';
        })
        .catch(() => {
            clearSuggestions();
        });
    });

    document.addEventListener('click', function (e) {
        if (!list.contains(e.target) && e.target !== input) {
            clearSuggestions();
        }
    });
});
</script>
@endpush

