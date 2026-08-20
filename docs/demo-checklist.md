# Demo Checklist — laptop & mobile

Run the full list before recording. Every row was executed on this build (sandbox: WP + SQLite integration, PHP 8.5) — re-verify on your demo machine.

## Preparation

- [ ] Fresh WordPress install, plugin ZIP installed & activated
- [ ] Demo Plugin Access Token at hand (`DEVELOPMENT.md`)
- [ ] Browser at 1920×1080, second window at 390×844 (devtools device mode)
- [ ] `docs/demo-script-fa.md` open on the second screen

## Laptop (≥1280 px)

### Onboarding
- [ ] Activation redirects to the wizard automatically
- [ ] Wrong PAT → clear Persian error; correct PAT → proceeds
- [ ] Back/Continue work on every step; progress bar advances
- [ ] Skipping the Arvan token forces Demo Mode (badge appears in admin bar)
- [ ] Page-creation step lists 8 pages; re-running creates no duplicates
- [ ] Final validation all green → finish lands on the admin dashboard

### Storefront & purchase
- [ ] Storefront shows 3 products with «شروع از …» prices
- [ ] Product page: plan switch updates the total instantly
- [ ] Buying while logged-out prompts login; register → auto-login
- [ ] Checkout → sandbox gateway shows correct amount + ref
- [ ] Pay → progress → success → service visible in dashboard
- [ ] **Replay button → «کال‌بک تکراری شناسایی شد …» and still one service/one payment**
- [ ] Cancel button returns to pending-orders page

### Customer dashboard
- [ ] Overview stats correct; services tab shows connection info (IP/region/image)
- [ ] Wallet: top-up via gateway credits once; ledger rows colored ±
- [ ] Usage tab fills after Sync-now; inbox shows notifications, mark-read works

### Failure & policy line
- [ ] Order a server named `demo-fail` → pays fine → «خطا در راه‌اندازی»
- [ ] Admin order detail → retry → active
- [ ] Health → Sync now twice → second run ingests 0 (message shows counts)
- [ ] Low balance → warning alert on customer dashboard, single notification

### Admin
- [ ] Dashboard cards: revenue / base cost / margin / customer credit / failed provisioning
- [ ] Customer file: rules save, manual wallet adjustment reflects in ledger
- [ ] Credentials: add (masked after save), test connection, delete — all audited
- [ ] Pricing: change markup → storefront price changes (after catalog refresh)
- [ ] Audit log shows the session's sensitive actions with IP
- [ ] Demo badge visible; System Health all green

## Mobile (390 px)

- [ ] Storefront: cards stack, nav wraps, CTA reachable
- [ ] Product page: plan cards + config + sticky total usable without horizontal scroll
- [ ] Auth: tabs, fields and buttons comfortably tappable
- [ ] Gateway page: amount readable, buttons full-width
- [ ] Dashboard: tab bar scrolls horizontally; tables scroll inside cards; wallet top-up usable
- [ ] Admin dashboard: readable and operable (cards stack) — best-effort per spec

## Recording hygiene

- [ ] No real tokens anywhere on screen (demo PAT is fine — it is a demo credential)
- [ ] Admin bar demo badge visible in admin shots
- [ ] Total runtime > 5:00 (target 6–8)
