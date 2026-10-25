/* Stop other sites from showing this page inside a frame (clickjacking); same-site frames are fine */
(function () {
  if (window.top === window.self) return;
  try { if (window.top.location.origin === window.location.origin) return; } catch (e) { /* cross-site parent */ }
  document.documentElement.style.display = 'none';
  window.top.location = window.location.href;
})();

/* Jovia Network — API client shared by the auth pages and the dashboard.
   Talks to the Laravel backend on this domain (/api) using its session cookie.
   Laravel needs a CSRF token on every change: it is read from the XSRF-TOKEN
   cookie and sent back in the X-XSRF-TOKEN header. */
(function () {
  'use strict';

  var BASE = window.JOVIA_API || '/api';

  function xsrfToken() {
    var m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
  }

  function ensureCsrf() {
    return xsrfToken() ? Promise.resolve() : fetch('/sanctum/csrf-cookie', { credentials: 'include' });
  }

  function send(method, path, body) {
    var opts = { method: method, credentials: 'include', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
    if (method !== 'GET') opts.headers['X-XSRF-TOKEN'] = xsrfToken();
    if (body instanceof FormData) {
      opts.body = body;
    } else if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(BASE + path, opts);
  }

  function request(method, path, body, retried) {
    var ready = method === 'GET' ? Promise.resolve() : ensureCsrf();
    return ready.then(function () { return send(method, path, body); }).then(function (res) {
      // Expired CSRF token: get a fresh one and try once more
      if (res.status === 419 && !retried) {
        return fetch('/sanctum/csrf-cookie', { credentials: 'include' }).then(function () { return request(method, path, body, true); });
      }
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) {
          var err = new Error(data.error || data.message || 'Something went wrong. Please try again.');
          err.status = res.status;
          throw err;
        }
        return data;
      });
    }, function () {
      throw new Error('Could not reach Jovia Network. Check your connection and try again.');
    });
  }

  function naira(n) {
    return '₦' + Number(n || 0).toLocaleString('en-NG');
  }

  window.JoviaAPI = {
    get: function (p) { return request('GET', p); },
    post: function (p, b) { return request('POST', p, b === undefined ? {} : b); },
    put: function (p, b) { return request('PUT', p, b); },
    del: function (p) { return request('DELETE', p); },
    naira: naira,
  };
})();
