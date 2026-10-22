/* Jovia Network — member dashboard (talks to the API in /backend) */
(function () {
  'use strict';

  var api = window.JoviaAPI;
  var naira = api.naira;
  var $ = function (id) { return document.getElementById(id); };
  var state = { data: null, modules: [], tab: null, txPage: 1, loaded: {}, orderTerms: null, chat: [] };

  var ICONS = { video: 'i-play', music: 'i-music', game: 'i-gamepad', meta: 'i-chart' };
  var STATUS_CHIP = {
    PENDING: 'pending', PAID: 'ok', APPROVED: 'ok', COMPLETED: 'ok', ACTIVE: 'ok',
    REJECTED: 'bad', ABANDONED: 'neutral', PENDING_PAYMENT: 'neutral', AWAITING_VERIFICATION: 'pending', SUSPENDED: 'bad',
    PENDING_REVIEW: 'pending', AWAITING_BALANCE: 'pending', SHIPPED: 'pending', CANCELLED: 'neutral', DISPUTED: 'bad',
  };
  var STATUS_LABEL = {
    PENDING_PAYMENT: 'Not activated', AWAITING_VERIFICATION: 'Verifying', ABANDONED: 'Stopped', PENDING_REVIEW: 'In review', REJECTED: 'Not approved',
    AWAITING_BALANCE: 'Balance due', SHIPPED: 'Shipped', DISPUTED: 'Problem reported', CANCELLED: 'Cancelled',
  };

  // ── Helpers ──
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function usd(n) {
    var rate = (state.data && state.data.config.usdToNgn) || 1000;
    var v = n / rate;
    return '$' + (Number.isInteger(v) ? v : v.toFixed(2));
  }
  function when(d) {
    return new Date(d).toLocaleString('en-NG', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  }
  function day(d) {
    return new Date(d).toLocaleDateString('en-NG', { day: 'numeric', month: 'short' });
  }
  function clock(sec) {
    sec = Math.max(0, Math.ceil(sec));
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    var mm = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    return h ? h + ':' + mm : mm;
  }
  function duration(sec) {
    if (sec < 60) return sec + ' sec';
    var m = Math.floor(sec / 60), s = sec % 60;
    return m + ' min' + (s ? ' ' + s + ' sec' : '');
  }
  function chip(status, label) {
    return '<span class="chip ' + (STATUS_CHIP[status] || 'neutral') + '">' +
      esc(label || STATUS_LABEL[status] || status.charAt(0) + status.slice(1).toLowerCase().replace(/_/g, ' ')) + '</span>';
  }
  // Phones are stored as digits: local numbers keep their leading 0, others get a +
  function fmtPhone(p) { return p.charAt(0) === '0' ? p : '+' + p; }
  function emptyRow(text) { return '<li class="empty">' + esc(text) + '</li>'; }
  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      if (document.querySelector('script[src="' + src + '"]')) return resolve();
      var s = document.createElement('script');
      s.src = src; s.async = true; s.onload = resolve; s.onerror = reject;
      document.head.appendChild(s);
    });
  }

  var toastTimer;
  function toast(msg, isErr) {
    var t = $('toast');
    t.textContent = msg;
    t.className = 'toast show' + (isErr ? ' err' : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.className = 'toast'; }, 3400);
  }

  // Wire a form: disables the button while busy, shows API errors inline
  function bindForm(form, handler) {
    var errEl = form.querySelector('.form-error');
    var btn = form.querySelector('button[type="submit"]');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (errEl) errEl.textContent = '';
      btn.disabled = true;
      Promise.resolve().then(function () { return handler(form); }).catch(function (err) {
        if (errEl) errEl.textContent = err.message; else toast(err.message, true);
      }).then(function () { btn.disabled = false; });
    });
  }

  function fill(attr, value) {
    document.querySelectorAll('[' + attr + ']').forEach(function (el) { el.textContent = value; });
  }

  // ── Boot ──
  function load() {
    return api.get('/me/overview').then(function (d) {
      if (d.user.role === 'ADMIN') { window.location.href = '/admin'; return; }
      state.data = d;
      render();
    }, function (err) {
      if (err.status === 401 || err.status === 403) { window.location.href = 'signin.html'; return; }
      $('appLoading').textContent = err.message;
    });
  }

  function render() {
    var d = state.data, u = d.user, c = d.config;
    $('appLoading').hidden = true;
    $('bellWrap').hidden = false;
    fill('data-first-name', u.firstName);
    fill('data-fee', naira(c.activationFee));
    fill('data-bonus', naira(c.welcomeBonus));
    fill('data-ref-bonus', naira(c.referralBonus));
    fill('data-mp-reward', naira(c.marketplaceReward) + ' (' + usd(c.marketplaceReward) + ')');
    fill('data-fbr-reward', naira(c.fbrReward) + ' (' + usd(c.fbrReward) + ')');
    loadNotifications();

    if (u.status === 'ACTIVE') {
      $('gate').hidden = true;
      $('shell').hidden = false;
      $('topBalance').hidden = false;
      $('topBalance').textContent = naira(d.stats.balance);
      renderOverviewStats();
      showTab((location.hash || '#overview').slice(1));
    } else {
      $('shell').hidden = true;
      $('gate').hidden = false;
      renderGate();
    }
  }

  // ── Notifications ──
  function loadNotifications() {
    return api.get('/me/notifications').then(function (r) {
      var count = $('bellCount');
      count.hidden = !r.unread;
      count.textContent = r.unread > 9 ? '9+' : r.unread;
      $('bellList').innerHTML = r.items.length ? r.items.map(function (n) {
        var inner = '<strong>' + esc(n.title) + '</strong><p>' + esc(n.body) + '</p><time>' + when(n.createdAt) + '</time>';
        return '<li class="' + (n.readAt ? '' : 'unread') + '">' + (n.link ? '<a href="' + esc(n.link) + '">' + inner + '</a>' : inner) + '</li>';
      }).join('') : '<li class="empty">No notifications yet.</li>';
    }, function () {});
  }
  function markRead() {
    api.post('/me/notifications/read').then(loadNotifications, function () {});
  }
  $('bellBtn').addEventListener('click', function (e) {
    e.stopPropagation();
    var panel = $('bellPanel');
    panel.hidden = !panel.hidden;
    $('bellBtn').setAttribute('aria-expanded', String(!panel.hidden));
    if (!panel.hidden && !$('bellCount').hidden) setTimeout(markRead, 1500);
  });
  $('bellRead').addEventListener('click', markRead);
  $('bellPanel').addEventListener('click', function (e) {
    if (e.target.closest('a')) $('bellPanel').hidden = true;
    e.stopPropagation();
  });
  document.addEventListener('click', function () { $('bellPanel').hidden = true; });
  setInterval(function () { if (!document.hidden && state.data) loadNotifications(); }, 60000);

  // ── Activation gate ──
  var gatePoll;
  function renderGate() {
    var d = state.data, u = d.user, p = d.payment, c = d.config;
    ['gatePay', 'gateVerify', 'gateRefund'].forEach(function (id) { $(id).hidden = true; });
    clearInterval(gatePoll);

    if (p && (p.status === 'REFUND_REQUESTED' || p.status === 'REFUNDED')) {
      $('gateRefund').hidden = false;
      $('refundTitle').textContent = p.status === 'REFUNDED' ? 'Refund sent' : 'Refund requested';
      $('refundText').textContent = p.status === 'REFUNDED'
        ? 'Your ' + naira(p.amount) + ' refund has been sent to the account you paid from.' + (p.adminNote ? ' Note: ' + p.adminNote : '')
        : 'Your account was never activated, so you are entitled to a full refund of ' + naira(p.amount) + '. Our team will send it to the account you paid from.';
      return;
    }
    if (u.status === 'AWAITING_VERIFICATION') {
      $('gateVerify').hidden = false;
      var link = $('supportLink');
      if (c.supportUrl) { link.href = c.supportUrl; link.hidden = false; }
      gatePoll = setInterval(function () {
        api.get('/me/overview').then(function (fresh) {
          if (fresh.user.status !== 'AWAITING_VERIFICATION') { state.data = fresh; render(); if (fresh.user.status === 'ACTIVE') toast('Your account is active. Welcome Bonus credited!'); }
        }, function () {});
      }, 30000);
      return;
    }
    $('gatePay').hidden = false;
    ['bankName', 'accountName', 'accountNumber'].forEach(function (k) {
      document.querySelector('[data-bank="' + k + '"]').textContent = c.bank[k] || 'Shown here once confirmed by Jovia Network';
    });
    var rej = $('gateRejected');
    rej.hidden = !(p && p.status === 'REJECTED');
    if (p && p.status === 'REJECTED') rej.textContent = 'We could not verify your last payment' + (p.adminNote ? ': ' + p.adminNote : '.') + ' Please check the details and submit again.';
    if (!$('senderName').value) $('senderName').value = u.firstName + ' ' + u.lastName;
  }

  bindForm($('payForm'), function () {
    return api.post('/me/payments', { senderName: $('senderName').value, reference: $('payRef').value }).then(function () {
      toast('Payment details received. We\'ll verify it shortly.');
      return load();
    });
  });
  $('cancelPayBtn').addEventListener('click', function () {
    if (!confirm('Go back to the payment step? Only do this if you have not paid yet.')) return;
    api.post('/me/payments/cancel').then(load, function (e) { toast(e.message, true); });
  });
  $('refundBtn').addEventListener('click', function () {
    var reason = prompt('Request a full refund of your activation payment. Tell us why (optional):', '');
    if (reason === null) return;
    api.post('/me/payments/refund', { reason: reason }).then(function () { toast('Refund requested.'); return load(); }, function (e) { toast(e.message, true); });
  });

  // ── Tabs ──
  var TABS = ['overview', 'tasks', 'marketplace', 'wallet', 'referrals', 'fbr', 'assistant', 'account'];
  function showTab(name) {
    if (TABS.indexOf(name) === -1) name = 'overview';
    state.tab = name;
    TABS.forEach(function (t) { $('tab-' + t).hidden = t !== name; });
    document.querySelectorAll('.app-nav a').forEach(function (a) { a.classList.toggle('active', a.dataset.tab === name); });
    ({ overview: loadOverview, tasks: loadTasks, marketplace: loadMarketplace, wallet: loadWallet, referrals: loadReferrals, fbr: loadFbr, assistant: loadAssistant, account: loadAccount })[name]();
    window.scrollTo(0, 0);
  }
  window.addEventListener('hashchange', function () {
    if (!$('shell').hidden) showTab(location.hash.slice(1));
  });
  // Clicking the tab you're already on refreshes it
  document.querySelector('.app-nav').addEventListener('click', function (e) {
    var a = e.target.closest('a[data-tab]');
    if (a && location.hash === '#' + a.dataset.tab) showTab(a.dataset.tab);
  });

  function refreshStats() {
    return api.get('/me/overview').then(function (d) {
      state.data = d;
      $('topBalance').textContent = naira(d.stats.balance);
      renderOverviewStats();
    });
  }

  // ── Overview ──
  function renderOverviewStats() {
    var s = state.data.stats;
    $('stBalance').textContent = naira(s.balance);
    $('stToday').textContent = naira(s.todayEarnings);
    $('stTotal').textContent = naira(s.totalEarned);
    $('stWithdrawn').textContent = naira(s.totalWithdrawn);
  }

  function txRow(t) {
    var plus = t.amount > 0;
    return '<li><div class="t-main"><div class="t-title">' + esc(t.description) + '</div><div class="t-sub">' + when(t.createdAt) + '</div></div>' +
      '<span class="t-amt ' + (plus ? 'plus' : 'minus') + '">' + (plus ? '+' : '−') + naira(Math.abs(t.amount)) + '</span></li>';
  }

  function loadOverview() {
    var recent = state.data.recent;
    $('ovRecent').innerHTML = recent.length ? recent.map(txRow).join('') : emptyRow('No activity yet. Start a task to see your earnings here.');
    loadModules().then(function () { $('ovModules').innerHTML = state.modules.map(function (m) { return moduleCard(m, false); }).join(''); });
    api.get('/fbr').then(function (f) {
      var live = f.state === 'open';
      $('fbrBanner').hidden = !live;
      if (live) $('fbrBannerText').textContent = 'Friday Bonus is live — ' + f.claimsLeft + ' of ' + f.maxWinners + ' rewards left';
    }, function () {});
  }

  // ── Tasks ──
  function loadModules() {
    return api.get('/tasks/modules').then(function (r) { state.modules = r.modules; });
  }

  function rateText(m) {
    var per = m.rewardType === 'PER_SECOND'
      ? naira(m.rewardAmount) + ' per second'
      : naira(m.rewardAmount) + ' (' + usd(m.rewardAmount) + ') per session';
    return per + ' · up to ' + naira(m.dailyCap) + ' (' + usd(m.dailyCap) + ') a day';
  }

  function moduleCard(m, clickable) {
    var pct = m.dailyCap ? Math.min(100, (m.earnedToday / m.dailyCap) * 100) : 0;
    var tag = clickable ? 'button type="button" data-module="' + esc(m.key) + '"' + (m.remainingToday <= 0 ? ' disabled' : '') : 'div';
    return '<' + tag + ' class="card module-card">' +
      '<div class="module-top"><span class="icon-tile"><svg class="icon"><use href="#' + (ICONS[m.key] || 'i-clock') + '"/></svg></span>' +
      '<div><h4>' + esc(m.name) + '</h4><div class="rate">' + esc(rateText(m)) + '</div></div></div>' +
      (clickable ? '<p>' + esc(m.description) + '</p>' : '') +
      '<div class="progress thin"><span style="width:' + pct + '%"></span></div>' +
      '<div class="cap-row"><span>' + naira(m.earnedToday) + ' earned today</span><span>' +
      (m.remainingToday > 0 ? naira(m.remainingToday) + ' left' : 'Limit reached') + '</span></div>' +
      '</' + tag.split(' ')[0] + '>';
  }

  function renderTaskModules() {
    $('taskModules').innerHTML = state.modules.map(function (m) { return moduleCard(m, true); }).join('');
  }

  function loadTasks() {
    loadModules().then(function () {
      renderTaskModules();
      if (!run.session) {
        api.get('/tasks/sessions/current').then(function (r) { if (r.session) resumeSession(r.session); });
      }
    });
    loadSessions();
  }

  function loadSessions() {
    api.get('/tasks/sessions').then(function (r) {
      $('sessionList').innerHTML = r.items.length ? r.items.map(function (s) {
        var m = state.modules.find(function (x) { return x.key === s.moduleKey; });
        var right = s.status === 'COMPLETED' ? '<span class="t-amt plus">+' + naira(s.reward) + '</span>'
          : s.status === 'PENDING_REVIEW' ? chip(s.status, 'In review · ' + naira(s.reward)) : chip(s.status);
        return '<li><div class="t-main"><div class="t-title">' + esc((m ? m.name : s.moduleKey) + (s.contentTitle ? ' · ' + s.contentTitle : '')) +
          '</div><div class="t-sub">' + duration(s.durationSeconds) + ' · ' + when(s.startedAt) + (s.reviewNote ? ' · ' + esc(s.reviewNote) : '') + '</div></div>' + right + '</li>';
      }).join('') : emptyRow('No sessions yet.');
    });
  }

  $('taskModules').addEventListener('click', function (e) {
    var btn = e.target.closest('[data-module]');
    if (btn && !btn.disabled) openRunner(btn.dataset.module);
  });

  // ── Task runner ──
  // The server decides when a session is complete; this page shows the countdown
  // and only sends heartbeats while the timer is running. It pauses when:
  //   · the member leaves the page (modules that require focus),
  //   · a YouTube video or Spotify track isn't playing,
  //   · nobody has touched a built-in game for 20 seconds.
  var run = {
    module: null, content: null, session: null, active: 0, timer: null, beat: null, completing: false,
    mediaKind: 'none', hiddenPause: false, mediaPaused: false, idlePaused: false, wasPaused: false, lastActivity: 0, contentList: [],
  };
  var IDLE_MS = 20000;

  function showRunPanel(which) {
    ['runSetup', 'runLive', 'runProof', 'runDone'].forEach(function (id) { $(id).hidden = id !== which; });
  }

  function selectedContent() {
    var id = Number($('runContent').value);
    return run.contentList.find(function (c) { return c.id === id; }) || null;
  }

  function renderBrief() {
    var c = !$('contentField').hidden ? selectedContent() : null;
    var brief = $('runBrief');
    brief.hidden = !(c && c.instructions);
    if (c && c.instructions) {
      brief.innerHTML = esc(c.instructions) + (c.requiresProof ? '\n\nWhen the timer ends you\'ll enter ' + esc(c.proofLabel || 'your proof') + ' for review.' : '');
    }
  }

  function openRunner(key) {
    var m = state.modules.find(function (x) { return x.key === key; });
    if (!m) return;
    run.module = m;
    $('runner').hidden = false;
    $('runModuleName').textContent = m.name;
    $('runTitle').textContent = 'Set up your session';
    $('runError').textContent = '';
    showRunPanel('runSetup');

    var range = $('runDuration');
    var span = m.maxSeconds - m.minSeconds;
    range.min = m.minSeconds;
    range.max = m.maxSeconds;
    range.step = span <= 120 ? 5 : span <= 600 ? 15 : 30;
    range.value = m.minSeconds;
    range.disabled = span === 0;
    $('runMin').textContent = duration(m.minSeconds);
    $('runMax').textContent = duration(m.maxSeconds);
    updateRunPreview();

    var field = $('contentField');
    var select = $('runContent');
    select.innerHTML = '';
    field.hidden = true;
    run.contentList = [];
    $('runBrief').hidden = true;
    if (m.contentCount) {
      api.get('/tasks/modules/' + m.key + '/content').then(function (r) {
        run.contentList = r.items;
        select.innerHTML = r.items.map(function (i) {
          return '<option value="' + i.id + '">' + esc(i.title + (i.provider ? ' — ' + i.provider : '')) + '</option>';
        }).join('');
        field.hidden = !r.items.length;
        renderBrief();
      });
    }
    $('runner').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  $('runContent').addEventListener('change', renderBrief);

  function updateRunPreview() {
    var m = run.module, secs = Number($('runDuration').value);
    $('runDurationLabel').textContent = duration(secs);
    var reward = m.rewardType === 'PER_SECOND' ? m.rewardAmount * secs : m.rewardAmount;
    var capped = Math.min(reward, m.remainingToday);
    $('runReward').textContent = naira(capped) + (capped < reward ? ' (today\'s limit)' : '');
  }
  $('runDuration').addEventListener('input', updateRunPreview);

  $('runStart').addEventListener('click', function () {
    var btn = $('runStart');
    var body = { moduleKey: run.module.key, durationSeconds: Number($('runDuration').value) };
    var content = !$('contentField').hidden ? selectedContent() : null;
    if (content) body.contentId = content.id;
    btn.disabled = true;
    $('runError').textContent = '';
    api.post('/tasks/sessions', body).then(function (r) {
      startLive(r.session, content);
    }, function (e) { $('runError').textContent = e.message; }).then(function () { btn.disabled = false; });
  });

  function resumeSession(session) {
    var m = state.modules.find(function (x) { return x.key === session.moduleKey; });
    if (!m) return;
    run.module = m;
    $('runner').hidden = false;
    $('runModuleName').textContent = m.name;
    var contentReq = session.contentId ? api.get('/tasks/modules/' + m.key + '/content') : Promise.resolve({ items: [] });
    contentReq.then(function (r) {
      run.contentList = r.items;
      var content = r.items.find(function (c) { return c.id === session.contentId; }) || null;
      return api.post('/tasks/sessions/' + session.id + '/resume').then(function (res) { startLive(res.session, content); });
    }, sessionGone);
  }

  // ── Media: decide how this content plays and how we know it's active ──
  function setupMedia(content) {
    var url = content && content.url;
    var box = $('runMedia');
    run.mediaPaused = false;
    run.idlePaused = false;
    var m;

    if (run.module.key === 'meta' || (content && content.instructions && !content.url)) {
      run.mediaKind = 'task';
      box.innerHTML = '<div class="task-brief">' + esc((content && content.instructions) || 'Complete the task while the timer runs.') +
        (url ? '<br><a class="btn btn-outline btn-sm" href="' + esc(url) + '" target="_blank" rel="noopener">Open the task</a>' : '') + '</div>';
      return;
    }
    if (!url) {
      run.mediaKind = 'none';
      box.innerHTML = '<div class="media-placeholder">Keep this page open and stay active until the timer finishes.</div>';
      return;
    }
    if ((m = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/shorts\/)([\w-]{6,})/))) {
      run.mediaKind = 'youtube';
      run.mediaPaused = true;
      box.innerHTML = '<iframe id="ytFrame" src="https://www.youtube.com/embed/' + m[1] + '?enablejsapi=1&rel=0&playsinline=1&origin=' +
        encodeURIComponent(location.origin) + '" allow="autoplay; encrypted-media" allowfullscreen title="Video"></iframe>';
      watchYouTube(run.session.id);
      return;
    }
    if ((m = url.match(/open\.spotify\.com\/(track|album|playlist|episode)\/(\w+)/))) {
      run.mediaKind = 'spotify';
      run.mediaPaused = true;
      box.innerHTML = '<div id="spotifyEmbed"></div>';
      watchSpotify(run.session.id, 'spotify:' + m[1] + ':' + m[2]);
      return;
    }
    if (url.charAt(0) === '/') {
      // Built-in game on this site: it reports activity with postMessage
      run.mediaKind = 'game';
      run.lastActivity = Date.now();
      box.innerHTML = '<iframe src="' + esc(url) + '" title="Game" style="aspect-ratio:auto;height:min(560px,75vh)"></iframe>';
      return;
    }
    if ((m = url.match(/audiomack\.com\/([\w-]+)\/song\/([\w-]+)/))) {
      run.mediaKind = 'other';
      box.innerHTML = '<iframe class="short" src="https://audiomack.com/embed/song/' + m[1] + '/' + m[2] + '" title="Music"></iframe>';
      return;
    }
    run.mediaKind = 'other';
    box.innerHTML = '<iframe src="' + esc(url) + '" allow="autoplay; fullscreen" title="Activity"></iframe>';
  }

  // If a player API can't load (blocked, offline), don't hold the timer hostage
  function playerFallback(sessionId) {
    setTimeout(function () {
      if (run.session && run.session.id === sessionId && run.mediaPaused && !run.playerReady) {
        run.mediaPaused = false;
        updatePause();
      }
    }, 8000);
  }

  var ytReady = null;
  function watchYouTube(sessionId) {
    run.playerReady = false;
    playerFallback(sessionId);
    if (!ytReady) {
      ytReady = new Promise(function (resolve) {
        if (window.YT && window.YT.Player) return resolve();
        window.onYouTubeIframeAPIReady = resolve;
        loadScript('https://www.youtube.com/iframe_api').catch(function () {});
      });
    }
    ytReady.then(function () {
      if (!run.session || run.session.id !== sessionId) return;
      run.playerReady = true;
      new window.YT.Player('ytFrame', {
        events: {
          onStateChange: function (e) {
            if (!run.session || run.session.id !== sessionId) return;
            run.mediaPaused = e.data !== window.YT.PlayerState.PLAYING;
            updatePause();
          },
        },
      });
    });
  }

  var spotifyApi = null;
  function watchSpotify(sessionId, uri) {
    run.playerReady = false;
    playerFallback(sessionId);
    if (!spotifyApi) {
      spotifyApi = new Promise(function (resolve) {
        window.onSpotifyIframeApiReady = resolve;
        loadScript('https://open.spotify.com/embed/iframe-api/v1').catch(function () {});
      });
    }
    spotifyApi.then(function (IFrameAPI) {
      if (!run.session || run.session.id !== sessionId) return;
      run.playerReady = true;
      IFrameAPI.createController($('spotifyEmbed'), { uri: uri, width: '100%', height: 152 }, function (controller) {
        controller.addListener('playback_update', function (e) {
          if (!run.session || run.session.id !== sessionId) return;
          run.mediaPaused = e.data.isPaused;
          updatePause();
        });
      });
    });
  }

  window.addEventListener('message', function (e) {
    if (e.origin !== location.origin || !e.data || e.data.type !== 'jovia:activity') return;
    run.lastActivity = Date.now();
    if (run.idlePaused) { run.idlePaused = false; updatePause(); }
  });

  function isPaused() { return run.hiddenPause || run.mediaPaused || run.idlePaused; }

  // Called whenever a pause reason changes. Coming back from a pause tells the
  // server to restart its clock, so paused time is never counted.
  function updatePause() {
    var paused = isPaused();
    if (run.session && run.wasPaused && !paused) {
      api.post('/tasks/sessions/' + run.session.id + '/resume').then(function (r) {
        run.active = Math.min(run.active, r.session.activeSeconds + 1);
      }, sessionGone);
    }
    run.wasPaused = paused;
    paintClock();
  }

  function startLive(session, content) {
    stopLive();
    run.session = session;
    run.content = content;
    run.active = session.activeSeconds;
    run.completing = false;
    run.hiddenPause = run.module.requireFocus && document.hidden;
    $('runTitle').textContent = session.contentTitle || run.module.name;
    showRunPanel('runLive');
    setupMedia(content);
    run.wasPaused = isPaused();
    paintClock();

    var last = Date.now();
    run.timer = setInterval(function () {
      var now = Date.now();
      if (run.mediaKind === 'game' && !run.idlePaused && now - run.lastActivity > IDLE_MS) {
        run.idlePaused = true;
        updatePause();
      }
      if (!isPaused()) run.active = Math.min(run.session.durationSeconds, run.active + (now - last) / 1000);
      last = now;
      paintClock();
      if (!isPaused() && run.active >= run.session.durationSeconds) timerFinished();
    }, 250);
    run.beat = setInterval(sendBeat, 5000);
  }

  function sendBeat() {
    if (!run.session || (isPaused() && $('runProof').hidden)) return;
    api.post('/tasks/sessions/' + run.session.id + '/beat').then(function (r) {
      // Never let the display run ahead of what the server has counted by more than a few seconds
      if (run.active - r.session.activeSeconds > 6) run.active = r.session.activeSeconds;
    }, sessionGone);
  }

  function pauseMessage() {
    if (run.hiddenPause) return 'Paused — you left the page. Come back to continue.';
    if (run.mediaPaused) return run.mediaKind === 'spotify' ? 'Press play on the track to start the timer.' : 'Press play on the video to start the timer.';
    if (run.idlePaused) return 'Paused — keep playing to keep the timer running.';
    return run.mediaKind === 'task' ? 'Running — complete the task, then come back here.' : 'Running — keep this page open.';
  }

  function paintClock() {
    var s = run.session;
    if (!s) return;
    $('runClock').textContent = clock(s.durationSeconds - run.active);
    $('runProgress').style.width = Math.min(100, (run.active / s.durationSeconds) * 100) + '%';
    var stateEl = $('runState');
    stateEl.textContent = pauseMessage();
    stateEl.classList.toggle('paused', isPaused());
  }

  function stopLive() {
    clearInterval(run.timer);
    clearInterval(run.beat);
    run.timer = run.beat = null;
  }

  function sessionGone(err) {
    if (err && err.status === 409) {
      stopLive();
      run.session = null;
      $('runner').hidden = true;
      toast('That session ended because another one was started.', true);
    }
  }

  // Timer reached zero: tasks that need proof ask for it first
  function timerFinished() {
    if (run.completing) return;
    if (run.content && run.content.requiresProof) {
      run.completing = true;
      clearInterval(run.timer);
      run.timer = null;
      $('proofLabel').textContent = 'Enter ' + (run.content.proofLabel || 'your proof');
      $('proofInput').value = '';
      showRunPanel('runProof');
      $('proofInput').focus();
      return;
    }
    completeSession('');
  }

  bindForm($('runProof'), function () {
    return completeSession($('proofInput').value, true);
  });

  function completeSession(proof, throwErrors) {
    run.completing = true;
    return api.post('/tasks/sessions/' + run.session.id + '/complete', { proof: proof }).then(function (r) {
      stopLive();
      run.session = null;
      $('runMedia').innerHTML = '';
      showRunPanel('runDone');
      if (r.pendingReview) {
        $('runDoneTitle').textContent = 'Submitted for review';
        $('runDoneText').textContent = naira(r.session.reward) + ' will be added to your wallet once our team checks your proof. We\'ll notify you.';
      } else {
        $('runDoneTitle').textContent = r.session.reward ? '+' + naira(r.session.reward) + ' earned' : 'Session complete';
        $('runDoneText').textContent = r.capReached
          ? 'You reached today\'s limit for this module. It resets at midnight (Lagos time).'
          : 'Credited to your wallet. New balance: ' + naira(r.balance) + '.';
      }
      $('topBalance').textContent = naira(r.balance);
      loadModules().then(function () {
        renderTaskModules();
        run.module = state.modules.find(function (x) { return x.key === run.module.key; }) || run.module;
      });
      loadSessions();
      refreshStats();
    }, function (err) {
      if (err.status === 409) return sessionGone(err);
      if (throwErrors) { run.completing = true; throw err; }
      // Server counted less time than shown (e.g. a dropped heartbeat): sync and keep going
      api.post('/tasks/sessions/' + run.session.id + '/beat').then(function (r) {
        run.active = r.session.activeSeconds;
        run.completing = false;
      }, sessionGone);
    });
  }

  document.addEventListener('visibilitychange', function () {
    if (!run.session || !run.module) return;
    run.hiddenPause = run.module.requireFocus && document.hidden;
    updatePause();
  });

  $('runAbandon').addEventListener('click', function () {
    if (!run.session || !confirm('Stop this session? You won\'t be paid for it.')) return;
    api.post('/tasks/sessions/' + run.session.id + '/abandon').then(function () {
      stopLive();
      run.session = null;
      $('runMedia').innerHTML = '';
      $('runner').hidden = true;
      loadSessions();
    });
  });
  $('runClose').addEventListener('click', function () {
    if (run.session) { $('runAbandon').click(); return; }
    $('runner').hidden = true;
  });
  $('runAgain').addEventListener('click', function () { openRunner(run.module.key); });

  // ── Marketplace ──
  var browseItems = [];
  function listingCard(l) {
    return '<article class="card listing"><div class="listing-img">' +
      (l.image ? '<img src="' + esc(l.image) + '" alt="' + esc(l.title) + '" loading="lazy">' : '<svg class="icon"><use href="#i-bag"/></svg>') +
      '</div><div class="listing-body"><h4>' + esc(l.title) + '</h4><div class="price">' + naira(l.price) + '</div>' +
      '<div class="meta">' + esc(l.category) + (l.seller ? ' · ' + esc(l.seller) : '') + '</div>' +
      '<div class="stock">' + l.quantity + ' in stock</div>' +
      (l.mine ? '<div class="meta">Your listing</div>' : '<button type="button" class="btn btn-primary buy" data-buy="' + l.id + '">Buy</button>') +
      '</div></article>';
  }

  function orderRow(o) {
    var lines = [naira(o.price) + (o.plan === 'INSTALMENT' ? ' · instalments' : '') + ' · ' + esc(o.counterpart) + ' · ' + day(o.createdAt)];
    var actions = [];
    if (o.role === 'buyer') {
      if (o.status === 'AWAITING_BALANCE') {
        lines.push('Paid ' + naira(o.paidAmount) + ' · ' + naira(o.balanceDue) + ' due by ' + day(o.dueAt));
        actions.push('<button class="btn btn-primary" data-order="pay-balance" data-id="' + o.id + '">Pay ' + naira(o.balanceDue) + '</button>');
        actions.push('<button class="btn btn-ghost" data-order="cancel" data-id="' + o.id + '">Cancel</button>');
      } else if (o.status === 'PAID') {
        lines.push('Waiting for the seller to ship');
        actions.push('<button class="btn btn-ghost" data-order="cancel" data-id="' + o.id + '">Cancel</button>');
      } else if (o.status === 'SHIPPED') {
        lines.push('Shipped ' + day(o.shippedAt) + ' — confirm when it arrives');
        actions.push('<button class="btn btn-primary" data-order="received" data-id="' + o.id + '">I received it</button>');
        actions.push('<button class="btn btn-ghost" data-order="dispute" data-id="' + o.id + '">Report a problem</button>');
      } else if (o.status === 'DISPUTED') {
        lines.push('You reported: ' + esc(o.disputeReason) + ' — our team is reviewing');
      }
    } else {
      if (o.status === 'AWAITING_BALANCE') {
        lines.push('Deposit paid — don\'t ship until the balance is paid (due ' + day(o.dueAt) + ')');
      } else if (o.status === 'PAID') {
        lines.push('Deliver to: ' + esc(o.delivery.name) + ' · ' + esc(o.delivery.phone) + ' · ' + esc(o.delivery.address));
        actions.push('<button class="btn btn-primary" data-order="ship" data-id="' + o.id + '">Mark shipped</button>');
        actions.push('<button class="btn btn-ghost" data-order="cancel" data-id="' + o.id + '">Cancel</button>');
      } else if (o.status === 'SHIPPED') {
        lines.push('Waiting for the buyer to confirm delivery');
      } else if (o.status === 'DISPUTED') {
        lines.push('Buyer reported: ' + esc(o.disputeReason));
      }
    }
    if (o.adminNote && (o.status === 'CANCELLED' || o.status === 'COMPLETED')) lines.push(esc(o.adminNote));
    return '<li class="order"><div class="t-main"><div class="t-title">' + esc(o.title) + '</div>' +
      lines.map(function (l) { return '<div class="t-sub">' + l + '</div>'; }).join('') +
      (actions.length ? '<div class="order-actions">' + actions.join('') + '</div>' : '') + '</div>' + chip(o.status) + '</li>';
  }

  function loadOrders() {
    api.get('/marketplace/orders').then(function (r) {
      $('orderList').innerHTML = r.items.length ? r.items.map(orderRow).join('') : emptyRow('You haven\'t bought anything yet.');
    });
    api.get('/marketplace/sales').then(function (r) {
      $('salesList').innerHTML = r.items.length ? r.items.map(orderRow).join('') : emptyRow('No sales yet.');
    });
  }

  function loadMarketplace() {
    if (!state.loaded.categories) {
      api.get('/marketplace/categories').then(function (r) {
        state.loaded.categories = true;
        $('lCategory').innerHTML = '<option value="" disabled selected hidden>Choose…</option>' +
          r.categories.map(function (c) { return '<option>' + esc(c) + '</option>'; }).join('');
      });
    }
    if (!state.orderTerms) api.get('/marketplace/order-terms').then(function (t) { state.orderTerms = t; });
    api.get('/marketplace/mine').then(function (r) {
      $('myListings').innerHTML = r.items.length ? r.items.map(function (l) {
        return '<li><div class="t-main"><div class="t-title">' + esc(l.title) + '</div><div class="t-sub">' + naira(l.price) + ' · ' + l.quantity + ' in stock · ' + when(l.createdAt) +
          (l.adminNote ? ' · ' + esc(l.adminNote) : '') + '</div></div>' + chip(l.status) + '</li>';
      }).join('') : emptyRow('You have not listed anything yet.');
    });
    api.get('/marketplace').then(function (r) {
      browseItems = r.items;
      $('browseListings').innerHTML = r.items.length ? r.items.map(listingCard).join('') : '<div class="card empty-block">No products for sale yet. Be the first to list one.</div>';
    });
    loadOrders();
  }

  bindForm($('listingForm'), function (form) {
    var fd = new FormData(form);
    fd.set('price', String(fd.get('price') || '').replace(/\D/g, ''));
    fd.set('quantity', String(fd.get('quantity') || '1').replace(/\D/g, '') || '1');
    if (!$('lImage').files.length) fd.delete('image');
    return api.post('/marketplace', fd).then(function () {
      form.reset();
      toast('Listing submitted. You\'ll be paid once it\'s approved.');
      loadMarketplace();
    });
  });

  // Buy flow
  var buying = null;
  $('browseListings').addEventListener('click', function (e) {
    var b = e.target.closest('[data-buy]');
    if (!b) return;
    buying = browseItems.find(function (l) { return l.id === Number(b.dataset.buy); });
    if (!buying) return;
    var t = state.orderTerms || { depositPercent: 50, instalmentDays: 14, autoReleaseDays: 7 };
    var deposit = Math.ceil(buying.price * t.depositPercent / 100);
    $('buyTitle').textContent = 'Buy ' + buying.title;
    $('buyPrice').textContent = naira(buying.price) + ' · sold by ' + (buying.seller || 'a member');
    $('planFullText').textContent = 'Pay ' + naira(buying.price) + ' now. The seller ships right away.';
    $('planInstText').textContent = 'Pay ' + naira(deposit) + ' now and ' + naira(buying.price - deposit) + ' within ' + t.instalmentDays + ' days. The seller ships once it\'s fully paid; if you don\'t finish paying, your deposit comes back to you.';
    $('buyBalance').textContent = naira(state.data.stats.balance);
    var u = state.data.user;
    if (!$('dName').value) $('dName').value = u.firstName + ' ' + u.lastName;
    if (!$('dPhone').value) $('dPhone').value = fmtPhone(u.phone);
    $('buyForm').querySelector('.form-error').textContent = '';
    $('buyModal').hidden = false;
  });
  document.querySelectorAll('[data-close-modal]').forEach(function (b) {
    b.addEventListener('click', function () { b.closest('.modal').hidden = true; });
  });
  $('buyModal').addEventListener('click', function (e) { if (e.target === $('buyModal')) $('buyModal').hidden = true; });

  bindForm($('buyForm'), function (form) {
    var fd = Object.fromEntries(new FormData(form));
    return api.post('/marketplace/orders', {
      listingId: buying.id, plan: fd.plan, deliveryName: fd.deliveryName, deliveryPhone: fd.deliveryPhone, deliveryAddress: fd.deliveryAddress,
    }).then(function () {
      $('buyModal').hidden = true;
      toast(fd.plan === 'FULL' ? 'Order placed. The seller has been told to ship.' : 'Deposit paid. Pay the balance from My orders.');
      return refreshStats().then(loadMarketplace);
    });
  });

  document.querySelector('#tab-marketplace').addEventListener('click', function (e) {
    var b = e.target.closest('[data-order]');
    if (!b) return;
    var action = b.dataset.order, id = b.dataset.id, body = {};
    var confirms = {
      'pay-balance': 'Pay the balance from your wallet now?',
      cancel: 'Cancel this order? Any money paid goes back to the buyer\'s wallet.',
      ship: 'Mark this order as shipped? Only do this once you have sent it.',
      received: 'Confirm you received this item? The seller will be paid.',
    };
    if (action === 'dispute') {
      var reason = prompt('What went wrong with this order?', '');
      if (!reason) return;
      body.reason = reason;
    } else if (!confirm(confirms[action])) {
      return;
    }
    b.disabled = true;
    api.post('/marketplace/orders/' + id + '/' + action, body).then(function () {
      toast({ 'pay-balance': 'Balance paid. The seller will ship now.', cancel: 'Order cancelled.', ship: 'Marked as shipped.', received: 'Thanks! The seller has been paid.', dispute: 'Problem reported. Our team will review it.' }[action]);
      refreshStats();
      loadMarketplace();
    }, function (err) { b.disabled = false; toast(err.message, true); });
  });

  // ── Wallet ──
  var BANKS = ['Access Bank', 'Ecobank', 'Fidelity Bank', 'First Bank', 'FCMB', 'GTBank', 'Keystone Bank', 'Kuda', 'Moniepoint', 'OPay', 'PalmPay',
    'Polaris Bank', 'Stanbic IBTC', 'Sterling Bank', 'UBA', 'Union Bank', 'Wema Bank', 'Zenith Bank', 'MTN MoMo', 'M-Pesa', 'Absa', 'Equity Bank', 'Standard Bank'];

  function loadWallet() {
    var d = state.data, s = d.stats, u = d.user;
    $('wBalance').textContent = naira(s.balance);
    $('wPending').textContent = naira(s.pendingWithdrawals);
    $('wWithdrawn').textContent = naira(s.totalWithdrawn);
    $('bankList').innerHTML = BANKS.map(function (b) { return '<option value="' + esc(b) + '">'; }).join('');
    if (u.bank) {
      $('bBank').value = u.bank.bankName;
      $('bNumber').value = u.bank.accountNumber;
      $('bName').value = u.bank.accountName;
    }
    $('wHint').textContent = u.bank
      ? 'Paid to ' + u.bank.bankName + ' · ' + u.bank.accountNumber + (d.config.minWithdrawal ? ' · minimum ' + naira(d.config.minWithdrawal) : '') + '.'
      : 'Save a bank account first.';
    api.get('/me/withdrawals').then(function (r) {
      $('withdrawList').innerHTML = r.items.length ? r.items.map(function (w) {
        return '<li><div class="t-main"><div class="t-title">' + naira(w.amount) + ' to ' + esc(w.bankName) + ' · ' + esc(w.accountNumber) + '</div><div class="t-sub">' +
          when(w.createdAt) + (w.adminNote ? ' · ' + esc(w.adminNote) : '') + '</div></div>' + chip(w.status) + '</li>';
      }).join('') : emptyRow('No withdrawals yet.');
    });
    loadTx();
  }

  function loadTx() {
    api.get('/me/transactions?page=' + state.txPage).then(function (r) {
      $('txList').innerHTML = r.items.length ? r.items.map(txRow).join('') : emptyRow('No transactions yet.');
      $('txPage').textContent = 'Page ' + r.page + ' of ' + r.pages;
      $('txPrev').disabled = r.page <= 1;
      $('txNext').disabled = r.page >= r.pages;
    });
  }
  $('txPrev').addEventListener('click', function () { state.txPage--; loadTx(); });
  $('txNext').addEventListener('click', function () { state.txPage++; loadTx(); });

  bindForm($('bankForm'), function () {
    return api.put('/me/bank', { bankName: $('bBank').value, accountNumber: $('bNumber').value, accountName: $('bName').value }).then(function (r) {
      state.data.user = r.user;
      toast('Bank account saved.');
      loadWallet();
    });
  });

  bindForm($('withdrawForm'), function () {
    var amount = Number(String($('wAmount').value).replace(/\D/g, ''));
    return api.post('/me/withdrawals', { amount: amount }).then(function () {
      $('wAmount').value = '';
      toast('Withdrawal requested. Paid within 24–48 hours on business days.');
      return refreshStats().then(loadWallet);
    });
  });

  // ── Referrals ──
  function loadReferrals() {
    var link = location.origin + location.pathname.replace(/dashboard\.html$/, '') + 'signup.html?ref=' + state.data.user.referralCode;
    $('refLink').textContent = link;
    var levels = state.data.config.spilloverLevels || [];
    $('spillNote').textContent = levels.length
      ? 'Spillovers: ' + levels.map(function (amt, i) { return 'level ' + (i + 2) + ' ' + naira(amt); }).join(', ') + ' — paid when people further down your network activate.'
      : '';
    api.get('/me/referrals').then(function (r) {
      $('rCount').textContent = r.direct.length;
      $('rEarned').textContent = naira(r.referralEarned);
      $('rSpill').textContent = naira(r.spilloverEarned);
      $('refList').innerHTML = r.direct.length ? r.direct.map(function (p) {
        return '<li><div class="t-main"><div class="t-title">' + esc(p.name) + '</div><div class="t-sub">Joined ' + when(p.joinedAt) + '</div></div>' + chip(p.status) + '</li>';
      }).join('') : emptyRow('No referrals yet. Share your link to start earning.');
    });
  }
  $('copyRef').addEventListener('click', function () {
    var text = $('refLink').textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(
      function () { toast('Referral link copied.'); },
      function () { prompt('Copy your referral link:', text); }
    );
  });

  // ── Friday Bonus ──
  function loadFbr() {
    var card = $('fbrCard');
    api.get('/fbr').then(function (f) {
      var reward = naira(f.reward) + ' (' + usd(f.reward) + ')';
      if (f.state === 'not_friday') {
        card.innerHTML = '<h3>Next drop: Friday at ' + String(f.openHour).padStart(2, '0') + ':00</h3><p class="muted">Come back on Friday. A number appears here and the fastest members to claim it win ' + reward + '.</p>';
      } else if (f.state === 'upcoming') {
        card.innerHTML = '<h3>Today\'s drop opens at ' + new Date(f.opensAt).toLocaleTimeString('en-NG', { hour: '2-digit', minute: '2-digit' }) + '</h3><p class="muted">' + f.maxWinners + ' rewards of ' + reward + ' up for grabs. Stay close.</p>';
      } else if (f.state === 'claimed') {
        card.innerHTML = '<h3>You won this week\'s Friday Bonus 🎉</h3><p class="muted">' + reward + ' has been added to your wallet.</p>';
      } else if (f.state === 'closed') {
        card.innerHTML = '<h3>All rewards claimed</h3><p class="muted">This week\'s ' + f.maxWinners + ' rewards are gone. Try again next Friday.</p>';
      } else {
        card.innerHTML = '<h3>Friday Bonus is live!</h3><p class="muted">' + f.claimsLeft + ' of ' + f.maxWinners + ' rewards left. Type the number below to claim ' + reward + '.</p>' +
          '<div class="fbr-code">' + esc(f.code) + '</div>' +
          '<form id="fbrForm" class="app-form" novalidate><div class="app-field"><label for="fbrInput">Enter the number</label><input id="fbrInput" inputmode="numeric" autocomplete="off" required></div>' +
          '<p class="form-error" role="alert"></p><button type="submit" class="btn btn-primary btn-block">Claim ' + esc(naira(f.reward)) + '</button></form>';
        bindForm($('fbrForm'), function () {
          return api.post('/fbr/claim', { code: $('fbrInput').value }).then(function (r) {
            toast('You won ' + naira(r.reward) + '!');
            refreshStats();
            loadFbr();
          });
        });
      }
    }, function (e) { card.innerHTML = '<p class="muted">' + esc(e.message) + '</p>'; });
  }

  // ── AI Assistant ──
  try { state.chat = JSON.parse(sessionStorage.getItem('jovia_chat') || '[]'); } catch (e) { state.chat = []; }

  function renderChat(typing) {
    var log = $('chatLog');
    var bubbles = state.chat.map(function (m) { return '<div class="bubble ' + (m.role === 'user' ? 'me' : 'ai') + '">' + esc(m.content) + '</div>'; });
    if (!state.chat.length) bubbles.push('<div class="bubble ai">Hi ' + esc(state.data.user.firstName) + '! I\'m the Jovia AI Assistant. Ask me anything about your tasks, wallet, referrals or the marketplace.</div>');
    if (typing) bubbles.push('<div class="bubble ai typing">Thinking…</div>');
    log.innerHTML = bubbles.join('');
    log.scrollTop = log.scrollHeight;
    $('chatSuggest').hidden = state.chat.length > 0;
  }

  function loadAssistant() {
    renderChat(false);
    api.get('/assistant/status').then(function (s) {
      $('aiRemaining').textContent = s.available ? s.remaining + ' of ' + s.limit + ' messages left today.' : 'The assistant is not switched on yet.';
      $('chatInput').disabled = !s.available;
    }, function () {});
  }

  function sendChat(text) {
    text = text.trim();
    if (!text) return;
    state.chat.push({ role: 'user', content: text });
    renderChat(true);
    $('chatInput').value = '';
    $('chatInput').disabled = true;
    api.post('/assistant', { messages: state.chat.slice(-12) }).then(function (r) {
      state.chat.push({ role: 'assistant', content: r.reply });
      if (typeof r.remaining === 'number') $('aiRemaining').textContent = r.remaining + ' messages left today.';
    }, function (err) {
      state.chat.pop();
      toast(err.message, true);
      $('chatInput').value = text;
    }).then(function () {
      try { sessionStorage.setItem('jovia_chat', JSON.stringify(state.chat.slice(-20))); } catch (e) { /* storage unavailable */ }
      $('chatInput').disabled = false;
      $('chatInput').focus();
      renderChat(false);
    });
  }
  $('chatForm').addEventListener('submit', function (e) { e.preventDefault(); sendChat($('chatInput').value); });
  $('chatSuggest').addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (b) sendChat(b.textContent);
  });

  // ── Account ──
  function loadAccount() {
    var u = state.data.user;
    $('pFirst').value = u.firstName;
    $('pLast').value = u.lastName;
    $('pEmail').textContent = u.email;
    $('pPhone').textContent = fmtPhone(u.phone);
    $('pCountry').textContent = u.country;
    $('pSince').textContent = new Date(u.createdAt).toLocaleDateString('en-NG', { day: 'numeric', month: 'long', year: 'numeric' });
  }
  bindForm($('profileForm'), function () {
    return api.put('/me/profile', { firstName: $('pFirst').value, lastName: $('pLast').value }).then(function (r) {
      state.data.user = r.user;
      fill('data-first-name', r.user.firstName);
      toast('Profile saved.');
    });
  });
  bindForm($('passwordForm'), function (form) {
    return api.put('/me/password', { current: $('pwCurrent').value, next: $('pwNext').value }).then(function () {
      form.reset();
      toast('Password updated.');
    });
  });

  $('logoutBtn').addEventListener('click', function () {
    try { sessionStorage.removeItem('jovia_chat'); } catch (e) { /* storage unavailable */ }
    api.post('/auth/logout').then(function () { window.location.href = 'signin.html'; }, function () { window.location.href = 'signin.html'; });
  });

  load();
})();
