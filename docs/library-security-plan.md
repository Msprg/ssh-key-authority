# Library Security Plan

Date: 2026-04-04
Branch: `bootstrap5-upgrade-part1`

## Runtime Library Inventory

The UI now loads these frontend assets from [templates/base.php](/var/www/ska/templates/base.php):

| Asset | Runtime status | Notes |
| --- | --- | --- |
| `public_html/header.js` | Loaded globally | Pre-paint fingerprint visibility logic |
| `public_html/extra.js` | Loaded globally | Shared page behaviors, now native DOM/fetch based |
| `public_html/icons/*.svg` | Loaded on demand | Repo-owned icon assets used by semantic `ska-icon` markup and entity-link icons |

Retired from runtime on this branch:

- `public_html/bootstrap/css/bootstrap.min.css`
- `public_html/jquery/jquery-3.7.1.min.js`
- `public_html/bootstrap/js/bootstrap.min.js`
- `public_html/bootstrap5-compat.js`

PHP dependencies remain small:

- `phpseclib/phpseclib:^3.0` is the only third-party runtime PHP library in [composer.json](/var/www/ska/composer.json)
- `make ci-check` covers `composer audit`

## Risk Assessment

| Library / asset family | Risk | Why it matters | Current mitigation |
| --- | --- | --- | --- |
| Historical Bootstrap/Glyphicon references | Low | Older docs and checkpoints can mislead future work after the runtime asset removal | Keep current-state docs updated and trim stale references as cleanup continues |
| Local frontend runtime in `extra.js` | Medium | Now repo-owned rather than third-party, but still central to tabs, collapses, dropdowns, alerts, and sync polling | Covered by smoke tests plus targeted browser verification on interaction-heavy slices |
| Browser interaction smoke in `scripts/smoke/browser-interactions.sh` | Low | New repo-owned regression guard that drives real frontend interactions in headless Chromium | Reuses smoke env vars and now runs as part of `make smoke` |
| Browser-debugging helper `scripts/smoke/browser-capture.sh` | Low | New repo-owned debugging tool that logs into the smoke environment for screenshots | Reuses the existing smoke env vars and is not loaded in application runtime |
| Dormant vendored frontend assets | Low | Unreferenced assets expand reviewer surface and can hide stale dependencies | Remove once docs/checkpoints and fallback references are gone |
| `phpseclib` 3.x | Low | Runtime cryptography and SSH dependency still deserves continuous monitoring | `composer audit` in `make ci-check`, lockfile already updated to a non-advised release |

## What Is Actually Exposed At Runtime

Important distinction on the current branch:

- No third-party frontend JavaScript is loaded at runtime.
- No jQuery global is present in the browser.
- No Bootstrap JS plugins are executed.
- No third-party frontend CSS framework is loaded at runtime.
- Live icon rendering in templates/runtime JS no longer depends on the Bootstrap glyphicon font or markup.
- No additional legacy browser libraries such as `moment.js`, `select2`, `datepicker`, or old jQuery plugins were found in the runtime asset scan.

That shifts the frontend risk profile substantially: the main remaining exposure is page-local rendering regressions and stale historical references, not an active legacy library in the browser runtime.

## Upgrade / Deprecation Plan

### Phase 1: Completed on this branch

Objective: remove active reliance on Bootstrap 3 JS and jQuery without changing backend behavior.

Completed work:

- native tab, collapse, dropdown, and alert-dismiss handlers in [public_html/extra.js](/var/www/ska/public_html/extra.js)
- repo-local tab styling in [public_html/style.css](/var/www/ska/public_html/style.css) for migrated tabsets, removing the Bootstrap 3 tab CSS dependency from those pages
- repo-local table styling in [public_html/style.css](/var/www/ska/public_html/style.css) for the busiest list/detail pages, removing their Bootstrap 3 table-class dependency
- repo-local button and alert styling in [public_html/style.css](/var/www/ska/public_html/style.css), shrinking reliance on Bootstrap 3 for those shared primitives
- retirement of runtime `public_html/bootstrap5-compat.css` by folding its live aliases into [public_html/style.css](/var/www/ska/public_html/style.css)
- repo-local grid/text utility styling in [public_html/style.css](/var/www/ska/public_html/style.css) for the live `container`, `row`, `col-*`, and status text classes used by high-traffic pages, with the shell/login and busiest list/detail templates now rendering `ska-*` layout and spacing helpers directly
- repo-local form/input-group styling in [public_html/style.css](/var/www/ska/public_html/style.css) for the live `form-group`, `form-control`, and `input-group` classes used by the busiest forms, plus migration of active templates to local `ska-form-group` wrappers
- repo-local base typography/content, badge, and collapse-state styling in [public_html/style.css](/var/www/ska/public_html/style.css), allowing the app shell to render without Bootstrap CSS
- retirement of the Bootstrap 3-only `in` state marker from active tabs/collapses in templates and native runtime code
- Bootstrap handoff prep via repo-local aliases such as `ska-btn*`, `ska-alert*`, `ska-form-label`, `ska-form-control`, `ska-input-group*`, `ska-form-check*`, and `ska-badge*`, reducing future class-family conflicts when real Bootstrap 5 CSS is introduced; the busiest list/detail forms, actions, and alerts now use `ska-form-control`, `ska-btn*`, and `ska-alert*`
- native replacements for the local jQuery form helpers and sync polling
- removal of runtime `bootstrap.min.css`, `bootstrap.min.js`, jQuery, and `bootstrap5-compat.js`
- smoke assertions that authenticated pages do not load those assets

Temporary mitigation still in effect:

- keep repo-local utility/component styling in [public_html/style.css](/var/www/ska/public_html/style.css) while templates remain mixed
- preserve Bootstrap-style custom events from native handlers where existing code may still observe them

### Phase 2: Current focus, clean up after Bootstrap CSS removal

Objective: remove dormant vendor residue and finish page-local cleanup now that the legacy CSS bundle is out of the runtime.

Priority work:

- visually recheck untargeted secondary pages under the repo-local baseline
- finish removing stale Bootstrap-era helper/content assumptions from mixed templates
- continue migrating the remaining untargeted secondary templates onto explicit `ska-*` layout/utility aliases where they still use Bootstrap-era class vocabulary
- trim remaining icon-compatibility selectors and stale documentation references
- remove dormant vendored Bootstrap/Glyphicon assets once no longer needed for historical checkpoints

Exit criteria:

- no Bootstrap-era helper/content assumptions remain on active templates
- glyphicon font files and dormant Bootstrap CSS assets are no longer needed anywhere in the repo runtime path
- smoke/browser capture confirms the cleaned high-traffic and secondary pages still render acceptably under the repo-local baseline

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
| Now | Recheck untargeted secondary pages and remove dead Bootstrap/Glyphicon residue |
| Next 1-2 PRs | Finish page-local cleanup and trim stale migration references |
| Before final Bootstrap cleanup | Remove stale docs/checkpoints and any remaining fallback references |
| Final cleanup | Delete remaining dormant Bootstrap 3 artifacts and glyphicons |

## Repository Policy For Remaining Mixed Pages

Now that Bootstrap 3 CSS is gone from runtime:

- touched pages should move toward Bootstrap 5-compatible or SKA-local markup rather than add new Bootstrap-era structures
- new compatibility hacks should be explicit and narrowly scoped
- no secrets, config, or key material are introduced
- smoke-critical LDAP auth, key lifecycle, access rules, sync output, and audit/event behavior must remain unchanged
