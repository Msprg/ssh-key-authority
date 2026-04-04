# Bootstrap 5 Gap Analysis

Date: 2026-04-04
Branch: `bootstrap5-upgrade-part1`

## Scope

This inventory covers the rendered HTML templates and the globally loaded frontend runtime:

- `templates/base.php`
- `templates/*.php`
- `public_html/bootstrap5-compat.css`
- `public_html/extra.js`

Status legend:

- `Bootstrap 5-ready`: mostly modern markup, only inherits the remaining Bootstrap 3 CSS baseline.
- `Mixed`: behavior is already native/Bootstrap 5-style, but the template still depends on Bootstrap 3 CSS or local compatibility shims for legacy markup families.
- `Legacy-heavy`: high-traffic page with substantial remaining Bootstrap 3 layout or helper markup that would make Bootstrap 3 CSS removal risky today.

## Runtime Baseline

Current pages load the frontend shell from [templates/base.php](/var/www/ska/templates/base.php) with:

- `public_html/bootstrap/css/bootstrap.min.css`
- `public_html/style.css`
- `public_html/bootstrap5-compat.css`
- `public_html/header.js`
- `public_html/extra.js`

Current runtime facts:

- Bootstrap 3 JS is no longer loaded.
- jQuery is no longer loaded.
- `public_html/bootstrap5-compat.js` has been retired.
- Tabs, collapses, dropdowns, alert dismissal, and the local form helpers now run through native DOM code in [public_html/extra.js](/var/www/ska/public_html/extra.js).
- Migrated tabsets now use repo-local `ska-tabs` / `ska-tab-content` / `ska-tab-pane` classes in [public_html/style.css](/var/www/ska/public_html/style.css), so active tab behavior and styling no longer depend on Bootstrap 3 tab CSS.
- High-traffic list/detail tables on the main shell pages now use repo-local `ska-table*` classes in [public_html/style.css](/var/www/ska/public_html/style.css), reducing dependence on Bootstrap 3 table CSS.
- Shared button and alert presentation now has a repo-local baseline in [public_html/style.css](/var/www/ska/public_html/style.css), so active pages no longer rely on Bootstrap 3 for those visual primitives.
- High-traffic pages now use semantic `ska-icon` helpers and entity-link icons rendered through repo-owned SVG assets in [public_html/icons/](/var/www/ska/public_html/icons/) via [public_html/style.css](/var/www/ska/public_html/style.css), not the Bootstrap font glyphs.
- Bootstrap 3 `panel-*` markup has been migrated to local `ska-card*` classes in active templates.
- [public_html/bootstrap5-compat.css](/var/www/ska/public_html/bootstrap5-compat.css) has been trimmed down to the aliases that still have live runtime usage.
- Headless browser capture is now available through [scripts/smoke/browser-capture.sh](/var/www/ska/scripts/smoke/browser-capture.sh) for authenticated visual regression checks.

The main remaining blockers are now CSS- and markup-oriented rather than plugin-oriented:

- a few remaining Bootstrap 3 shell/layout conventions
- final compatibility selectors and Bootstrap 3 CSS assumptions that still need cleanup on untargeted pages
- remaining global Bootstrap 3 CSS assumptions in the shell and untargeted secondary templates
- reliance on `public_html/bootstrap5-compat.css` utility aliases while pages are still mixed

## Page Inventory

