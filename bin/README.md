# bin/ — build tools

Plain PHP, no Composer runtime deps, no `wp-cli`, no `gettext-tools`. Every
script refuses to run outside the CLI SAPI, because `bin/` ships inside the
plugin directory and must be inert over HTTP.

## Regenerating the translation catalog

Run these three, in order, after **any** change to a `__()` / `esc_html__()` /
`_e()` string:

```sh
php bin/make-pot.php languages/arvan-reseller.pot --po languages/arvan-reseller-fa_IR.po
php bin/make-mo.php  languages/arvan-reseller-fa_IR.po
php bin/php74-check.php
```

1. **`make-pot.php`** tokenises (`token_get_all`, not regex — the copy is
   Persian and full of apostrophes and escaped quotes) every `.php` under
   `src/` and `templates/` plus `arvan-reseller.php` and `uninstall.php`,
   collecting `__`, `_e`, `esc_html__`, `esc_html_e`, `esc_attr__`,
   `esc_attr_e`, `_n`, `_x` and `_nx` calls whose text-domain argument is
   `arvan-reseller` or absent. It rewrites `languages/arvan-reseller.pot`, and
   with `--po` it also refreshes that locale catalog: existing translations are
   kept, dropped strings disappear, new strings arrive. It prints the string
   count and the number of non-literal msgids it had to skip — **that second
   number should stay 0**; anything else is a `__($variable)` that no
   translator will ever see.
2. **`make-mo.php`** compiles the `.po` to the binary `.mo` WordPress loads,
   then reads it back with an independent parser and fails the command if the
   round-trip, the entry count, the header's `Plural-Forms`, or the sort order
   of the originals table is wrong. `php bin/make-mo.php --verify <file.mo>`
   runs only that check, which is what CI should call on a catalog it did not
   just build.
3. **`php74-check.php`** parses the runtime with a pinned PHP 7.4 grammar and
   flags constructs that a PHP 8 `php -l` would happily accept. It is the gate
   behind the `Requires PHP: 7.4` header.

## Adding a locale

```sh
php bin/make-pot.php languages/arvan-reseller.pot --po languages/arvan-reseller-ar.po
# translate the empty msgstr entries in languages/arvan-reseller-ar.po
php bin/make-mo.php languages/arvan-reseller-ar.po
```

The filename is what WordPress matches on: `arvan-reseller-{locale}.mo`, where
`{locale}` is the value of `get_locale()` (`fa_IR`, `ar`, `en_GB`, …). Both the
`.po` and the compiled `.mo` are committed; `load_plugin_textdomain()` in
`arvan-reseller.php` reads the `.mo` from `Domain Path: /languages`.

`arvan-reseller-fa_IR.po` is a real catalog rather than a stub: the shipped
source copy *is* Persian, so for `fa_IR` the identity mapping is the correct
translation. Seeding it that way is what makes the loader observably work —
a Persian string that renders after `load_plugin_textdomain()` has run came
through the `.mo`, not through the source literal.

## Notes

* `gettext.php` is the shared PO reader/writer. It is required by both catalog
  tools and is not a standalone command.
* The POT is byte-stable: files are walked in sorted order and entries keep
  first-appearance order, so a regeneration with no string changes produces a
  one-line diff (`POT-Creation-Date`) rather than a reshuffle.
* Fuzzy entries are **not** compiled into the `.mo`. Clear the `#, fuzzy` flag
  once a translation has been reviewed, or it will not ship.

## `build-plugin.php` — the release artifact

```bash
php bin/build-plugin.php            # → dist/arvan-reseller-<version>.zip
```

This is what actually produces the ZIP attached to a GitHub Release. It builds
from an **allow list** (`src`, `templates`, `assets`, `languages`, `data`, plus
the named root files), not by excluding things — a deny list silently ships
whatever you forgot to name, and "whatever you forgot" is how a credential
reaches a public download.

It refuses to build when the plugin header is missing a required field, when
`Version`, `ARVRS_VERSION` and `readme.txt`'s `Stable tag` disagree, when a
compiled `.mo` is absent while `languages/` ships, or when any shipped file
contains a plaintext access token or an API key. After writing, it reopens the
archive and asserts it has exactly one top-level directory named for the slug —
which is what makes **Plugins → Add New → Upload Plugin** unpack correctly.

Prefer it over `git archive`: the pathspec exclusions in the older recipe do
not apply the way they look like they do, and `data/license-hashes.php` is a
runtime dependency that is easy to leave out — without it the plugin installs
and can never be licensed.
