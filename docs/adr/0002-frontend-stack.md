# ADR-0002 — Server-rendered PHP + vanilla JS/CSS (React rejected with evidence)

## Status
Accepted

## Context
Storefront, customer dashboard, admin pages and a 7-step wizard — Persian, RTL, responsive, Sorkhab-informed — must ship inside the plugin with no Node.js at runtime.

## Decision Drivers
Zero-build installability · RTL fidelity · bundle size · one-language maintainability · the actual interactivity level required (forms, tables, one payment flow).

## Options Considered
Weighted matrix in [STACK_EVALUATION.md §2](../STACK_EVALUATION.md): SSR PHP+vanilla (860/1000), Vanilla TS+build (750), React+TS (734), Vue (690).

## Decision
Server-rendered PHP templates (escape at sink) + one vanilla JS file (checkout fetch, sandbox gateway, top-up, mark-read — 16 KB as of v1.1.0, up from ~6 KB pre-remediation; measure `assets/js/front.js` rather than trust this number) + one hand-written CSS file with Sorkhab-verified tokens (radius 8/12 px, 40 px buttons, RTL-always-right tables) + bundled Vazirmatn (OFL).

## Why
Every screen in scope is a form or a table. React's payoff begins where client state graphs get deep — that point is not in this product's demo path, and React inside wp-admin adds version-drift risk against WP's own bundled React. The REST API is already the seam where a future SPA attaches.

## Consequences
Easier: no build step ever; instant RTL; any WP developer can contribute day one. Harder: if a live-updating operations console is demanded, JS will need a framework — behind the existing REST API, not inside the domain.

## Risks
Vanilla JS discipline decays as interactivity grows. Trigger below guards this.

## Revisit Trigger
Any screen needing optimistic updates + shared client state across components (e.g. live provisioning progress board).
