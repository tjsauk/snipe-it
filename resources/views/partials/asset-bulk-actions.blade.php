<div id="{{ (isset($id_divname)) ? $id_divname : 'assetsBulkEditToolbar' }}" style="min-width:400px">
    <form
    method="POST"
    action="{{ route('hardware/bulkedit') }}"
    accept-charset="UTF-8"
    class="form-inline"
    id="{{ (isset($id_formname)) ? $id_formname : 'assetsBulkForm' }}"
>
    @csrf

    {{-- The sort and order will only be used if the cookie is actually empty (like on first-use) --}}
    <input name="sort" type="hidden" value="assets.id">
    <input name="order" type="hidden" value="asc">
    <label for="bulk_actions">
        <span class="sr-only">
            {{ trans('button.bulk_actions') }}
        </span>
    </label>
    <select name="bulk_actions" class="form-control select2" aria-label="bulk_actions" style="min-width: 350px !important;">
        @if ((isset($status)) && ($status == 'Deleted'))
            @can('delete', \App\Models\Asset::class)
                <option value="restore">{{trans('button.restore')}}</option>
            @endcan
        @else

            @can('update', \App\Models\Asset::class)
                <option value="edit">{{ trans('button.edit') }}</option>
                <option value="maintenance">{{ trans('button.add_maintenance') }}</option>
            @endcan

            @if((!isset($status)) || (($status != 'Deployed') && ($status != 'Archived')))
                @can('checkout', \App\Models\Asset::class)
                    <option value="checkout">{{ trans('general.bulk_checkout') }}</option>
                @endcan
                @can('view', \App\Models\Asset::class)
                    <option value="basket">{{ trans('general.basket_add_multiple') }}</option>
                @endcan
            @endif

            @can('checkin', \App\Models\Asset::class)
                <option value="checkin">{{ trans('general.bulk_checkin') }}</option>
            @endcan

            @can('delete', \App\Models\Asset::class)
                <option value="delete">{{ trans('button.delete') }}</option>
            @endcan

            <option value="labels" {{$snipeSettings->shortcuts_enabled == 1 ? "accesskey=l" : ''}}>{{ trans_choice('button.generate_labels', 2) }}</option>
        @endif
    </select>

    <button class="btn btn-theme" id="{{ (isset($id_button)) ? $id_button : 'bulkAssetEditButton' }}" disabled>{{ trans('button.go') }}</button>
    </form>

    @can('view', \App\Models\Asset::class)
    @if((!isset($status)) || (($status != 'Deployed') && ($status != 'Archived')))
    <button
        type="button"
        id="{{ (isset($id_bulkreserve)) ? $id_bulkreserve : 'bulkReserveDirectBtn' }}"
        class="btn btn-warning"
        style="display:none; margin-left:6px;"
        data-form="{{ (isset($id_formname)) ? $id_formname : 'assetsBulkForm' }}"
    >
        <i class="fa fa-shopping-cart"></i> {{ trans('general.basket_add_multiple') }}
    </button>
    @endif
    @endcan
</div>

<script>
jQuery(function ($) {
    var btnId  = '{{ (isset($id_bulkreserve)) ? $id_bulkreserve : 'bulkReserveDirectBtn' }}';
    var formId = '{{ (isset($id_formname)) ? $id_formname : 'assetsBulkForm' }}';
    var $btn   = $('#' + btnId);
    if (!$btn.length) return;

    function getCount() {
        return $('.snipe-table').bootstrapTable('getSelections').length;
    }

    function sync() {
        $btn.toggle(getCount() >= 2);
    }

    $(document).on('check.bs.table uncheck.bs.table check-all.bs.table uncheck-all.bs.table', '.snipe-table', sync);

    $btn.on('click', function () {
        var $form = $('#' + formId);
        $form.find('select[name="bulk_actions"]').val('basket');
        $form.submit();
    });
});
</script>
