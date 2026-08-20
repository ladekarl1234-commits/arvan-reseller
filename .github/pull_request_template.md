# Pull Request

## What & why

<!-- One paragraph. Link the spec section this implements: spec.md §… -->

## Checklist

- [ ] Spec reference: `spec.md §…` (update the spec if behavior changed)
- [ ] Tests added/updated and passing (`composer test`)
- [ ] Security impact considered (authz, nonces, escaping, secrets, SQL)
- [ ] Database impact: none / migration added (idempotent, `ARVRS_SCHEMA_VERSION` bumped)
- [ ] API impact: none / REST args + permission_callback reviewed
- [ ] UI change: screenshots attached (RTL, mobile 390px + laptop)
- [ ] ADR needed? (irreversible/expensive decision → `docs/adr/`)
- [ ] Backwards compatible with existing installs
- [ ] Documentation updated (README / docs/…)

## Security notes

<!-- Anything a security reviewer should look at first. -->
