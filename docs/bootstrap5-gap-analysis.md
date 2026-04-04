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
- Bootstrap glyphicon classes and entity-link icons are now rendered through repo-owned SVG assets in [public_html/icons/](/var/www/ska/public_html/icons/) via [public_html/style.css](/var/www/ska/public_html/style.css), not the Bootstrap font glyphs.
- Bootstrap 3 `panel-*` markup has been migrated to local `ska-card*` classes in active templates.
- Headless browser capture is now available through [scripts/smoke/browser-capture.sh](/var/www/ska/scripts/smoke/browser-capture.sh) for authenticated visual regression checks.

The main remaining blockers are now CSS- and markup-oriented rather than plugin-oriented:

- a few remaining Bootstrap 3 shell/layout conventions
- legacy icon classnames and local compatibility styling that still need semantic cleanup
- remaining global Bootstrap 3 CSS assumptions in the shell and untargeted secondary templates
- reliance on `public_html/bootstrap5-compat.css` utility aliases while pages are still mixed

## Page Inventory

| Page / Route | Template | Status | Current state | Remaining blockers |
| --- | --- | --- | --- | --- |
| Global shell | `templates/base.php` | Mixed | Native dropdown and alert-dismiss behavior; no jQuery or Bootstrap JS | Still uses Bootstrap 3 navbar/layout CSS and global Bootstrap 3 stylesheet |
| Login `/login` | `templates/login.php` | Bootstrap 5-ready | Form markup is already modern and low-complexity | Inherits Bootstrap 3 shell/base CSS |
| Home `/` | `templates/home.php` | Mixed | Native add-key interactions; core form flow is stable | Still uses legacy icon classnames and Bootstrap 3 table/button styling |
| Users list `/users` | `templates/users.php` | Bootstrap 5-ready | No significant template-local Bootstrap 3 markers | Inherits global CSS baseline only |
| User detail `/users/:uid` | `templates/user.php` | Mixed | Tabs are on native Bootstrap 5-style behavior | Still uses legacy icon classnames and Bootstrap 3 table/form styling in key-management areas |
| Groups list `/groups` | `templates/groups.php` | Mixed | Tabs are migrated; filter card and add forms use local SKA/Bootstrap 5-style markup | Remaining blockers are legacy icon classnames and Bootstrap 3 table/shell styling |
| Group detail `/groups/:name` | `templates/group.php` | Mixed | Tabs, add flows, and settings forms are migrated to local SKA/Bootstrap 5-style markup | Still dense with legacy icon classnames and Bootstrap 3 table/layout styling |
| Servers list `/servers` | `templates/servers.php` | Mixed | Tabs are migrated; filter card uses local check controls and pending badges are modernized | Remaining blockers are legacy icon classnames and Bootstrap 3 table/shell styling |
| Server detail `/servers/:hostname` | `templates/server.php` | Mixed | Native tabs, native settings toggles, modernized settings/contact forms, local note cards | Still dense with legacy icon classnames, some legacy utility hooks, and Bootstrap 3 table/shell styling |
| Server account `/servers/:hostname/accounts/:name` | `templates/serveraccount.php` | Mixed | Native tabs, native sync polling, modernized access/public-key forms, add-leader flow updated | Remaining blockers are legacy icon classnames and Bootstrap 3 table/layout styling across multiple panes |
| Public key admin `/pubkeys` | `templates/pubkeys.php` | Mixed | Tabs are migrated and filter/details cards are local SKA markup | Legacy icon classnames and some Bootstrap 3 table/status styling remain |
| Public key detail `/pubkeys/:id` | `templates/pubkey.php` | Mixed | Tabs are migrated and key actions are stable | Legacy icon classnames and Bootstrap 3 table/button styling remain |
| Help `/help` | `templates/help.php` | Mixed | Accordion now runs on native collapse behavior and local SKA card markup | Legacy icon classnames and Bootstrap 3 content styling remain |
| Access options | `templates/access_options.php` | Mixed | Advanced options collapse is native, wrapped in local SKA card markup, and uses local check controls | Remaining blockers are legacy icon classnames and shell baseline CSS |
| Servers bulk action | `templates/servers_bulk_action.php` | Mixed | Server-list collapse is native and wrapped in local SKA card markup | Legacy icon classnames and Bootstrap 3 form/table styling remain |
| User public keys | `templates/user_pubkeys.php` | Mixed | Simple data/form page now uses local SKA cards | Export actions still use legacy icon classnames and Bootstrap 3 form styling |
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
- remaining template markup that still assumes Bootstrap 3 spacing or component defaults
- untargeted secondary pages that still inherit old Bootstrap 3 conventions without page-local cleanup

This is the main path to dropping `bootstrap.min.css`.

### 2. Legacy icon markup remains widespread

Legacy glyphicon classnames are still embedded in:

- page headings for users, groups, servers, and accounts
- JSON/TXT export buttons
- deleted/signed/destination-restricted indicators
- list-management actions and admin list rendering

The live font dependency is gone because [public_html/style.css](/var/www/ska/public_html/style.css) now renders those icons through local SVG assets, but the legacy classnames and string-built icon markup should still be cleaned up.

### 3. Compatibility CSS is still carrying mixed pages

[public_html/bootstrap5-compat.css](/var/www/ska/public_html/bootstrap5-compat.css) is still doing real work:

- utility aliases (`float-end`, spacing, visibility, badges, etc.)
- Bootstrap 5-style close button styling
- mixed-layout support while templates are only partially migrated

That file should shrink only after the remaining shell/helper/layout migrations land.

## Recommended Next Slices

1. Replace remaining legacy glyphicon classnames with semantic local icon helpers in the core admin and key-management flows:
   - `templates/server.php`
   - `templates/serveraccount.php`
   - `templates/user.php`
   - `templates/home.php`
   - `templates/pubkeys.php`

2. Continue shell/layout cleanup on untargeted secondary pages:
   - `templates/groups.php`
   - `templates/servers.php`
   - `templates/servers_bulk_action.php`
   - `templates/user_pubkeys.php`

3. Reduce the compatibility layer after semantic cleanup:
   - trim [public_html/bootstrap5-compat.css](/var/www/ska/public_html/bootstrap5-compat.css)
   - remove any remaining migration-only markup once pages no longer need compatibility aliases

## Exit Criteria For Removing Bootstrap 3 CSS

Bootstrap 3 CSS can be removed when:

- remaining Bootstrap 3 shell/helper/layout classes are eliminated from HTML templates
- legacy glyphicon classnames and icon shims are replaced with semantic local icons
- the shell no longer depends on Bootstrap 3 navbar/layout styling
- `public_html/bootstrap5-compat.css` no longer needs to alias Bootstrap 3 behaviors to keep mixed pages working
