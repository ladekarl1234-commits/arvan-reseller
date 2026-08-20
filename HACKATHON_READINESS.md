# Hackathon Readiness

Mapping of the official 300-point rubric to this submission, with verifiable evidence for every claim. Fine-grained requirement mapping: [`docs/REQUIREMENTS_TRACEABILITY.md`](docs/REQUIREMENTS_TRACEABILITY.md).

## Implementation — 120 pts

| Criterion | Where | Evidence | Status |
|---|---|---|---|
| End-to-end working flow (install → license → onboard → buy → pay → provision → manage) | whole plugin | **42-check E2E on a real WordPress: ALL PASSED** (`tests/integration/e2e.php`, TESTING.md) + screenshots of the live sandbox | ✅ |
| Three products sellable | storefront + providers | E2E orders cloud_server; CDN/storage plans priced & configurable (screenshots) | ✅ |
| Real Arvan API layer, no invented endpoints | `src/Arvan/RealProvider.php` | endpoint-by-endpoint source audit in `docs/API_INTEGRATION.md` | ✅ (live firing needs a real token — honest note in traceability) |
| Wallet/ledger/usage/policy engines | `src/Wallet|Usage|Policies` | unit + E2E checks 20–30; admin reconciliation view | ✅ |
| Multi-credential management | `src/Arvan/Credentials.php` | encrypted rows, routing, health, screenshots | ✅ |
| Jobs/retry/failure recovery | `src/Jobs`, `Provisioning` | E2E checks 37–42 (fail → retry → active) | ✅ |
| Demo mode = boundary-only | ADR-0010 | same code paths in demo E2E; `is_demo` flags; admin badge | ✅ |

## UI/UX — 70 pts

| Criterion | Evidence | Status |
|---|---|---|
| Polished onboarding wizard | `docs/screenshots/wizard.png`; validation, Back/Continue, progress | ✅ |
| Persian-first RTL with design-system discipline | Sorkhab-verified tokens (radius 8/12, 40px buttons, RTL tables — research-sourced), bundled Vazirmatn (OFL) | ✅ |
| Real states everywhere | loading spinner (gateway), empty states (dashboard/orders), error alerts with retry, success flows | ✅ |
| Responsive 390 px + laptop | `mobile-dashboard.png`, `mobile-product.png` + checklist | ✅ |
| Accessibility basics | semantic landmarks, labels on every field, visible focus rings, aria on tabs/alerts, reduced-motion support | ✅ |
| Customer never needs wp-admin | role-gated redirect + front dashboard | ✅ |

## Security — 70 pts

| Criterion | Evidence | Status |
|---|---|---|
| Documented control inventory | `SECURITY.md` (control → code location) | ✅ |
| Threat model | `docs/THREAT_MODEL.md` — 13 attack scenarios, each with its stopping control | ✅ |
| Payment replay/tamper safety | E2E checks 12, 17–19, 24–25 + `PaymentVerificationTest` | ✅ tested |
| Customer isolation | E2E checks 31–36 (direct + REST) | ✅ tested |
| Secret handling | sodium encryption, masking, REST omission, log redaction (unit-tested) | ✅ |
| CSRF/XSS/SQLi discipline | nonces on every action, escape-at-sink templates, 100% prepared SQL | ✅ |
| Licensing hygiene | bcrypt-only repo, CI secret scan | ✅ |
| Audit trail | `audit_log` + admin viewer screenshot | ✅ |

## Presentation — 40 pts

| Criterion | Evidence | Status |
|---|---|---|
| ≥5-minute coherent demo prepared | `docs/demo-script-fa.md` (7-min narrative incl. the live replay-safety moment) | ✅ script ready — **video recording is the participant's step** |
| Laptop + mobile demo checklist | `docs/demo-checklist.md` | ✅ |
| Repo tells the story in 2 minutes | README: problem → flow → architecture diagram → security → install | ✅ |
| Judges can run it without credentials | Demo Mode + demo PAT + PHP-only sandbox (`DEVELOPMENT.md`) | ✅ |

## Definition-of-Done sweep (prompt checklist)

Install ✅ · no third-party plugin deps ✅ · PAT gate ✅ · onboarding ✅ · branding ✅ · credential save/test ✅ · multi-credential ✅ · three storefronts ✅ · registration/login ✅ · pricing engine ✅ · customer-specific pricing ✅ · orders ✅ · sandbox payment E2E ✅ · duplicate-callback safety ✅ (tested) · provisioning architecture ✅ · real API integration for documented ops ✅ · customer dashboard ✅ · admin dashboard ✅ · isolation ✅ (tested) · ledger ✅ · usage/sync ✅ · credit policies ✅ · security controls ✅ · audit logs ✅ · responsive tested ✅ · demo mode ✅ · automated tests pass ✅ (46U+42E) · static checks ✅ (lint 3 PHP versions) · no secrets in repo ✅ (CI-enforced) · README ✅ · security docs ✅ · architecture docs ✅ · demo script ✅ · demo checklist ✅ · full scenario without DB surgery ✅ (E2E is exactly that).

## Known gaps a judge may probe

1. **Live paid provisioning** was not fired from this environment (no real Arvan credential here). The client, endpoints and error handling are implemented against the verified specs; the connection-test path exercises real HTTP the moment a token is entered.
2. **Real-mode usage rows**: no upstream API exists (verified) — packaged-price billing ships instead, usage engine proven via the boundary-faithful demo provider.
3. **MySQL-run E2E**: executed here on the official SQLite integration; wp-env/MySQL instructions provided. Duplicate-detection was deliberately implemented on semantics both databases share (`rows_affected`).