| Page / Route | Template | Status | Current state | Remaining blockers |
| --- | --- | --- | --- | --- |
| Global shell | `templates/base.php` | Mixed | Native dropdown and alert-dismiss behavior; no jQuery or Bootstrap JS | Still uses Bootstrap 3 navbar/layout CSS and global Bootstrap 3 stylesheet |
| Login `/login` | `templates/login.php` | Bootstrap 5-ready | Form markup is already modern and low-complexity | Inherits Bootstrap 3 shell/base CSS |
| Home `/` | `templates/home.php` | Mixed | Native add-key interactions; core form flow is stable; high-traffic icon markup and table markup are now local | Still inherits Bootstrap 3 button/shell styling |
| Users list `/users` | `templates/users.php` | Bootstrap 5-ready | No significant template-local Bootstrap 3 markers | Inherits global CSS baseline only |
| User detail `/users/:uid` | `templates/user.php` | Mixed | Tabs, high-traffic tables, and icons are now local | Still uses Bootstrap 3 form styling in key-management areas |
| Groups list `/groups` | `templates/groups.php` | Mixed | Tabs are local; filter card, group-list table, and add forms use local SKA/Bootstrap 5-style markup; action icons are semantic | Remaining blockers are Bootstrap 3 shell/button styling |
| Group detail `/groups/:name` | `templates/group.php` | Mixed | Tabs, major detail tables, add flows, settings forms, and icon markup are migrated to local SKA/Bootstrap 5-style markup | Still dense with Bootstrap 3 layout/button styling |
| Servers list `/servers` | `templates/servers.php` | Mixed | Tabs are local; filter card, server-list table, and action icons are local | Remaining blockers are Bootstrap 3 shell/button styling |
| Server detail `/servers/:hostname` | `templates/server.php` | Mixed | Local tabs, high-traffic tables, native settings toggles, modernized settings/contact forms, local note cards, and semantic icon markup | Still uses some legacy utility hooks plus Bootstrap 3 shell/button styling |
| Server account `/servers/:hostname/accounts/:name` | `templates/serveraccount.php` | Mixed | Local tabs, high-traffic tables, native sync polling, modernized access/public-key forms, add-leader flow updated, and semantic icon markup | Remaining blockers are Bootstrap 3 layout/button styling across multiple panes |
| Public key admin `/pubkeys` | `templates/pubkeys.php` | Mixed | Local tabs, high-traffic tables, filter/details cards, and semantic icon markup | Some Bootstrap 3 status/button styling remains |
| Public key detail `/pubkeys/:id` | `templates/pubkey.php` | Mixed | Local tabs, major detail tables, key actions, and semantic icon markup | Bootstrap 3 button/content styling remains |
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

### 1. Bootstrap 3 shell and global CSS baseline still dominate several pages

Most remaining work is now about replacing:

- Bootstrap 3 navbar/layout structures still assumed by the shell
- remaining template markup that still assumes Bootstrap 3 spacing, form-grid, or component defaults
- untargeted secondary pages that still inherit old Bootstrap 3 conventions without page-local cleanup

This is the main path to dropping `bootstrap.min.css`.

### 2. Icon migration is functionally complete, but cleanup remains

Semantic `ska-icon` markup now covers the active templates and runtime JS. Remaining icon-related cleanup is now about deleting compatibility residue and dead references such as:

- old documentation references
- dormant vendored glyphicon font assets bundled with Bootstrap 3 CSS
- any compatibility selectors that become removable after the final CSS swap

The live font dependency is gone because [public_html/style.css](/var/www/ska/public_html/style.css) now renders active icons through local SVG assets.

### 3. Compatibility CSS is still carrying mixed pages

[public_html/bootstrap5-compat.css](/var/www/ska/public_html/bootstrap5-compat.css) is still doing real work, but it is now much smaller and closer to a final removal candidate:

- utility aliases (`float-end`, spacing, visibility, badges, etc.)
- Bootstrap 5-style close button styling
- mixed-layout support while templates are only partially migrated

That file should shrink only after the remaining shell/helper/layout migrations land.

## Recommended Next Slices

1. Continue shell/layout cleanup on untargeted secondary pages:
   - `templates/groups.php`
   - `templates/servers.php`
   - `templates/servers_bulk_action.php`
   - `templates/user_pubkeys.php`

2. Reduce the compatibility layer after structural cleanup:
   - trim [public_html/bootstrap5-compat.css](/var/www/ska/public_html/bootstrap5-compat.css)
   - remove any remaining migration-only markup once pages no longer need compatibility aliases
   - delete dead icon compatibility selectors once Bootstrap 3 CSS is removed

## Exit Criteria For Removing Bootstrap 3 CSS

Bootstrap 3 CSS can be removed when:

- remaining Bootstrap 3 shell/helper/layout classes are eliminated from HTML templates
- semantic local icons are the only live icon path in templates/runtime JS
- the shell no longer depends on Bootstrap 3 navbar/layout styling
- `public_html/bootstrap5-compat.css` no longer needs to alias Bootstrap 3 behaviors to keep mixed pages working
