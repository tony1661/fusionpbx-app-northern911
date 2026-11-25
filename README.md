# E911 for FusionPBX (Northern 911)

Keeps the Northern 911 address for every emergency number in each domain, audits extensions against those addresses, and exports the file you upload to Northern 911.

## Install

1. Clone the app into a `northern911` folder under the FusionPBX app directory. The folder must be named `northern911`, because the menu links point to `/app/northern911/`.
   ```
   cd /var/www/fusionpbx/app
   git clone https://github.com/tony1661/fusionpbx-app-northern911.git northern911
   ```
2. `chown -R www-data:www-data /var/www/fusionpbx/app/northern911`
3. In FusionPBX go to Advanced > Upgrade, tick App Defaults, Menu Defaults, Permission Defaults and Schema, then run it. From the shell you can run `cd /var/www/fusionpbx && php core/upgrade/upgrade.php` instead.
4. Log out and back in so the new menu items and permissions load.

You will find E911 and E911 Dashboard under Applications.

## First run

1. Set your customer number (see Customer number below).
2. Open the Northern 911 sheet in Excel and save it as CSV.
3. Go to E911 Dashboard > Import and upload it. Leave "already in the carrier database" ticked so these numbers are not flagged as waiting for export.
4. On the review screen, check the domain picked for each customer. Numbers matched by name are marked "please check". Anything with no match is listed first and is skipped unless you pick a domain. Rows with a missing required field are marked "will be skipped" and are not imported. Fix them in the sheet and import again, or add them by hand.

## Addresses

Every field is required except Unit / Suite, 911 Instructions and Internal Notes. An address cannot be saved or imported with a required field missing.

Domain admins see only their own domain. Superadmins can use Show All to list every domain's addresses, or open a domain from the dashboard.

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

To leave an extension out of the audit (fax, paging, door phones), put `no911` in its description. The tag can be changed under Default Settings, category `northern911`, subcategory `exempt_tag`.

## Export

E911 Dashboard > Export writes the Northern 911 file: 13 columns, no header row, CRLF line endings. By default it exports every address in every domain. You can pick one domain, or only the addresses that are new or changed since the last export. Disabled addresses are never exported, and any address with a missing field is left out and listed on the page. Exported addresses are marked as sent so the dashboard knows they have been uploaded.

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

Set it under Advanced > Default Settings, category `northern911`, subcategory `account_id`, and make sure it is enabled. Until it is set, every E911 page shows a red banner and exports are blocked.

Deleting or disabling an address here does not remove it from Northern 911. Remove it there as well.

## Permissions

| Permission | Default groups |
|---|---|
| northern911_address_view, add, edit, delete | superadmin, admin |
| northern911_address_all (see every domain) | superadmin |
| northern911_dashboard_view, northern911_import, northern911_export | superadmin |

## Uninstall

Uninstalling removes the app and its data from FusionPBX only. It does not remove any numbers from Northern 911, so remove those there first if the service is ending.

1. Keep a copy of the data. Run an export (Everything, All domains) and/or back up the table:
   ```
   sudo -u postgres pg_dump fusionpbx -t v_northern911_addresses > /root/northern911_addresses.sql
   ```
2. Remove the app folder:
   ```
   rm -rf /var/www/fusionpbx/app/northern911
   ```
3. Remove the menu items, permissions, settings and table:
   ```
   sudo -u postgres psql fusionpbx
   ```
   ```sql
   delete from v_menu_item_groups where menu_item_uuid in ('a9f66ce4-ca27-4b1b-9d35-740dd9aaa797', 'f90666db-bdf9-43bb-888d-d20f4e43fd7f');
   delete from v_menu_languages where menu_item_uuid in ('a9f66ce4-ca27-4b1b-9d35-740dd9aaa797', 'f90666db-bdf9-43bb-888d-d20f4e43fd7f');
   delete from v_menu_items where menu_item_uuid in ('a9f66ce4-ca27-4b1b-9d35-740dd9aaa797', 'f90666db-bdf9-43bb-888d-d20f4e43fd7f');
   delete from v_group_permissions where permission_name like 'northern911\_%';
   delete from v_permissions where permission_name like 'northern911\_%';
   delete from v_default_settings where default_setting_category = 'northern911';
   delete from v_domain_settings where domain_setting_category = 'northern911';
   drop table if exists v_northern911_addresses;
   \q
   ```
4. Log out and back in so the menu and permissions reload.

Running Menu Defaults after removing the folder also removes the menu items, but it rebuilds the whole default menu and undoes any menu changes you have made. The SQL above removes only this app's entries.

## Requirements

FusionPBX 5.x on PostgreSQL, PHP 7.3 or newer.
