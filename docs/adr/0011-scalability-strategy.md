# ADR-0011 — Scale by configuration first, extraction second, rewrite never

## Status
Accepted

## Context
The hackathon deployment is one WordPress + MySQL; a successful reseller could reach tens of thousands of customers and hundreds of thousands of usage rows. "Scalable" must mean concrete stages, not aspiration.

## Decision Drivers
No rewrite between stages · WP-Cron's traffic dependency · usage ingestion as the first real volume driver · module seams that survive extraction.

## Options Considered
1. Design for Stage A only
2. **Stage A now + documented configuration path (Stage B) + extraction seams (Stage C)**
3. Build Stage C infrastructure now (workers, queues, Redis)

## Decision
Option 2, detailed in [SCALABILITY.md](../SCALABILITY.md):
- **A (now):** WP + MySQL + jobs table + WP-Cron. Indexes and unique keys already sized for B.
- **B (config only):** real server cron → `wp-cron.php`; persistent object cache (Redis drop-in) accelerates transients/rate limits automatically (WP API-level, zero plugin change); batch sizes raised via one constant.
- **C (extraction):** provisioning worker and usage-ingestion worker read the SAME jobs/usage tables from outside PHP-FPM; the modular seams (`Jobs`, `Usage`, `Arvan`) are the service boundaries. Triggers quantified in SCALABILITY §"When the architecture must change".

## Why
Option 3 is resume-driven development for a plugin whose Stage A serves every hackathon judge and most real resellers. Option 1 would bake in schema decisions (no unique keys, meta storage) that DO require rewrites — the expensive-to-change parts are done now; the cheap-to-add parts wait.

## Consequences
Easier: single-command install stays; each stage is an ops change or an added binary, not a refactor. Harder: none at Stage A.

## Risks
Underestimated Stage B thresholds → CAPACITY_MODEL states assumptions explicitly so they can be re-measured.

## Revisit Trigger
Any Stage C trigger in SCALABILITY firing, or multi-tenant SaaS packaging.
