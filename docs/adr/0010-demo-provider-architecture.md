# ADR-0010 — Demo mode simulates the boundary, never the application

## Status
Accepted

## Context
Judges evaluate without paid Arvan resources or PSP credentials (HC-9), yet a demo that bypasses internal logic would prove nothing and risk fake-success dishonesty.

## Decision Drivers
Every internal flow must execute for real · deterministic and repeatable demos · impossible to confuse demo with production data.

## Options Considered
1. Mock at the service layer ("if demo, mark paid")
2. Seed static fake rows
3. **Swap only the two boundary providers (`DemoProvider`, `SandboxProvider`) behind the production interfaces**

## Decision
Option 3. Demo mode changes exactly two constructor choices in `Plugin`. Registration, pricing, order state machine, callback verification, claims, ledger, jobs, usage ingestion, policies, notifications — identical code paths in both modes. Determinism built in: `DemoProvider.create` derives remote IDs from the idempotency key; usage is a pure function of (resource, hour) so re-syncs prove dedup live; a `demo-fail` server name fails exactly once to demo the retry path. Demo rows carry `is_demo=1` and never mix into real reconciliation; the admin bar shows «حالت دمو»; demo mode force-enables while no credential has passed a connection test.

## Why
The rubric measures a working system; option 3 is the only one where the demoed behavior IS the production behavior.

## Consequences
Easier: honest demos, integration tests (the E2E suite runs in demo mode and exercises production logic). Harder: DemoProvider must track RealProvider's DTO shapes — a small, visible cost.

## Risks
Shape drift between providers → the shared DTO classes make drift a type-shape error rather than silent divergence.

## Revisit Trigger
A third provider implementation (staging Arvan account) — none expected.
