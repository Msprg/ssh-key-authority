# Bootstrap 5 Gap Analysis

Date: 2026-04-01
Branch: `bootstrap5-upgrade-part1`

## Scope

This inventory focuses on the rendered HTML templates and the globally loaded frontend runtime:

- `templates/base.php`
- `templates/*.php`
- `public_html/bootstrap5-compat.css`
- `public_html/bootstrap5-compat.js`
- `public_html/extra.js`

Status legend:

- `Bootstrap 5-ready`: mostly Bootstrap 5 markup already, only inherits global legacy shell.
- `Mixed`: page renders correctly behind the compatibility layer but still depends on Bootstrap 3 classes or JS hooks.
- `Legacy-heavy`: page still depends on Bootstrap 3 patterns in ways that would break if Bootstrap 3 JS/CSS were removed today.

## Runtime Baseline

Current authenticated pages still load Bootstrap 3.4.1 CSS/JS plus jQuery 3.7.1 from [templates/base.php](/var/www/ska/templates/base.php). Bootstrap 5 migration today is enabled by:

- `public_html/bootstrap5-compat.css`: utility and class aliases.
- `public_html/bootstrap5-compat.js`: `data-bs-*` to `data-*` attribute backfill for Bootstrap 3 runtime.
- partial template updates that mix Bootstrap 3 and Bootstrap 5 markup on the same page.

The main blockers to dropping Bootstrap 3 JS are:

- shared tab behavior in [public_html/extra.js](/var/www/ska/public_html/extra.js)
- shared collapse/accordion behavior in [public_html/extra.js](/var/www/ska/public_html/extra.js)
- remaining `data-toggle`, `data-parent`, `panel-*`, `input-group-addon`, `btn-default`, `btn-xs`, `label label-default`, and glyphicon usage

## Page Inventory

| Page / Route | Template | Status | Current state | Remaining blockers |
| --- | --- | --- | --- | --- |
| Global shell | `templates/base.php` | Mixed | Includes skip link, mixed Bootstrap 5 utility classes, responsive dropdown shell, compat assets | Still loads Bootstrap 3.4.1 CSS/JS globally; navbar markup is Bootstrap 3-derived; dropdown and dismissible alert behavior still relies on Bootstrap 3 JS |
| Login `/login` | `templates/login.php` | Bootstrap 5-ready | Login form already uses `mb-*`, `form-label`, `w-100` | Still inherits Bootstrap 3 shell/runtime from base template |
| Home `/` | `templates/home.php` | Mixed | Key add form already uses modern spacing utilities and `w-100`; no tab/collapse dependency | Buttons, badges, deleted-state iconography, and admin list iconography still use Bootstrap 3 button/icon patterns |
| Users list `/users` | `templates/users.php` | Bootstrap 5-ready | No template-local Bootstrap 3 markers found in scan | Inherits global shell and table styling only |
| User detail `/users/:uid` | `templates/user.php` | Legacy-heavy | Critical admin workflow; tabbed detail/settings view | Old tab hooks, glyphicon usage, `label label-default`, `btn-default`, `btn-xs` |
| Groups list `/groups` | `templates/groups.php` | Mixed | Tabs already partially use `nav-item`/`nav-link`; filters are straightforward forms | Still uses `data-toggle`, panel markup, `btn-default`, glyphicons in actions |
| Group detail `/groups/:name` | `templates/group.php` | Legacy-heavy | High-traffic management page with members/access/outbound/admin/settings/log tabs | Old tabs, `input-group-addon`, glyphicons, labels, `btn-default`, `btn-xs` |
| Servers list `/servers` | `templates/servers.php` | Legacy-heavy | High-traffic management page with list/add/add-bulk tabs | Old tab hooks, panel markup, glyphicons, badges, `btn-default`, `btn-xs` |
| Server detail `/servers/:hostname` | `templates/server.php` | Legacy-heavy | Core admin workflow with accounts/leaders/settings/log/notes/contact tabs | Old tabs, many glyphicon references, `input-group-addon`, labels, pull utilities, `btn-default`, `btn-xs` |
| Server account `/servers/:hostname/accounts/:name` | `templates/serveraccount.php` | Legacy-heavy | Core access/public-key workflow; most smoke-critical admin behavior lives here | Old tabs, `input-group-addon`, glyphicons, labels, `btn-default`, `btn-xs` |
| Public key admin `/pubkeys` | `templates/pubkeys.php` | Legacy-heavy | Multi-tab management view for managed/new/allowed/denied keys | Old tabs, panel markup, `input-group-addon`, glyphicons, `btn-default`, `btn-xs` |
| Public key detail `/pubkeys/:id` | `templates/pubkey.php` | Legacy-heavy | Tabbed key information/signing/destination restrictions view | Old tabs, glyphicons, `btn-default`, `btn-xs` |
| Help `/help` | `templates/help.php` | Legacy-heavy | Accordion-style documentation page | Depends on `data-toggle="collapse"`, `data-parent`, `panel-*`, glyphicon iconography |
| Access options | `templates/access_options.php` | Legacy-heavy | Uses collapsible advanced options section | Depends on Bootstrap 3 collapse and panel styling |
| Servers bulk action | `templates/servers_bulk_action.php` | Mixed | Bulk action form mostly modernized | Still has collapsible server list trigger, panel markup, glyphicons, `btn-default`, `btn-xs` |
| User public keys | `templates/user_pubkeys.php` | Mixed | Data listing is straightforward | Export actions and panels still use Bootstrap 3 patterns |
| Activity | `templates/activity.php` | Bootstrap 5-ready | No template-local Bootstrap 3 markers found in scan | Inherits global shell |
| Report | `templates/report.php` | Mixed | Mostly static | Still uses `panel-*` wrappers |
| Tools | `templates/tools.php` | Bootstrap 5-ready | No template-local Bootstrap 3 markers found in scan | Inherits global shell |
| Bulk mail | `templates/bulk_mail.php` / `templates/bulk_mail_choose.php` | Bootstrap 5-ready | No template-local Bootstrap 3 markers found in scan | Inherits global shell |
| Error / not-found pages | `templates/error*.php`, `templates/*_not_found.php`, `templates/not_admin.php` | Bootstrap 5-ready | Little or no page-local Bootstrap 3 usage | Inherit global shell only |
| JSON/TXT responses | `templates/*json.php`, `templates/*txt.php` | N/A | Not HTML application pages | No Bootstrap dependency |

