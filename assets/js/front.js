/**
 * Arvan Reseller storefront JS. Vanilla, no dependencies (ADR-0002).
 * All prices/decisions are server-side; this file only submits intents and
 * renders what the server reported.
 *
 * The pure logic — the payment poll state machine, the state→panel map and the
 * tablist key handler — is exported on `window.ARVRS_FRONT` so it can be
 * exercised without a browser (EX-126). Everything below the export is DOM
 * wiring and is skipped when the localized `ARVRS` object is absent.
 */
(function (root) {
  'use strict';

  /* ==================== pure logic (exported, testable) ==================== */

  var MAX_POLLS = 20;
  var POLL_INTERVAL_MS = 3000;

  /**
   * Which result panel a server-reported provisioning state maps to.
   *
   * The payment page renders from this map and from nothing else. An unknown
   * or missing state resolves to 'failed', never to 'ready': the page must not
   * announce a service it has not observed (EX-002).
   */
  var PANEL_BY_STATE = { active: 'ready', pending: 'pending', failed: 'failed' };

  function panelForState(state) {
    return Object.prototype.hasOwnProperty.call(PANEL_BY_STATE, state)
      ? PANEL_BY_STATE[state]
      : 'failed';
  }

  /** @returns {{attempts:number,max:number,done:boolean,last:string}} */
  function createPollState(maxAttempts) {
    return { attempts: 0, max: maxAttempts || MAX_POLLS, done: false, last: '' };
  }

  /**
   * Advance the machine by one observed provisioning state.
   *
   * 'wait' means poll again after POLL_INTERVAL_MS. 'timeout' is the honest
   * terminal state: provisioning is still running, we stopped watching, and
   * the customer is told they will be notified — it is never reported as
   * success.
   *
   * @returns {'ready'|'failed'|'wait'|'timeout'}
   */
  function pollStep(machine, state) {
    if (machine.done) {
      return machine.last;
    }
    var panel = panelForState(state);
    if (panel === 'ready' || panel === 'failed') {
      machine.done = true;
      machine.last = panel;
      return panel;
    }
    machine.attempts += 1;
    if (machine.attempts >= machine.max) {
      machine.done = true;
      machine.last = 'timeout';
      return 'timeout';
    }
    return 'wait';
  }

  /**
   * ARIA APG tabs key handling with a roving tabindex. The storefront is
   * RTL-first, so in RTL ArrowLeft advances and ArrowRight goes back.
   *
   * @returns {number} index to activate, or -1 when the key is not ours
   */
  function nextTabIndex(key, current, count, rtl) {
    if (count < 1) {
      return -1;
    }
    var forward = rtl ? 'ArrowLeft' : 'ArrowRight';
    var back = rtl ? 'ArrowRight' : 'ArrowLeft';
    if (key === forward) { return (current + 1) % count; }
    if (key === back) { return (current - 1 + count) % count; }
    if (key === 'Home') { return 0; }
    if (key === 'End') { return count - 1; }
    return -1;
  }

  root.ARVRS_FRONT = {
    MAX_POLLS: MAX_POLLS,
    POLL_INTERVAL_MS: POLL_INTERVAL_MS,
    PANEL_BY_STATE: PANEL_BY_STATE,
    panelForState: panelForState,
    createPollState: createPollState,
    pollStep: pollStep,
    nextTabIndex: nextTabIndex
  };

  /* ============================== DOM wiring ============================== */

  if (typeof root.ARVRS === 'undefined' || typeof document === 'undefined') {
    return;
  }
  var ARVRS = root.ARVRS;
  var t = ARVRS.i18n || {};

  var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
  var isRtl = function () {
    var app = $('.arvrs-app');
    return app ? (app.getAttribute('dir') || 'rtl') === 'rtl' : true;
  };

  function request(method, path, body) {
    var init = {
      method: method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ARVRS.nonce }
    };
    if (body) {
      init.body = JSON.stringify(body);
    }
    return fetch(ARVRS.rest + path, init).then(function (res) {
      return res.json()
        .catch(function () { return {}; }) // a proxy error page is not JSON
        .then(function (json) { return { status: res.status, json: json }; });
    });
  }

  function api(path, body) { return request('POST', path, body || {}); }
  function apiGet(path) { return request('GET', path, null); }

  /** Write into an already-visible live region (EX-112: unhide first, then write). */
  function announce(el, message) {
    if (!el) { return; }
    el.hidden = false;
    // A separate task lets AT observe the change against rendered content.
    root.setTimeout(function () { el.textContent = message; }, 60);
  }

  /** Same ordering rule for role="alert": render the box, then fill it. */
  function showError(el, message) {
    announce(el, message || t.error || '');
  }

  /* ---------- auth tabs (ARIA APG: click + arrow keys, roving tabindex) ---------- */
  var tabLogin = $('#arvrs-tab-login'), tabRegister = $('#arvrs-tab-register');
  if (tabLogin && tabRegister) {
    var tabs = [tabLogin, tabRegister];
    var panels = [$('#arvrs-panel-login'), $('#arvrs-panel-register')];

    var switchTab = function (index, moveFocus) {
      tabs.forEach(function (tab, i) {
        var on = i === index;
        tab.classList.toggle('is-active', on);
        tab.setAttribute('aria-selected', on ? 'true' : 'false');
        tab.tabIndex = on ? 0 : -1;
        if (panels[i]) { panels[i].hidden = !on; }
      });
      if (moveFocus) { tabs[index].focus(); }
    };

    tabs.forEach(function (tab, i) {
      tab.addEventListener('click', function () { switchTab(i, false); });
      tab.addEventListener('keydown', function (e) {
        var next = nextTabIndex(e.key, i, tabs.length, isRtl());
        if (next < 0) { return; }
        e.preventDefault();
        switchTab(next, true);
      });
    });

    // Honour the server's choice of open panel (a failed registration re-opens
    // the register tab with its values intact — EX-066).
    switchTab(tabRegister.getAttribute('aria-selected') === 'true' ? 1 : 0, false);
  }

  /* ---------- mobile nav disclosure ---------- */
  var navToggle = $('[data-arvrs-nav-toggle]');
  if (navToggle) {
    var navId = navToggle.getAttribute('aria-controls');
    var nav = navId ? document.getElementById(navId) : null;
    navToggle.addEventListener('click', function () {
      var open = navToggle.getAttribute('aria-expanded') !== 'true';
      navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (nav) { nav.classList.toggle('is-open', open); }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && navToggle.getAttribute('aria-expanded') === 'true') {
        navToggle.setAttribute('aria-expanded', 'false');
        if (nav) { nav.classList.remove('is-open'); }
        navToggle.focus();
      }
    });
  }

  /* ---------- product order form ---------- */
  var orderForm = $('#arvrs-order-form');
  if (orderForm) {
    orderForm.addEventListener('change', function (e) {
      if (e.target.name === 'plan_id') {
        var total = $('#arvrs-total');
        if (total) { total.textContent = e.target.getAttribute('data-price-label') || ''; }
      }
    });
    orderForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = $('#arvrs-buy'), err = $('#arvrs-order-error');
      if (!btn) { return; }
      var restore = function () {
        btn.disabled = false;
        btn.textContent = btn.getAttribute('data-label') || '';
      };
      btn.disabled = true;
      btn.textContent = t.processing || '';
      if (err) { err.hidden = true; }

      var config = {};
      ['region', 'image', 'name', 'domain', 'bucket'].forEach(function (field) {
        var input = orderForm.querySelector('[name="' + field + '"]');
        if (input && input.value) { config[field] = input.value; }
      });
      var plan = orderForm.querySelector('[name="plan_id"]:checked');

      api('checkout', {
        product: orderForm.getAttribute('data-product'),
        plan_id: plan ? plan.value : '',
        config: config
      }).then(function (res) {
        if (res.status === 200 && res.json.redirect) {
          root.location.href = res.json.redirect;
          return;
        }
        showError(err, (res.json && res.json.message) || t.error);
        restore();
      }).catch(function () {
        showError(err, t.error);
        restore();
      });
    });
  }

  /* ---------- payment gateway ---------- */
  var gateway = $('#arvrs-gateway');
  if (gateway) {
    var ref = gateway.getAttribute('data-ref');
    var payType = gateway.getAttribute('data-type');
    var proof = gateway.getAttribute('data-proof');
    var orderId = parseInt(gateway.getAttribute('data-order-id'), 10) || 0;

    var progress = $('#arvrs-pay-progress');
    var progressText = $('#arvrs-pay-progress-text');
    var live = $('#arvrs-pay-live');
    var result = $('#arvrs-pay-result');
    var payOk = $('#arvrs-pay-ok');
    var payFail = $('#arvrs-pay-fail');
    var actions = $('#arvrs-pay-actions');

    var setProgress = function (visible, message) {
      if (progress) { progress.hidden = !visible; }
      if (progressText && visible) { progressText.textContent = message || ''; }
    };

    /**
     * Reveal exactly one result panel and hand keyboard focus to its heading
     * (EX-112 — hiding the button the user just pressed dropped focus to
     * <body>). `panel` is always one of panelForState()'s outputs.
     */
    var showPanel = function (panel, message) {
      if (actions) { actions.hidden = true; }
      setProgress(false, '');
      if (result) { result.hidden = false; }
      ['ready', 'pending', 'failed', 'timeout'].forEach(function (name) {
        var el = $('#arvrs-panel-' + name);
        if (el) { el.hidden = name !== panel; }
      });
      var shown = $('#arvrs-panel-' + panel);
      var slot = shown ? shown.querySelector('[data-arvrs-panel-message]') : null;
      if (slot && message) { slot.textContent = message; }
      announce(live, message || (shown ? shown.getAttribute('data-arvrs-announce') || '' : ''));
      var heading = shown ? shown.querySelector('[data-arvrs-panel-heading]') : null;
      if (heading) { heading.focus(); }
    };

    /** Bounded poll of the owner-scoped state route; never claims readiness. */
    var machine = createPollState(MAX_POLLS);
    var poll = function () {
      apiGet('orders/' + orderId + '/state').then(function (res) {
        var payload = res.json || {};
        var step = pollStep(machine, res.status === 200 ? payload.provision_state : 'pending');
        if (step === 'wait') {
          root.setTimeout(poll, POLL_INTERVAL_MS);
          return;
        }
        if (step === 'timeout') {
          showPanel('timeout', '');
          return;
        }
        showPanel(step, payload.message || '');
      }).catch(function () {
        // A dropped poll is not a failed provisioning — keep counting attempts
        // so the loop still terminates, and stay on the honest pending panel.
        var step = pollStep(machine, 'pending');
        if (step === 'wait') {
          root.setTimeout(poll, POLL_INTERVAL_MS);
        } else {
          showPanel('timeout', '');
        }
      });
    };

    if (payOk) {
      payOk.addEventListener('click', function () {
        var restore = function () {
          payOk.disabled = false;
          if (payFail) { payFail.disabled = false; }
          setProgress(false, '');
        };
        payOk.disabled = true;
        if (payFail) { payFail.disabled = true; }
        setProgress(true, t.verifying || t.processing || '');

        api('payment/callback', { ref: ref, type: payType, sandbox_proof: proof })
          .then(function (res) {
            var payload = res.json || {};
            if (!payload.ok) {
              // A refused callback is recoverable: give the buttons back and
              // say why, in the page's own alert (never alert()).
              restore();
              showPanel('failed', payload.message || t.error);
              if (actions) { actions.hidden = false; }
              return;
            }
            if (payType === 'topup') {
              showPanel('ready', payload.message || '');
              return;
            }
            var provision = payload.provision || {};
            var panel = panelForState(provision.state);
            if (panel === 'pending' && orderId) {
              showPanel('pending', provision.message || '');
              root.setTimeout(poll, POLL_INTERVAL_MS);
              return;
            }
            showPanel(panel === 'pending' ? 'timeout' : panel, provision.message || payload.message || '');
          })
          .catch(function () {
            // The money may or may not have moved. Say exactly that, and let
            // the customer retry or check the dashboard (EX-017).
            restore();
            showError($('#arvrs-pay-error'), t.payUnknown || t.error);
          });
      });
    }

    var replay = $('#arvrs-pay-replay');
    if (replay) {
      replay.addEventListener('click', function () {
        var out = $('#arvrs-replay-result');
        replay.disabled = true;
        api('payment/callback', { ref: ref, type: payType, sandbox_proof: proof })
          .then(function (res) {
            if (out) {
              out.textContent = res.json && res.json.replay
                ? (t.replayDetected || '')
                : ((res.json && res.json.message) || '');
            }
            replay.disabled = false;
          })
          .catch(function () {
            if (out) { out.textContent = t.error || ''; }
            replay.disabled = false;
          });
      });
    }

    if (payFail) {
      payFail.addEventListener('click', function () {
        root.location.href = ARVRS.pages.checkout;
      });
    }
  }

  /* ---------- wallet top-up ---------- */
  var topupBtn = $('#arvrs-topup-btn');
  if (topupBtn) {
    topupBtn.addEventListener('click', function () {
      var input = $('#arvrs-topup-amount');
      var err = $('#arvrs-topup-error');
      var restore = function () {
        topupBtn.disabled = false;
        topupBtn.textContent = topupBtn.getAttribute('data-label') || '';
      };
      if (err) { err.hidden = true; }
      topupBtn.disabled = true;
      topupBtn.textContent = t.processing || '';
      api('me/topup', { amount: parseInt(input ? input.value : '0', 10) || 0 })
        .then(function (res) {
          if (res.status === 200 && res.json.redirect) {
            root.location.href = res.json.redirect;
            return;
          }
          showError(err, (res.json && res.json.message) || t.error);
          restore();
        })
        .catch(function () {
          showError(err, t.error);
          restore();
        });
    });
  }

  /* ---------- copy a connection value ---------- */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-arvrs-copy]') : null;
    if (!btn || !root.navigator || !root.navigator.clipboard) { return; }
    root.navigator.clipboard.writeText(btn.getAttribute('data-arvrs-copy') || '').then(function () {
      var was = btn.textContent;
      btn.textContent = t.copied || was;
      root.setTimeout(function () { btn.textContent = was; }, 1600);
    }).catch(function () { /* clipboard denied — the value is on screen anyway */ });
  });

  /* ---------- notifications mark-read ---------- */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.arvrs-mark-read') : null;
    if (!btn) { return; }
    btn.disabled = true;
    api('me/notifications/' + btn.getAttribute('data-id') + '/read', {})
      .then(function () {
        var card = btn.closest('.arvrs-notification');
        if (card) { card.classList.remove('is-unread'); }
        btn.remove();
      })
      .catch(function () { btn.disabled = false; });
  });
})(typeof window !== 'undefined' ? window : this);
