# ADR-0005 — One HTTP client, one provider interface, small DTOs

## Status
Accepted

## Context
Three Arvan products across two API hosts (`napi.arvancloud.ir`, `storage.arvanapis.ir`), an auth-header convention the official docs state inconsistently, no billing/usage API, and a hard "do not invent endpoints" rule.

## Decision Drivers
Testability without network · demo/real swap (HC-9) · error normalization to Persian-safe messages · single audit point for outbound traffic (SSRF posture).

## Options Considered
1. Raw `wp_remote_*` in services/controllers
2. Generated OpenAPI clients per product
3. **`ArvanClient` (HTTP concerns) + `ProviderInterface` (domain operations) + DTOs**

## Decision
Option 3. `ArvanClient` owns: auth header (with a one-shot raw-key ↔ `Apikey ` prefix fallback persisted on success, because the official docs are ambiguous), connect/total timeouts, ≤2 retries on 5xx/timeout, Retry-After handling, status→`ProviderError{kind}` normalization, correlation IDs, redacted logging. `RealProvider` holds all endpoint knowledge (verified list in [API_INTEGRATION.md](../API_INTEGRATION.md)); `DemoProvider` mirrors it deterministically. DTOs: `Plan`, `RemoteResource`, `UsageRow`, `ProviderError` — no raw upstream arrays cross the boundary.

## Why
The interface is simultaneously the demo seam, the unit-test seam and the future multi-credential-routing seam — one abstraction earning three jobs is the opposite of abstraction theater. Generated clients were rejected because Arvan's own ECC spec is drifting (region enum stale in their SDK) and 95% of generated surface would be dead code.

## Consequences
Easier: adding an endpoint = one method on RealProvider; REST/admin code never sees HTTP. Harder: multi-cloud would need interface generalization (acceptable — YAGNI now, seam exists).

## Risks
Doc-vs-reality drift in endpoint shapes. Mitigated: connection test surfaces failures with correlation IDs; response parsing is defensive.

## Revisit Trigger
Arvan publishes usage/billing endpoints (implement in `RealProvider::usage`, nothing else changes) or a second cloud vendor is added.
