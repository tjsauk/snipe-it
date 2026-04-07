@php
    $bannerUser   = auth()->user();
    $isNewUser    = $bannerUser && $bannerUser->groups->contains('name', 'New Users');
    $superUsers   = \App\Models\User::where(function($q) {
                        $q->where('permissions', 'LIKE', '%"superuser":"1"%')
                          ->orWhere('permissions', 'LIKE', '%"superuser":1%');
                    })
                    ->whereNull('deleted_at')
                    ->where('activated', 1)
                    ->whereNotNull('email')
                    ->where('email', '!=', '')
                    ->orderBy('last_name')
                    ->get(['first_name', 'last_name', 'email']);
    $helpPdfUrl   = \App\Helpers\Helper::helpPdfUrl();
@endphp

@if($isNewUser)
<div class="row" style="margin-bottom: 15px;">
    <div class="col-md-12">
        <div class="alert alert-info" style="margin-bottom: 0;">
            <h4 style="margin-top: 0;"><i class="fas fa-info-circle"></i> Welcome!</h4>
            <p>
                Your account currently has limited access. To request the necessary permissions,
                please contact one of the administrators below:
            </p>
            <ul style="margin-bottom: 10px;">
                @foreach($superUsers as $su)
                    <li>
                        {{ trim($su->first_name . ' ' . $su->last_name) }}
                        &mdash;
                        <a href="mailto:{{ $su->email }}">{{ $su->email }}</a>
                    </li>
                @endforeach
            </ul>
            @if($helpPdfUrl)
            <p style="margin-bottom: 0;">
                <i class="fa-solid fa-circle-question"></i>
                For instructions on how to use this site, click the <strong>Help</strong> link
                (<i class="fa-solid fa-circle-question"></i>) in the left sidebar.
            </p>
            @endif
        </div>
    </div>
</div>
@else
<div class="row" style="margin-bottom: 15px;">
    <div class="col-md-12">
        <div class="alert alert-info" style="margin-bottom: 0;">
            @if($helpPdfUrl)
            <p style="margin-bottom: 8px;">
                <i class="fa-solid fa-circle-question"></i>
                For instructions on how to use this site, click the <strong>Help</strong> link
                (<i class="fa-solid fa-circle-question"></i>) in the left sidebar.
            </p>
            @endif
            <p style="margin-bottom: 0;">
                If you need assistance or have found a critical issue, please contact one of the
                administrators below:
            </p>
            <ul style="margin-bottom: 0; margin-top: 8px;">
                @foreach($superUsers as $su)
                    <li>
                        {{ trim($su->first_name . ' ' . $su->last_name) }}
                        &mdash;
                        <a href="mailto:{{ $su->email }}">{{ $su->email }}</a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
@endif
