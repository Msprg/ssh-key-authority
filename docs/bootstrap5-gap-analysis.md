# Bootstrap 5 Gap Analysis

Date: 2026-04-04
Branch: `bootstrap5-upgrade-part1`

## Scope

This inventory covers the rendered HTML templates and the globally loaded frontend runtime:

- `templates/base.php`
- `templates/*.php`
- `public_html/extra.js`

Status legend:

- `Bootstrap 5-ready`: mostly modern markup, only inherits the remaining Bootstrap 3 CSS baseline.
- `Mixed`: behavior is already native/Bootstrap 5-style, but the template still depends on Bootstrap 3 CSS or local compatibility shims for legacy markup families.
- `Legacy-heavy`: high-traffic page with substantial remaining Bootstrap 3 layout or helper markup that would make Bootstrap 3 CSS removal risky today.

## Runtime Baseline

Current pages load the frontend shell from [templates/base.php](/var/www/ska/templates/base.php) with:

- `public_html/style.css`
- `public_html/header.js`
- `public_html/extra.js`

Current runtime facts:

- Bootstrap 3 CSS is no longer loaded.
- Bootstrap 3 JS is no longer loaded.
- jQuery is no longer loaded.
- `public_html/bootstrap5-compat.js` has been retired.
- Tabs, collapses, dropdowns, alert dismissal, and the local form helpers now run through native DOM code in [public_html/extra.js](/var/www/ska/public_html/extra.js).
- Migrated tabsets now use repo-local `ska-tabs` / `ska-tab-content` / `ska-tab-pane` classes in [public_html/style.css](/var/www/ska/public_html/style.css), so active tab behavior and styling no longer depend on Bootstrap 3 tab CSS.
- High-traffic list/detail tables on the main shell pages now use repo-local `ska-table*` classes in [public_html/style.css](/var/www/ska/public_html/style.css), reducing dependence on Bootstrap 3 table CSS.
- Shared button and alert presentation now has a repo-local baseline in [public_html/style.css](/var/www/ska/public_html/style.css), so active pages no longer rely on Bootstrap 3 for those visual primitives.
- Shared `container`, `row`, `col-sm-*`, `col-md-*`, and text/status utility styling now has a repo-local baseline in [public_html/style.css](/var/www/ska/public_html/style.css), reducing dependence on Bootstrap 3’s grid layer.
- Shared `form-group`, `form-control`, and `input-group` styling now has a repo-local baseline in [public_html/style.css](/var/www/ska/public_html/style.css), reducing dependence on Bootstrap 3’s form/layout layer across the busiest pages.
- Active form-heavy templates now render local `ska-form-group` wrappers instead of the Bootstrap 3-only `form-group` helper, and the shell dropdown root uses repo-local class hooks.
- Base typography/content defaults, badge styling, and collapse state styling now have repo-local coverage in [public_html/style.css](/var/www/ska/public_html/style.css), allowing the shell to render without `bootstrap.min.css`.
- High-traffic pages now use semantic `ska-icon` helpers and entity-link icons rendered through repo-owned SVG assets in [public_html/icons/](/var/www/ska/public_html/icons/) via [public_html/style.css](/var/www/ska/public_html/style.css), not the Bootstrap font glyphs.
- Bootstrap 3 `panel-*` markup has been migrated to local `ska-card*` classes in active templates.
- `public_html/bootstrap5-compat.css` has been retired; its remaining live utility and component aliases were folded into [public_html/style.css](/var/www/ska/public_html/style.css).
- Headless browser capture is now available through [scripts/smoke/browser-capture.sh](/var/www/ska/scripts/smoke/browser-capture.sh) for authenticated visual regression checks.

The main remaining blockers are now page-local CSS- and markup-oriented rather than framework/plugin-oriented:

- untargeted secondary templates that may still assume older Bootstrap-era content defaults
- remaining page-local helper/content cleanup on mixed templates
- cleanup of stale docs/checkpoint references from earlier migration phases

## Page Inventory

