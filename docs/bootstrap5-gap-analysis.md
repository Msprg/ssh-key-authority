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
- `Mixed`: behavior is already native/Bootstrap 5-style, but the template still depends on Bootstrap 3 CSS structures or glyphicons.
- `Legacy-heavy`: high-traffic page with substantial remaining `panel-*`, glyphicon, or old layout markup that would make Bootstrap 3 CSS removal risky today.

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

The main remaining blockers are now CSS- and markup-oriented rather than plugin-oriented:

- `panel-*` wrappers
- glyphicon usage
- a few remaining Bootstrap 3 shell/layout conventions
- reliance on `public_html/bootstrap5-compat.css` utility aliases while pages are still mixed

## Page Inventory

| Page / Route | Template | Status | Current state | Remaining blockers |
| --- | --- | --- | --- | --- |
| Global shell | `templates/base.php` | Mixed | Native dropdown and alert-dismiss behavior; no jQuery or Bootstrap JS | Still uses Bootstrap 3 navbar/layout CSS and global Bootstrap 3 stylesheet |
| Login `/login` | `templates/login.php` | Bootstrap 5-ready | Form markup is already modern and low-complexity | Inherits Bootstrap 3 shell/base CSS |
| Home `/` | `templates/home.php` | Mixed | Native add-key interactions; core form flow is stable | Glyphicon-heavy action and status iconography |
| Users list `/users` | `templates/users.php` | Bootstrap 5-ready | No significant template-local Bootstrap 3 markers | Inherits global CSS baseline only |
| User detail `/users/:uid` | `templates/user.php` | Mixed | Tabs are on native Bootstrap 5-style behavior | Glyphicons and panel styling remain in key-management areas |
| Groups list `/groups` | `templates/groups.php` | Mixed | Tabs are migrated; forms/actions are low-risk | Filter/results still use `panel-*` and glyphicons |
| Group detail `/groups/:name` | `templates/group.php` | Mixed | Tabs and form primitives are migrated; core workflows preserved | Heavy glyphicon usage and some panel wrappers remain across sections |
| Servers list `/servers` | `templates/servers.php` | Mixed | Tabs are migrated; add/add-bulk flows already run on native JS | Filter/list areas still use `panel-*` and glyphicon-based UI cues |
| Server detail `/servers/:hostname` | `templates/server.php` | Legacy-heavy | Native tabs, native settings toggles, modernized key form primitives | Still one of the densest pages for panels, glyphicons, and Bootstrap 3 layout classes |
| Server account `/servers/:hostname/accounts/:name` | `templates/serveraccount.php` | Legacy-heavy | Native tabs, native sync polling, modernized access/public-key form primitives | Still dense with glyphicons and Bootstrap 3 CSS structures across multiple panes |
| Public key admin `/pubkeys` | `templates/pubkeys.php` | Mixed | Tabs are migrated and runtime behavior is native | Uses `panel-*`, glyphicons, and legacy status rendering |
| Public key detail `/pubkeys/:id` | `templates/pubkey.php` | Mixed | Tabs are migrated and key actions are stable | Panel and glyphicon cleanup still needed |
| Help `/help` | `templates/help.php` | Mixed | Accordion now runs on native collapse behavior | Content is still built around `panel-*` and glyphicon markup |
| Access options | `templates/access_options.php` | Mixed | Advanced options collapse is native | Still wrapped in Bootstrap 3 panel markup |
| Servers bulk action | `templates/servers_bulk_action.php` | Mixed | Server-list collapse is native | Still uses `panel-*` and glyphicons |
| User public keys | `templates/user_pubkeys.php` | Mixed | Simple data/form page; no JS-plugin blocker remains | Export actions and wrappers still use glyphicons and `panel-*` |
| Activity | `templates/activity.php` | Bootstrap 5-ready | No significant local Bootstrap 3 markers found | Inherits global CSS baseline only |
| Report | `templates/report.php` | Mixed | Mostly static content | Still built on `panel-*` wrappers |
| Tools | `templates/tools.php` | Bootstrap 5-ready | No significant local Bootstrap 3 markers found | Inherits global CSS baseline only |
| Bulk mail | `templates/bulk_mail.php` / `templates/bulk_mail_choose.php` | Bootstrap 5-ready | No significant local Bootstrap 3 markers found | Inherits global CSS baseline only |
| Error / not-found pages | `templates/error*.php`, `templates/*_not_found.php`, `templates/not_admin.php` | Bootstrap 5-ready | Little or no page-local Bootstrap 3 usage | Inherit global shell only |
| JSON/TXT responses | `templates/*json.php`, `templates/*txt.php` | N/A | Not HTML application pages | No Bootstrap dependency |

## Common Blockers

### 1. Bootstrap 3 CSS structures still dominate several pages

Most remaining work is now about replacing:

- `panel`, `panel-group`, `panel-heading`, `panel-body`, `panel-footer`
- Bootstrap 3 navbar/layout structures still assumed by the shell
- a few remaining Bootstrap 3 helper-class conventions

This is the main path to dropping `bootstrap.min.css`.

### 2. Glyphicon dependency remains widespread

Glyphicons are still embedded in:

- page headings for users, groups, servers, and accounts
- JSON/TXT export buttons
- deleted/signed/destination-restricted indicators
- list-management actions and admin list rendering

Bootstrap 3 CSS cannot be fully removed while the UI still depends on the glyphicon font files.

### 3. Compatibility CSS is still carrying mixed pages

[public_html/bootstrap5-compat.css](/var/www/ska/public_html/bootstrap5-compat.css) is still doing real work:

- utility aliases (`float-end`, spacing, visibility, badges, etc.)
- Bootstrap 5-style close button styling
- mixed-layout support while templates are only partially migrated

That file should shrink only after the remaining panel/icon/layout migrations land.

## Recommended Next Slices

1. Replace `panel-*` on the highest-traffic detail pages with Bootstrap 5-compatible card/section markup:
   - `templates/server.php`
   - `templates/serveraccount.php`
   - `templates/group.php`

2. Replace glyphicon usage with local inline SVG or a small icon helper in the core admin and key-management flows:
   - `templates/server.php`
   - `templates/serveraccount.php`
   - `templates/user.php`
   - `templates/home.php`
   - `templates/pubkeys.php`

3. Reduce the compatibility layer after structural cleanup:
   - trim [public_html/bootstrap5-compat.css](/var/www/ska/public_html/bootstrap5-compat.css)
   - remove obsolete `data-ska-skip-legacy` markers where they are no longer needed

## Exit Criteria For Removing Bootstrap 3 CSS

Bootstrap 3 CSS can be removed when:

- `panel-*` and other Bootstrap 3 structural classes are eliminated from HTML templates
- glyphicon usage is replaced with local icons
- the shell no longer depends on Bootstrap 3 navbar/layout styling
- `public_html/bootstrap5-compat.css` no longer needs to alias Bootstrap 3 behaviors to keep mixed pages working
