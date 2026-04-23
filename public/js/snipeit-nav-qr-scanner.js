
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

    var onSuccess = function () {
      self.setStatus('Camera opened. Point at an asset or location QR code.', 'is-good');
    };

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

  SnipeItNavQrScanner.prototype.stopScanner = function () {
    var self = this;
    var takePhotoBtn = $(this.options.takePhotoSelector, this.root);
    if (takePhotoBtn) takePhotoBtn.style.display = 'none';
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
