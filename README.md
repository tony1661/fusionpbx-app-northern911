# E911 for FusionPBX

Keeps the E911 address for every emergency number in each domain, audits extensions against those addresses, and exports the file you upload to your E911 carrier.

## Install

1. Copy the `e911` folder to `/var/www/fusionpbx/app/e911`
2. `chown -R www-data:www-data /var/www/fusionpbx/app/e911`
3. In FusionPBX go to Advanced > Upgrade, tick App Defaults, Menu Defaults, Permission Defaults and Schema, then run it. From the shell you can run `cd /var/www/fusionpbx && php core/upgrade/upgrade.php` instead.
4. Log out and back in so the new menu items and permissions load.

You will find E911 and E911 Dashboard under Applications.

## First run

1. Set your customer number (see Customer number below).
2. Open the Northern 911 sheet in Excel and save it as CSV.
3. Go to E911 Dashboard > Import and upload it. Leave "already in the carrier database" ticked so these numbers are not flagged as waiting for export.
4. On the review screen, check the domain picked for each customer. Numbers matched by name are marked "please check". Anything with no match is listed first and is skipped unless you pick a domain.

## What the audit checks

Only enabled extensions in enabled domains are checked, using the Emergency Caller ID Number on each extension.

Errors
- Extension has no emergency caller ID number
- Extension emergency number is not a valid 10 digit number
- Extension uses a number with no E911 address
- Extension uses a number whose address is in a different domain
- Same number has more than one address
- Address is missing a required field (name, street number, street name, city, province, postal code)
- Postal code is not valid

Warnings
- Address exists but no extension uses that number
- Number is not an inbound destination in the domain (only checked when the domain has inbound destinations)

Notices
- Address has never been exported, or changed after the last export
- Postal code has no space (K6J5T8 instead of K6J 5T8)
- Address is disabled

To leave an extension out of the audit (fax, paging, door phones), put `no911` in its description. The tag can be changed under Default Settings, category `e911`, subcategory `exempt_tag`.

## Export

E911 Dashboard > Export writes the same 13 column, no header layout as the carrier file. By default it only includes new or changed addresses, leaves out any with missing fields, and marks what it exported so the dashboard knows it has been sent.

Columns in the export:

| Column | Value |
|---|---|
| A | Customer number from Default Settings |
| B | Always A |
| C | Phone number |
| D | Name the 911 operator sees |
| E | Always blank |
| F | Street number |
| G | Unit |
| H | Street name |
| I | City |
| J | Province code |
| K | Postal code |
| L | Instructions for the 911 operator |
| M | Always N |

### Customer number

Set it under Advanced > Default Settings, category `e911`, subcategory `account_id`, and make sure it is enabled. Until it is set, every E911 page shows a red banner and exports are blocked.

Deleting an address here does not remove it from the carrier. Remove it there as well.

## Permissions

| Permission | Default groups |
|---|---|
| e911_address_view, add, edit, delete | superadmin, admin |
| e911_address_all (see every domain) | superadmin |
| e911_dashboard_view, e911_import, e911_export | superadmin |

Domain admins see only their own domain's addresses and extensions.

## Requirements

FusionPBX 5.x on PostgreSQL, PHP 7.3 or newer.
