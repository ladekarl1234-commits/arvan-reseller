/**
 * Arvan Reseller storefront JS. Vanilla, no dependencies (ADR-0002).
 * All prices/decisions are server-side; this file only submits intents and
 * renders responses.
 */
(function () {
  'use strict';
  if (typeof ARVRS === 'undefined') return;

  var $ = function (sel, root) { return (root || document).querySelector(sel); };

  function api(path, body) {
    return fetch(ARVRS.rest + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ARVRS.nonce },
      body: JSON.stringify(body || {})
    }).then(function (res) {
      return res.json().then(function (json) { return { status: res.status, json: json }; });
    });
  }

  /* ---------- auth tabs ---------- */
  var tabLogin = $('#arvrs-tab-login'), tabRegister = $('#arvrs-tab-register');
  if (tabLogin && tabRegister) {
    var switchTab = function (active) {
      var login = active === 'login';
      tabLogin.classList.toggle('is-active', login);
      tabRegister.classList.toggle('is-active', !login);
      tabLogin.setAttribute('aria-selected', login);
      tabRegister.setAttribute('aria-selected', !login);
      $('#arvrs-panel-login').hidden = !login;
      $('#arvrs-panel-register').hidden = login;
    };
    tabLogin.addEventListener('click', function () { switchTab('login'); });
    tabRegister.addEventListener('click', function () { switchTab('register'); });
  }

  /* ---------- product order form ---------- */
  var orderForm = $('#arvrs-order-form');
  if (orderForm) {
    // Live price update on plan change.
    orderForm.addEventListener('change', function (e) {
      if (e.target.name === 'plan_id') {
        var total = $('#arvrs-total');
        if (total) total.textContent = e.target.getAttribute('data-price-label') || '';
      }
    });
    orderForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = $('#arvrs-buy'), err = $('#arvrs-order-error');
      if (!btn) return;
      btn.disabled = true;
      btn.textContent = ARVRS.i18n.processing;
      if (err) err.hidden = true;

      var config = {};
      ['region', 'image', 'name', 'domain', 'bucket'].forEach(function (field) {
        var input = orderForm.querySelector('[name="' + field + '"]');
        if (input && input.value) config[field] = input.value;
      });
      var plan = orderForm.querySelector('[name="plan_id"]:checked');

      api('checkout', {
        product: orderForm.getAttribute('data-product'),
        plan_id: plan ? plan.value : '',
        config: config
      }).then(function (res) {
        if (res.status === 200 && res.json.redirect) {
          window.location.href = res.json.redirect;
          return;
        }
        if (err) {
          err.textContent = (res.json && res.json.message) ? res.json.message : ARVRS.i18n.error;
          err.hidden = false;
        }
        btn.disabled = false;
        btn.textContent = btn.getAttribute('data-label') || 'ادامه و پرداخت';
      }).catch(function () {
        if (err) { err.textContent = ARVRS.i18n.error; err.hidden = false; }
        btn.disabled = false;
      });
    });
  }

  /* ---------- sandbox gateway ---------- */
  var gateway = $('#arvrs-gateway');
  if (gateway) {
    var ref = gateway.getAttribute('data-ref');
    var type = gateway.getAttribute('data-type');
    var proof = gateway.getAttribute('data-proof');
    var callback = function () {
      return api('payment/callback', { ref: ref, type: type, sandbox_proof: proof });
    };

    var payOk = $('#arvrs-pay-ok');
    if (payOk) payOk.addEventListener('click', function () {
      $('#arvrs-pay-progress').hidden = false;
      payOk.disabled = true;
      var fail = $('#arvrs-pay-fail'); if (fail) fail.disabled = true;
      callback().then(function (res) {
        $('#arvrs-pay-progress').hidden = true;
        if (res.json && res.json.ok) {
          payOk.hidden = true; if (fail) fail.hidden = true;
          $('#arvrs-pay-done').hidden = false;
          var message = $('#arvrs-pay-message');
          if (message) message.textContent = res.json.message || '';
        } else {
          alert((res.json && res.json.message) || ARVRS.i18n.error);
          payOk.disabled = false; if (fail) fail.disabled = false;
        }
      });
    });

    var replay = $('#arvrs-pay-replay');
    if (replay) replay.addEventListener('click', function () {
      replay.disabled = true;
      callback().then(function (res) {
        var out = $('#arvrs-replay-result');
        if (out) {
          out.textContent = res.json && res.json.replay
            ? '✓ کال‌بک تکراری شناسایی شد — هیچ تراکنش یا سرویس دوباره‌ای ساخته نشد.'
            : (res.json && res.json.message) || '';
        }
        replay.disabled = false;
      });
    });

    var payFail = $('#arvrs-pay-fail');
    if (payFail) payFail.addEventListener('click', function () {
      window.location.href = ARVRS.pages.checkout;
    });
  }

  /* ---------- wallet top-up ---------- */
  var topupBtn = $('#arvrs-topup-btn');
  if (topupBtn) topupBtn.addEventListener('click', function () {
    var amount = parseInt($('#arvrs-topup-amount').value, 10);
    var err = $('#arvrs-topup-error');
    topupBtn.disabled = true;
    topupBtn.textContent = ARVRS.i18n.processing;
    api('me/topup', { amount: amount }).then(function (res) {
      if (res.status === 200 && res.json.redirect) {
        window.location.href = res.json.redirect;
        return;
      }
      if (err) {
        err.textContent = (res.json && res.json.message) || ARVRS.i18n.error;
        err.hidden = false;
      }
      topupBtn.disabled = false;
      topupBtn.textContent = 'پرداخت و شارژ';
    });
  });

  /* ---------- notifications mark-read ---------- */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.arvrs-mark-read');
    if (!btn) return;
    api('me/notifications/' + btn.getAttribute('data-id') + '/read', {}).then(function () {
      var card = btn.closest('.arvrs-notification');
      if (card) card.classList.remove('is-unread');
      btn.remove();
    });
  });
})();
