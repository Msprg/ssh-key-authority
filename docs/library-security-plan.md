# Library Security Plan

Date: 2026-04-01
Branch: `bootstrap5-upgrade-part1`

## Runtime Library Inventory

The authenticated UI currently loads these frontend assets from [templates/base.php](/var/www/ska/templates/base.php):

| Asset | Runtime status | Notes |
| --- | --- | --- |
| `public_html/bootstrap/css/bootstrap.min.css` | Loaded globally | Bootstrap 3.4.1 baseline CSS; also brings in glyphicon font references |
| `public_html/jquery/jquery-3.7.1.min.js` | Loaded globally | Current shared DOM/event utility layer |
| `public_html/bootstrap/js/bootstrap.min.js` | Loaded globally | Bootstrap 3.4.1 JS plugins |
| `public_html/bootstrap5-compat.css` | Loaded globally | Local compatibility layer for mixed Bootstrap 5 markup |
| `public_html/bootstrap5-compat.js` | Loaded globally | Local compatibility layer mapping `data-bs-*` back to Bootstrap 3 |
| `public_html/header.js` | Loaded globally | Pre-paint fingerprint visibility logic |
| `public_html/extra.js` | Loaded globally | Shared page behaviors, still partly jQuery and Bootstrap 3 plugin driven |

Vendored but not referenced by application templates today:

- `public_html/bootstrap/css/bootstrap-theme*.css`
- `public_html/bootstrap/js/bootstrap.js`
- `public_html/bootstrap/js/npm.js`
- source maps under `public_html/bootstrap/**` and `public_html/jquery/**`

PHP dependencies are in better shape:

- `phpseclib/phpseclib:^3.0` is the only third-party runtime PHP library in [composer.json](/var/www/ska/composer.json)
- dev tooling now includes `php-cs-fixer`, `parallel-lint`, and `phpstan`

## Risk Assessment

| Library / asset family | Risk | Why it matters | Current mitigation |
| --- | --- | --- | --- |
| Bootstrap 3.4.1 JS | High | End-of-life frontend framework, large jQuery plugin surface, fragile event/data-API coupling, blocks migration away from legacy markup | Compatibility shim, CSP defaults, no user-generated HTML injected into plugin options, incremental replacement plan |
| Bootstrap 3.4.1 CSS | High | End-of-life styling baseline; keeps glyphicons, panels, labels, and old button semantics in the app | Compatibility stylesheet lets pages adopt Bootstrap 5 utilities incrementally |
| Glyphicons font assets | Medium | Ties UI semantics and rendering to Bootstrap 3 CSS bundle | Keep until icon replacement pass; avoid adding any new glyphicon usage |
| jQuery 3.7.1 | Medium | Not the primary vulnerability concern, but it keeps a large mutable global and encourages plugin-style coupling | Reduce new usage; replace local handlers with native JS where low-risk |
| `bootstrap-theme*`, `bootstrap.js`, `npm.js`, source maps | Low | Not loaded at runtime, but stale vendored artifacts expand maintenance and reviewer surface | Leave untouched for now; remove only after migration slices confirm no direct references |
| `phpseclib` 3.x | Low | Runtime cryptography/SSH dependency deserves continuous monitoring | Covered by `composer audit` in `make ci-check` |

## What Is Still Actually Exposed At Runtime

Important distinction:

- `bootstrap.min.js` is still executed on every authenticated page today.
- `bootstrap-theme`, `bootstrap.js`, `npm.js`, and source maps are present in the repo but are not loaded by the app templates.
- No additional legacy frontend libraries such as `moment.js`, `select2`, `datepicker`, or old jQuery plugins were found in the runtime asset path scan.

That means the main security reduction target is not a big dependency sweep. It is removing reliance on Bootstrap 3 JS first, then Bootstrap 3 CSS.

## Upgrade / Deprecation Plan

### Phase 1: Immediate risk reduction on the current branch

Objective: shrink active dependence on Bootstrap 3 JS without changing backend behavior.

- Replace tab and collapse handling in [public_html/extra.js](/var/www/ska/public_html/extra.js) with Bootstrap 5-compatible native behavior.
- Migrate the busiest templates from `data-toggle` to `data-bs-toggle`.
- Keep `bootstrap.min.js` loaded temporarily for dropdowns and alert dismissal on untouched pages.
- Add deprecation notes in docs for remaining Bootstrap 3-only markup families.

Temporary mitigation during this phase:

- continue loading the compatibility shim so unmigrated pages still work
- do not introduce new Bootstrap 3-only data attributes or markup in touched files

### Phase 2: Remove Bootstrap 3 JS dependency page-by-page

Objective: make `bootstrap.min.js` optional rather than critical.

- Replace remaining dropdown, alert-dismiss, and collapse behaviors with native or Bootstrap 5-compatible handlers.
- Remove last `shown.bs.tab` / `show.bs.collapse` assumptions from shared JS.
- Verify smoke-critical pages:
  - `/`
  - `/servers`
  - `/servers/:hostname`
  - `/servers/:hostname/accounts/:name`
  - `/groups`
  - `/groups/:name`
  - `/help`

Exit criteria:

- no template depends on Bootstrap 3 jQuery plugins
- no application JS calls `$(...).tab(...)`, `$(...).collapse(...)`, or similar plugin APIs

### Phase 3: Remove Bootstrap 3 CSS dependency

Objective: eliminate the legacy CSS bundle and the glyphicon font chain.

- Replace `panel-*` with Bootstrap 5 card/accordion-compatible markup or SKA-local components.
- Replace `input-group-addon` with Bootstrap 5-compatible text wrappers.
- Replace `btn-default`, `btn-xs`, `label label-default`, `pull-*`, and remaining hidden utility leftovers.
- Replace glyphicon usage with inline SVG or a local icon helper.

Exit criteria:

- no Bootstrap 3 structural classes remain in app templates
- glyphicon font files are no longer needed by the UI

### Phase 4: Remove stale vendored artifacts

Objective: reduce dormant supply-chain surface and maintenance burden.

- Remove unused vendored assets once no templates or docs reference them:
  - `bootstrap-theme*.css`
  - `bootstrap.js`
  - `npm.js`
  - obsolete source maps if not needed for debugging policy

This should happen only after runtime references are fully gone to avoid breaking operators who may still inspect files manually.

## Timeline And Milestones

| Timeline | Target |
| --- | --- |
| Now | Replace tab/collapse plugin dependency and migrate highest-traffic pages first |
| Next 1-2 PRs | Convert help/access-options/bulk-action collapses, then major account/group/server form controls |
| Before removing Bootstrap 3 JS | Ensure dropdown/alert behavior has a non-BS3 implementation |
| Before removing Bootstrap 3 CSS | Replace glyphicons, panels, old buttons/labels, and input-group addons |
| Final cleanup | Delete unused vendored Bootstrap 3 artifacts |

## Repository Policy For Unmigrated Pages

Until Bootstrap 3 is fully gone:

- compatibility shims stay in place
- unmigrated pages must keep working under mixed markup
- no new secrets, config, or key material are introduced
- smoke-critical LDAP auth, key lifecycle, access rules, sync output, and audit/event behavior must remain unchanged
