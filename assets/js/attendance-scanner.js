'use strict';
/**
 * Camera: html5-qrcode 2.3.8 (loads via CDN), with BarcodeDetector fallback.
 * Manual and USB QR modes do not depend on camera permission or the CDN.
 */
(() => {
  const config = window.RMMC_ATTENDANCE_CONFIG || {};
  const byId = (id) => document.getElementById(id);
  const eventSelect = byId('attendanceEvent');
  const slotSelect = byId('attendanceSlot');
  const studentNumber = byId('studentNumber');
  const qrValue = byId('qrValue');
  const result = byId('scanResult');
  const cameraStatus = byId('cameraStatus');
  const nativePreview = byId('nativePreview');
  const qrReader = byId('qrReader');

  let decoder = null, nativeStream = null, cameraRunning = false;
  let nativeLoop = 0, requestBusy = false, lastCode = '', lastCodeTime = 0;
  let cameraStarting = false, stopRequested = false;

  function showResult(ok, message) {
    result.classList.remove('ok', 'error');
    result.classList.add(ok ? 'ok' : 'error');
    result.textContent = message;
  }

  async function record(mode, value, source, reason = '', verified = false) {
    if (requestBusy) return false;
    if (!config.canRecord || !eventSelect?.value || !slotSelect?.value) {
      showResult(false, 'Select an ongoing event with an enabled checkpoint first.');
      return false;
    }
    const credential = (value || '').trim();
    if (!credential) {
      showResult(false, mode === 'student_id' ? 'Enter the student number.' : 'Scan or paste a QR value.');
      return false;
    }
    requestBusy = true;
    try {
      const body = new URLSearchParams({
        csrf: config.csrf,
        event_id: eventSelect.value,
        slot: slotSelect.value,
        mode,
        value: credential,
        source,
        reason,
        verified: verified ? '1' : '0'
      });
      const response = await fetch(config.endpoint, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      if (response.redirected && response.url.includes('login.php')) {
        showResult(false, 'Your login session has expired. Sign in again to record attendance.');
        return false;
      }
      const data = await response.json();
      if (!data.ok) {
        showResult(false, data.message || 'Unable to record attendance.');
        return false;
      }
      showResult(true,
        `${data.student} (${data.student_number})\n` +
        `${data.program} · Year ${data.year_level}\n` +
        `${data.message}\n` +
        `Progress: ${data.progress} checkpoints · Status: ${data.status}`);
      if (mode === 'student_id') {
        studentNumber.value = '';
        byId('manualVerified').checked = false;
        studentNumber.focus();
      } else if (source === 'qr_reader') {
        qrValue.value = '';
        qrValue.focus();
      }
      return true;
    } catch (error) {
      showResult(false, 'Cannot reach the scanner endpoint. Check Apache and your login session.');
      return false;
    } finally {
      requestBusy = false;
    }
  }

  function onQrDetected(text) {
    const code = (text || '').trim();
    if (!code || requestBusy) return;
    const now = Date.now();
    if (code === lastCode && now - lastCodeTime < 5000) return;
    lastCode = code;
    lastCodeTime = now;
    void record('qr', code, 'camera');
  }

  async function stopCamera() {
    stopRequested = true;
    cancelAnimationFrame(nativeLoop);
    nativeLoop = 0;
    if (decoder) {
      const current = decoder;
      decoder = null;
      try { if (cameraRunning) await current.stop(); } catch (e) { /* already stopped */ }
      try { current.clear(); } catch (e) { /* UI already removed */ }
    }
    if (nativeStream) {
      nativeStream.getTracks().forEach((track) => track.stop());
      nativeStream = null;
    }
    nativePreview.srcObject = null;
    nativePreview.hidden = true;
    cameraRunning = false;
    cameraStatus.textContent = 'Camera stopped. You can continue using Student ID or USB QR input.';
  }

  async function startNativeBarcodeDetector() {
    // Only used when the external scanner library is unavailable.
    if (!('BarcodeDetector' in window)) return false;
    const formats = await BarcodeDetector.getSupportedFormats();
    if (!formats.includes('qr_code')) return false;
    const detector = new BarcodeDetector({ formats: ['qr_code'] });
    nativeStream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'environment'}, audio: false});
    nativePreview.hidden = false;
    nativePreview.srcObject = nativeStream;
    await nativePreview.play();
    if (stopRequested) { await stopCamera(); return true; }
    cameraRunning = true;
    cameraStatus.textContent = 'Camera active (browser QR reader).';
    const loop = async () => {
      if (!cameraRunning || !nativeStream) return;
      try {
        const detected = await detector.detect(nativePreview);
        if (detected.length) onQrDetected(detected[0].rawValue);
      } catch (e) { /* Ignore individual undecodable frames. */ }
      if (cameraRunning) nativeLoop = requestAnimationFrame(loop);
    };
    nativeLoop = requestAnimationFrame(loop);
    return true;
  }

  async function startCamera() {
    if (cameraStarting || cameraRunning || !config.canRecord) return;
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
      cameraStatus.textContent = 'Browser camera blocked on HTTP rmmc-ite.local. Use http://localhost:8000/scan.php on this PC or configure trusted HTTPS. Student ID attendance works here.';
      return;
    }
    stopRequested = false;
    cameraStarting = true;
    cameraStatus.textContent = 'Starting camera… Allow camera access in your browser.';
    try {
      if (typeof window.Html5Qrcode === 'function') {
        decoder = new Html5Qrcode('qrReader', {
          formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE],
          verbose: false
        });
        await decoder.start(
          { facingMode: 'environment' },
          { fps: 8, qrbox: {width: 240, height: 240} },
          onQrDetected,
          () => {} // Most frames do not contain a QR; ignore these callbacks.
        );
        cameraRunning = true;
        cameraStatus.textContent = 'Camera active. Show a student QR code to record attendance.';
        if (stopRequested) await stopCamera();
      } else if (!await startNativeBarcodeDetector()) {
        cameraStatus.textContent = 'QR library could not load. Check your internet access, or use Student ID / USB QR input.';
      }
    } catch (error) {
      cameraStatus.textContent = 'Camera failed: ' + (error?.message || String(error)) + '. Use Student ID backup.';
      await stopCamera();
    } finally {
      cameraStarting = false;
    }
  }

  eventSelect?.addEventListener('change', () => {
    const url = new URL(config.endpoint, location.href);
    url.searchParams.set('event_id', eventSelect.value);
    location.href = url.toString();
  });

  byId('manualForm')?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    await record('student_id', studentNumber.value, 'manual_id', byId('manualReason').value, byId('manualVerified').checked);
  });

  byId('submitQr')?.addEventListener('click', () => record('qr', qrValue.value, 'qr_reader'));
  qrValue?.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) {
      ev.preventDefault();
      void record('qr', qrValue.value, 'qr_reader');
    }
  });

  byId('startCamera')?.addEventListener('click', startCamera);
  byId('stopCamera')?.addEventListener('click', () => { void stopCamera(); });
  window.addEventListener('pagehide', () => { void stopCamera(); });
  if (!window.isSecureContext) {
    cameraStatus.textContent = 'Camera is unavailable at HTTP rmmc-ite.local. Student ID and USB QR backup remain available. For local camera testing, use localhost:8000.';
  }
})();
