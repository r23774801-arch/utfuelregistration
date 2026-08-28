  (function () {
  const endpoint = 'session_ping.php';
  const minIntervalMs = 60 * 1000;
  let lastPingAt = 0;
  let timer = null;

  function ping() {
    const now = Date.now();
    if (now - lastPingAt < minIntervalMs) return;
    lastPingAt = now;

    fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).catch(() => {});
  }

  function schedulePing() {
    clearTimeout(timer);
    timer = setTimeout(ping, 250);
  }

  ['click', 'mousemove', 'keydown', 'scroll', 'touchstart'].forEach((eventName) => {
    window.addEventListener(eventName, schedulePing, { passive: true });
  });

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) ping();
  });
})();
