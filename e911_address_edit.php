<?php
/*
	E911 for FusionPBX
	Add or edit one E911 address.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";
	require_once __DIR__ . "/resources/e911.php";

//load an existing record
	$uuid = (!empty($_REQUEST['id']) && is_uuid($_REQUEST['id'])) ? $_REQUEST['id'] : null;
	$row = [];
	if ($uuid) {
		$sql = "select *, cast(northern911_address_exported as text) as exported_text from v_northern911_addresses where northern911_address_uuid = :uuid";
		$row = e911_select($sql, ['uuid' => $uuid], 'row');
		if (!is_array($row) || empty($row)) {
			message::add('Address not found', 'negative');
			header('Location: e911_addresses.php');
			exit;
		}
	}

//check permissions
	if (($uuid && !permission_exists('northern911_address_edit')) || (!$uuid && !permission_exists('northern911_address_add'))) {
		echo "access denied";
		exit;
	}

//work out the domain
	$can_all = permission_exists('northern911_address_all');
	if ($uuid) {
		$domain_uuid = $row['domain_uuid'];
		if (!$can_all && $domain_uuid != $_SESSION['domain_uuid']) {
			echo "access denied";
			exit;
		}
	}
	else {
		$domain_uuid = $_SESSION['domain_uuid'];
		if ($can_all && !empty($_REQUEST['domain_uuid']) && is_uuid($_REQUEST['domain_uuid'])) {
			$domain_uuid = $_REQUEST['domain_uuid'];
		}
		$row = [
			'northern911_address_number' => e911_audit::normalize($_GET['number'] ?? ''),
			'northern911_address_enabled' => 'true',
		];
	}

//save
	$errors = [];
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add('Invalid token, please try again', 'negative');
			header('Location: e911_addresses.php');
			exit;
		}

		if ($can_all && !empty($_POST['domain_uuid']) && is_uuid($_POST['domain_uuid'])) {
			$domain_uuid = $_POST['domain_uuid'];
		}

		$data = [];
		foreach (e911_fields() as $field) {
			$data[$field] = trim((string)($_POST[$field] ?? ''));
		}
		$data['northern911_address_number'] = e911_audit::normalize($data['northern911_address_number']);
		$data['northern911_address_province'] = strtoupper($data['northern911_address_province']);
		$data['northern911_address_postal_code'] = strtoupper($data['northern911_address_postal_code']);
		$data['northern911_address_enabled'] = ($_POST['northern911_address_enabled'] ?? '') === 'true' ? 'true' : 'false';

		if (!e911_audit::valid_number($data['northern911_address_number'])) {
			$errors[] = 'Enter a valid 10 digit phone number.';
		}
		else {
			$sql = "select northern911_address_uuid, domain_uuid from v_northern911_addresses where northern911_address_number = :number";
			foreach (e911_select($sql, ['number' => $data['northern911_address_number']]) as $existing) {
				if ($existing['northern911_address_uuid'] != $uuid) {
					$where = e911_select("select domain_name from v_domains where domain_uuid = :d", ['d' => $existing['domain_uuid']], 'column');
					$errors[] = e911_format_number($data['northern911_address_number']).' already has an E911 address in '.($where ?: 'another domain').'. A number can only have one 911 address, edit that record instead.';
					break;
				}
			}
		}
		foreach (e911_audit::missing_fields($data) as $missing) {
			$errors[] = 'The '.$missing.' is required.';
		}

		if (empty($errors)) {
			e911_save_record($domain_uuid, $data, $uuid);
			message::add('Saved. Export it to the carrier to make the change live.');
			header('Location: e911_addresses.php');
			exit;
		}
		$row = array_merge($row, $data);
	}

//extensions using this number
	$using = [];
	$number = e911_audit::normalize($row['northern911_address_number'] ?? '');
	if ($number !== '') {
		$sql = "select extension, effective_caller_id_name, emergency_caller_id_number from v_extensions ";
		$sql .= "where domain_uuid = :domain_uuid and cast(enabled as text) = 'true'";
		foreach (e911_select($sql, ['domain_uuid' => $domain_uuid]) as $ext) {
			if (e911_audit::normalize($ext['emergency_caller_id_number']) === $number) {
				$using[] = $ext['extension'].(trim((string)$ext['effective_caller_id_name']) !== '' ? ' ('.$ext['effective_caller_id_name'].')' : '');
			}
		}
		natsort($using);
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = 'E911 Address';
	require_once "resources/header.php";
	e911_styles();

	echo "<form method='post' name='frm' id='frm'>\n";

	$buttons = e911_button('Back', 'e911_addresses.php', 'chevron-left');
	$buttons .= button::create(['type' => 'submit', 'label' => 'Save', 'icon' => e911_icon('save', 'check'), 'id' => 'btn_save', 'style' => 'margin-left: 15px;']);
	e911_action_bar($uuid ? 'Edit E911 Address' : 'Add E911 Address', $buttons);

	if ($errors) {
		echo "<div style='margin-bottom: 15px;'>";
		foreach ($errors as $error) {
			echo e911_badge('error')." ".escape($error)."<br>";
		}
		echo "</div>\n";
	}

	$value = function ($field) use ($row) {
		return escape($row[$field] ?? '');
	};
	$input = function ($label, $field, $help = '', $extra = '') use ($value) {
		echo "<tr>\n";
		$label_class = strpos($extra, 'required') !== false ? 'vncellreq' : 'vncell';
		echo "	<td class='".$label_class."' width='30%' valign='top' align='left' nowrap='nowrap'>".escape($label)."</td>\n";
		echo "	<td class='vtable' width='70%' align='left'>\n";
		echo "		<input class='formfld' type='text' name='".$field."' maxlength='255' value='".$value($field)."' ".$extra.">\n";
		if ($help !== '') {
			echo "		<br>".escape($help)."\n";
		}
		echo "	</td>\n";
		echo "</tr>\n";
	};

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	if ($can_all) {
		echo "<tr>\n";
		echo "	<td class='vncellreq' width='30%' valign='top' align='left' nowrap='nowrap'>Domain</td>\n";
		echo "	<td class='vtable' width='70%' align='left'>\n";
		echo "		<select class='formfld' name='domain_uuid'>\n";
		foreach (e911_domains() as $domain) {
			$selected = ($domain['domain_uuid'] == $domain_uuid) ? " selected='selected'" : '';
			echo "			<option value='".escape($domain['domain_uuid'])."'".$selected.">".escape($domain['domain_name'])."</option>\n";
		}
		echo "		</select>\n";
		echo "	</td>\n";
		echo "</tr>\n";
	}

	$input('Phone Number', 'northern911_address_number', '10 digits. This must match the Emergency Caller ID Number on the extensions at this location.', "required='required'");
	$input('Name', 'northern911_address_name', 'The name the 911 operator will see.', "required='required'");
	$input('Street Number', 'northern911_address_street_number', '', "required='required'");
	$input('Unit / Suite', 'northern911_address_unit', 'Leave blank or N/A if there is none.');
	$input('Street Name', 'northern911_address_street_name', 'Include the suffix and direction, for example Queen St W.', "required='required'");
	$input('City', 'northern911_address_city', '', "required='required'");
	$input('Province / State', 'northern911_address_province', 'Two letter code, for example ON.', "required='required' list='e911_provinces' style='width: 80px;'");
	$input('Postal Code', 'northern911_address_postal_code', 'Format A1A 1A1.', "required='required' style='width: 120px;'");

	echo "<datalist id='e911_provinces'>";
	foreach (['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','QC','SK','YT'] as $code) {
		echo "<option value='".$code."'>";
	}
	echo "</datalist>\n";

	echo "<tr>\n";
	echo "	<td class='vncell' valign='top' align='left' nowrap='nowrap'>911 Instructions</td>\n";
	echo "	<td class='vtable' align='left'>\n";
	echo "		<textarea class='formfld' name='northern911_address_location_info' rows='3' style='width: 100%; max-width: 500px;'>".$value('northern911_address_location_info')."</textarea>\n";
	echo "		<br>Instructions for the 911 operator, for example which entrance or floor to use.\n";
	echo "	</td>\n";
	echo "</tr>\n";


	echo "<tr>\n";
	echo "	<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>Enabled</td>\n";
	echo "	<td class='vtable' align='left'>\n";
	echo "		<select class='formfld' name='northern911_address_enabled'>\n";
	echo "			<option value='true'".(($row['northern911_address_enabled'] ?? 'true') !== 'false' ? " selected='selected'" : '').">True</option>\n";
	echo "			<option value='false'".(($row['northern911_address_enabled'] ?? '') === 'false' ? " selected='selected'" : '').">False</option>\n";
	echo "		</select>\n";
	echo "		<br>Disabled addresses are kept for reference but not audited or exported.\n";
	echo "	</td>\n";
	echo "</tr>\n";

	$input('Internal Notes', 'northern911_address_description', 'Not sent to the carrier.');

	if ($uuid) {
		echo "<tr>\n";
		echo "	<td class='vncell' valign='top' align='left' nowrap='nowrap'>Used By</td>\n";
		echo "	<td class='vtable' align='left'>".(empty($using) ? e911_badge('warning', 'No extensions') : escape(implode(', ', $using)))."</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "	<td class='vncell' valign='top' align='left' nowrap='nowrap'>Last Export</td>\n";
		echo "	<td class='vtable' align='left'>".escape(!empty($row['exported_text']) ? substr($row['exported_text'], 0, 16) : 'Never')."</td>\n";
		echo "</tr>\n";
	}

	echo "</table>\n";
	echo "</div>\n";

	if ($uuid) {
		echo "<input type='hidden' name='id' value='".escape($uuid)."'>\n";
	}
	if (!$can_all) {
		echo "<input type='hidden' name='domain_uuid' value='".escape($domain_uuid)."'>\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";
	echo "<br>\n";

//include the footer
	require_once "resources/footer.php";

?>
