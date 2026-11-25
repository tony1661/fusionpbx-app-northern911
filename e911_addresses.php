<?php
/*
	E911 for FusionPBX
	Domain view: E911 addresses for one domain (or every domain with Show All) and the audit of its extensions.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";
	require_once __DIR__ . "/resources/e911.php";

//check permissions
	if (!permission_exists('northern911_address_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$text = e911_text();

//request variables
	$can_all = permission_exists('northern911_address_all');
	$show = ($can_all && ($_REQUEST['show'] ?? '') == 'all') ? 'all' : '';
	$search = trim((string)($_REQUEST['search'] ?? ''));
	$sortable = ['domain_name', 'number', 'name', 'address', 'used_by', 'enabled', 'status', 'last_export'];
	$order_by = in_array($_GET['order_by'] ?? '', $sortable) ? $_GET['order_by'] : '';
	$order = ($_GET['order'] ?? '') == 'desc' ? 'desc' : 'asc';

//domain: the current domain, or another one for users who can see every domain (links from the dashboard)
	$domain_uuid = $_SESSION['domain_uuid'];
	if ($can_all && is_uuid($_REQUEST['domain_uuid'] ?? '')) {
		$domain_uuid = $_REQUEST['domain_uuid'];
	}

//query string that keeps the current view
	$param = [];
	if ($domain_uuid != $_SESSION['domain_uuid']) {
		$param['domain_uuid'] = $domain_uuid;
	}
	if ($show == 'all') {
		$param['show'] = 'all';
	}
	if ($search !== '') {
		$param['search'] = $search;
	}
	$query_string = http_build_query($param);
	$self = 'e911_addresses.php'.($query_string !== '' ? '?'.$query_string : '');

//toggle or delete the checked addresses
	if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['addresses']) && is_array($_POST['addresses'])) {
		$action = $_POST['action'] ?? '';
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add($text['message-invalid_token'] ?? 'Invalid token, please try again', 'negative');
			header('Location: '.$self);
			exit;
		}
		$user_uuid = (isset($_SESSION['user_uuid']) && is_uuid($_SESSION['user_uuid'])) ? $_SESSION['user_uuid'] : null;
		$done = 0;
		foreach ($_POST['addresses'] as $item) {
			if (($item['checked'] ?? '') != 'true' || !is_uuid($item['uuid'] ?? '')) {
				continue;
			}
			$where = "northern911_address_uuid = :uuid";
			$parameters = ['uuid' => $item['uuid']];
			if ($show != 'all') {
				$where .= " and domain_uuid = :domain_uuid";
				$parameters['domain_uuid'] = $domain_uuid;
			}
			if (!e911_select("select count(*) from v_northern911_addresses where ".$where, $parameters, 'column')) {
				continue;
			}
			if ($action == 'delete' && permission_exists('northern911_address_delete')) {
				e911_execute("delete from v_northern911_addresses where ".$where, $parameters);
				$done++;
			}
			elseif ($action == 'toggle' && permission_exists('northern911_address_edit')) {
				$sql = "update v_northern911_addresses set ";
				$sql .= "northern911_address_enabled = case when northern911_address_enabled = 'false' then 'true' else 'false' end, ";
				$sql .= "update_date = now(), update_user = :update_user where ".$where;
				$parameters['update_user'] = $user_uuid;
				e911_execute($sql, $parameters);
				$done++;
			}
		}
		if ($done > 0) {
			message::add($action == 'delete' ? $text['message-e911_deleted'] : $text['message-e911_toggled']);
		}
		header('Location: '.$self);
		exit;
	}

//run the audit, for this domain or for every enabled domain
	$audit = new e911_audit;
	$audit->run($show == 'all' ? null : $domain_uuid);
	$summary = $audit->summary[$domain_uuid] ?? null;
	$domain_name = $audit->domain_name($domain_uuid);

//addresses to list
	$rank = ['ok' => 0, 'disabled' => 0, 'notice' => 1, 'warning' => 2, 'error' => 3];
	$records = [];
	foreach ($audit->records as $uuid => $row) {
		if ($show != 'all' && $row['domain_uuid'] != $domain_uuid) {
			continue;
		}
		$row['domain_name'] = $audit->domain_name($row['domain_uuid']);
		$row['address'] = e911_format_address($row);
		$row['used_by'] = $audit->record_usage[$uuid] ?? [];
		$row['issues'] = $audit->record_issues[$uuid] ?? [];
		$row['audited'] = isset($audit->summary[$row['domain_uuid']]);
		$row['status'] = $row['enabled'] ? e911_audit::worst($row['issues']) : 'disabled';
		if ($search !== '') {
			$haystack = strtolower(implode(' ', [
				$row['number'], e911_format_number($row['number']), $row['northern911_address_name'], $row['address'],
				$row['northern911_address_location_info'], $row['northern911_address_description'], $row['domain_name'],
			]));
			if (strpos($haystack, strtolower($search)) === false) {
				continue;
			}
		}
		$records[$uuid] = $row;
	}

//sort
	if ($order_by !== '') {
		uasort($records, function ($a, $b) use ($order_by, $order, $rank) {
			switch ($order_by) {
				case 'domain_name': $result = strnatcasecmp($a['domain_name'], $b['domain_name']); break;
				case 'number': $result = strcmp($a['number'], $b['number']); break;
				case 'name': $result = strnatcasecmp($a['northern911_address_name'], $b['northern911_address_name']); break;
				case 'address': $result = strnatcasecmp($a['address'], $b['address']); break;
				case 'used_by': $result = count($a['used_by']) - count($b['used_by']); break;
				case 'enabled': $result = (int)$a['enabled'] - (int)$b['enabled']; break;
				case 'status': $result = $rank[$a['status']] - $rank[$b['status']]; break;
				case 'last_export': $result = strcmp((string)$a['exported_text'], (string)$b['exported_text']); break;
				default: $result = 0;
			}
			return $order == 'desc' ? -$result : $result;
		});
	}

//theme options
	$list_row_edit_button = e911_theme_bool('list_row_edit_button');
	$can_toggle = permission_exists('northern911_address_edit');
	$can_delete = permission_exists('northern911_address_delete');

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-e911_addresses'];
	require_once "resources/header.php";
	e911_styles();

//action bar
	$heading = $text['title-e911_addresses'];
	if ($show != 'all' && $domain_uuid != $_SESSION['domain_uuid'] && $domain_name !== '') {
		$heading .= ': '.$domain_name;
	}
	$buttons = '';
	if (permission_exists('northern911_dashboard_view')) {
		$buttons .= e911_button($text['button-dashboard'], 'e911_dashboard.php', 'chart-pie');
	}
	if (permission_exists('northern911_import')) {
		$buttons .= e911_button($text['button-import'], 'e911_import.php', e911_icon('import', 'upload'));
	}
	if (permission_exists('northern911_export')) {
		$buttons .= e911_button($text['button-export'], 'e911_export.php', e911_icon('export', 'download'), ['style' => 'margin-right: 15px;']);
	}
	if ($can_toggle && $records) {
		$buttons .= button::create(['type' => 'button', 'label' => $text['button-toggle'], 'icon' => e911_icon('toggle', 'toggle-on'), 'id' => 'btn_toggle', 'name' => 'btn_toggle', 'style' => 'display: none;', 'onclick' => "modal_open('modal-toggle','btn_toggle');"]);
	}
	if ($can_delete && $records) {
		$buttons .= button::create(['type' => 'button', 'label' => $text['button-delete'], 'icon' => e911_icon('delete', 'trash'), 'id' => 'btn_delete', 'name' => 'btn_delete', 'style' => 'display: none;', 'onclick' => "modal_open('modal-delete','btn_delete');"]);
	}
	$buttons .= "<form id='form_search' class='inline' method='get'>\n";
	if ($show == 'all') {
		$buttons .= "<input type='hidden' name='show' value='all'>\n";
	}
	elseif ($can_all) {
		$buttons .= e911_button($text['button-show_all'], '?show=all', e911_icon('all', 'globe'));
	}
	if (isset($param['domain_uuid'])) {
		$buttons .= "<input type='hidden' name='domain_uuid' value='".escape($domain_uuid)."'>\n";
	}
	$buttons .= "<input type='text' class='txt list-search' name='search' id='search' value=\"".escape($search)."\" placeholder=\"".escape($text['label-search'])."\">";
	$buttons .= button::create(['label' => $text['button-search'], 'icon' => e911_icon('search', 'search'), 'type' => 'submit', 'id' => 'btn_search']);
	$buttons .= "</form>\n";
	e911_action_bar($heading, $buttons, count($records));

	if ($can_toggle && $records) {
		echo modal::create(['id' => 'modal-toggle', 'type' => 'toggle', 'actions' => button::create(['type' => 'button', 'label' => $text['button-continue'], 'icon' => 'check', 'id' => 'btn_toggle', 'style' => 'float: right; margin-left: 15px;', 'collapse' => 'never', 'onclick' => "modal_close(); list_action_set('toggle'); list_form_submit('form_list');"])]);
	}
	if ($can_delete && $records) {
		echo modal::create(['id' => 'modal-delete', 'type' => 'delete', 'actions' => button::create(['type' => 'button', 'label' => $text['button-continue'], 'icon' => 'check', 'id' => 'btn_delete', 'style' => 'float: right; margin-left: 15px;', 'collapse' => 'never', 'onclick' => "modal_close(); list_action_set('delete'); list_form_submit('form_list');"])]);
	}

	e911_customer_number_banner();

	echo $text[$show == 'all' ? 'title_description-e911_addresses_all' : 'title_description-e911_addresses']."\n";
	echo "<br /><br />\n";

//one line summary for the domain
	if ($show != 'all' && $summary) {
		echo "<div class='e911-summary'>\n";
		echo "	<span>".e911_audit::status_badge($summary['status'])."</span>\n";
		echo "	<span><b>".$summary['extensions_ok']." / ".$summary['extensions']."</b> ".escape($text['label-extensions_compliant'])."</span>\n";
		echo "	<span><b>".$summary['records_used']." / ".$summary['records']."</b> ".escape($text['label-addresses_in_use'])."</span>\n";
		echo "	<span class='".($summary['error'] ? 'e911-error' : 'e911-muted')."'><b>".$summary['error']."</b> ".escape(strtolower($text['label-errors']))."</span>\n";
		echo "	<span class='".($summary['warning'] ? 'e911-warning' : 'e911-muted')."'><b>".$summary['warning']."</b> ".escape(strtolower($text['label-warnings']))."</span>\n";
		echo "</div>\n";
	}

//addresses
	$section_buttons = '';
	if (permission_exists('northern911_address_add')) {
		$section_buttons = e911_button($text['button-add_address'], 'e911_address_edit.php?domain_uuid='.urlencode($domain_uuid), e911_icon('add', 'plus'), ['id' => 'btn_add', 'collapse' => 'never']);
	}
	e911_section($text['title-e911_addresses'], $section_buttons);

	echo "<div class='card'>\n";
	echo "<form id='form_list' method='post'>\n";
	echo "<input type='hidden' id='action' name='action' value=''>\n";
	foreach ($param as $key => $value) {
		echo "<input type='hidden' name='".escape($key)."' value=\"".escape($value)."\">\n";
	}

	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	if ($can_toggle || $can_delete) {
		echo "	<th class='checkbox'>\n";
		echo "		<input type='checkbox' id='checkbox_all' name='checkbox_all' onclick='list_all_toggle(); checkbox_on_change(this);' ".(empty($records) ? "style='visibility: hidden;'" : null).">\n";
		echo "	</th>\n";
	}
	if ($show == 'all') {
		echo th_order_by('domain_name', $text['label-domain'], $order_by, $order, '', '', $query_string);
	}
	echo th_order_by('number', $text['label-e911_number'], $order_by, $order, '', '', $query_string);
	echo th_order_by('name', $text['label-e911_name'], $order_by, $order, '', '', $query_string);
	echo th_order_by('address', $text['label-e911_address'], $order_by, $order, '', '', $query_string);
	echo "	<th class='hide-md-dn'>".escape($text['label-e911_instructions'])."</th>\n";
	echo th_order_by('used_by', $text['label-used_by'], $order_by, $order, '', "class='hide-sm-dn'", $query_string);
	echo th_order_by('enabled', $text['label-enabled'], $order_by, $order, '', "class='center'", $query_string);
	echo th_order_by('status', $text['label-e911_status'], $order_by, $order, '', '', $query_string);
	echo th_order_by('last_export', $text['label-last_export'], $order_by, $order, '', "class='hide-sm-dn'", $query_string);
	if ($can_toggle && $list_row_edit_button) {
		echo "	<td class='action-button'>&nbsp;</td>\n";
	}
	echo "</tr>\n";

	if (empty($records)) {
		echo "<tr class='list-row'><td colspan='11'>".escape($search !== '' ? $text['message-nothing_to_show'] : $text['message-no_addresses'])."</td></tr>\n";
	}
	$x = 0;
	foreach ($records as $uuid => $row) {
		$edit_url = permission_exists('northern911_address_edit') ? 'e911_address_edit.php?id='.urlencode($uuid) : '';
		$number = e911_format_number($row['number']);
		echo "<tr class='list-row'".($edit_url ? " href='".escape($edit_url)."'" : '').">\n";
		if ($can_toggle || $can_delete) {
			echo "	<td class='checkbox'>\n";
			echo "		<input type='checkbox' name='addresses[".$x."][checked]' id='checkbox_".$x."' value='true' onclick=\"checkbox_on_change(this); if (!this.checked) { document.getElementById('checkbox_all').checked = false; }\">\n";
			echo "		<input type='hidden' name='addresses[".$x."][uuid]' value='".escape($uuid)."' />\n";
			echo "	</td>\n";
		}
		if ($show == 'all') {
			echo "	<td>".escape($row['domain_name'])."</td>\n";
		}
		echo "	<td class='no-wrap'>".($edit_url ? "<a href='".escape($edit_url)."' title=\"".escape($text['button-edit'])."\">".escape($number)."</a>" : escape($number))."</td>\n";
		echo "	<td>".escape($row['northern911_address_name'])."</td>\n";
		echo "	<td class='e911-col-address'>".escape($row['address'])."</td>\n";
		echo "	<td class='description overflow hide-md-dn'>".escape($row['northern911_address_location_info'])."</td>\n";
		echo "	<td class='hide-sm-dn'>".(empty($row['used_by']) ? "<span class='e911-muted'>".escape($text['label-none'])."</span>" : escape(implode(', ', $row['used_by'])))."</td>\n";
		$enabled_label = $row['enabled'] ? $text['label-true'] : $text['label-false'];
		if ($can_toggle) {
			echo "	<td class='no-link center'>";
			echo button::create(['type' => 'submit', 'class' => 'link', 'label' => $enabled_label, 'title' => $text['button-toggle'], 'onclick' => "list_self_check('checkbox_".$x."'); list_action_set('toggle'); list_form_submit('form_list')"]);
			echo "</td>\n";
		}
		else {
			echo "	<td class='center'>".escape($enabled_label)."</td>\n";
		}
		echo "	<td class='e911-col-status'>";
		if (!$row['enabled']) {
			echo e911_badge('disabled');
		}
		elseif (!$row['audited']) {
			echo e911_badge('disabled', 'Domain disabled');
		}
		else {
			echo e911_issue_summary($row['issues'], $number);
		}
		echo "</td>\n";
		echo "	<td class='no-wrap hide-sm-dn'>".escape(!empty($row['exported_text']) ? substr($row['exported_text'], 0, 16) : $text['label-never'])."</td>\n";
		if ($can_toggle && $list_row_edit_button) {
			echo "	<td class='action-button'>".button::create(['type' => 'button', 'title' => $text['button-edit'], 'icon' => e911_icon('edit', 'pencil-alt'), 'link' => $edit_url])."</td>\n";
		}
		echo "</tr>\n";
		$x++;
	}
	echo "</table>\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";
	echo "</div>\n";

//extensions in this domain
	if ($show != 'all') {
		e911_section($text['title-extensions'], '', count($audit->extensions));
		$can_edit_extension = ($domain_uuid == $_SESSION['domain_uuid'] && permission_exists('extension_edit'));

		echo "<div class='card'>\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'>\n";
		echo "	<th>".escape($text['label-extension'] ?? 'Extension')."</th>\n";
		echo "	<th>".escape($text['label-e911_name'])."</th>\n";
		echo "	<th>".escape($text['label-emergency_caller_id'])."</th>\n";
		echo "	<th>".escape($text['label-e911_status'])."</th>\n";
		echo "	<td class='action-button'>&nbsp;</td>\n";
		echo "</tr>\n";
		if (empty($audit->extensions)) {
			echo "<tr class='list-row'><td colspan='5'>".escape($text['message-no_enabled_extensions'])."</td></tr>\n";
		}
		foreach ($audit->extensions as $uuid => $ext) {
			$status = $audit->extension_status[$uuid] ?? ['ok', ''];
			$number = e911_audit::normalize($ext['emergency_caller_id_number']);
			$ext_url = $can_edit_extension ? PROJECT_PATH.'/app/extensions/extension_edit.php?id='.urlencode($uuid) : '';
			echo "<tr class='list-row'".($ext_url ? " href='".escape($ext_url)."'" : '').">\n";
			echo "	<td>".($ext_url ? "<a href='".escape($ext_url)."'>".escape($ext['extension'])."</a>" : escape($ext['extension']))."</td>\n";
			echo "	<td>".escape($ext['effective_caller_id_name'])."</td>\n";
			echo "	<td>".escape(e911_audit::valid_number($number) ? e911_format_number($number) : $ext['emergency_caller_id_number'])."</td>\n";
			echo "	<td>".e911_badge($status[0], $status[0] == 'ok' ? null : $status[1])."</td>\n";
			echo "	<td class='no-link action-button'>";
			if ($status[0] == 'error' && e911_audit::valid_number($number) && permission_exists('northern911_address_add') && strpos($status[1], 'has no E911 address') !== false) {
				$url = 'e911_address_edit.php?domain_uuid='.urlencode($domain_uuid).'&number='.urlencode($number);
				echo e911_button($text['button-add_address'], $url, e911_icon('add', 'plus'), ['collapse' => 'never']);
			}
			echo "</td>\n";
			echo "</tr>\n";
		}
		echo "</table>\n";
		echo "</div>\n";
	}
	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>
