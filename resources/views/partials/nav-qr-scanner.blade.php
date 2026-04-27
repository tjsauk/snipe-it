@php
    $scannerReaderId = $scannerReaderId ?? 'nav-qr-reader';
@endphp

<div data-nav-qr-scanner>
    <div class="nav-qr-modal" data-nav-qr-modal aria-hidden="true">
        <div class="nav-qr-dialog" role="dialog" aria-modal="true" aria-label="Scan QR Code">
            <div class="nav-qr-header">
                <h3 class="nav-qr-title">
                    <i class="fa fa-search fa-fw"></i>
                    <i class="fa fa-camera fa-fw"></i>
                    Scan QR Code
                </h3>
            </div>

            <div class="nav-qr-body">
                <div id="{{ $scannerReaderId }}" class="nav-qr-reader" data-nav-qr-reader></div>

                <div class="nav-qr-zoom" data-nav-qr-zoom style="display:none" aria-hidden="true">
                    <i class="fa fa-search fa-fw nav-qr-zoom-icon"></i>
                    <input type="range" class="nav-qr-zoom-slider" data-nav-qr-zoom-slider
                           min="1" max="5" step="0.1" value="1" aria-label="Camera zoom">
                    <i class="fa fa-search-plus fa-fw nav-qr-zoom-icon"></i>
                </div>

                <div class="nav-qr-actions">
                    <button type="button" class="btn btn-default" data-nav-qr-start>
                        <i class="fa fa-video-camera"></i> Open camera
                    </button>
                    <button type="button" class="btn btn-default" data-nav-qr-stop>
                        <i class="fa fa-stop"></i> Stop camera
                    </button>
                    <button type="button" class="btn btn-default" data-nav-qr-switch-camera style="display:none">
                        <i class="fa fa-refresh"></i> Switch camera
                    </button>
                    <button type="button" class="btn btn-default" data-nav-qr-take-photo style="display:none">
                        <i class="fa fa-camera"></i> Take Photo
                    </button>
                    <button type="button" class="btn btn-default" data-nav-qr-close>
                        <i class="fa fa-times"></i> Close
                    </button>
                </div>
                <input type="file" accept="image/*" capture="environment"
                       data-nav-qr-file-input style="display:none" aria-hidden="true">

                <div class="nav-qr-status" data-nav-qr-status>Camera is idle.</div>

            </div>

            <div class="nav-qr-footer">
                <div class="nav-qr-actions">
                    <button type="button" class="btn btn-primary" data-nav-qr-use-route disabled>
                        <i class="fa fa-arrow-right"></i> Use scanned route
                    </button>
                    <button type="button" class="btn btn-primary" data-nav-qr-open-tab disabled>
                        <i class="fa fa-external-link"></i> Open in new tab
                    </button>
                    <button type="button" class="btn btn-default" data-nav-qr-close>
                        <i class="fa fa-times"></i> Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
