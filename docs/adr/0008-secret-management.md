# ADR-0008 — libsodium secretbox keyed from WP salts via HMAC

## Status
Accepted

## Context
Multiple ArvanCloud API tokens must live in the database of arbitrary WordPress installs — where DB dumps leak far more often than filesystems.

## Decision Drivers
Real confidentiality against DB-only leaks · zero configuration for the reseller · no new dependencies · never re-displayable.

## Options Considered
1. Plaintext / Base64
2. OpenSSL AES-256-GCM with a generated key stored in the DB
3. **sodium secretbox; key = HMAC-SHA256(context, wp_salt('auth')·wp_salt('secure_auth'))**
4. External KMS

## Decision
Option 3. libsodium ships in PHP ≥7.2 core; secretbox gives authenticated encryption (tamper → null, never garbage); deriving the key from WP salts means the key material lives in `wp-config.php` — a different trust domain than the database — with a context string so the derived key is unique to this purpose. Fresh random nonce per encryption, prefixed to the ciphertext. UI shows `••••last4`; secrets never re-render.

## Why
Option 2's key-in-DB dies with the same dump it protects against. Option 4 violates zero-config installability. Salts as key material is the strongest secret WordPress guarantees to exist on every install.

## Consequences
Easier: nothing to configure or rotate manually. Harder: salt rotation invalidates stored tokens by design — detected (`decrypt` → null) and surfaced as "re-enter the token", failing closed.

## Risks
Hosts with sodium disabled (rare, Windows custom builds) — detected; credential saving is refused with a clear message rather than downgrading silently.

## Revisit Trigger
A managed/SaaS offering → per-tenant keys in a KMS behind the same `Crypto` API.