| Page / Route | Template | Status | Current state | Remaining blockers |
| --- | --- | --- | --- | --- |
| Global shell | `templates/base.php` | Bootstrap 5-ready | Native dropdown and alert-dismiss behavior; no Bootstrap or jQuery runtime assets; dropdown root classes are repo-local | Residual risk is visual parity on less-traveled shell states, not framework dependency |
| Login `/login` | `templates/login.php` | Bootstrap 5-ready | Form markup is already modern and low-complexity; shared spacing/grid/text utilities and base typography are local | Residual risk is visual-only |
| Home `/` | `templates/home.php` | Bootstrap 5-ready | Native add-key interactions; high-traffic icon markup, table markup, shared grid utilities, shared form-control styling, and base typography are local | Residual risk is visual-only |
| Users list `/users` | `templates/users.php` | Bootstrap 5-ready | No significant template-local Bootstrap 3 markers | Inherits global CSS baseline only |
| User detail `/users/:uid` | `templates/user.php` | Mixed | Tabs, high-traffic tables, and icons are now local | Still uses Bootstrap 3 form styling in key-management areas |
| Groups list `/groups` | `templates/groups.php` | Mixed | Tabs are local; filter card, group-list table, add forms, shared grid/form utilities, and action icons are semantic | Remaining blockers are Bootstrap 3 content defaults and shell typography |
| Group detail `/groups/:name` | `templates/group.php` | Mixed | Tabs, major detail tables, add flows, settings forms, shared grid/form utilities, and icon markup are local | Still inherits Bootstrap 3 content defaults and some helper-class spacing |
| Servers list `/servers` | `templates/servers.php` | Mixed | Tabs are local; filter card, server-list table, shared grid/form utilities, and action icons are semantic | Remaining blockers are Bootstrap 3 content defaults and shell typography |
| Server detail `/servers/:hostname` | `templates/server.php` | Mixed | Local tabs, high-traffic tables, shared grid/form utilities, native settings toggles, modernized settings/contact forms, local note cards, and semantic icon markup | Still uses some legacy utility hooks plus Bootstrap 3 content defaults |
| Server account `/servers/:hostname/accounts/:name` | `templates/serveraccount.php` | Mixed | Local tabs, high-traffic tables, shared grid/form utilities, native sync polling, modernized access/public-key forms, add-leader flow updated, and semantic icon markup | Remaining blockers are Bootstrap 3 content/typography defaults across multiple panes |
| Public key admin `/pubkeys` | `templates/pubkeys.php` | Mixed | Local tabs, high-traffic tables, filter/details cards, shared form-control styling, and semantic icon markup | Some Bootstrap 3 content/status styling remains |
| Public key detail `/pubkeys/:id` | `templates/pubkey.php` | Mixed | Local tabs, major detail tables, key actions, shared form-control styling, and semantic icon markup | Bootstrap 3 content styling remains |
| Help `/help` | `templates/help.php` | Mixed | Accordion now runs on native collapse behavior, local SKA card markup, and semantic iconography | Bootstrap 3 content styling remains |
| Access options | `templates/access_options.php` | Mixed | Advanced options collapse is native, wrapped in local SKA card markup, and uses local check controls | Remaining blockers are shell baseline CSS and mixed utility styling |
| Servers bulk action | `templates/servers_bulk_action.php` | Mixed | Server-list collapse is native, wrapped in local SKA card markup, and action icons are semantic | Bootstrap 3 form/table styling remains |
| User public keys | `templates/user_pubkeys.php` | Mixed | Simple data/form page now uses local SKA cards and semantic export icons | Bootstrap 3 form styling remains |
| Activity | `templates/activity.php` | Bootstrap 5-ready | No significant local Bootstrap 3 markers found | Inherits global CSS baseline only |
| Report | `templates/report.php` | Mixed | Mostly static content on local SKA card markup | Still inherits Bootstrap 3 content styling |
| Tools | `templates/tools.php` | Bootstrap 5-ready | No significant local Bootstrap 3 markers found | Inherits global CSS baseline only |
| Bulk mail | `templates/bulk_mail.php` / `templates/bulk_mail_choose.php` | Bootstrap 5-ready | No significant local Bootstrap 3 markers found | Inherits global CSS baseline only |
| Error / not-found pages | `templates/error*.php`, `templates/*_not_found.php`, `templates/not_admin.php` | Bootstrap 5-ready | Little or no page-local Bootstrap 3 usage | Inherit global shell only |
| JSON/TXT responses | `templates/*json.php`, `templates/*txt.php` | N/A | Not HTML application pages | No Bootstrap dependency |

## Common Blockers

### 1. Remaining visual cleanup is now page-local

Most remaining work is now about replacing:

- untargeted secondary pages that still assume older Bootstrap-era helper or content defaults
- remaining template markup that can be simplified now that the repo no longer loads Bootstrap CSS
- stale references and checkpoints that are no longer part of the runtime

This is the main path to finishing the Bootstrap 5 migration cleanup.

### 2. Icon migration is functionally complete, but cleanup remains

Semantic `ska-icon` markup now covers the active templates and runtime JS. Remaining icon-related cleanup is now about deleting compatibility residue and dead references such as:

- old documentation references
- dormant vendored glyphicon font assets bundled with Bootstrap 3 CSS
- any compatibility selectors that become removable after the final CSS swap

The live font dependency is gone because [public_html/style.css](/var/www/ska/public_html/style.css) now renders active icons through local SVG assets.

### 3. Bootstrap 3 CSS is gone from runtime, but cleanup remains

The dedicated compatibility file is gone, and `bootstrap.min.css` is no longer loaded. Remaining work is concentrated in:

- secondary templates that have not yet been visually rechecked under the repo-local baseline
- stale docs/checkpoint references that still mention the old runtime path

The next structural work should target those residual page-local assumptions and dead assets directly.

## Recommended Next Slices

1. Continue shell/layout cleanup on untargeted secondary pages:
   - `templates/groups.php`
   - `templates/servers.php`
   - `templates/servers_bulk_action.php`
   - `templates/user_pubkeys.php`

2. Continue shell/content cleanup after structural cleanup:
   - visually recheck untargeted secondary pages under the repo-local baseline
   - remove any remaining migration-only markup once pages no longer need Bootstrap-era behavior
   - delete dead Bootstrap/icon compatibility selectors and stale migration references now that Bootstrap 3 CSS is gone

## Exit Criteria For Completing The CSS Migration

The CSS migration is complete when:

- remaining Bootstrap-era helper/content assumptions are eliminated from active templates
- semantic local icons are the only live icon path in templates/runtime JS
- stale migration references are trimmed so docs match the live runtime
- smoke/browser capture confirms the cleaned high-traffic and secondary pages still render acceptably under the repo-local baseline