## Common Blockers

### 1. Bootstrap 3 JS plugin coupling

Shared behavior in [public_html/extra.js](/var/www/ska/public_html/extra.js) still expects Bootstrap 3 jQuery plugins:

- `$('a[data-toggle="tab"]').tab('show')`
- `shown.bs.tab`
- `show.bs.collapse`

This is the biggest risk reducer to tackle first because it affects the busiest list/detail management pages at once.

### 2. Structural Bootstrap 3 markup still present

Most remaining pages still use one or more of:

- `panel`, `panel-group`, `panel-heading`, `panel-body`
- `input-group-addon`
- `btn-default`, `btn-xs`
- `label label-default`
- `pull-left`, `pull-right`

These are mostly CSS/markup migrations and are lower-risk than backend changes, but they are still required before Bootstrap 3 CSS can be removed.

### 3. Glyphicon dependency

Iconography is still embedded in core workflows:

- group/server/account/user headings
- JSON/TXT export buttons
- deleted/signed/destination-restricted indicators
- group admin list rendering

Because Bootstrap 3 CSS pulls glyphicons transitively, icon replacement is a required precondition for removing Bootstrap 3 CSS.

## Recommended Next Slices

1. Replace shared Bootstrap 3 tab behavior with Bootstrap 5-compatible native JS and migrate the highest-traffic tabbed pages first:
   - `templates/servers.php`
   - `templates/groups.php`
   - `templates/user.php`
   - shared tab support in `public_html/extra.js`

2. Replace shared collapse/accordion behavior with Bootstrap 5-compatible native JS and migrate:
   - `templates/help.php`
   - `templates/access_options.php`
   - `templates/servers_bulk_action.php`

3. Convert the next core admin forms from `input-group-addon`/`btn-default`/`label label-default` to Bootstrap 5-compatible markup while preserving layout:
   - `templates/serveraccount.php`
   - `templates/group.php`
   - `templates/server.php`

## Exit Criteria For Removing Bootstrap 3

Bootstrap 3 JS can be removed when:

- no page relies on `data-toggle`, `data-target`, `data-parent`, or jQuery Bootstrap plugin methods
- tabs, collapses, dropdowns, and alert dismissal all work through Bootstrap 5-compatible behavior

Bootstrap 3 CSS can be removed when:

- `panel-*`, `input-group-addon`, `btn-default`, `btn-xs`, `label label-default`, `pull-*`, and glyphicon dependencies are eliminated or fully replaced in app templates
