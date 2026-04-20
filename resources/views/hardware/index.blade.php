@extends('layouts/default')

@section('title0')

  @if ((Request::get('company_id')) && ($company))
    {{ $company->name }}
  @endif



@if (Request::get('status'))
  @if (Request::get('status')=='Pending')
    {{ trans('general.pending') }}
  @elseif (Request::get('status')=='RTD')
    {{ trans('general.ready_to_deploy') }}
  @elseif (Request::get('status')=='Deployed')
    {{ trans('general.deployed') }}
  @elseif (Request::get('status')=='Undeployable')
    {{ trans('general.undeployable') }}
  @elseif (Request::get('status')=='Deployable')
    {{ trans('general.deployed') }}
  @elseif (Request::get('status')=='Requestable')
    {{ trans('admin/hardware/general.requestable') }}
  @elseif (Request::get('status')=='Archived')
    {{ trans('general.archived') }}
  @elseif (Request::get('status')=='Deleted')
    {{ ucfirst(trans('general.deleted')) }}
  @elseif (Request::get('status')=='byod')
    {{ strtoupper(trans('general.byod')) }}
  @endif
@else
{{ trans('general.all') }}
@endif
{{ trans('general.assets') }}

  @if (Request::has('order_number'))
    : Order #{{ strval(Request::get('order_number')) }}
  @endif
@stop

{{-- Page title --}}
@section('title')
@yield('title0')  @parent
@stop


{{-- Page content --}}
@section('content')



<div class="row">
  <div class="col-md-12">
    <div class="box box-default">
      <div class="box-body">

        <div class="nav-tabs-custom" style="margin-bottom: 0; box-shadow: none;">
          <ul class="nav nav-tabs">
            <li class="active"><a href="#assets-list-tab" data-toggle="tab"><i class="fa fa-bars"></i> List</a></li>
            <li><a href="#assets-calendar-tab" data-toggle="tab" id="assets-calendar-nav-tab"><i class="fa fa-calendar"></i> Calendar</a></li>
          </ul>
          <div class="tab-content">
            <div class="tab-pane active" id="assets-list-tab">
          <div class="row">
            <div class="col-md-12">

                @include('partials.asset-bulk-actions', ['status' => Request::get('status')])

              <table
                data-columns="{{ \App\Presenters\AssetPresenter::dataTableLayout() }}"
                data-cookie-id-table="{{ request()->has('status') ? e(request()->input('status')) : ''  }}assetsListingTable"
                data-id-table="{{ request()->has('status') ? e(request()->input('status')) : ''  }}assetsListingTable"
                data-side-pagination="server"
                data-show-footer="true"
                data-sort-order="asc"
                data-sort-name="name"
                data-search-text="{{ session()->get('search') }}"
                data-show-columns-search="true"
                data-toolbar="#assetsBulkEditToolbar"
                data-bulk-button-id="#bulkAssetEditButton"
                data-bulk-form-id="#assetsBulkForm"
                data-buttons="assetButtons"
                id="{{ request()->has('status') ? e(request()->input('status')) : ''  }}assetsListingTable"
                class="table table-striped snipe-table"
                data-url="{{ route('api.assets.index',
                    array('status' => e(Request::get('status')),
                    'order_number'=>e(strval(Request::get('order_number'))),
                    'company_id'=>e(Request::get('company_id')),
                    'status_id'=>e(Request::get('status_id')))) }}"
                data-export-options='{
                "fileName": "export{{ (Request::has('status')) ? '-'.str_slug(Request::get('status')) : '' }}-assets-{{ date('Y-m-d') }}",
                "ignoreColumn": ["actions","image","change","checkbox","checkincheckout","icon"]
                }'>
              </table>

            </div><!-- /.col -->
          </div><!-- /.row -->

            </div>{{-- /.tab-pane#assets-list-tab --}}

            <div class="tab-pane" id="assets-calendar-tab">
              <div style="padding: 15px;">
                <p class="text-muted" id="assets-calendar-hint">
                  <i class="fa fa-info-circle"></i>
                  Select assets using the checkboxes in the List tab, then switch here to view reservations and check out assets to yourself.
                </p>
                <div id="assets-cal-root"></div>
              </div>
            </div>{{-- /.tab-pane#assets-calendar-tab --}}

          </div>{{-- /.tab-content --}}
        </div>{{-- /.nav-tabs-custom --}}

      </div><!-- ./box-body -->
    </div><!-- /.box -->
  </div>
</div>
@stop

@section('moar_scripts')
@include('partials.bootstrap-table')
<script src="{{ asset('vendor/asset-calendar/asset-calendar.js') }}"></script>
<script>
(function() {
    var currentUser   = @json(Auth::user()->username ?? (string)Auth::id());
    var currentUserId = @json(Auth::id());
    var csrfToken     = (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute('content') || '';
    var tableId       = '{{ (request()->has('status') ? e(request()->input('status')) : '') }}assetsListingTable';
    var bulkReserveUrl = '{{ route('hardware.bulkreserve.store') }}';

    jQuery('#assets-calendar-nav-tab').on('shown.bs.tab', loadAssetsCalendar);

    function getSelectedIds() {
        var idSet = {};
        // Hidden inputs persist across pagination pages
        jQuery('input[name="ids[]"]').each(function() {
            if (this.value) idSet[this.value] = true;
        });
        // Current page selections (may not overlap with hidden inputs on first check)
        try {
            var selections = jQuery('#' + tableId).bootstrapTable('getSelections');
            if (selections) {
                selections.forEach(function(r) { if (r.id) idSet[r.id] = true; });
            }
        } catch(e) {}
        return Object.keys(idSet);
    }

    function onCalendarConfirm(output) {
        if (!output || !output.assets) return;
        var hasAny = output.assets.some(function(a) {
            return a.selectedPeriods && a.selectedPeriods.length > 0;
        });
        if (!hasAny) {
            alert('No available periods found for the selected assets in this time range.');
            return;
        }

        var ids  = getSelectedIds();
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = bulkReserveUrl;
        form.style.display = 'none';

        function addHidden(name, value) {
            var el = document.createElement('input');
            el.type = 'hidden'; el.name = name; el.value = value;
            form.appendChild(el);
        }

        addHidden('_token', csrfToken);
        addHidden('_from_quick', '1');
        addHidden('checkout_to_type', 'user');
        addHidden('assigned_user', currentUserId);

        ids.forEach(function(id) { addHidden('selected_assets[]', id); });

        addHidden('periods_json', JSON.stringify(output.assets));

        document.body.appendChild(form);
        form.submit();
    }

    function loadAssetsCalendar() {
        var ids    = getSelectedIds();
        var hint   = document.getElementById('assets-calendar-hint');
        var rootEl = document.getElementById('assets-cal-root');

        if (ids.length === 0) {
            if (hint) hint.style.display = '';
            rootEl.innerHTML = '';
            return;
        }
        if (hint) hint.style.display = 'none';

        var headers = { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken };

        fetch('/api/v1/hardware/calendar-ranges?ids=' + ids.join(','), { headers: headers })
        .then(function(r) { return r.json(); })
        .then(function(assets) {
            if (typeof window.assetCalendarMount === 'function') {
                window.assetCalendarMount(rootEl, {
                    mode: 'reserve',
                    currentUser: currentUser,
                    continuousCutMode: false,
                    assets: assets
                }, onCalendarConfirm);
            }
        });
    }
})();
</script>
@stop
