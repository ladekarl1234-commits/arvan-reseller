# ADR-0006 — PaymentProviderInterface with a real sandbox; settlement out of scope

## Status
Accepted

## Context
Judges must complete purchases without PSP credentials; production needs Iranian gateways later; duplicate callbacks must be provably safe. Reseller↔Arvan settlement is explicitly out of scope (no API exists for it).

## Decision Drivers
Replay safety (HC-7) · demonstrability · adapter cost for future PSPs · never trusting the redirect leg.

## Options Considered
1. Hard-code one real PSP
2. Fake "mark as paid" button that skips verification
3. **`PaymentProviderInterface { start(ref, amount, type), verify(ref, amount, payload) }` + `SandboxProvider` whose verify() is a genuine server-side check**

## Decision
Option 3. The sandbox issues an HMAC proof over `(ref|amount|type)` keyed by a WP salt — its `verify()` fails on tampered amounts/refs exactly like a PSP verify API. The callback pipeline (verify → atomic claim with amount binding → `INSERT IGNORE` ledger pair → provision) is identical for sandbox and future real adapters; a Zarinpal adapter implements two methods and registers via the `arvrs_payment_provider` filter.

## Why
Option 2 would demo fine and prove nothing — the sandbox as built exercises every security property the rubric asks about, live (the payment page even ships a "resend callback" button that demonstrates replay rejection).

## Consequences
Easier: E2E demo without credentials; PSP adapters are ~50 lines. Harder: none identified.

## Risks
Sandbox proof leaking = free demo services on that site — bounded to demo installs; proof is salt-keyed per site.

## Revisit Trigger
First real gateway adapter → add webhook signature verification guidance to the extending-docs.
