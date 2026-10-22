/* Tells the Jovia dashboard the player is active, so the gameplay timer keeps running.
   Throttled to one message every 2 seconds. */
window.JoviaActivity = (function () {
  'use strict';
  var last = 0;
  function ping() {
    var now = Date.now();
    if (now - last < 2000 || window.parent === window) return;
    last = now;
    window.parent.postMessage({ type: 'jovia:activity' }, window.location.origin);
  }
  ['pointerdown', 'keydown', 'touchstart'].forEach(function (evt) {
    document.addEventListener(evt, ping, { passive: true });
  });
  return { ping: ping };
})();
