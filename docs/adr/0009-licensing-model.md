# ADR-0009 — Static bcrypt allowlist for Plugin Access Tokens

## Status
Accepted (hackathon scope; upgrade path defined)

## Context
The plugin must refuse to sell until the reseller proves authorization via a Plugin Access Token — distinct from the Arvan API token (HC-4) — with no licensing server available.

## Decision Drivers
No plaintext tokens anywhere in the repo · offline verification · honest scoping (this is not DRM) · swap-ability for a real licensing backend.

## Options Considered
1. Plaintext token list / hardcoded constant
2. MD5/SHA1 hash list
3. **bcrypt hash allowlist (`data/license-hashes.php`) + `password_verify`**
4. Remote license server with signed responses
5. Offline public-key-signed license files

## Decision
Option 3. Tokens are 128-bit random (`ARVRS-<32 hex>`); the repo ships only cost-12 bcrypt hashes; activation stores `active` + a SHA-256 fingerprint prefix (support can identify which token activated without ever holding it). Verification is rate-limited and audited. All verification logic lives behind `Licensing\License`, so options 4/5 replace the internals of `activate()`/`is_active()` without touching any caller.

## Why
bcrypt turns a leaked repo into nothing actionable; fast hashes (2) invite offline brute force of shorter tokens; option 4 needs infrastructure the hackathon doesn't have; option 5 is the right v2 and is why the class boundary exists.

## Consequences
Easier: zero-infra activation, demo tokens for judges distributed out-of-band (DEVELOPMENT.md). Harder: revocation requires a plugin update (accepted for hackathon).

## Risks
A source-modified plugin bypasses any offline check — documented openly in SECURITY.md; this control gates honest use, not piracy.

## Revisit Trigger
Commercial distribution → implement signed licenses (Ed25519 public key bundled, license = signed claims blob) behind the same class.
