# Bootstrap 5 Gap Analysis

Date: 2026-04-06
Branch: `bootstrap5-upgrade-part1`

## Scope

This inventory covers the rendered HTML templates and the globally loaded frontend runtime:

- `templates/base.php`
- `templates/*.php`
- `public_html/extra.js`

Status legend:

- `Bootstrap 5-ready`: generic layout/components now use Bootstrap 5 directly; only app-specific SKA wrappers remain.
- `Mixed`: page works on the Bootstrap 5 runtime, but still has heavier SKA-local layout/status wrappers that could be simplified later.

## Runtime Baseline

Current pages load the frontend shell from [templates/base.php](/var/www/ska/templates/base.php) with:

- `public_html/vendor/bootstrap5/bootstrap-5.3.8.min.css`
- `public_html/vendor/bootstrap5/bootstrap-5.3.8.bundle.min.js`
- `public_html/style.css`
- `public_html/header.js`
- `public_html/extra.js`

Current runtime facts:

- Bootstrap 5.3.8 CSS is now loaded globally from the local vendored asset in [public_html/vendor/bootstrap5/bootstrap-5.3.8.min.css](/var/www/ska/public_html/vendor/bootstrap5/bootstrap-5.3.8.min.css).
- Bootstrap 5.3.8 JS is now loaded globally from the local vendored asset in [public_html/vendor/bootstrap5/bootstrap-5.3.8.bundle.min.js](/var/www/ska/public_html/vendor/bootstrap5/bootstrap-5.3.8.bundle.min.js).
- Bootstrap 3 CSS is no longer loaded.
- Bootstrap 3 JS is no longer loaded.
- jQuery is no longer loaded.
- `public_html/bootstrap5-compat.js` has been retired.
- Tabs, collapses, dropdowns, and alert dismissal now run through the Bootstrap 5 bundle; [public_html/extra.js](/var/www/ska/public_html/extra.js) only keeps app-specific behaviors plus hash/ARIA glue for tabs/collapses and sync/form helpers.
- Active templates now use Bootstrap 5 classes for generic grid, forms, buttons, alerts, tables, tabs, spacing, responsive helpers, and dropdown markup.
- The remaining `ska-*` runtime surface is app-specific: shell layout, navigation treatment, icon system, badge/status treatment, settings-shell wrappers, card-stack wrappers, and a small number of special admin layouts.
- High-traffic pages now use semantic `ska-icon` helpers and entity-link icons rendered through repo-owned SVG assets in [public_html/icons/](/var/www/ska/public_html/icons/) via [public_html/style.css](/var/www/ska/public_html/style.css), not the Bootstrap font glyphs.
- Bootstrap 3 `panel-*` markup has been migrated either to Bootstrap 5 `card`/`accordion` markup or to a small number of app-specific `ska-card*` wrappers where the layout is domain-specific.
- `public_html/bootstrap5-compat.css` has been retired; its remaining live utility and component aliases were folded into [public_html/style.css](/var/www/ska/public_html/style.css).
- Headless browser capture is now available through [scripts/smoke/browser-capture.sh](/var/www/ska/scripts/smoke/browser-capture.sh) for authenticated visual regression checks, and [scripts/smoke/browser-interactions.sh](/var/www/ska/scripts/smoke/browser-interactions.sh) exercises one live dropdown, collapse, and tab interaction during smoke runs.

The major migration goal is now achieved. Remaining work is housekeeping:

- trim stale historical checkpoint references
- optionally simplify some app-specific wrappers on detail/settings pages
- keep browser/smoke coverage current as future UI work lands

## Page Inventory

