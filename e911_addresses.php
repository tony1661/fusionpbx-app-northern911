<?php
/*
	E911 for FusionPBX
	Domain view: E911 addresses for one domain and the audit of its extensions.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";
	require_once __DIR__ . "/resources/e911.php";

//check permissions
	if (!permission_exists('e911_address_view')) {
		echo "access denied";
		exit;
	}

//pick the domain
	$domain_uuid = $_SESSION['domain_uuid'];
	if (permission_exists('e911_address_all') && !empty($_REQUEST['domain_uuid']) && is_uuid($_REQUEST['domain_uuid'])) {
		$domain_uuid = $_REQUEST['domain_uuid'];
	}

//delete a record
	if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'delete' && permission_exists('e911_address_delete')) {
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add('Invalid token, please try again', 'negative');
		}
		elseif (is_uuid($_POST['e911_address_uuid'] ?? '')) {
			$sql = "delete from v_e911_addresses where e911_address_uuid = :uuid and domain_uuid = :domain_uuid";
			e911_execute($sql, ['uuid' => $_POST['e911_address_uuid'], 'domain_uuid' => $domain_uuid]);
			message::add('Address deleted. Remove the number from the carrier database too.');
		}
		header('Location: e911_addresses.php?domain_uuid='.urlencode($domain_uuid));
		exit;
	}

//run the audit for this domain
	$audit = new e911_audit;
	$audit->run($domain_uuid);
	$summary = $audit->summary[$domain_uuid] ?? null;
	$domain_name = $audit->domain_name($domain_uuid);

	$records = [];
	foreach ($audit->records as $uuid => $row) {
		if ($row['domain_uuid'] == $domain_uuid) {
			$records[$uuid] = $row;
		}
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = 'E911';
	require_once "resources/header.php";
	e911_styles();

//action bar
	$buttons = '';
	if (permission_exists('e911_dashboard_view')) {
		$buttons .= e911_button('Dashboard', 'e911_dashboard.php', 'chart-bar');
	}
	if (permission_exists('e911_import')) {
		$buttons .= e911_button('Import', 'e911_import.php', 'upload');
	}
	if (permission_exists('e911_export')) {
		$buttons .= e911_button('Export', 'e911_export.php?domain_uuid='.urlencode($domain_uuid), 'download');
	}
	if (permission_exists('e911_address_add')) {
		$buttons .= e911_button('Add', 'e911_address_edit.php?domain_uuid='.urlencode($domain_uuid), 'plus');
	}
	e911_action_bar('E911 Addresses'.($domain_name ? ': '.$domain_name : ''), $buttons);
	e911_customer_number_banner();

//domain picker for superadmins
	if (permission_exists('e911_address_all')) {
		echo "<form method='get' style='margin-bottom: 15px;'>\n";
		echo "Domain &nbsp;<select name='domain_uuid' class='formfld' onchange='this.form.submit();'>\n";
		foreach ($audit->domains as $domain) {
			$selected = ($domain['domain_uuid'] == $domain_uuid) ? " selected='selected'" : '';
			$suffix = ($domain['domain_enabled'] === 'true') ? '' : ' (disabled)';
			echo "<option value='".escape($domain['domain_uuid'])."'".$selected.">".escape($domain['domain_name'].$suffix)."</option>\n";
		}
		echo "</select>\n";
		echo "</form>\n";
	}

//summary cards
	if ($summary) {
		echo "<div class='e911-cards'>\n";
		echo "<div class='e911-card'><div class='e911-lbl'>Status</div><div style='margin-top: 8px;'>".e911_audit::status_badge($summary['status'])."</div></div>\n";
		echo "<div class='e911-card'><div class='e911-num'>".$summary['extensions_ok']." / ".$summary['extensions']."</div><div class='e911-lbl'>Extensions compliant</div></div>\n";
		echo "<div class='e911-card'><div class='e911-num'>".$summary['records_used']." / ".$summary['records']."</div><div class='e911-lbl'>Addresses in use</div></div>\n";
		echo "<div class='e911-card'><div class='e911-num'>".$summary['error']."</div><div class='e911-lbl'>Errors</div></div>\n";
		echo "<div class='e911-card'><div class='e911-num'>".$summary['warning']."</div><div class='e911-lbl'>Warnings</div></div>\n";
		echo "</div>\n";
	}

//addresses
	echo "<div class='e911-section'>E911 Addresses</div>\n";
	echo "<form method='post' id='frm_delete'>\n";
	echo "<input type='hidden' name='action' value='delete'>\n";
	echo "<input type='hidden' name='domain_uuid' value='".escape($domain_uuid)."'>\n";
	echo "<input type='hidden' name='e911_address_uuid' id='delete_uuid' value=''>\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>Number</th>\n";
	echo "	<th>Name</th>\n";
	echo "	<th>Address</th>\n";
	echo "	<th class='hide-sm-dn'>Location Info</th>\n";
	echo "	<th>Used By</th>\n";
	echo "	<th>Status</th>\n";
	echo "	<th class='hide-sm-dn'>Last Export</th>\n";
	echo "	<th class='action-button'>&nbsp;</th>\n";
	echo "</tr>\n";
	if (empty($records)) {
		echo "<tr class='list-row'><td colspan='8'>No E911 addresses for this domain yet.</td></tr>\n";
	}
	foreach ($records as $uuid => $row) {
		$issues = $audit->record_issues[$uuid] ?? [];
		$used_by = $audit->record_usage[$uuid] ?? [];
		$edit_url = 'e911_address_edit.php?id='.urlencode($uuid);
		echo "<tr class='list-row".($row['enabled'] ? '' : ' e911-muted')."'>\n";
		echo "	<td class='no-wrap'>";
		echo permission_exists('e911_address_edit') ? "<a href='".escape($edit_url)."'>".escape(e911_format_number($row['number']))."</a>" : escape(e911_format_number($row['number']));
		echo "</td>\n";
		echo "	<td>".escape($row['e911_address_name'])."</td>\n";
		echo "	<td>".escape(e911_format_address($row))."</td>\n";
		echo "	<td class='hide-sm-dn' style='max-width: 260px;'>".escape($row['e911_address_location_info'])."</td>\n";
		echo "	<td>".(empty($used_by) ? "<span class='e911-muted'>None</span>" : escape(implode(', ', $used_by)))."</td>\n";
		echo "	<td>";
		if (!$row['enabled']) {
			echo e911_badge('disabled');
		}
		elseif (empty($issues)) {
			echo e911_badge('ok');
		}
		else {
			echo "<ul class='e911-msgs'>";
			foreach ($issues as $issue) {
				$text = preg_replace('/^'.preg_quote(e911_format_number($row['number']), '/').'(:)? ?/', '', $issue['message']);
				echo "<li>".e911_badge($issue['severity'])." ".escape(ucfirst($text))."</li>";
			}
			echo "</ul>";
		}
		echo "</td>\n";
		echo "	<td class='hide-sm-dn no-wrap'>".escape($row['exported_text'] ? substr($row['exported_text'], 0, 16) : 'Never')."</td>\n";
		echo "	<td class='no-wrap'>";
		if (permission_exists('e911_address_edit')) {
			echo "<a href='".escape($edit_url)."' title='Edit'><span class='fas fa-pencil-alt fa-fw'></span></a> ";
		}
		if (permission_exists('e911_address_delete')) {
			$confirm = 'Delete '.e911_format_number($row['number']).'?';
			echo "<a href='javascript:void(0);' title='Delete' onclick=\"if (confirm('".escape($confirm)."')) { document.getElementById('delete_uuid').value='".escape($uuid)."'; document.getElementById('frm_delete').submit(); }\"><span class='fas fa-trash fa-fw'></span></a>";
		}
		echo "</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";

//extensions
	echo "<div class='e911-section'>Extensions</div>\n";
	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>Extension</th>\n";
	echo "	<th>Name</th>\n";
	echo "	<th>Emergency Caller ID</th>\n";
	echo "	<th>Status</th>\n";
	echo "	<th class='action-button'>&nbsp;</th>\n";
	echo "</tr>\n";
	if (empty($audit->extensions)) {
		echo "<tr class='list-row'><td colspan='5'>No enabled extensions in this domain.</td></tr>\n";
	}
	foreach ($audit->extensions as $uuid => $ext) {
		$status = $audit->extension_status[$uuid] ?? ['ok', ''];
		$number = e911_audit::normalize($ext['emergency_caller_id_number']);
		echo "<tr class='list-row'>\n";
		if ($domain_uuid == $_SESSION['domain_uuid'] && permission_exists('extension_edit')) {
			echo "	<td><a href='/app/extensions/extension_edit.php?id=".urlencode($uuid)."'>".escape($ext['extension'])."</a></td>\n";
		}
		else {
			echo "	<td>".escape($ext['extension'])."</td>\n";
		}
		echo "	<td>".escape($ext['effective_caller_id_name'])."</td>\n";
		echo "	<td>".escape($ext['emergency_caller_id_number'])."</td>\n";
		echo "	<td>".e911_badge($status[0])." ".($status[0] == 'ok' ? '' : escape($status[1]))."</td>\n";
		echo "	<td class='no-wrap'>";
		if ($status[0] == 'error' && e911_audit::valid_number($number) && permission_exists('e911_address_add') && strpos($status[1], 'has no E911 address') !== false) {
			$url = 'e911_address_edit.php?domain_uuid='.urlencode($domain_uuid).'&number='.urlencode($number);
			echo "<a href='".escape($url)."'>Add address</a>";
		}
		echo "</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br>\n";

//include the footer
	require_once "resources/footer.php";

?>
