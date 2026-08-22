/**
 * Browser-layer coverage for assets/js/front.js (EX-126).
 *
 * `.playwright-cli/` holds manual session dumps, not tests, so every
 * client-side behaviour was regression-tested by a human. This file covers the
 * part where a bug costs money or lies to a customer: the payment page's poll
 * state machine and the state→panel map, which decide whether the page
 * announces "سرویس شما آماده است" for a service nobody has observed.
 *
 * Node's built-in test runner, no framework, no dependency:
 *
 *   node --test tests/js/
 *
 * front.js is a plain IIFE that exports its pure logic on the global and skips
 * all DOM wiring when `document` is absent, so it is loaded into a bare vm
 * context rather than mocked.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../assets/js/front.js', import.meta.url), 'utf8');
const sandbox = vm.createContext({});
vm.runInContext(source, sandbox, { filename: 'front.js' });

const F = sandbox.ARVRS_FRONT;

test('the pure logic is exported for testing at all', () => {
  assert.ok(F, 'front.js must expose ARVRS_FRONT');
  for (const name of ['panelForState', 'createPollState', 'pollStep', 'nextTabIndex']) {
    assert.equal(typeof F[name], 'function', name);
  }
  assert.equal(typeof F.MAX_POLLS, 'number');
  assert.ok(F.MAX_POLLS > 0 && F.MAX_POLLS <= 60, 'a bounded poll, not an infinite one');
});

/* ------------------------------------------------------------- the label map */

test('each server state maps to its own panel', () => {
  assert.equal(F.panelForState('active'), 'ready');
  assert.equal(F.panelForState('pending'), 'pending');
  assert.equal(F.panelForState('failed'), 'failed');
});

test('anything the server did not say resolves to failed, never to ready', () => {
  for (const state of ['', 'ACTIVE', 'unknown', 'provisioning', null, undefined, 0, {}]) {
    assert.equal(F.panelForState(state), 'failed', `state: ${String(state)}`);
  }
});

test('inherited Object properties cannot be mistaken for a state', () => {
  // A bare `PANEL_BY_STATE[state]` lookup would return a function here and the
  // page would render a panel named "[object Function]" — or worse, truthy.
  for (const key of ['toString', 'constructor', 'hasOwnProperty', '__proto__']) {
    assert.equal(F.panelForState(key), 'failed', key);
  }
});

/* --------------------------------------------------------- the poll machine */

test('a fresh machine starts unfinished with the configured budget', () => {
  // Compared field by field, not with deepEqual: the object is minted in the
  // vm realm, so its prototype is not this realm's Object.prototype.
  const m = F.createPollState(5);
  assert.equal(m.attempts, 0);
  assert.equal(m.max, 5);
  assert.equal(m.done, false);
  assert.equal(m.last, '');
  assert.equal(F.createPollState().max, F.MAX_POLLS, 'no argument falls back to the shipped bound');
});

test('an observed active state ends the poll as ready', () => {
  const m = F.createPollState(5);
  assert.equal(F.pollStep(m, 'active'), 'ready');
  assert.equal(m.done, true);
});

test('an observed failure ends the poll as failed', () => {
  const m = F.createPollState(5);
  assert.equal(F.pollStep(m, 'failed'), 'failed');
  assert.equal(m.done, true);
});

test('pending keeps waiting until the budget runs out, then times out honestly', () => {
  const m = F.createPollState(3);
  assert.equal(F.pollStep(m, 'pending'), 'wait');
  assert.equal(F.pollStep(m, 'pending'), 'wait');
  assert.equal(F.pollStep(m, 'pending'), 'timeout', 'the last attempt is a timeout, not another wait');
  assert.equal(m.done, true);
  assert.equal(m.attempts, 3);
});

test('a timeout is never reported as success', () => {
  const m = F.createPollState(1);
  const result = F.pollStep(m, 'pending');
  assert.equal(result, 'timeout');
  assert.notEqual(result, 'ready');
});

test('a finished machine is sticky and never re-decides', () => {
  const m = F.createPollState(5);
  assert.equal(F.pollStep(m, 'failed'), 'failed');
  // A late response arriving after the machine settled must not flip the panel.
  assert.equal(F.pollStep(m, 'active'), 'failed');
  assert.equal(F.pollStep(m, 'pending'), 'failed');
  assert.equal(m.attempts, 0, 'a settled machine stops counting');
});

test('no sequence without an observed active state can ever end ready', () => {
  const states = ['pending', 'failed', '', 'unknown', undefined];
  for (const a of states) {
    for (const b of states) {
      for (const c of states) {
        const m = F.createPollState(10);
        const results = [F.pollStep(m, a), F.pollStep(m, b), F.pollStep(m, c)];
        assert.ok(!results.includes('ready'), `[${String(a)}, ${String(b)}, ${String(c)}] must not claim readiness`);
      }
    }
  }
});

test('a dropped request is counted as one more pending attempt, not as a failure', () => {
  // front.js calls pollStep(machine, 'pending') from its fetch .catch(), so a
  // flaky network must not turn into a false "provisioning failed" panel.
  const m = F.createPollState(4);
  assert.equal(F.pollStep(m, 'pending'), 'wait');
  assert.equal(m.attempts, 1);
  assert.equal(m.done, false);
});

/* ------------------------------------------------------------ tablist keys */

test('RTL arrow keys move in reading order and wrap', () => {
  assert.equal(F.nextTabIndex('ArrowLeft', 0, 3, true), 1);
  assert.equal(F.nextTabIndex('ArrowLeft', 2, 3, true), 0, 'forward wraps to the first tab');
  assert.equal(F.nextTabIndex('ArrowRight', 1, 3, true), 0);
  assert.equal(F.nextTabIndex('ArrowRight', 0, 3, true), 2, 'backward wraps to the last tab');
});

test('LTR arrow keys are mirrored', () => {
  assert.equal(F.nextTabIndex('ArrowRight', 0, 3, false), 1);
  assert.equal(F.nextTabIndex('ArrowLeft', 0, 3, false), 2);
});

test('Home and End jump to the ends in both directions', () => {
  assert.equal(F.nextTabIndex('Home', 2, 3, true), 0);
  assert.equal(F.nextTabIndex('End', 0, 3, true), 2);
  assert.equal(F.nextTabIndex('Home', 2, 3, false), 0);
  assert.equal(F.nextTabIndex('End', 0, 3, false), 2);
});

test('keys the tablist does not own are handed back to the browser', () => {
  for (const key of ['Tab', 'Enter', ' ', 'a', 'ArrowUp', 'Escape']) {
    assert.equal(F.nextTabIndex(key, 0, 3, true), -1, key);
  }
});

test('an empty tablist cannot produce an index', () => {
  assert.equal(F.nextTabIndex('ArrowLeft', 0, 0, true), -1);
  assert.equal(F.nextTabIndex('Home', 0, 0, true), -1);
});

/* ------------------------------------------------------- loading hygiene */

test('loading front.js outside a browser wires up no DOM handlers', () => {
  // The file is enqueued on pages that may not localize ARVRS; it must be inert
  // rather than throwing on a missing global.
  assert.equal(sandbox.document, undefined);
  assert.equal(sandbox.ARVRS, undefined);
});
