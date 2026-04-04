# Library Security Plan

Date: 2026-04-04
Branch: `bootstrap5-upgrade-part1`

## Runtime Library Inventory

The UI now loads these frontend assets from [templates/base.php](/var/www/ska/templates/base.php):

| Asset | Runtime status | Notes |
| --- | --- | --- |
| `public_html/bootstrap/css/bootstrap.min.css` | Loaded globally | Bootstrap 3.4.1 baseline CSS; still carries the legacy shell and helper-class surface |
| `public_html/bootstrap5-compat.css` | Loaded globally | Local compatibility layer for mixed Bootstrap 5-era markup on top of Bootstrap 3 CSS |
| `public_html/header.js` | Loaded globally | Pre-paint fingerprint visibility logic |
| `public_html/extra.js` | Loaded globally | Shared page behaviors, now native DOM/fetch based |
| `public_html/icons/*.svg` | Loaded on demand | Repo-owned icon assets that replace the old glyphicon font path |

Retired from runtime on this branch:

- `public_html/jquery/jquery-3.7.1.min.js`
- `public_html/bootstrap/js/bootstrap.min.js`
- `public_html/bootstrap5-compat.js`

PHP dependencies remain small:

- `phpseclib/phpseclib:^3.0` is the only third-party runtime PHP library in [composer.json](/var/www/ska/composer.json)
- `make ci-check` covers `composer audit`

## Risk Assessment

| Library / asset family | Risk | Why it matters | Current mitigation |
| --- | --- | --- | --- |
| Bootstrap 3.4.1 CSS | High | End-of-life frontend baseline; still carries the shell, old helper classes, and many implicit component styles | Incremental template migration plus [public_html/bootstrap5-compat.css](/var/www/ska/public_html/bootstrap5-compat.css) |
| Glyphicons font assets | Low | Bootstrap CSS still vendors the font files, but live icon rendering now goes through local SVG assets in [public_html/icons/](/var/www/ska/public_html/icons/) via [public_html/style.css](/var/www/ska/public_html/style.css) | Keep avoiding new legacy icon markup; remove the dormant font files when Bootstrap 3 CSS is gone |
| Local compatibility CSS | Medium | Safe compared with third-party JS, but it can become sticky technical debt if pages never finish migrating | Keep scope explicit and shrink after each structural cleanup slice |
| Local frontend runtime in `extra.js` | Medium | Now repo-owned rather than third-party, but still central to tabs, collapses, dropdowns, alerts, and sync polling | Covered by smoke tests plus targeted browser verification on interaction-heavy slices |
| Browser-debugging helper `scripts/smoke/browser-capture.sh` | Low | New repo-owned debugging tool that logs into the smoke environment for screenshots | Reuses the existing smoke env vars and is not loaded in application runtime |
| Dormant vendored frontend assets | Low | Unreferenced assets expand reviewer surface and can hide stale dependencies | Remove once runtime/template references are gone |
| `phpseclib` 3.x | Low | Runtime cryptography and SSH dependency still deserves continuous monitoring | `composer audit` in `make ci-check`, lockfile already updated to a non-advised release |

## What Is Actually Exposed At Runtime

Important distinction on the current branch:

- No third-party frontend JavaScript is loaded at runtime.
- No jQuery global is present in the browser.
- No Bootstrap JS plugins are executed.
- The remaining third-party browser dependency is Bootstrap 3 CSS.
- Live icon rendering no longer depends on the Bootstrap glyphicon font.
- No additional legacy browser libraries such as `moment.js`, `select2`, `datepicker`, or old jQuery plugins were found in the runtime asset scan.

That shifts the frontend risk profile substantially: the main remaining exposure is a stale CSS framework, not an active legacy JS/plugin surface.

## Upgrade / Deprecation Plan

### Phase 1: Completed on this branch

Objective: remove active reliance on Bootstrap 3 JS and jQuery without changing backend behavior.

Completed work:

- native tab, collapse, dropdown, and alert-dismiss handlers in [public_html/extra.js](/var/www/ska/public_html/extra.js)
- native replacements for the local jQuery form helpers and sync polling
- removal of runtime `bootstrap.min.js`, jQuery, and `bootstrap5-compat.js`
- smoke assertions that authenticated pages do not load those scripts

Temporary mitigation still in effect:

- keep [public_html/bootstrap5-compat.css](/var/www/ska/public_html/bootstrap5-compat.css) while templates remain mixed
- preserve Bootstrap-style custom events from native handlers where existing code may still observe them

### Phase 2: Current focus, remove Bootstrap 3 CSS dependency

Objective: eliminate the legacy CSS bundle and the remaining Bootstrap 3 markup assumptions.

Priority work:

- finish replacing remaining Bootstrap 3 shell/helper/layout markup with Bootstrap 5-compatible or SKA-local equivalents
- replace remaining legacy glyphicon classnames with semantic local icon helpers
- finish any shell/layout cleanup that still assumes Bootstrap 3 navbar or utility semantics
- shrink compatibility CSS as migrated pages stop needing aliases

Exit criteria:

- no Bootstrap 3 structural classes remain in templates
- glyphicon font files are no longer needed anywhere in the repo runtime path
- the shell renders correctly without Bootstrap 3 CSS
- smoke/browser capture confirms the cleaned high-traffic pages still render acceptably after the CSS swap

### Phase 3: Remove stale vendored artifacts

Objective: reduce dormant supply-chain surface and maintenance burden.

Safe cleanup targets once unreferenced:

- Bootstrap theme CSS files
- obsolete Bootstrap JS files
- obsolete jQuery files
- unused source maps under vendored frontend directories

This phase can now proceed incrementally because runtime references are already gone.

## Timeline And Milestones

| Timeline | Target |
| --- | --- |
| Now | Finish Bootstrap 3 shell/helper cleanup on `server`, `serveraccount`, `group`, and key-management views |
| Next 1-2 PRs | Replace legacy icon markup with semantic helpers and trim compatibility CSS |
| Before removing Bootstrap 3 CSS | Finish shell/layout cleanup and trim compatibility CSS |
| Final cleanup | Delete remaining dormant Bootstrap 3 artifacts and glyphicons |

## Repository Policy For Remaining Mixed Pages

Until Bootstrap 3 CSS is fully gone:

- touched pages should move toward Bootstrap 5-compatible markup rather than add new Bootstrap 3 structures
- local compatibility CSS may remain, but new compatibility hacks should be explicit and narrowly scoped
- no secrets, config, or key material are introduced
- smoke-critical LDAP auth, key lifecycle, access rules, sync output, and audit/event behavior must remain unchanged
