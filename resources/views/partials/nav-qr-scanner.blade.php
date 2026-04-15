{{--
    QR-skanneri modal – sisältyy default.blade.php:ssä
    CSS ja JS ladataan erikseen layoutissa.
--}}
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

                <div class="nav-qr-actions">
                    <button type="button" class="btn btn-default" data-nav-qr-start>
                        <i class="fa fa-video-camera"></i> Avaa kamera
                    </button>
                    <button type="button" class="btn btn-default" data-nav-qr-stop>
                        <i class="fa fa-stop"></i> Pysäytä kamera
                    </button>
                    <button type="button" class="btn btn-default" data-nav-qr-close>
                        <i class="fa fa-times"></i> Sulje
                    </button>
                </div>

                <div class="nav-qr-status" data-nav-qr-status>Kamera odottaa.</div>

                <div class="nav-qr-box">
                    <label class="nav-qr-box-label" for="nav-qr-manual-input">Manuaalinen syöte</label>
                    <input
                        id="nav-qr-manual-input"
                        type="text"
                        class="nav-qr-manual-input"
                        data-nav-qr-manual-input
                        placeholder="Liitä QR-URL tai polku joka sisältää /reitti"
                    >
                    <div class="nav-qr-actions">
                        <button type="button" class="btn btn-default" data-nav-qr-parse-manual>
                            <i class="fa fa-search"></i> Tulkitse arvo
                        </button>
                    </div>
                </div>

                <div class="nav-qr-box">
                    <span class="nav-qr-box-label">Skannattu arvo</span>
                    <div class="nav-qr-mono" data-nav-qr-raw>—</div>
                </div>

                <div class="nav-qr-box">
                    <span class="nav-qr-box-label">Tunnistettu reitti</span>
                    <div class="nav-qr-mono" data-nav-qr-route>—</div>
                </div>

                <div class="nav-qr-box">
                    <span class="nav-qr-box-label">Reittimatch</span>
                    <div data-nav-qr-match>Ei skannausta vielä.</div>
                </div>
            </div>

            <div class="nav-qr-footer">
                <div class="nav-qr-actions">
                    <button type="button" class="btn btn-primary" data-nav-qr-use-route disabled>
                        <i class="fa fa-arrow-right"></i> Avaa reitti
                    </button>
                    <button type="button" class="btn btn-primary" data-nav-qr-open-tab disabled>
                        <i class="fa fa-external-link"></i> Avaa tässä välilehdessä
                    </button>
                    <button type="button" class="btn btn-default" data-nav-qr-close>
                        <i class="fa fa-times"></i> Peruuta
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
