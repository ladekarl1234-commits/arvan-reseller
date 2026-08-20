# ArvanCloud API Integration

Verified endpoint reference and the honest limitation list. Everything here was checked against official sources at build time (Aug 2026): the OpenAPI specs served from arvancloud.ir, docs.arvancloud.ir, and ArvanCloud's own SDK repositories (`arvancloud/ecc-go-client`, `arvancloud/cdn-go`). **No endpoint in this plugin is invented.**

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
| Delete | `DELETE /regions/{region}/servers/{id}` |

Documented error semantics handled: `402` insufficient upstream wallet → surfaced to the admin as a provider error (never to the customer as raw JSON).

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

Connect timeout 5 s, total 20 s · ≤2 retries on 5xx/timeout with 250/500 ms backoff · `429` honours `Retry-After` ≤5 s in-request, otherwise raises `rate_limit` for job-level backoff · every error normalized to `ProviderError{kind: auth|rate_limit|timeout|invalid|unavailable|unknown}` with an 8-char correlation ID that appears in both the admin log and the thrown message · request/response logging is key-redacted (`Audit::redact`).

## NOT available via documented API (and what we do instead)

| Missing | Impact | Shipped fallback |
|---|---|---|
| **Billing/wallet/usage endpoints** (verified absent from all three specs) | Real-mode per-resource consumption cannot be fetched | Reseller sells fixed monthly packages; the complete usage engine (ingestion→ledger→policy) runs against the demo provider and has ONE integration point (`RealProvider::usage`) for the day Arvan publishes one |
| **Pricing API** | Base costs can't be pulled | Admin-maintained `base_costs` table, seeded from the public pricing page with source+timestamp, editable in Pricing settings |
| Machine-user / sub-account creation | No per-customer upstream isolation | All customers map to reseller credentials locally (`services.credential_id`); isolation is enforced plugin-side (HC-5) |
| Object Storage account provisioning & S3 key issuance | Keys can't be minted by the plugin | Bucket is provisioned via API; the customer is told keys come from the reseller (panel-issued); documented in the service connection info |
| Rate-limit contract | Unknown throttling behavior | Defensive client backoff + job retries (no assumptions made) |
| Reseller/partner API | No upstream reseller construct | The entire plugin IS the reseller layer, by design |

## Service-termination policy alignment

Official policy (arvancloud.ir/fa/legal/service-termination, اردیبهشت ۱۴۰۵): on negative balance — warning → panel restriction (immediate) → network cut (2 h) → power-off (48 h) → permanent deletion (~1 week) for cloud servers; CDN proxy-off after 24 h, DNS off after 2 weeks. The plugin's Policy Engine mirrors the *shape* (warning → critical → grace → restricted) with **reseller-configurable thresholds**, never claims Arvan's exact timings as its own, and never deletes remote resources automatically (spec §5.5).
