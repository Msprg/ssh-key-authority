# Bootstrap 5 Gap Analysis

Date: 2026-04-02
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
- Bootstrap glyphicon classes and entity-link icons are now rendered through repo-owned SVG masks in [public_html/style.css](/var/www/ska/public_html/style.css), not the Bootstrap font glyphs.
- Bootstrap 3 `panel-*` markup has been migrated to local `ska-card*` classes in active templates.

The main remaining blockers are now CSS- and markup-oriented rather than plugin-oriented:

- a few remaining Bootstrap 3 shell/layout conventions
- remaining Bootstrap 3 helper/form/layout classes such as `form-inline`, `checkbox`, `table-condensed`, and shell navbar structures
- legacy icon classnames and local compatibility styling that still need semantic cleanup
- reliance on `public_html/bootstrap5-compat.css` utility aliases while pages are still mixed

## Page Inventory

| Page / Route | Template | Status | Current state | Remaining blockers |
| --- | --- | --- | --- | --- |
| Global shell | `templates/base.php` | Mixed | Native dropdown and alert-dismiss behavior; no jQuery or Bootstrap JS | Still uses Bootstrap 3 navbar/layout CSS and global Bootstrap 3 stylesheet |
| Login `/login` | `templates/login.php` | Bootstrap 5-ready | Form markup is already modern and low-complexity | Inherits Bootstrap 3 shell/base CSS |
| Home `/` | `templates/home.php` | Mixed | Native add-key interactions; core form flow is stable | Still uses legacy icon classnames and Bootstrap 3 table/button styling |
| Users list `/users` | `templates/users.php` | Bootstrap 5-ready | No significant template-local Bootstrap 3 markers | Inherits global CSS baseline only |
| User detail `/users/:uid` | `templates/user.php` | Mixed | Tabs are on native Bootstrap 5-style behavior | Still uses legacy icon classnames and Bootstrap 3 table/form styling in key-management areas |
| Groups list `/groups` | `templates/groups.php` | Mixed | Tabs are migrated; filter card is now local SKA markup | Remaining blockers are Bootstrap 3 checkbox/form/list styling and legacy icon classnames |
| Group detail `/groups/:name` | `templates/group.php` | Legacy-heavy | Tabs and form primitives are migrated; core workflows preserved | Still dense with legacy icon classnames and Bootstrap 3 table/form/layout styling |
| Servers list `/servers` | `templates/servers.php` | Mixed | Tabs are migrated; filter card is now local SKA markup | Remaining blockers are Bootstrap 3 checkbox/table/list styling and legacy icon classnames |
| Server detail `/servers/:hostname` | `templates/server.php` | Legacy-heavy | Native tabs, native settings toggles, modernized key form primitives, local note cards | Still one of the densest pages for legacy icon classnames, Bootstrap 3 tables/forms, and shell-era layout patterns |
| Server account `/servers/:hostname/accounts/:name` | `templates/serveraccount.php` | Legacy-heavy | Native tabs, native sync polling, modernized access/public-key form primitives | Still dense with legacy icon classnames and Bootstrap 3 table/form/layout styling across multiple panes |
| Public key admin `/pubkeys` | `templates/pubkeys.php` | Mixed | Tabs are migrated and filter/details cards are local SKA markup | Legacy icon classnames and some Bootstrap 3 table/status styling remain |
| Public key detail `/pubkeys/:id` | `templates/pubkey.php` | Mixed | Tabs are migrated and key actions are stable | Legacy icon classnames and Bootstrap 3 table/button styling remain |
| Help `/help` | `templates/help.php` | Mixed | Accordion now runs on native collapse behavior and local SKA card markup | Legacy icon classnames and Bootstrap 3 content styling remain |
| Access options | `templates/access_options.php` | Mixed | Advanced options collapse is native and wrapped in local SKA card markup | Remaining blockers are Bootstrap 3 checkbox/form styling and shell baseline CSS |
| Servers bulk action | `templates/servers_bulk_action.php` | Mixed | Server-list collapse is native and wrapped in local SKA card markup | Legacy icon classnames and Bootstrap 3 form/table styling remain |
| User public keys | `templates/user_pubkeys.php` | Mixed | Simple data/form page now uses local SKA cards | Export actions still use legacy icon classnames and Bootstrap 3 form styling |
| Activity | `templates/activity.php` | Bootstrap 5-ready | No significant local Bootstrap 3 markers found | Inherits global CSS baseline only |
| Report | `templates/report.php` | Mixed | Mostly static content on local SKA card markup | Still inherits Bootstrap 3 content styling |
| Tools | `templates/tools.php` | Bootstrap 5-ready | No significant local Bootstrap 3 markers found | Inherits global CSS baseline only |
| Bulk mail | `templates/bulk_mail.php` / `templates/bulk_mail_choose.php` | Bootstrap 5-ready | No significant local Bootstrap 3 markers found | Inherits global CSS baseline only |
| Error / not-found pages | `templates/error*.php`, `templates/*_not_found.php`, `templates/not_admin.php` | Bootstrap 5-ready | Little or no page-local Bootstrap 3 usage | Inherit global shell only |
| JSON/TXT responses | `templates/*json.php`, `templates/*txt.php` | N/A | Not HTML application pages | No Bootstrap dependency |

