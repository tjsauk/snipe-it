
(function (window, document) {
  'use strict';

  function extend(target) {
    for (var i = 1; i < arguments.length; i += 1) {
      var source = arguments[i] || {};
      Object.keys(source).forEach(function (key) {
        target[key] = source[key];
      });
    }
    return target;
  }

  function $(selector, root) {
    return (root || document).querySelector(selector);
  }

  function SnipeItNavQrScanner(options) {
    this.options = extend({
      containerSelector: '[data-nav-qr-scanner]',
      buttonSelector: '[data-nav-qr-open]',
      modalSelector: '[data-nav-qr-modal]',
      readerSelector: '[data-nav-qr-reader]',
      rawValueSelector: '[data-nav-qr-raw]',
      routeValueSelector: '[data-nav-qr-route]',
      matchValueSelector: '[data-nav-qr-match]',
      manualInputSelector: '[data-nav-qr-manual-input]',
      parseManualSelector: '[data-nav-qr-parse-manual]',
      useRouteSelector: '[data-nav-qr-use-route]',
      openInTabSelector: '[data-nav-qr-open-tab]',
      closeSelector: '[data-nav-qr-close]',
      stopSelector: '[data-nav-qr-stop]',
      startSelector: '[data-nav-qr-start]',
      statusSelector: '[data-nav-qr-status]',
      fileInputSelector: '[data-nav-qr-file-input]',
      takePhotoSelector: '[data-nav-qr-take-photo]',
      zoomSelector: '[data-nav-qr-zoom]',
      zoomSliderSelector: '[data-nav-qr-zoom-slider]',
      switchCameraSelector: '[data-nav-qr-switch-camera]',
      autoStart: true,
      confirmBeforeNavigate: true,
      scannerScriptReadyCheck: function () {
        return typeof window.Html5Qrcode !== 'undefined';
      },
      patterns: [
        { name: 'Asset by tag', regex: /^\/hardware\/bytag\/([^\ /?#]+)([?#].*)?$/i },
        { name: 'Asset by numeric id', regex: /^\/hardware\/(\d+)([?#].*)?$/i },
        { name: 'Location by numeric id', regex: /^\/locations\/(\d+)(#rtd_assets)?([?#].*)?$/i }
      ]
    }, options || {});

    this.root = null;
    this.button = null;
    this.modal = null;
    this.reader = null;
    this.manualInput = null;
    this.rawValue = null;
    this.routeValue = null;
    this.matchValue = null;
    this.useRouteButton = null;
    this.openInTabButton = null;
    this.status = null;
    this.html5QrCode = null;
    this.currentRoute = null;
    this.currentRawValue = null;
    this._cameraList = [];
  }

  SnipeItNavQrScanner.prototype.init = function () {
    this.root = $(this.options.containerSelector);
    if (!this.root) return;

    this.button = $(this.options.buttonSelector, this.root);
    this.modal = $(this.options.modalSelector, this.root);
    this.reader = $(this.options.readerSelector, this.root);
    this.manualInput = $(this.options.manualInputSelector, this.root);
    this.rawValue = $(this.options.rawValueSelector, this.root);
    this.routeValue = $(this.options.routeValueSelector, this.root);
    this.matchValue = $(this.options.matchValueSelector, this.root);
    this.useRouteButton = $(this.options.useRouteSelector, this.root);
    this.openInTabButton = $(this.options.openInTabSelector, this.root);
    this.status = $(this.options.statusSelector, this.root);

    this.bindEvents();
    this.bindFileInput();
    this.bindZoom();
    this.bindTapToFocus();
  };

  SnipeItNavQrScanner.prototype.bindEvents = function () {
    var self = this;

    if (this.button) {
      this.button.addEventListener('click', function () {
        self.open();
      });
    }

    this.root.querySelectorAll(this.options.closeSelector).forEach(function (btn) {
      btn.addEventListener('click', function () {
        self.close();
      });
    });

    var stop = $(this.options.stopSelector, this.root);
    if (stop) {
      stop.addEventListener('click', function () {
        self.stopScanner();
      });
    }

    var start = $(this.options.startSelector, this.root);
    if (start) {
      start.addEventListener('click', function () {
        self.startScanner();
      });
    }

    var parseManual = $(this.options.parseManualSelector, this.root);
    if (parseManual) {
      parseManual.addEventListener('click', function () {
        self.applyScanValue(self.manualInput ? self.manualInput.value : '');
      });
    }

    var switchCamera = $(this.options.switchCameraSelector, this.root);
    if (switchCamera) {
      switchCamera.addEventListener('click', function () {
        self.switchCamera();
      });
    }

    if (this.useRouteButton) {
      this.useRouteButton.addEventListener('click', function () {
        self.navigateToCurrentRoute();
      });
    }

    if (this.openInTabButton) {
      this.openInTabButton.addEventListener('click', function () {
        self.openInNewTab();
      });
    }

    if (this.modal) {
      this.modal.addEventListener('click', function (event) {
        if (event.target === self.modal) {
          self.close();
        }
      });
    }
  };

  SnipeItNavQrScanner.prototype.bindFileInput = function () {
    var self = this;
    var fileInput = $(this.options.fileInputSelector, this.root);
    var takePhotoBtn = $(this.options.takePhotoSelector, this.root);

    if (takePhotoBtn && fileInput) {
      takePhotoBtn.addEventListener('click', function () {
        fileInput.value = '';
        fileInput.click();
      });
    }

    if (fileInput) {
      fileInput.addEventListener('change', function () {
        var file = fileInput.files && fileInput.files[0];
        if (!file) return;
        self.scanFromFile(file);
      });
    }
  };

  SnipeItNavQrScanner.prototype.bindZoom = function () {
    var self = this;
    var slider = $(this.options.zoomSliderSelector, this.root);
    if (!slider) return;
    slider.addEventListener('input', function () {
      self.applyZoom(parseFloat(slider.value));
    });
  };

  SnipeItNavQrScanner.prototype.bindTapToFocus = function () {
    var self = this;
    if (!this.reader) return;
    this.reader.addEventListener('click', function (e) {
      self.handleTapToFocus(e.clientX, e.clientY);
    });
    this.reader.addEventListener('touchend', function (e) {
      if (e.changedTouches.length === 1) {
        e.preventDefault();
        self.handleTapToFocus(e.changedTouches[0].clientX, e.changedTouches[0].clientY);
      }
    }, { passive: false });
  };

  SnipeItNavQrScanner.prototype.handleTapToFocus = function (clientX, clientY) {
    if (!this._focusTrack) return;
    var rect = this.reader.getBoundingClientRect();
    var x = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
    var y = Math.max(0, Math.min(1, (clientY - rect.top) / rect.height));
    this.showFocusRing(clientX - rect.left, clientY - rect.top);
    this.refocusCamera(x, y);
  };

  SnipeItNavQrScanner.prototype.refocusCamera = function (x, y) {
    var track = this._focusTrack;
    if (!track) return;
    var caps = track.getCapabilities ? track.getCapabilities() : {};
    var hasPoi = typeof x === 'number' && typeof y === 'number';

    if (caps.focusMode && caps.focusMode.indexOf('continuous') !== -1) {
      // Single-shot → continuous cycle forces immediate re-focus on many Androids
      // (Samsung etc. won't re-trigger if already in continuous)
      var cycleConstraints = [{ focusMode: 'single-shot' }];
      if (hasPoi) cycleConstraints.unshift({ focusMode: 'single-shot', pointOfInterest: { x: x, y: y } });
      track.applyConstraints({ advanced: cycleConstraints })
        .catch(function () {})
        .then(function () {
          var contConstraints = [{ focusMode: 'continuous' }];
          if (hasPoi) contConstraints.unshift({ focusMode: 'continuous', pointOfInterest: { x: x, y: y } });
          return track.applyConstraints({ advanced: contConstraints });
        })
        .catch(function () {});
      return;
    }

    if (caps.focusMode && caps.focusMode.indexOf('single-shot') !== -1) {
      track.applyConstraints({ advanced: [{ focusMode: 'single-shot' }] }).catch(function () {});
      return;
    }

    if (caps.focusMode && caps.focusMode.indexOf('manual') !== -1 && hasPoi) {
      track.applyConstraints({ advanced: [{ focusMode: 'manual', pointOfInterest: { x: x, y: y } }] })
        .catch(function () {});
      return;
    }

    // Zoom nudge — triggers autofocus on devices with no focusMode support
    if (caps.zoom) {
      var settings = track.getSettings ? track.getSettings() : {};
      var current = settings.zoom || caps.zoom.min || 1;
      var nudged = Math.min(caps.zoom.max, current + 0.01);
      track.applyConstraints({ advanced: [{ zoom: nudged }] })
        .then(function () {
          return track.applyConstraints({ advanced: [{ zoom: current }] });
        })
        .catch(function () {});
      return;
    }

    // Blind attempt — getCapabilities() may have returned incomplete data at camera start;
    // try continuous autofocus anyway, failures are silent
    track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(function () {});
  };

  SnipeItNavQrScanner.prototype.showFocusRing = function (x, y) {
    var el = document.createElement('div');
    el.className = 'nav-qr-focus-ring';
    el.style.left = x + 'px';
    el.style.top = y + 'px';
    this.reader.appendChild(el);
    setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 900);
  };

  SnipeItNavQrScanner.prototype.applyZoom = function (value) {
    var self = this;
    if (this._zoomTrack) {
      this._zoomTrack.applyConstraints({ advanced: [{ zoom: value }] })
        .then(function () { self.refocusCamera(); })
        .catch(function () {});
    } else {
      this.applyCssZoom(value);
    }
  };

  SnipeItNavQrScanner.prototype.applyCssZoom = function (value) {
    var video = this.reader && this.reader.querySelector('video');
    if (video) video.style.transform = value === 1 ? '' : 'scale(' + value + ')';
  };

  SnipeItNavQrScanner.prototype.scanFromFile = function (file) {
    var self = this;
    if (!this.options.scannerScriptReadyCheck()) {
      this.setStatus('Scanner library is not loaded.', 'is-bad');
      return;
    }
    this.setStatus('Processing image…', '');
    var scanner = new window.Html5Qrcode(this.reader.id);
    scanner.scanFile(file, false)
      .then(function (decodedText) {
        if (self.manualInput) self.manualInput.value = decodedText;
        self.applyScanValue(decodedText);
      })
      .catch(function () {
        self.setStatus('No QR code found in image. Try a clearer photo.', 'is-bad');
      });
  };

  SnipeItNavQrScanner.prototype.setStatus = function (message, stateClass) {
    if (!this.status) return;
    this.status.className = 'nav-qr-status' + (stateClass ? ' ' + stateClass : '');
    this.status.textContent = message || '';
  };

  SnipeItNavQrScanner.prototype.open = function () {
    if (!this.modal) return;
    var takePhotoBtn = $(this.options.takePhotoSelector, this.root);
    if (takePhotoBtn) takePhotoBtn.style.display = 'none';
    var zoomEl = $(this.options.zoomSelector, this.root);
    if (zoomEl) { zoomEl.style.display = 'none'; zoomEl.setAttribute('aria-hidden', 'true'); }
    var slider = $(this.options.zoomSliderSelector, this.root);
    if (slider) slider.value = '1';
    this.modal.classList.add('is-open');
    if (this.options.autoStart) {
      this.startScanner();
    }
  };

  SnipeItNavQrScanner.prototype.close = function () {
    if (!this.modal) return;
    this.stopScanner();
    this.modal.classList.remove('is-open');
  };

  SnipeItNavQrScanner.prototype.startScanner = function () {
    var self = this;

    if (!this.options.scannerScriptReadyCheck()) {
      this.setStatus('Scanner library is not loaded.', 'is-bad');
      return;
    }

    if (!this.reader) {
      this.setStatus('Scanner container not found.', 'is-bad');
      return;
    }

    if (this.html5QrCode) {
      return;
    }

    this.html5QrCode = new window.Html5Qrcode(this.reader.id);

    var qrbox = function (width, height) {
      var edge = Math.floor(Math.min(width, height) * 0.75);
      return { width: edge, height: edge };
    };

    var onDecode = function (decodedText) {
      if (self.manualInput) self.manualInput.value = decodedText;
      self.applyScanValue(decodedText);
    };

    var onSuccess = function () { self._onCameraReady(); };

    var onFail = function () {
      self.html5QrCode = null;
      self.setStatus('Camera blocked — tap “Take Photo” to use your device camera app instead.', 'is-warn');
      var takePhotoBtn = $(self.options.takePhotoSelector, self.root);
      if (takePhotoBtn) takePhotoBtn.style.display = '';
    };

    // Attempt 1: rear camera string form (widest Android compatibility) + zoom
    self.html5QrCode.start(
      { facingMode: 'environment' },
      { fps: 10, qrbox: qrbox, rememberLastUsedCamera: true,
        videoConstraints: { facingMode: { ideal: 'environment' }, advanced: [{ zoom: 2.0 }] } },
      onDecode, function () {}
    )
    .then(onSuccess)
    .catch(function () {
      // Attempt 2: rear camera string form, no zoom
      self.html5QrCode.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: qrbox, rememberLastUsedCamera: true },
        onDecode, function () {}
      )
      .then(onSuccess)
      .catch(function () {
        // Attempt 3: any camera (no facing preference)
        self.html5QrCode.start(
          { facingMode: 'user' },
          { fps: 10, qrbox: qrbox },
          onDecode, function () {}
        )
        .then(onSuccess)
        .catch(onFail);
      });
    });
  };

  SnipeItNavQrScanner.prototype._onCameraReady = function () {
    var self = this;
    self.setStatus('Camera opened. Point at an asset or location QR code.', 'is-good');
    var video = self.reader && self.reader.querySelector('video');
    var stream = video && video.srcObject;
    var track = stream && stream.getVideoTracks && stream.getVideoTracks()[0];
    var caps = track && track.getCapabilities && track.getCapabilities();
    self._zoomTrack = (caps && caps.zoom) ? track : null;
    self._focusTrack = track || null;
    if (self._focusTrack) {
      self.reader.classList.add('is-focusable');
      setTimeout(function () { self.refocusCamera(); }, 600);
    }
    var zoomEl = $(self.options.zoomSelector, self.root);
    if (zoomEl) { zoomEl.style.display = ''; zoomEl.removeAttribute('aria-hidden'); }
    var slider = $(self.options.zoomSliderSelector, self.root);
    if (slider) slider.value = '1';
    // Fetch camera list after permission is granted — enable switch button if >1 camera
    window.Html5Qrcode.getCameras()
      .then(function (cameras) {
        self._cameraList = cameras || [];
        var switchBtn = $(self.options.switchCameraSelector, self.root);
        if (switchBtn) switchBtn.disabled = self._cameraList.length < 2;
      })
      .catch(function () {});
  };

  SnipeItNavQrScanner.prototype.switchCamera = function () {
    var self = this;
    if (!this._cameraList || this._cameraList.length < 2) return;
    // Find which camera is currently active by deviceId
    var video = this.reader && this.reader.querySelector('video');
    var stream = video && video.srcObject;
    var track = stream && stream.getVideoTracks && stream.getVideoTracks()[0];
    var currentId = track && track.getSettings && track.getSettings().deviceId;
    var currentIdx = -1;
    for (var i = 0; i < this._cameraList.length; i++) {
      if (this._cameraList[i].id === currentId) { currentIdx = i; break; }
    }
    var nextIdx = (currentIdx + 1) % this._cameraList.length;
    var next = this._cameraList[nextIdx];
    this.stopScanner();
    this._startWithCameraId(next.id, next.label);
  };

  SnipeItNavQrScanner.prototype._startWithCameraId = function (cameraId, label) {
    var self = this;
    if (this.html5QrCode) return;
    this.html5QrCode = new window.Html5Qrcode(this.reader.id);
    var qrbox = function (w, h) {
      var edge = Math.floor(Math.min(w, h) * 0.75);
      return { width: edge, height: edge };
    };
    var onDecode = function (text) {
      if (self.manualInput) self.manualInput.value = text;
      self.applyScanValue(text);
    };
    self.setStatus('Switching camera' + (label ? ' (' + label + ')' : '') + '…', '');
    this.html5QrCode.start(cameraId, { fps: 10, qrbox: qrbox }, onDecode, function () {})
      .then(function () { self._onCameraReady(); })
      .catch(function () {
        self.html5QrCode = null;
        self.setStatus('Could not open this camera.', 'is-bad');
      });
  };

  SnipeItNavQrScanner.prototype.stopScanner = function () {
    var self = this;
    var takePhotoBtn = $(this.options.takePhotoSelector, this.root);
    if (takePhotoBtn) takePhotoBtn.style.display = 'none';
    var switchBtn = $(this.options.switchCameraSelector, this.root);
    if (switchBtn) switchBtn.disabled = true;
    var zoomEl = $(this.options.zoomSelector, this.root);
    if (zoomEl) { zoomEl.style.display = 'none'; zoomEl.setAttribute('aria-hidden', 'true'); }
    var slider = $(this.options.zoomSliderSelector, this.root);
    if (slider) slider.value = '1';
    this._zoomTrack = null;
    this._focusTrack = null;
    if (this.reader) this.reader.classList.remove('is-focusable');
    if (!this.html5QrCode) return;

    var instance = this.html5QrCode;
    this.html5QrCode = null;

    instance.stop()
      .catch(function () {})
      .then(function () {
        return instance.clear();
      })
      .catch(function () {});
    self.setStatus('Camera stopped.', '');
  };

  SnipeItNavQrScanner.prototype.errorMessage = function (error) {
    if (!error) return 'Unknown error';
    return error.message || String(error);
  };

  SnipeItNavQrScanner.prototype.extractRoute = function (rawValue) {
    var value = String(rawValue || '').trim();
    if (!value) {
      return { ok: false, reason: 'Empty value.' };
    }

    try {
      var url = new URL(value);
      return {
        ok: true,
        route: url.pathname + (url.search || '') + (url.hash || ''),
        source: 'url'
      };
    } catch (e) {}

    var firstSlash = value.indexOf('/');
    if (firstSlash >= 0) {
      return {
        ok: true,
        route: value.slice(firstSlash),
        source: 'first-slash'
      };
    }

    return {
      ok: false,
      reason: "Could not find a route starting with '/'."
    };
  };

  SnipeItNavQrScanner.prototype.validateRoute = function (route) {
    var patterns = this.options.patterns || [];
    for (var i = 0; i < patterns.length; i += 1) {
      if (patterns[i].regex.test(route)) {
        return { valid: true, name: patterns[i].name };
      }
    }
    return {
      valid: false,
      reason: 'Route extracted but not one of the allowed patterns.'
    };
  };

  SnipeItNavQrScanner.prototype.applyScanValue = function (rawValue) {
    this.currentRawValue = rawValue || '';
    if (this.rawValue) this.rawValue.textContent = this.currentRawValue || '—';

    var extracted = this.extractRoute(rawValue);
    if (!extracted.ok) {
      this.currentRoute = null;
      if (this.routeValue) this.routeValue.textContent = '—';
      if (this.matchValue) this.matchValue.textContent = extracted.reason;
      if (this.useRouteButton) this.useRouteButton.disabled = true;
      if (this.openInTabButton) this.openInTabButton.disabled = true;
      this.setStatus(extracted.reason, 'is-bad');
      return;
    }

    this.currentRoute = extracted.route;
    if (this.routeValue) this.routeValue.textContent = extracted.route;

    var validation = this.validateRoute(extracted.route);
    if (validation.valid) {
      if (this.matchValue) this.matchValue.textContent = 'Supported pattern: ' + validation.name;
      if (this.useRouteButton) this.useRouteButton.disabled = false;
      if (this.openInTabButton) this.openInTabButton.disabled = false;
      this.setStatus('Valid route detected.', 'is-good');
    } else {
      if (this.matchValue) this.matchValue.textContent = validation.reason;
      if (this.useRouteButton) this.useRouteButton.disabled = false;
      if (this.openInTabButton) this.openInTabButton.disabled = false;
      this.setStatus('Route extracted but not matched to current defaults.', 'is-warn');
    }
  };

  SnipeItNavQrScanner.prototype.navigateToCurrentRoute = function () {
    if (!this.currentRoute) return;
    window.location.assign(this.currentRoute);
  };

  SnipeItNavQrScanner.prototype.openInNewTab = function () {
    if (!this.currentRoute) return;
    window.open(this.currentRoute, '_blank');
  };

  window.SnipeItNavQrScanner = SnipeItNavQrScanner;

  // Global function – callable from onclick anywhere in the page
  window.openNavQrScanner = function () {
    if (window.__snipeItNavQrScanner) {
      window.__snipeItNavQrScanner.open();
    } else {
      // Scanner not yet ready – show modal directly via CSS class
      var modal = document.querySelector('[data-nav-qr-modal]');
      if (modal) { modal.classList.add('is-open'); }
    }
  };

  document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('[data-nav-qr-scanner]');
    if (!root) return;

    var scanner = new SnipeItNavQrScanner();
    scanner.init();
    window.__snipeItNavQrScanner = scanner;
  });
})(window, document);
