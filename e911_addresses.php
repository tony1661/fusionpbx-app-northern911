<?php
/*
	E911 for FusionPBX
	One page for everything: charts, addresses, extensions and issues for the current domain
	(the one picked at the top right), or for every domain with Show All.
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
	$domain_uuid = $_SESSION['domain_uuid'];
	$can_all = permission_exists('northern911_address_all');
	$show = ($can_all && ($_REQUEST['show'] ?? '') == 'all') ? 'all' : '';
	$search = trim((string)($_REQUEST['search'] ?? ''));
	$severity = $_REQUEST['severity'] ?? 'error';
	if (!in_array($severity, ['error', 'warning', 'notice', 'all'])) {
		$severity = 'error';
	}
	$sortable = [
		'domain_name', 'number', 'name', 'address', 'used_by', 'enabled', 'status', 'last_export',
		'dom_name', 'dom_status', 'dom_extensions', 'dom_records', 'dom_error', 'dom_warning', 'dom_notice', 'dom_exempt',
	];
	$order_by = in_array($_GET['order_by'] ?? '', $sortable) ? $_GET['order_by'] : '';
	$order = ($_GET['order'] ?? '') == 'desc' ? 'desc' : 'asc';

//query string that keeps the current view
	$param = [];
	if ($show == 'all') {
		$param['show'] = 'all';
	}
	if ($search !== '') {
		$param['search'] = $search;
	}
	if ($severity != 'error') {
		$param['severity'] = $severity;
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

//download the issue list as csv
	if (($_GET['download'] ?? '') == 'issues') {
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="e911-audit-'.date('Y-m-d').'.csv"');
		$out = fopen('php://output', 'w');
		fputcsv($out, ['Domain', 'Severity', 'Type', 'Item', 'Number', 'Message'], ',', '"', '\\');
		foreach ($audit->issues as $issue) {
			fputcsv($out, [
				$audit->domain_name($issue['domain_uuid']), $issue['severity'], $issue['type'],
				$issue['label'], $issue['number'], $issue['message'],
			], ',', '"', '\\');
		}
		fclose($out);
		exit;
	}

//search helper
	$matches = function (...$values) use ($search) {
		return $search === '' || stripos(implode(' ', $values), $search) !== false;
	};

//totals for the charts
	$totals = [
		'domains' => 0, 'compliant' => 0, 'review' => 0, 'not_compliant' => 0, 'disabled' => 0,
		'extensions' => 0, 'extensions_ok' => 0, 'exempt' => 0,
		'records' => 0, 'records_used' => 0, 'records_no_ext' => 0, 'unused' => 0, 'pending' => 0,
		'error' => 0, 'warning' => 0, 'notice' => 0,
	];
	foreach ($audit->summary as $counts) {
		$totals['domains']++;
		//disabled domains only count in the domain and issue charts
		if ($counts['status'] == 'disabled') {
			$totals['disabled']++;
			foreach (['error', 'warning', 'notice'] as $key) {
				$totals[$key] += $counts[$key];
			}
			continue;
		}
		if ($counts['status'] == 'ok' || $counts['status'] == 'empty') {
			$totals['compliant']++;
		}
		elseif ($counts['status'] == 'error') {
			$totals['not_compliant']++;
		}
		else {
			$totals['review']++;
		}
		foreach (['extensions', 'extensions_ok', 'exempt', 'records', 'records_used', 'records_no_ext', 'error', 'warning', 'notice'] as $key) {
			$totals[$key] += $counts[$key];
		}
	}
	foreach ($audit->issues as $issue) {
		if ($issue['type'] == 'rec_unused') {
			$totals['unused']++;
		}
		if ($issue['type'] == 'rec_not_sent' || $issue['type'] == 'rec_changed') {
			$totals['pending']++;
		}
	}

//domains (show all only), worst first unless a column was clicked
	$status_order = ['error' => 0, 'disabled' => 1, 'warning' => 2, 'ok' => 3, 'empty' => 4];
	$domain_uuids = [];
	if ($show == 'all') {
		foreach ($audit->summary as $uuid => $counts) {
			if ($matches($audit->domain_name($uuid))) {
				$domain_uuids[] = $uuid;
			}
		}
		usort($domain_uuids, function ($a, $b) use ($audit, $status_order, $order_by, $order) {
			$sa = $audit->summary[$a];
			$sb = $audit->summary[$b];
			switch ($order_by) {
				case 'dom_name': $result = strnatcasecmp($audit->domain_name($a), $audit->domain_name($b)); break;
				case 'dom_status': $result = $status_order[$sa['status']] - $status_order[$sb['status']]; break;
				case 'dom_extensions': $result = ($sa['extensions'] - $sa['extensions_ok']) - ($sb['extensions'] - $sb['extensions_ok']); break;
				case 'dom_records': $result = ($sa['records'] - $sa['records_used'] - $sa['records_no_ext']) - ($sb['records'] - $sb['records_used'] - $sb['records_no_ext']); break;
				case 'dom_error': case 'dom_warning': case 'dom_notice': case 'dom_exempt':
					$key = substr($order_by, 4);
					$result = $sa[$key] - $sb[$key];
					break;
				default:
					if ($status_order[$sa['status']] != $status_order[$sb['status']]) {
						return $status_order[$sa['status']] - $status_order[$sb['status']];
					}
					if ($sa['error'] != $sb['error']) {
						return $sb['error'] - $sa['error'];
					}
					return strnatcasecmp($audit->domain_name($a), $audit->domain_name($b));
			}
			return $order == 'desc' ? -$result : $result;
		});
	}

//addresses
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
		if (!$matches($row['number'], e911_format_number($row['number']), $row['northern911_address_name'], $row['address'],
			$row['northern911_address_location_info'], $row['northern911_address_description'], $row['domain_name'])) {
			continue;
		}
		$records[$uuid] = $row;
	}
	if ($order_by !== '' && substr($order_by, 0, 4) != 'dom_') {
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

//extensions
	$extensions = [];
	foreach ($audit->extensions as $uuid => $ext) {
		if ($matches($ext['extension'], $ext['effective_caller_id_name'], $ext['emergency_caller_id_number'], $audit->domain_name($ext['domain_uuid']))) {
			$extensions[$uuid] = $ext;
		}
	}
	if ($show == 'all') {
		uasort($extensions, function ($a, $b) use ($audit) {
			$domain = strnatcasecmp($audit->domain_name($a['domain_uuid']), $audit->domain_name($b['domain_uuid']));
			return $domain != 0 ? $domain : strnatcmp($a['extension'], $b['extension']);
		});
	}

//issues
	$issues = [];
	foreach ($audit->issues as $issue) {
		if ($severity != 'all' && $issue['severity'] != $severity) {
			continue;
		}
		if (!$matches($issue['message'], $audit->domain_name($issue['domain_uuid']))) {
			continue;
		}
		$issues[] = $issue;
	}

//links into other pages, switching to the record's domain when needed
	$address_url = function ($uuid, $record_domain_uuid) {
		return e911_domain_link('e911_address_edit.php?id='.urlencode($uuid), $record_domain_uuid);
	};
	$extension_url = function ($uuid, $extension_domain_uuid) {
		if (!permission_exists('extension_edit')) {
			return '';
		}
		$same_domain = ($extension_domain_uuid == $_SESSION['domain_uuid']);
		if (!$same_domain && !permission_exists('domain_select')) {
			return '';
		}
		return e911_domain_link(PROJECT_PATH.'/app/extensions/extension_edit.php?id='.urlencode($uuid), $extension_domain_uuid);
	};

//theme options and permissions
	$list_row_edit_button = e911_theme_bool('list_row_edit_button');
	$can_edit = permission_exists('northern911_address_edit');
	$can_delete = permission_exists('northern911_address_delete');
	$can_add = permission_exists('northern911_address_add');

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-e911'];
	require_once "resources/header.php";
	e911_styles();

//action bar
	$buttons = '';
	if (permission_exists('northern911_import')) {
		$buttons .= e911_button($text['button-import'], 'e911_import.php', e911_icon('import', 'upload'));
	}
	if (permission_exists('northern911_export')) {
		$buttons .= e911_button($text['button-export'], 'e911_export.php', e911_icon('export', 'download'));
	}
	$buttons .= e911_button($text['button-audit_csv'], $self.($query_string !== '' ? '&' : '?').'download=issues', 'file-csv', ['style' => 'margin-right: 15px;']);
	if ($can_edit && $records) {
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
		$buttons .= e911_button($text['button-show_all'], '?'.http_build_query(array_merge($param, ['show' => 'all'])), e911_icon('all', 'globe'));
	}
	$buttons .= "<input type='text' class='txt list-search' name='search' id='search' value=\"".escape($search)."\" placeholder=\"".escape($text['label-search'])."\">";
	$buttons .= button::create(['label' => $text['button-search'], 'icon' => e911_icon('search', 'search'), 'type' => 'submit', 'id' => 'btn_search']);
	if ($severity != 'error') {
		$buttons .= "<input type='hidden' name='severity' value='".escape($severity)."'>\n";
	}
	$buttons .= "</form>\n";
	e911_action_bar($text['title-e911'], $buttons);

	if ($can_edit && $records) {
		echo modal::create(['id' => 'modal-toggle', 'type' => 'toggle', 'actions' => button::create(['type' => 'button', 'label' => $text['button-continue'], 'icon' => 'check', 'id' => 'btn_toggle', 'style' => 'float: right; margin-left: 15px;', 'collapse' => 'never', 'onclick' => "modal_close(); list_action_set('toggle'); list_form_submit('form_list');"])]);
	}
	if ($can_delete && $records) {
		echo modal::create(['id' => 'modal-delete', 'type' => 'delete', 'actions' => button::create(['type' => 'button', 'label' => $text['button-continue'], 'icon' => 'check', 'id' => 'btn_delete', 'style' => 'float: right; margin-left: 15px;', 'collapse' => 'never', 'onclick' => "modal_close(); list_action_set('delete'); list_form_submit('form_list');"])]);
	}

	e911_customer_number_banner();

	echo $text[$show == 'all' ? 'title_description-e911_all' : 'title_description-e911']."\n";
	echo "<br /><br />\n";

//status line for the domain
	$summary = $audit->summary[$domain_uuid] ?? null;
	if ($show != 'all' && $summary) {
		echo "<div class='e911-summary'>\n";
		echo "	<span><b>".escape($audit->domain_name($domain_uuid))."</b></span>\n";
		echo "	<span>".e911_audit::status_badge($summary['status'])."</span>\n";
		echo "</div>\n";
	}

//charts
	$colors = e911_colors();
	$widgets = [];
	if ($show == 'all') {
		$widgets[] = [
			'id' => 'e911_chart_domains',
			'title' => $text['label-widget_domains'],
			'center' => $totals['compliant'].' / '.$totals['domains'],
			'data' => [$totals['compliant'], $totals['review'], $totals['not_compliant'], $totals['disabled']],
			'colors' => [$colors['ok'], $colors['warning'], $colors['error'], $colors['disabled']],
			'legend' => [
				['ok', $totals['compliant'], $text['label-compliant']],
				['warning', $totals['review'], $text['label-needs_review']],
				['error', $totals['not_compliant'], $text['label-not_compliant']],
				['disabled', $totals['disabled'], $text['label-domain_disabled']],
			],
		];
	}
	$widgets[] = [
		'id' => 'e911_chart_extensions',
		'title' => $text['label-widget_extensions'],
		'center' => $totals['extensions_ok'].' / '.$totals['extensions'],
		'data' => [$totals['extensions_ok'], $totals['extensions'] - $totals['extensions_ok']],
		'colors' => [$colors['ok'], $colors['error']],
		'legend' => [
			['ok', $totals['extensions_ok'], $text['label-ext_ok']],
			['error', $totals['extensions'] - $totals['extensions_ok'], $text['label-ext_failing']],
			['exempt', $totals['exempt'], $text['label-exempt']],
		],
	];
	$widgets[] = [
		'id' => 'e911_chart_addresses',
		'title' => $text['label-widget_addresses'],
		'center' => ($totals['records_used'] + $totals['records_no_ext']).' / '.$totals['records'],
		'data' => [$totals['records_used'], $totals['records_no_ext'], $totals['records'] - $totals['records_used'] - $totals['records_no_ext']],
		'colors' => [$colors['ok'], $colors['exempt'], $colors['warning']],
		'legend' => [
			['ok', $totals['records_used'], $text['label-addr_used']],
			['exempt', $totals['records_no_ext'], $text['label-addr_no_ext']],
			['warning', $totals['unused'], $text['label-addr_unused']],
			['notice', $totals['pending'], $text['label-addr_pending']],
		],
	];
	$widgets[] = [
		'id' => 'e911_chart_issues',
		'title' => $text['title-issues'],
		'center' => (string)($totals['error'] + $totals['warning']),
		'data' => [$totals['error'], $totals['warning'], $totals['notice']],
		'colors' => [$colors['error'], $colors['warning'], $colors['notice']],
		'legend' => [
			['error', $totals['error'], $text['label-errors']],
			['warning', $totals['warning'], $text['label-warnings']],
			['notice', $totals['notice'], $text['label-notices']],
		],
	];
	e911_widgets($widgets);

//domains
	if ($show == 'all') {
		e911_section($text['title-domains'], '', count($domain_uuids));
		echo "<div class='card'>\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'>\n";
		echo th_order_by('dom_name', $text['label-domain'], $order_by, $order, '', '', $query_string);
		echo th_order_by('dom_status', $text['label-e911_status'], $order_by, $order, '', '', $query_string);
		echo th_order_by('dom_extensions', $text['label-extensions_ok'], $order_by, $order, '', "class='center'", $query_string);
		echo th_order_by('dom_records', $text['label-addresses_used'], $order_by, $order, '', "class='center'", $query_string);
		echo th_order_by('dom_error', $text['label-errors'], $order_by, $order, '', "class='center'", $query_string);
		echo th_order_by('dom_warning', $text['label-warnings'], $order_by, $order, '', "class='center'", $query_string);
		echo th_order_by('dom_notice', $text['label-notices'], $order_by, $order, '', "class='center hide-sm-dn'", $query_string);
		echo th_order_by('dom_exempt', $text['label-exempt'], $order_by, $order, '', "class='center hide-sm-dn'", $query_string);
		echo "</tr>\n";
		if (empty($domain_uuids)) {
			echo "<tr class='list-row'><td colspan='8'>".escape($text['message-nothing_to_show'])."</td></tr>\n";
		}
		$count_cell = function ($value, $class) {
			return $value ? "<span class='".$class."'><b>".$value."</b></span>" : "<span class='e911-muted'>0</span>";
		};
		foreach ($domain_uuids as $uuid) {
			$counts = $audit->summary[$uuid];
			$url = permission_exists('domain_select') ? e911_domain_link('e911_addresses.php', $uuid) : '';
			echo "<tr class='list-row'".($url ? " href='".escape($url)."'" : '').">\n";
			echo "	<td>".($url ? "<a href='".escape($url)."'>".escape($audit->domain_name($uuid))."</a>" : escape($audit->domain_name($uuid)))."</td>\n";
			echo "	<td>".e911_audit::status_badge($counts['status'])."</td>\n";
			echo "	<td class='center'>".$counts['extensions_ok']." / ".$counts['extensions']."</td>\n";
			echo "	<td class='center'>".($counts['records_used'] + $counts['records_no_ext'])." / ".$counts['records']."</td>\n";
			echo "	<td class='center'>".$count_cell($counts['error'], 'e911-error')."</td>\n";
			echo "	<td class='center'>".$count_cell($counts['warning'], 'e911-warning')."</td>\n";
			echo "	<td class='center hide-sm-dn'>".$count_cell($counts['notice'], 'e911-notice')."</td>\n";
			echo "	<td class='center hide-sm-dn'>".$count_cell($counts['exempt'], 'e911-exempt')."</td>\n";
			echo "</tr>\n";
		}
		echo "</table>\n";
		echo "</div>\n";
	}

//addresses
	$section_buttons = '';
	if ($can_add) {
		$section_buttons = e911_button($text['button-add_address'], 'e911_address_edit.php', e911_icon('add', 'plus'), ['id' => 'btn_add', 'collapse' => 'never']);
	}
	e911_section($text['title-e911_addresses'], $section_buttons, count($records));

	echo "<div class='card'>\n";
	echo "<form id='form_list' method='post'>\n";
	echo "<input type='hidden' id='action' name='action' value=''>\n";
	foreach ($param as $key => $value) {
		echo "<input type='hidden' name='".escape($key)."' value=\"".escape($value)."\">\n";
	}

	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	if ($can_edit || $can_delete) {
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
	if ($can_edit && $list_row_edit_button) {
		echo "	<td class='action-button'>&nbsp;</td>\n";
	}
	echo "</tr>\n";

	if (empty($records)) {
		echo "<tr class='list-row'><td colspan='11'>".escape($search !== '' ? $text['message-nothing_to_show'] : $text['message-no_addresses'])."</td></tr>\n";
	}
	$x = 0;
	foreach ($records as $uuid => $row) {
		$edit_url = $can_edit ? $address_url($uuid, $row['domain_uuid']) : '';
		$number = e911_format_number($row['number']);
		echo "<tr class='list-row'".($edit_url ? " href='".escape($edit_url)."'" : '').">\n";
		if ($can_edit || $can_delete) {
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
		echo "	<td class='hide-sm-dn'>".(empty($row['used_by']) ? "<span class='e911-muted'>".escape($row['extension_required'] ? $text['label-none'] : $text['label-addr_no_ext'])."</span>" : escape(implode(', ', $row['used_by'])))."</td>\n";
		$enabled_label = $row['enabled'] ? $text['label-true'] : $text['label-false'];
		if ($can_edit) {
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
		if ($can_edit && $list_row_edit_button) {
			echo "	<td class='action-button'>".button::create(['type' => 'button', 'title' => $text['button-edit'], 'icon' => e911_icon('edit', 'pencil-alt'), 'link' => $edit_url])."</td>\n";
		}
		echo "</tr>\n";
		$x++;
	}
	echo "</table>\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";
	echo "</div>\n";

//extensions
	e911_section($text['title-extensions'], '', count($extensions));
	if ($audit->exempt_tag !== '') {
		echo "<div class='e911-muted' style='margin: -5px 0 8px 0;'>".escape(str_replace('{tag}', $audit->exempt_tag, $text['description-exempt_hint']))."</div>\n";
	}
	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	if ($show == 'all') {
		echo "	<th>".escape($text['label-domain'])."</th>\n";
	}
	echo "	<th>".escape($text['label-extension'] ?? 'Extension')."</th>\n";
	echo "	<th>".escape($text['label-e911_name'])."</th>\n";
	echo "	<th>".escape($text['label-emergency_caller_id'])."</th>\n";
	echo "	<th>".escape($text['label-e911_status'])."</th>\n";
	echo "	<td class='action-button'>&nbsp;</td>\n";
	echo "</tr>\n";
	if (empty($extensions)) {
		echo "<tr class='list-row'><td colspan='6'>".escape($search !== '' ? $text['message-nothing_to_show'] : $text['message-no_enabled_extensions'])."</td></tr>\n";
	}
	foreach ($extensions as $uuid => $ext) {
		$status = $audit->extension_status[$uuid] ?? ['ok', ''];
		$number = e911_audit::normalize($ext['emergency_caller_id_number']);
		$ext_url = $extension_url($uuid, $ext['domain_uuid']);
		echo "<tr class='list-row'".($ext_url ? " href='".escape($ext_url)."'" : '').">\n";
		if ($show == 'all') {
			echo "	<td>".escape($audit->domain_name($ext['domain_uuid']))."</td>\n";
		}
		echo "	<td>".($ext_url ? "<a href='".escape($ext_url)."'>".escape($ext['extension'])."</a>" : escape($ext['extension']))."</td>\n";
		echo "	<td>".escape($ext['effective_caller_id_name'])."</td>\n";
		echo "	<td>".escape(e911_audit::valid_number($number) ? e911_format_number($number) : $ext['emergency_caller_id_number'])."</td>\n";
		echo "	<td>".e911_badge($status[0], $status[0] == 'ok' ? null : $status[1])."</td>\n";
		echo "	<td class='no-link action-button'>";
		if ($status[0] == 'error' && e911_audit::valid_number($number) && $can_add && strpos($status[1], 'has no E911 address') !== false) {
			$url = e911_domain_link('e911_address_edit.php?number='.urlencode($number), $ext['domain_uuid']);
			echo e911_button($text['button-add_address'], $url, e911_icon('add', 'plus'), ['collapse' => 'never']);
		}
		echo "</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";

//issues
	$severity_select = "<form method='get' class='inline'>\n";
	foreach ($param as $key => $value) {
		if ($key != 'severity') {
			$severity_select .= "<input type='hidden' name='".escape($key)."' value=\"".escape($value)."\">\n";
		}
	}
	$severity_select .= "<select class='formfld' name='severity' onchange=\"this.form.submit();\">\n";
	foreach (['error' => $text['label-errors'], 'warning' => $text['label-warnings'], 'notice' => $text['label-notices'], 'all' => $text['label-all_severities']] as $key => $label) {
		$severity_select .= "<option value='".$key."'".($key == $severity ? " selected='selected'" : '').">".escape($label)."</option>\n";
	}
	$severity_select .= "</select>\n</form>\n";
	e911_section($text['title-issues'], $severity_select, count($issues));

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>".escape($text['label-severity'])."</th>\n";
	if ($show == 'all') {
		echo "	<th>".escape($text['label-domain'])."</th>\n";
	}
	echo "	<th>".escape($text['label-issue'])."</th>\n";
	echo "</tr>\n";
	if (empty($issues)) {
		echo "<tr class='list-row'><td colspan='3'>".escape($text['message-nothing_to_show'])."</td></tr>\n";
	}
	foreach ($issues as $issue) {
		if ($issue['object'] == 'record') {
			$url = $can_edit ? $address_url($issue['ref'], $issue['domain_uuid']) : '';
		}
		else {
			$url = $extension_url($issue['ref'], $issue['domain_uuid']);
		}
		echo "<tr class='list-row'".($url ? " href='".escape($url)."'" : '').">\n";
		echo "	<td class='no-wrap'>".e911_badge($issue['severity'])."</td>\n";
		if ($show == 'all') {
			echo "	<td class='no-wrap'>".escape($audit->domain_name($issue['domain_uuid']))."</td>\n";
		}
		echo "	<td>".($url ? "<a href='".escape($url)."'>".escape($issue['message'])."</a>" : escape($issue['message']))."</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>
