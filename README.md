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

You will find E911 under Applications.

### Adding the menu item manually

If E911 does not show up under Applications after the upgrade, or you would rather not run Menu Defaults (it rebuilds the whole default menu and undoes any menu changes you have made), add the menu item by hand:

1. Go to Advanced > Menu Manager and click the default menu to edit it.
2. Click Add and fill in the menu item:

   | Field | Value |
   |---|---|
   | Title | E911 |
   | Link | `/app/northern911/e911_addresses.php` |
   | Target | Internal |
   | Parent Menu | Applications |
   | Groups | superadmin and admin |
   | Order | leave blank, or a number to place it in the list |
   | Protected | False |

3. Save, then log out and back in.

The menu item only controls what appears in the menu. Access to the page still comes from the permissions, so Permission Defaults must have been run (or the `northern911_*` permissions added to the groups under Advanced > Group Manager).

Leave Protected set to False. If you run Menu Defaults later, it replaces this item with the one from the app. A protected item would be kept as well, and you would end up with two.

## First run

1. Set your customer number (see Customer number below).
2. Open the Northern 911 sheet in Excel and save it as CSV.
3. Go to E911 > Import and upload it. Leave "already in the carrier database" ticked so these numbers are not flagged as waiting for export.
4. On the review screen, check the domain picked for each customer. Numbers matched by name are marked "please check". Anything with no match is listed first and is skipped unless you pick a domain. Rows with a missing required field are marked "will be skipped" and are not imported. Fix them in the sheet and import again, or add them by hand.

## The E911 page

Everything is on one page, for the domain selected at the top right of FusionPBX:

- Charts for extensions, addresses and issues
- The E911 addresses, with + Add Address, Toggle and Delete
- The extensions and whether each one is covered. Click an extension to open its settings.
- The list of issues, filtered by severity

Superadmins also get a Show All button. It shows the same page across every domain, with a Domains chart, a table of domains (worst first) and a Domain column on each list. Clicking a domain, address or extension switches FusionPBX to that domain and opens it, the same way the core list pages do. This needs a FusionPBX version that handles `domain_change=true` links and the `domain_select` permission.

Domain admins see only their own domain.

Every address field is required except Unit / Suite, 911 Instructions and Internal Notes. An address cannot be saved or imported with a required field missing.

## What the audit checks

Only enabled extensions in enabled domains are checked, using the Emergency Caller ID Number on each extension.

Disabled domains that still have E911 addresses are checked too. Their extensions are skipped, and each of their addresses is flagged because it is never exported and may still be registered with Northern 911. These domains show as Disabled in Show All.

Errors
- Extension has no emergency caller ID number
- Extension emergency number is not a valid 10 digit number
- Extension uses a number with no E911 address
- Extension uses a number whose address is in a different domain
- Same number has more than one address
- Address is missing a required field (name, street number, street name, city, province, postal code)
- Postal code is not valid
- Address belongs to a disabled domain but was exported before, so Northern 911 probably still has it. Remove it there, then delete or disable it here.

Warnings
- Address exists but no extension uses that number (unless the address has Extension Required set to False)
- Number is not an inbound destination in the domain (only checked when the domain has inbound destinations)
- Address belongs to a disabled domain and was never exported. Delete or disable it.

Notices
- Address has never been exported, or changed after the last export
- Postal code has no space (K6J5T8 instead of K6J 5T8)
- Address is disabled

To leave an extension out of the audit (fax, paging, door phones), put `no911` in its description. The tag can be changed under Default Settings, category `northern911`, subcategory `exempt_tag`. The E911 page shows this hint under Extensions.

Some numbers need a 911 address even though no extension uses them as its emergency number, such as a main line, an analog fax line or an alarm panel. Edit the address and set Extension Required to False. It is still checked and exported, but it is not flagged as unused, and it counts as "No extension needed" in the Addresses chart.

## Export

E911 > Export writes the Northern 911 file: 13 columns, no header row, CRLF line endings. By default it exports every address in every domain. You can pick one domain, or only the addresses that are new or changed since the last export. Disabled addresses and addresses in disabled domains are never exported. Those, and any address with a missing field, are left out and listed on the page. Exported addresses are marked as sent so the E911 page knows they have been uploaded.

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
| northern911_import, northern911_export | superadmin |

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
4. If you added the menu item by hand, the SQL above does not find it. Delete it under Advanced > Menu Manager instead.
5. Log out and back in so the menu and permissions reload.

Running Menu Defaults after removing the folder also removes the menu items, but it rebuilds the whole default menu and undoes any menu changes you have made. The SQL above removes only this app's entries.

## Requirements

FusionPBX 5.x on PostgreSQL, PHP 7.3 or newer.
