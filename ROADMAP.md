# Roadmap

Realistic next steps, ordered by commercial value. Nothing here blocks the hackathon deliverable.

## v1.1 — production hardening
- Real PSP adapters (Zarinpal, IDPay) per `docs/extending-payment-provider.md`, with webhook signature docs
- Signed-license verification (Ed25519) behind `Licensing\License` (ADR-0009 path)
- PHPCS + WordPress-Coding-Standards config gating CI; PHPStan level 5
- Playwright browser E2E for the checkout + replay flows
- `jobs`/`audit` retention sweeps (30/90 days)

## v1.2 — reseller operations
- Wallet-first checkout: pay orders from available credit (reservation → release flow — ledger types already exist)
- Notification email digests + per-customer channel preferences
- Customer-group pricing tier (spec §6 optional item; resolution chain already reserves the slot)
- CSV export for ledger/orders; monthly statement per customer

## v1.3 — upstream depth
- Arvan usage/billing API integration the day it is published (single point: `RealProvider::usage`)
- Server lifecycle actions for customers where documented (power on/off/reboot, snapshot)
- Per-credential load distribution + quota awareness
- CDN DNS-record management UI

## Later / triggered
- Stage C extractions per `docs/SCALABILITY.md` triggers (usage worker first)
- Ledger checkpoint rows past 10⁶ entries (ADR-0007)
- Multi-currency once a second market exists
