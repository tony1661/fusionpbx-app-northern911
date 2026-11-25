<?php

	//application details
		$apps[$x]['name'] = 'E911';
		$apps[$x]['uuid'] = 'e79f4942-44e3-4efe-86ea-e30cde31cd83';
		$apps[$x]['category'] = 'Switch';
		$apps[$x]['subcategory'] = '';
		$apps[$x]['version'] = '1.1';
		$apps[$x]['license'] = 'Mozilla Public License 1.1';
		$apps[$x]['url'] = '';
		$apps[$x]['description']['en-us'] = 'Keep Northern 911 addresses for each domain, audit extensions against them and export the carrier upload file.';

	//default settings
		$y = 0;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = 'b37f377c-6354-4a2e-9455-39b0baef8877';
		$apps[$x]['default_settings'][$y]['default_setting_category'] = 'northern911';
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = 'account_id';
		$apps[$x]['default_settings'][$y]['default_setting_name'] = 'text';
		$apps[$x]['default_settings'][$y]['default_setting_value'] = '';
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = 'true';
		$apps[$x]['default_settings'][$y]['default_setting_description'] = 'Your customer number with Northern 911. Sent in column A of every exported row.';
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = '5497620c-9ac4-491a-bc57-f70d6c78e5ae';
		$apps[$x]['default_settings'][$y]['default_setting_category'] = 'northern911';
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = 'exempt_tag';
		$apps[$x]['default_settings'][$y]['default_setting_name'] = 'text';
		$apps[$x]['default_settings'][$y]['default_setting_value'] = 'no911';
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = 'true';
		$apps[$x]['default_settings'][$y]['default_setting_description'] = 'Extensions with this text in their description are skipped by the audit (fax, paging, etc).';

	//permissions
		$y = 0;
		foreach ([
			'northern911_address_view' => ['superadmin', 'admin'],
			'northern911_address_add' => ['superadmin', 'admin'],
			'northern911_address_edit' => ['superadmin', 'admin'],
			'northern911_address_delete' => ['superadmin', 'admin'],
			'northern911_address_all' => ['superadmin'],
			'northern911_import' => ['superadmin'],
			'northern911_export' => ['superadmin'],
		] as $northern911_permission => $northern911_groups) {
			$apps[$x]['permissions'][$y]['name'] = $northern911_permission;
			$apps[$x]['permissions'][$y]['groups'] = $northern911_groups;
			$y++;
		}
		unset($northern911_permission, $northern911_groups);

	//schema
		$y = 0;
		$apps[$x]['db'][$y]['table']['name'] = 'v_northern911_addresses';
		$apps[$x]['db'][$y]['table']['parent'] = '';
		$z = 0;
		$apps[$x]['db'][$y]['fields'][$z]['name'] = 'northern911_address_uuid';
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
		foreach ([
			'northern911_address_number' => '10 digit phone number',
			'northern911_address_name' => 'Name the 911 operator sees',
			'northern911_address_street_number' => 'Street number',
			'northern911_address_unit' => 'Unit or suite',
			'northern911_address_street_name' => 'Street name',
			'northern911_address_city' => 'City',
			'northern911_address_province' => 'Province or state',
			'northern911_address_postal_code' => 'Postal or ZIP code',
			'northern911_address_location_info' => 'Instructions for the 911 operator',
			'northern911_address_enabled' => 'true or false',
			'northern911_address_description' => 'Internal notes, not exported',
		] as $northern911_field => $northern911_description) {
			$z++;
			$apps[$x]['db'][$y]['fields'][$z]['name'] = $northern911_field;
			$apps[$x]['db'][$y]['fields'][$z]['type'] = 'text';
			$apps[$x]['db'][$y]['fields'][$z]['description']['en-us'] = $northern911_description;
		}
		foreach (['northern911_address_exported', 'insert_date', 'update_date'] as $northern911_field) {
			$z++;
			$apps[$x]['db'][$y]['fields'][$z]['name'] = $northern911_field;
			$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql'] = 'timestamptz';
			$apps[$x]['db'][$y]['fields'][$z]['type']['sqlite'] = 'date';
			$apps[$x]['db'][$y]['fields'][$z]['type']['mysql'] = 'timestamp';
		}
		foreach (['insert_user', 'update_user'] as $northern911_field) {
			$z++;
			$apps[$x]['db'][$y]['fields'][$z]['name'] = $northern911_field;
			$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql'] = 'uuid';
			$apps[$x]['db'][$y]['fields'][$z]['type']['sqlite'] = 'text';
			$apps[$x]['db'][$y]['fields'][$z]['type']['mysql'] = 'char(36)';
		}
		unset($northern911_field, $northern911_description);

?>
