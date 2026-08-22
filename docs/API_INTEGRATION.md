# ArvanCloud API Integration

Verified endpoint reference and the honest limitation list. Every endpoint below was checked against the official source cited next to it (Aug 2026): the OpenAPI specs served from arvancloud.ir, docs.arvancloud.ir, and ArvanCloud's own SDK repositories (`arvancloud/ecc-go-client`, `arvancloud/cdn-go`). **What that claim does and doesn't mean:** this repository does not vendor those specs or any recorded response fixture, so a reader cannot check an endpoint against anything committed here — only against the live source cited. Treat the per-endpoint citations below as pointers to verify independently (`curl`/Postman against the spec, or a real call once a machine-user token is entered), not as self-proving. The one field-shape detail a prior review flagged — the Cloud Server security-group selection mapping an upstream `id` into the `name` field it sends when the object has no `name` of its own — has been corrected: `RealProvider::create_server()` now prefers the object's real `name` and only falls back to its `id` when `name` is absent (`RealProvider.php:299`).

## Hosts & authentication

| Concern | Value | Source |
|---|---|---|
| Gateway | `https://napi.arvancloud.ir` | docs.arvancloud.ir/en/developer-tools/api/api-usage |
| Object Storage management | `https://storage.arvanapis.ir` | arvancloud.ir/api-docs/storage-1.0.0.yaml |
| Auth header | `Authorization: <machine-user key>` (OpenAPI apiKey-in-header). Official curl samples inconsistently show an `Apikey ` prefix, so `ArvanClient` sends the raw key and falls back to the prefixed form once on 401, persisting whichever authenticated. | specs + docs samples (ambiguity documented) |
| Key creation | Panel → Settings → Workspace → Machine User. **No API exists to create keys programmatically.** | docs.arvancloud.ir/en/developer-tools/api/api-key |

## Endpoints used by `RealProvider`

### Cloud Server (ECC) — base `/ecc/v1`
| Purpose | Endpoint |
|---|---|
| Regions | `GET /regions` |
| Plans/flavors | `GET /regions/{region}/sizes` |
| OS images | `GET /regions/{region}/images?type=distributions` |
| Networks (create prerequisite) | `GET /regions/{region}/networks` |
| Security groups | `GET /regions/{region}/securities` |
| Create server | `POST /regions/{region}/servers` — body: `name, network_id, flavor_id, image_id, ssh_key(bool), count[, security_groups]` |
| Server details / status | `GET /regions/{region}/servers/{id}` |
| List servers in a region | `GET /regions/{region}/servers` — used to reconcile a create whose outcome is unknown: the deterministic remote name is looked up before creating, and again after a `timeout_indeterminate`, so a retried POST adopts the existing server instead of billing a second one (`RealProvider::find_server`) |
| Delete | `DELETE /regions/{region}/servers/{id}` |

Documented error semantics handled: `402` (insufficient upstream ArvanCloud wallet balance) maps to a dedicated `ProviderError` kind (`billing`) that is **not** retried — it is a permanent-until-topped-up condition, not a transient failure — and surfaces an actionable Persian message to the customer ("your order is safe, support is on it") while the admin gets the raw detail (`ArvanClient.php:198-202`, `DTO.php:117,134`).

### CDN — base `/cdn/4.0`
| Purpose | Endpoint |
|---|---|
| Create domain | `POST /domains/dns-service` — `{domain, domain_type:"full"}` |
| Set plan level | `PUT /domains/{domain}/plan` — `{plan_level:"1"|"2"|"3"}` |
| NS keys for the customer | `GET /domains/{domain}/ns-keys/check` |
| Domain details | `GET /domains/{domain}` |
| Delete | `DELETE /domains/{domain}` |

### Object Storage — base `https://storage.arvanapis.ir/v1`
| Purpose | Endpoint |
|---|---|
| Create bucket | `POST /buckets` — `{name, region: "ir-central1"|"ir-northwest1"}` |
| Bucket details | `GET /buckets/{bucketName}` |
| Delete bucket | `DELETE /buckets/{bucketName}` |

S3 data-plane endpoints handed to the customer: `s3.ir-thr-at1.arvanstorage.ir` (ir-central1) / `s3.ir-tbz-sh1.arvanstorage.ir` (ir-northwest1).

## Client behavior (`ArvanClient`)

Connect timeout 5 s (set via the `http_api_curl` hook — the WordPress HTTP API does not forward a `connect_timeout` argument on its own, so this is bound explicitly rather than passed through) · total 20 s · retries are verb-aware: idempotent verbs (GET/HEAD/PUT/DELETE/OPTIONS) get ≤2 retries on 5xx/timeout with 250/500 ms backoff, POST/PATCH are never blindly retried — a POST/PATCH that times out or hits 5xx raises `timeout_indeterminate` instead, and the caller reconciles by looking the resource up under its deterministic name rather than repeating the write · every request carries an `Idempotency-Key` header when the caller supplies one · `429` honours `Retry-After` ≤5 s in-request, otherwise raises `rate_limit` for job-level backoff · `402` raises a dedicated non-retryable `billing` kind · every error normalized to `ProviderError{kind: auth|rate_limit|timeout|timeout_indeterminate|invalid|conflict|billing|unavailable|unknown}` with an 8-char correlation ID that appears in both the admin log and the thrown message · error detail is logged key-redacted (`Audit::error`, whose `detail` array goes through `Audit::redact`); free-text upstream error messages are additionally scrubbed **by value** (`ArvanClient::scrub`) before logging, since a key-name redactor cannot catch a secret echoed inside a message string · successful-request bodies are logged only under `WP_DEBUG`, redacted by key name (`ArvanClient::redact_body`) — production logs carry no request/response bodies at all.

## NOT available via documented API (and what we do instead)

| Missing | Impact | Shipped fallback |
|---|---|---|
| **Billing/wallet/usage endpoints** (verified absent from all three specs) | Real-mode per-resource consumption cannot be fetched | Recurring revenue comes from `Billing\Renewals` — a term-based charge against each service's own clock (`renews_at`/`term_days`/`renewal_price`), not from metered upstream usage. The metered-usage engine (ingestion→ledger→policy) still exists end to end and runs against the demo provider; it has ONE integration point (`RealProvider::usage`) for the day Arvan publishes a real one |
| **Pricing API** | Base costs can't be pulled | Admin-maintained `base_costs` table, seeded from the public pricing page with source+timestamp, editable in Pricing settings |
| Machine-user / sub-account creation | No per-customer upstream isolation | All customers map to reseller credentials locally (`services.credential_id`); isolation is enforced plugin-side (HC-5) |
| Object Storage account provisioning & S3 key issuance | Keys can't be minted by the plugin | Bucket is provisioned via API; the customer is told keys come from the reseller (panel-issued); documented in the service connection info |
| Rate-limit contract | Unknown throttling behavior | Defensive client backoff + job retries (no assumptions made) |
| Reseller/partner API | No upstream reseller construct | The entire plugin IS the reseller layer, by design |

## Service-termination policy alignment

Official policy (arvancloud.ir/fa/legal/service-termination, اردیبهشت ۱۴۰۵): on negative balance — warning → panel restriction (immediate) → network cut (2 h) → power-off (48 h) → permanent deletion (~1 week) for cloud servers; CDN proxy-off after 24 h, DNS off after 2 weeks. The plugin's Policy Engine mirrors the *shape* (warning → critical → grace → restricted) with **reseller-configurable thresholds**, never claims Arvan's exact timings as its own, and never deletes remote resources automatically (spec §5.5).
