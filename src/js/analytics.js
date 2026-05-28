(function () {
  if (window._jAnalyticsSent) return;
  window._jAnalyticsSent = true;

  // Skip if sendBeacon is unavailable (very old browsers)
  if (!navigator.sendBeacon) return;

  function uid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      const r = (Math.random() * 16) | 0;
      return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
  }

  let vid = '';
  let sid = '';
  try {
    vid = localStorage.getItem('_jvid') || '';
    if (!vid) { vid = uid(); localStorage.setItem('_jvid', vid); }
    sid = sessionStorage.getItem('_jsid') || '';
    if (!sid) { sid = uid(); sessionStorage.setItem('_jsid', sid); }
  } catch (_) {
    vid = uid();
    sid = uid();
  }

  function send() {
    const payload = {
      path:     location.pathname,
      title:    document.title,
      referrer: document.referrer || '',
      screen:   screen.width + 'x' + screen.height,
      viewport: window.innerWidth + 'x' + window.innerHeight,
      lang:     navigator.language || '',
      tz:       (Intl && Intl.DateTimeFormat ? Intl.DateTimeFormat().resolvedOptions().timeZone : '') || '',
      touch:    ('ontouchstart' in window) ? 1 : 0,
      conn:     (navigator.connection && navigator.connection.effectiveType) || '',
      visitor:  vid,
      session:  sid,
    };

    try {
      const t = performance.timing;
      if (t && t.loadEventEnd > 0) {
        payload.load_ms = Math.max(0, t.loadEventEnd - t.navigationStart);
      }
    } catch (_) {}

    navigator.sendBeacon('/analytics.php', JSON.stringify(payload));
  }

  if (document.readyState === 'complete') {
    setTimeout(send, 0);
  } else {
    window.addEventListener('load', function () { setTimeout(send, 0); }, { once: true });
  }
})();