## Common Blockers

### 1. Bootstrap 3 shell and helper classes still dominate several pages

Most remaining work is now about replacing:

- Bootstrap 3 navbar/layout structures still assumed by the shell
- helper/layout classes such as `form-inline`, `checkbox`, `table-condensed`, and old visibility/layout conventions
- remaining template markup that still assumes Bootstrap 3 spacing or component defaults

This is the main path to dropping `bootstrap.min.css`.

### 2. Legacy icon markup remains widespread

Legacy glyphicon classnames are still embedded in:

- page headings for users, groups, servers, and accounts
- JSON/TXT export buttons
- deleted/signed/destination-restricted indicators
- list-management actions and admin list rendering

The live font dependency is gone because [public_html/style.css](/var/www/ska/public_html/style.css) now renders those icons through local SVG masks, but the markup and compatibility shim should still be cleaned up.

### 3. Compatibility CSS is still carrying mixed pages

[public_html/bootstrap5-compat.css](/var/www/ska/public_html/bootstrap5-compat.css) is still doing real work:

- utility aliases (`float-end`, spacing, visibility, badges, etc.)
- Bootstrap 5-style close button styling
- mixed-layout support while templates are only partially migrated

That file should shrink only after the remaining shell/helper/layout migrations land.

## Recommended Next Slices

1. Replace remaining Bootstrap 3 shell/helper/layout primitives on the highest-traffic detail pages:
   - `templates/server.php`
   - `templates/serveraccount.php`
   - `templates/group.php`

2. Replace remaining legacy glyphicon classnames with semantic local icon helpers in the core admin and key-management flows:
   - `templates/server.php`
   - `templates/serveraccount.php`
   - `templates/user.php`
   - `templates/home.php`
   - `templates/pubkeys.php`

3. Reduce the compatibility layer after structural cleanup:
   - trim [public_html/bootstrap5-compat.css](/var/www/ska/public_html/bootstrap5-compat.css)
   - remove any remaining migration-only markup once pages no longer need compatibility aliases

## Exit Criteria For Removing Bootstrap 3 CSS

Bootstrap 3 CSS can be removed when:

- remaining Bootstrap 3 shell/helper/layout classes are eliminated from HTML templates
- legacy glyphicon classnames and icon shims are replaced with semantic local icons
- the shell no longer depends on Bootstrap 3 navbar/layout styling
- `public_html/bootstrap5-compat.css` no longer needs to alias Bootstrap 3 behaviors to keep mixed pages working
