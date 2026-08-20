# Development

Copy-paste path from clone to a running WordPress with the plugin active and demo data. Two environments are supported; **Option A needs only PHP** (no Docker, no MySQL).

## Prerequisites

- PHP ≥ 7.4 CLI with `sodium`, `mbstring` (unit tests need 8.2+ for PHPUnit 12)
- Composer (dev tooling only)
- Option B additionally: Docker (for `wp-env`)

## Clone & install dev tooling

```bash
git clone https://github.com/ladekarl1234-commits/arvan-reseller.git
cd arvan-reseller
composer install
```

## Run the unit suite

```bash
composer test          # 46 tests, no WordPress required
```

## Option A — full WordPress sandbox with PHP only (SQLite)

Uses WordPress core + the official SQLite integration plugin + wp-cli. This is exactly how the shipped E2E evidence was produced.

```bash
mkdir -p ~/wp-sandbox && cd ~/wp-sandbox
curl -sLO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
curl -sL -o wp.zip https://wordpress.org/latest.zip && unzip -q wp.zip && mv wordpress wp
curl -sL -o sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
unzip -q sqlite.zip -d wp/wp-content/plugins/
cp wp/wp-content/plugins/sqlite-database-integration/db.copy wp/wp-content/db.php
php wp-cli.phar config create --path=wp --dbname=x --dbuser=x --dbpass=x --skip-check
php wp-cli.phar core install --path=wp --url=http://localhost:8899 \
  --title="Demo Reseller" --admin_user=admin --admin_password=admin123 \
  --admin_email=admin@example.com --skip-email

# link the plugin (copy on Windows, symlink elsewhere)
cp -r /path/to/arvan-reseller wp/wp-content/plugins/arvan-reseller
php wp-cli.phar plugin activate arvan-reseller --path=wp

# serve it
php -S localhost:8899 -t wp
```

Open http://localhost:8899/wp-admin (admin / admin123) → the onboarding wizard starts.

## Option B — wp-env (Docker + MySQL)

```bash
npm -g i @wordpress/env
wp-env start          # from the plugin directory; mounts it automatically
```

## Demo credentials (DEMO ONLY — not production secrets)

| What | Value |
|---|---|
| Plugin Access Token (judges) | `ARVRS-0845499FB98AB18F8984F7D1F2F84581` |
| Sandbox payment | built-in — the gateway page has pay / fail / replay buttons |
| Arvan API token | not required — leave empty in the wizard to run Demo Mode |

These tokens exist only to activate demo installs; the repo ships bcrypt hashes (`data/license-hashes.php`). Generating a fresh token set:

```bash
php -r '$t="ARVRS-".strtoupper(bin2hex(random_bytes(16)));echo $t,"\n",password_hash($t,PASSWORD_BCRYPT,["cost"=>12]),"\n";'
```

## Seed demo data / run the E2E scenario

```bash
php wp-cli.phar eval-file /path/to/arvan-reseller/tests/integration/e2e.php --path=wp
```

Fresh install required (it registers `alice@example.com` / `bob@example.com`, both `password123`, buys and provisions services, syncs usage, exercises replay safety — 42 checks). Log in as alice on the front-end afterwards to browse a fully populated customer dashboard.

## Linters / static checks

```bash
find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;   # syntax, all files
composer test                                                     # unit suite
```

## Create the plugin ZIP

```bash
git archive --format=zip --prefix=arvan-reseller/ -o arvan-reseller.zip HEAD \
  ":(exclude)tests" ":(exclude)docs" ":(exclude).github"
```

The ZIP is installable via wp-admin → Plugins → Upload. Runtime needs no Composer/Node artifacts — `vendor/` is dev-only and excluded automatically (gitignored).

## No `.env` needed

The plugin has no environment secrets: the encryption key derives from WordPress salts, demo mode needs no credentials, and real Arvan tokens are entered through the admin UI (encrypted at rest). Hence no `.env.example` — there is nothing to put in it.
