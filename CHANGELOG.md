# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/), versioning: SemVer.

## [1.0.0] — 2026-08-20

First complete release for the ArvanCloud reseller hackathon.

### Added
- Plugin Access Token licensing (bcrypt allowlist, fingerprint-only storage)
- 7-step onboarding wizard with server-side validation and idempotent page creation
- Persian RTL storefront for Cloud Server / CDN / Object Storage with plan configuration
- Pricing engine: global / per-product / per-customer markup, discounts, fixed adjustments, immutable order snapshots; admin-maintained base-cost table
- Sandbox payment gateway with HMAC server-side verification and a live duplicate-callback demonstration; PSP adapter interface
- Order state machine with append-only event history and atomic optimistic transitions
- Instant provisioning with three-layer idempotency and durable retry jobs (backoff + dead-letter)
- Real ArvanCloud provider over verified documented endpoints (ECC, CDN 4.0, Object-Storage management API); demo provider with deterministic catalog/usage
- Multiple sodium-encrypted Arvan credentials with per-product routing and connection health
- Append-only wallet ledger with derived balances, top-ups and admin reconciliation
- Usage engine: idempotent per-period ingestion → ledger debits → configurable credit-policy staging with cooldown-aware notifications
- Customer dashboard (services/orders/wallet/usage/inbox) and 10-section admin experience incl. System Health with Sync-now and an audit log
- 46 unit tests + 42-check end-to-end scenario on a real WordPress; CI (lint 7.4/8.2/8.3, tests, secret scan)
- Engineering handbook: spec, 11 ADRs, threat model, stack evaluation, scalability/capacity models, API integration reference, demo script (fa) and checklist
