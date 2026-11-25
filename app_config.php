<?php

	//application details
		$apps[$x]['name'] = 'E911';
		$apps[$x]['uuid'] = 'e79f4942-44e3-4efe-86ea-e30cde31cd83';
		$apps[$x]['category'] = 'Switch';
		$apps[$x]['subcategory'] = '';
		$apps[$x]['version'] = '1.0';
		$apps[$x]['license'] = 'Mozilla Public License 1.1';
		$apps[$x]['url'] = '';
		$apps[$x]['description']['en-us'] = 'Keep E911 addresses for each domain, audit extensions against them and export the carrier upload file.';

	//default settings
		$y = 0;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = '881be909-9cfe-41e8-9da0-3b3300af4f13';
		$apps[$x]['default_settings'][$y]['default_setting_category'] = 'e911';
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = 'account_id';
		$apps[$x]['default_settings'][$y]['default_setting_name'] = 'text';
		$apps[$x]['default_settings'][$y]['default_setting_value'] = '';
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = 'true';
		$apps[$x]['default_settings'][$y]['default_setting_description'] = 'Your customer number with the E911 carrier. Sent in column A of every exported row.';
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = '9c1004df-4793-472e-91c2-7ecb252d50e4';
		$apps[$x]['default_settings'][$y]['default_setting_category'] = 'e911';
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = 'exempt_tag';
		$apps[$x]['default_settings'][$y]['default_setting_name'] = 'text';
		$apps[$x]['default_settings'][$y]['default_setting_value'] = 'no911';
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = 'true';
		$apps[$x]['default_settings'][$y]['default_setting_description'] = 'Extensions with this text in their description are skipped by the audit (fax, paging, etc).';

	//permissions
		$permissions = [
			'e911_address_view' => ['superadmin', 'admin'],
			'e911_address_add' => ['superadmin', 'admin'],
			'e911_address_edit' => ['superadmin', 'admin'],
			'e911_address_delete' => ['superadmin', 'admin'],
			'e911_address_all' => ['superadmin'],
			'e911_dashboard_view' => ['superadmin'],
			'e911_import' => ['superadmin'],
			'e911_export' => ['superadmin'],
		];
		$y = 0;
		foreach ($permissions as $permission_name => $permission_groups) {
			$apps[$x]['permissions'][$y]['name'] = $permission_name;
			foreach ($permission_groups as $group_name) {
				$apps[$x]['permissions'][$y]['groups'][] = $group_name;
			}
			$y++;
		}
		unset($permissions, $permission_name, $permission_groups, $group_name);

	//schema
		$y = 0;
		$apps[$x]['db'][$y]['table']['name'] = 'v_e911_addresses';
		$apps[$x]['db'][$y]['table']['parent'] = '';
		$z = 0;
		$apps[$x]['db'][$y]['fields'][$z]['name'] = 'e911_address_uuid';
		$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql'] = 'uuid';
		$apps[$x]['db'][$y]['fields'][$z]['type']['sqlite'] = 'text';
		$apps[$x]['db'][$y]['fields'][$z]['type']['mysql'] = 'char(36)';
		$apps[$x]['db'][$y]['fields'][$z]['key']['type'] = 'primary';
		$z++;
		$apps[$x]['db'][$y]['fields'][$z]['name'] = 'domain_uuid';
		$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql'] = 'uuid';
		$apps[$x]['db'][$y]['fields'][$z]['type']['sqlite'] = 'text';
		$apps[$x]['db'][$y]['fields'][$z]['type']['mysql'] = 'char(36)';
		$apps[$x]['db'][$y]['fields'][$z]['key']['type'] = 'foreign';
		$apps[$x]['db'][$y]['fields'][$z]['key']['reference']['table'] = 'v_domains';
		$apps[$x]['db'][$y]['fields'][$z]['key']['reference']['field'] = 'domain_uuid';
		$text_fields = [
			'e911_address_number' => '10 digit phone number',
			'e911_address_name' => 'Name the 911 operator sees',
			'e911_address_street_number' => 'Street number',
			'e911_address_unit' => 'Unit or suite',
			'e911_address_street_name' => 'Street name',
			'e911_address_city' => 'City',
			'e911_address_province' => 'Province or state',
			'e911_address_postal_code' => 'Postal or ZIP code',
			'e911_address_location_info' => 'Instructions for the 911 operator',
			'e911_address_enabled' => 'true or false',
			'e911_address_description' => 'Internal notes, not exported',
		];
		foreach ($text_fields as $field_name => $field_description) {
			$z++;
			$apps[$x]['db'][$y]['fields'][$z]['name'] = $field_name;
			$apps[$x]['db'][$y]['fields'][$z]['type'] = 'text';
			$apps[$x]['db'][$y]['fields'][$z]['description']['en-us'] = $field_description;
		}
		unset($text_fields, $field_name, $field_description);
		foreach (['e911_address_exported', 'insert_date', 'update_date'] as $field_name) {
			$z++;
			$apps[$x]['db'][$y]['fields'][$z]['name'] = $field_name;
			$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql'] = 'timestamptz';
			$apps[$x]['db'][$y]['fields'][$z]['type']['sqlite'] = 'date';
			$apps[$x]['db'][$y]['fields'][$z]['type']['mysql'] = 'timestamp';
		}
		foreach (['insert_user', 'update_user'] as $field_name) {
			$z++;
			$apps[$x]['db'][$y]['fields'][$z]['name'] = $field_name;
			$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql'] = 'uuid';
			$apps[$x]['db'][$y]['fields'][$z]['type']['sqlite'] = 'text';
			$apps[$x]['db'][$y]['fields'][$z]['type']['mysql'] = 'char(36)';
		}
		unset($field_name);

?>