| Page / Route | Template | Status | Current state | Remaining blockers |
| --- | --- | --- | --- | --- |
| Global shell | `templates/base.php` | Bootstrap 5-ready | Bootstrap 5 CSS and JS are loaded globally; shell dropdown and flash alerts now use Bootstrap 5 component markup; SKA owns only the shell/navigation presentation | Residual risk is visual-only on less-traveled shell states |
| Login `/login` | `templates/login.php` | Bootstrap 5-ready | Uses Bootstrap 5 form, alert, spacing, and button classes with only a small app-specific login wrapper | Residual risk is visual-only |
| Home `/` | `templates/home.php` | Bootstrap 5-ready | Uses Bootstrap 5 forms, tables, buttons, spacing, grid, and responsive helpers; add-key behavior remains app-specific JS | Residual risk is visual-only |
| Users list `/users` | `templates/users.php` | Bootstrap 5-ready | Uses Bootstrap 5 table styling; inactive-user treatment is app-specific status styling only | Inherits global shell only |
| User detail `/users/:uid` | `templates/user.php` | Bootstrap 5-ready | Uses Bootstrap 5 tabs, tables, buttons, and form controls; only the settings-shell layout and status treatment remain app-specific | Residual risk is visual-only |
| Groups list `/groups` | `templates/groups.php` | Bootstrap 5-ready | Uses Bootstrap 5 tabs, cards, tables, forms, and layout helpers | Only app-specific status/card-stack wrappers remain |
| Group detail `/groups/:name` | `templates/group.php` | Mixed | Generic components are now Bootstrap 5-native; remaining SKA wrappers are the settings-shell and some domain-specific status/layout treatment | Further simplification is optional, not required for Bootstrap ownership |
| Servers list `/servers` | `templates/servers.php` | Bootstrap 5-ready | Uses Bootstrap 5 tabs, cards, tables, forms, alerts, and layout helpers | Only app-specific status/card-stack wrappers remain |
| Server detail `/servers/:hostname` | `templates/server.php` | Mixed | Generic components are now Bootstrap 5-native; remaining SKA wrappers are settings-shell, note-card, and domain-specific status treatment | Further simplification is optional |
| Server account `/servers/:hostname/accounts/:name` | `templates/serveraccount.php` | Mixed | Generic components are now Bootstrap 5-native across tabs, tables, forms, buttons, and layout | Remaining SKA wrappers are limited to domain-specific secondary-pane/status treatment |
| Public key admin `/pubkeys` | `templates/pubkeys.php` | Bootstrap 5-ready | Uses Bootstrap 5 tabs, cards, filters, forms, input groups, and tables | Residual risk is visual-only |
| Public key detail `/pubkeys/:id` | `templates/pubkey.php` | Bootstrap 5-ready | Uses Bootstrap 5 tabs, forms, buttons, alerts, and tables | Residual risk is visual-only |
| Help `/help` | `templates/help.php` | Bootstrap 5-ready | Uses Bootstrap 5 accordion, tabs, alerts, and collapse behavior | Residual risk is visual-only |
| Access options | `templates/access_options.php` | Bootstrap 5-ready | Uses Bootstrap 5 form controls, checks, buttons, grid, and collapse/card markup | Residual risk is visual-only |
| Servers bulk action | `templates/servers_bulk_action.php` | Bootstrap 5-ready | Uses Bootstrap 5 card, form, table, alert, and collapse behavior | Residual risk is visual-only |
| User public keys | `templates/user_pubkeys.php` | Bootstrap 5-ready | User key export/add flows now use native Bootstrap 5 card, form, and button classes | Residual risk is visual-only |
| Activity | `templates/activity.php` | Bootstrap 5-ready | Secondary activity table now renders through native Bootstrap 5 `card` and `table` classes | Residual risk is visual-only |
| Report | `templates/report.php` | Bootstrap 5-ready | Secondary report sections now use native Bootstrap 5 `card`, `table`, and `table-responsive` classes | Residual risk is visual-only |
| Tools | `templates/tools.php` | Bootstrap 5-ready | Tool list card now uses native Bootstrap 5 card markup | Residual risk is visual-only |
| Bulk mail | `templates/bulk_mail.php` / `templates/bulk_mail_choose.php` | Bootstrap 5-ready | Chooser and compose form now use native Bootstrap 5 card, alert, form, and button classes | Residual risk is visual-only |
| Error / not-found pages | `templates/error*.php`, `templates/*_not_found.php`, `templates/not_admin.php` | Bootstrap 5-ready | Little or no page-local Bootstrap 3 usage | Inherit global shell only |
| JSON/TXT responses | `templates/*json.php`, `templates/*txt.php` | N/A | Not HTML application pages | No Bootstrap dependency |

## Common Blockers

### 1. Icon migration is functionally complete, but cleanup remains

Semantic `ska-icon` markup now covers the active templates and runtime JS. Remaining icon-related cleanup is now about deleting compatibility residue and dead references such as:

- old documentation references
- dormant vendored glyphicon font assets bundled with Bootstrap 3 CSS
- any compatibility selectors that become removable after the final CSS swap

The live font dependency is gone because [public_html/style.css](/var/www/ska/public_html/style.css) now renders active icons through local SVG assets.

### 2. Generic component ownership is now Bootstrap 5-native

Bootstrap 3 CSS/JS is gone from runtime, Bootstrap 5 CSS/JS is loaded from fixed local assets, and generic component ownership now sits with Bootstrap 5. Remaining work is maintenance-oriented:

- stale docs/checkpoint references that still describe earlier migration phases
- optional simplification of app-specific wrappers on a few detail/settings pages
- keeping smoke/browser coverage current as those pages evolve

## Recommended Next Slices

1. Keep stale migration docs/checkpoints trimmed so current-state docs stay authoritative.
2. Optionally simplify the remaining app-specific settings-shell wrappers on `group`, `server`, and `serveraccount` if future UI work touches them.
3. Extend browser smoke coverage when new interaction-heavy UI changes land.

## Exit Criteria For Completing The CSS Migration

This migration target is reached on the current branch when:

- Bootstrap 5 CSS and JS are the only third-party frontend runtime assets
- generic layout/components use Bootstrap 5 directly
- SKA-specific CSS is limited to shell, icon, status, and bespoke admin-layout concerns
- semantic local icons are the only live icon path in templates/runtime JS
- smoke/browser capture confirms the cleaned high-traffic and secondary pages still render acceptably
