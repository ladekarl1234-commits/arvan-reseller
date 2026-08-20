# ADR-0001 — Modular monolith inside a standalone WordPress plugin

## Status
Accepted

## Context
The product must install as one plugin on arbitrary WordPress hosts, yet contains genuinely different domains (pricing, orders, payments, provisioning, wallet, usage, policies) with financial-correctness requirements.

## Decision Drivers
Installability (hard constraint) · testability of money-handling logic · onboarding speed for future engineers · a credible path to commercial scale without rewrite.

## Options Considered
1. Procedural hook-based plugin
2. **Modular monolith: namespaced modules, pure domain cores, thin WP adapters**
3. Full hexagonal with DI container and repository interfaces everywhere
4. Plugin + external backend service
5. Microservices

## Decision
Option 2. `src/<Module>/` per domain; pure logic (PricingEngine, StateMachine, PolicyEngine, Ledger::derive) has zero WP dependency; WordPress-specific code (hooks, `$wpdb`, REST, templates) sits at module edges. Composition happens in one place (`Plugin::boot` + static accessors) instead of a container.

## Why
The two boundaries that actually vary at runtime (Arvan provider, payment provider) get interfaces; everything else gets namespaces and discipline. That is the smallest architecture that keeps money-logic unit-testable and modules extractable, without the indirection tax of option 3 on a codebase one engineer must deliver in days.

## Consequences
Easier: unit testing without WP; reading one module top-to-bottom; extracting a module later (SCALABILITY §seams). Harder: swapping the persistence layer wholesale (services call `$wpdb` directly — accepted, see ADR-0003).

## Risks
Static service accessors can rot into hidden globals. Mitigation: only `Plugin::arvan()`/`Plugin::payments()` exist; everything else is explicit.

## Revisit Trigger
A second deployment target (SaaS control plane) or a second team working in parallel.
