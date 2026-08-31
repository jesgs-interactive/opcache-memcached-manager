# AGENTS.md

Working notes for this plugin. Conventions follow the `wordpress-plugin-scaffold` skill.

## Structure

- `opcache-memcached-manager.php` — bootstrap only: header, constants, `require_once` chain, hook wiring.
- `includes/class-omm-*.php` — one class per file, loaded via `require_once` (no autoloader; the plugin has no runtime dependencies).
- `dropins/*-dropin.php` — templates copied into `wp-content/` by the `OMM_*_Dropin` classes. They run before WordPress loads and must stay self-contained.
- `tests/` — plain PHPUnit unit tests, no WordPress. `tests/bootstrap.php` shims the few WP functions the tested code touches.

## Invariants

- **Page cache key logic is duplicated on purpose.** `omm_pagecache_build_key()` in `dropins/advanced-cache-dropin.php` and `OMM_PageCache::build_key()` in `includes/class-omm-pagecache.php` must produce identical keys — the drop-in can't call into plugin code. `tests/PageCacheKeyTest.php` locks this; run it after touching either.
- **Drop-in edits require a version bump.** Bump the `Version:` header and the `OMM_*_DROPIN_VERSION` constant in the template, and the matching `const VERSION` in the `OMM_*_Dropin` class, so installed copies report `outdated` and admins get a reinstall prompt.
- **Deactivation is non-destructive.** Data removal lives in `uninstall.php` only.

## Security baseline

Every state-changing admin action checks capability (`OMM_CAPABILITY`) **and** verifies a nonce. Sanitize on input, escape on output. See `references/security.md` in the skill.

## Release

Source carries `{{VERSION}}` placeholders in the plugin header, `OMM_VERSION`, and the readme `Stable tag` — never a real number. Pushing a tag runs `.github/workflows/release.yml`, which runs the tests, stamps the tag into those placeholders, packages via `.distignore`, and attaches `opcache-memcached-manager.zip` to the GitHub Release. `OMM_Update_Checker` points installed copies at that release feed.

Between releases the working tree shows `{{VERSION}}`; the update checker treats that as "not a real release" and skips the update check (unless `WP_DEBUG`).
