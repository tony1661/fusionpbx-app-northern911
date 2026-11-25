# CLAUDE.md

Living context for AI sessions working on this repo. Read it first; **keep it current** (see the last section).

## What this app is

A FusionPBX 5.x app ("E911", installed at `/var/www/fusionpbx/app/northern911`) for a VoIP provider that uses **Northern 911** as its Canadian E911 carrier. It:

1. Stores the Northern 911 civic address for every emergency number, per FusionPBX domain (table `v_northern911_addresses`).
2. Audits extensions against those addresses (each extension's *Emergency Caller ID Number* must map to an address in the same domain).
3. Exports the CSV file that is manually uploaded to Northern 911 (13 columns, no header, CRLF).
4. Imports the existing Northern 911 sheet (CSV) to seed the table, matching carrier customers to domains.

There is no API integration with Northern 911. Everything goes through file upload/download. Deleting or disabling an address here does **not** remove it from the carrier.

`README.md` is the end-user/admin documentation (install, audit rules, export column layout, permissions, uninstall SQL). Keep it in sync when behaviour changes. Don't duplicate it here.

## Files

| File | Role |
|---|---|
| `app_config.php` | App UUID/version, default settings (`northern911/account_id`, `northern911/exempt_tag`), permissions, schema for `v_northern911_addresses` |
| `app_menu.php` | One menu item under Applications → `e911_addresses.php` |
| `app_languages.php` | `$text[...]['en-us']` strings (en-us only) |
| `resources/e911.php` | Shared helpers (`e911_*` functions) + `class e911_audit` (all audit/issue logic). Wrapped in `if (!function_exists('e911_db'))` |
| `e911_addresses.php` | The main single page: charts/widgets, addresses list, extensions coverage, issues. "Show All" mode across domains for `northern911_address_all` |
| `e911_address_edit.php` | Add/edit one address |
| `e911_import.php` | CSV upload → review screen (domain matching, "please check", skip rows) → import |
| `e911_export.php` | Builds carrier file; marks rows exported (`northern911_address_exported` timestamp) |

## Conventions

- Every page starts with `require_once dirname(__DIR__, 2) . "/resources/require.php"`, `resources/check_auth.php`, then `__DIR__ . "/resources/e911.php"`, then a `permission_exists('northern911_*')` check.
- DB access goes through `e911_select()` / `e911_execute()` (wrap FusionPBX `database`, supports `database::new()` or `new database`). Always use bound `:parameters`.
- Writes to addresses go through `e911_save_record()` (strips CR/LF, trims). Carrier row formatting is in `e911_carrier_row()`. CSV lines are built with `e911_csv_line()`.
- POST forms use FusionPBX `token` (create with `$_SERVER['PHP_SELF']`, validate on submit).
- Settings come from `v_default_settings` category `northern911` via `e911_setting()`, which only reads enabled settings.
- Booleans in FusionPBX tables are compared as text: `cast(x as text) = 'true'`.
- Code style follows FusionPBX core: tabs, `//comment` section headers indented one level, `echo` HTML strings.
- Cross-domain links use `e911_domain_link()` (`domain_change=true`), which needs the `domain_select` permission in core.
- Target: PostgreSQL, PHP 7.3+. Schema also declares sqlite/mysql types, but only pgsql is really supported.

## Domain rules worth remembering

- Audit scope: enabled extensions in enabled domains. Disabled domains that still have addresses get flagged: an error if the address was exported before, a warning if it never was.
- Disabled addresses and addresses in disabled domains are **never exported**.
- Required address fields: everything except unit, location_info (911 instructions), description (internal notes).
- Phone numbers: normalized 10-digit NANP (`e911_audit::normalize` / `valid_number`). Postal codes are validated for Canadian provinces (`$canada` list), and a missing space is a notice.
- Exempt extensions: description contains the `exempt_tag` setting (default `no911`).
- Addresses with `northern911_address_extension_required = 'false'` skip the `rec_unused` warning and are counted in `summary['records_no_ext']` (separate from `records_used`). NULL/missing means required, so old rows and imports keep the old behaviour.
- Severity ranking: ok/exempt/disabled < notice < warning < error.
- Without `account_id` set, every page shows a red banner and export is blocked.

## Testing / running

No test suite and no local runtime in the repo. Changes are verified on a FusionPBX server. After schema, menu or permission changes, run Advanced > Upgrade (App Defaults / Permission Defaults / Schema) or `php core/upgrade/upgrade.php`. Use `php -l <file>` locally for syntax checks.

## History / decisions

- 2026: Dashboard (`e911_dashboard.php`) merged into the single `e911_addresses.php` page ("Making it all one page").
- Added handling for disabled domains (audit flags + excluded from export).
- README documents manual menu-item creation because Menu Defaults resets custom menus.
- The exempt tag was only documented in README and the setting description, so users couldn't find it. Added a hint under the Extensions section of the E911 page (`description-exempt_hint`, shows the live tag via `e911_audit::$exempt_tag`).
- Added per-address "Extension Required" field (new column, needs Upgrade > Schema) for numbers like main lines/alarm panels that need a 911 address but no extension points at them.

## Keeping this file current (instruction to AI sessions)

At the end of any session that changes the app's behaviour, structure, conventions, or reveals a non-obvious gotcha:

1. Update the relevant section above (files table, conventions, domain rules).
2. Add a one-line entry under **History / decisions** for meaningful changes, including *why* if it isn't obvious.
3. Update `README.md` too if user-visible behaviour changed.
4. Remove anything that is no longer true. Keep this file concise; it is loaded into every session.

Don't wait to be asked. Treat keeping this file accurate as part of finishing the task.
