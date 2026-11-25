<?php
/*
	E911 for FusionPBX
	Global dashboard: audits every enabled domain.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";
	require_once __DIR__ . "/resources/e911.php";

//check permissions
	if (!permission_exists('northern911_dashboard_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$text = e911_text();

//run the audit
	$audit = new e911_audit;
	$audit->run();

//download the issue list as csv
	if (($_GET['download'] ?? '') == 'issues') {
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="e911-audit-'.date('Y-m-d').'.csv"');
		$out = fopen('php://output', 'w');
		fputcsv($out, ['Domain', 'Severity', 'Type', 'Item', 'Number', 'Message'], ',', '"', '\\');
		foreach ($audit->issues as $issue) {
			fputcsv($out, [
				$audit->domain_name($issue['domain_uuid']),
				$issue['severity'],
				$issue['type'],
				$issue['label'],
				$issue['number'],
				$issue['message'],
			], ',', '"', '\\');
		}
		fclose($out);
		exit;
	}

//filters
	$show = ($_GET['show'] ?? '') == 'all' ? 'all' : '';
	$severity = $_GET['severity'] ?? 'error';
	if (!in_array($severity, ['error', 'warning', 'notice', 'all'])) {
		$severity = 'error';
	}
	$search = trim((string)($_GET['search'] ?? ''));
	$sortable = ['domain_name', 'status', 'extensions_ok', 'records_used', 'error', 'warning', 'notice', 'exempt'];
	$order_by = in_array($_GET['order_by'] ?? '', $sortable) ? $_GET['order_by'] : '';
	$order = ($_GET['order'] ?? '') == 'desc' ? 'desc' : 'asc';
	$param = ['severity' => $severity];
	if ($show == 'all') {
		$param['show'] = 'all';
	}
	if ($search !== '') {
		$param['search'] = $search;
	}
	$query_string = http_build_query($param);

//totals
	$totals = [
		'domains' => 0, 'compliant' => 0, 'error' => 0, 'warning' => 0,
		'extensions' => 0, 'extensions_ok' => 0, 'exempt' => 0,
		'records' => 0, 'records_used' => 0, 'unused' => 0, 'pending' => 0,
	];
	foreach ($audit->summary as $counts) {
		$totals['domains']++;
		if ($counts['status'] == 'ok' || $counts['status'] == 'empty') {
			$totals['compliant']++;
		}
		elseif ($counts['status'] == 'error') {
			$totals['error']++;
		}
		else {
			$totals['warning']++;
		}
		$totals['extensions'] += $counts['extensions'];
		$totals['extensions_ok'] += $counts['extensions_ok'];
		$totals['exempt'] += $counts['exempt'];
		$totals['records'] += $counts['records'];
		$totals['records_used'] += $counts['records_used'];
	}
	foreach ($audit->issues as $issue) {
		if ($issue['type'] == 'rec_unused') {
			$totals['unused']++;
		}
		if ($issue['type'] == 'rec_not_sent' || $issue['type'] == 'rec_changed') {
			$totals['pending']++;
		}
	}

//domains, worst first unless a column was clicked
	$status_order = ['error' => 0, 'warning' => 1, 'ok' => 2, 'empty' => 3];
	$domain_uuids = [];
	foreach ($audit->summary as $domain_uuid => $counts) {
		if ($show != 'all' && ($counts['status'] == 'ok' || $counts['status'] == 'empty')) {
			continue;
		}
		if ($search !== '' && stripos($audit->domain_name($domain_uuid), $search) === false) {
			continue;
		}
		$domain_uuids[] = $domain_uuid;
	}
	usort($domain_uuids, function ($a, $b) use ($audit, $status_order, $order_by, $order) {
		$sa = $audit->summary[$a];
		$sb = $audit->summary[$b];
		switch ($order_by) {
			case 'domain_name': $result = strnatcasecmp($audit->domain_name($a), $audit->domain_name($b)); break;
			case 'status': $result = $status_order[$sa['status']] - $status_order[$sb['status']]; break;
			case 'extensions_ok': $result = ($sa['extensions'] - $sa['extensions_ok']) - ($sb['extensions'] - $sb['extensions_ok']); break;
			case 'records_used': $result = ($sa['records'] - $sa['records_used']) - ($sb['records'] - $sb['records_used']); break;
			case 'error': case 'warning': case 'notice': case 'exempt': $result = $sa[$order_by] - $sb[$order_by]; break;
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

//issues
	$issues = [];
	foreach ($audit->issues as $issue) {
		if ($severity != 'all' && $issue['severity'] != $severity) {
			continue;
		}
		if ($search !== '' && stripos($issue['message'].' '.$audit->domain_name($issue['domain_uuid']), $search) === false) {
			continue;
		}
		$issues[] = $issue;
	}

//include the header
	$document['title'] = $text['title-e911_dashboard'];
	require_once "resources/header.php";
	e911_styles();

//action bar
	$buttons = e911_button($text['button-addresses'], 'e911_addresses.php', 'list');
	if (permission_exists('northern911_import')) {
		$buttons .= e911_button($text['button-import'], 'e911_import.php', e911_icon('import', 'upload'));
	}
	if (permission_exists('northern911_export')) {
		$buttons .= e911_button($text['button-export'], 'e911_export.php', e911_icon('export', 'download'));
	}
	$buttons .= e911_button($text['button-audit_csv'], 'e911_dashboard.php?download=issues', 'file-csv', ['style' => 'margin-right: 15px;']);
	$buttons .= "<form id='form_search' class='inline' method='get'>\n";
	if ($show == 'all') {
		$buttons .= "<input type='hidden' name='show' value='all'>\n";
	}
	else {
		$buttons .= e911_button($text['button-show_all'], '?'.http_build_query(array_merge($param, ['show' => 'all'])), e911_icon('all', 'globe'));
	}
	$buttons .= "<select class='formfld' name='severity' style='margin-left: 15px;' onchange=\"this.form.submit();\">\n";
	foreach (['error' => $text['label-errors'], 'warning' => $text['label-warnings'], 'notice' => $text['label-notices'], 'all' => $text['label-all_severities']] as $key => $label) {
		$buttons .= "<option value='".$key."'".($key == $severity ? " selected='selected'" : '').">".escape($label)."</option>\n";
	}
	$buttons .= "</select>\n";
	$buttons .= "<input type='text' class='txt list-search' name='search' id='search' value=\"".escape($search)."\" placeholder=\"".escape($text['label-search'])."\">";
	$buttons .= button::create(['label' => $text['button-search'], 'icon' => e911_icon('search', 'search'), 'type' => 'submit', 'id' => 'btn_search']);
	$buttons .= "</form>\n";
	e911_action_bar($text['title-e911_dashboard'], $buttons);
	e911_customer_number_banner();

	echo $text['title_description-e911_dashboard']."\n";
	echo "<br /><br />\n";

//widgets, drawn like the FusionPBX dashboard
	$colors = e911_colors();
	$widgets = [
		[
			'id' => 'e911_chart_domains',
			'title' => $text['label-widget_domains'],
			'center' => $totals['compliant'].' / '.$totals['domains'],
			'data' => [$totals['compliant'], $totals['warning'], $totals['error']],
			'colors' => [$colors['ok'], $colors['warning'], $colors['error']],
			'legend' => [
				['ok', $totals['compliant'], $text['label-compliant']],
				['warning', $totals['warning'], $text['label-needs_review']],
				['error', $totals['error'], $text['label-not_compliant']],
			],
		],
		[
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
		],
		[
			'id' => 'e911_chart_addresses',
			'title' => $text['label-widget_addresses'],
			'center' => $totals['records_used'].' / '.$totals['records'],
			'data' => [$totals['records_used'], $totals['records'] - $totals['records_used']],
			'colors' => [$colors['ok'], $colors['warning']],
			'legend' => [
				['ok', $totals['records_used'], $text['label-addr_used']],
				['warning', $totals['unused'], $text['label-addr_unused']],
				['notice', $totals['pending'], $text['label-addr_pending']],
			],
		],
	];
	$number_color = e911_theme('dashboard_number_text_color', '#2a2a2a');
	$sub_color = e911_theme('dashboard_missed_calls_chart_sub_color', '#d4d4d4');

	echo "<script src='".PROJECT_PATH."/resources/chartjs/chart.min.js'></script>\n";
	echo "<div class='e911-widgets'>\n";
	foreach ($widgets as $widget) {
		echo "<div class='widget'>\n";
		echo "	<div class='hud_box'>\n";
		echo "		<div class='hud_content'>\n";
		echo "			<span class='hud_title'>".escape($widget['title'])."</span>\n";
		echo "			<div class='hud_chart'><canvas id='".$widget['id']."'></canvas></div>\n";
		echo "			<div class='e911-legend'>";
		foreach ($widget['legend'] as $item) {
			echo "<span><span class='e911-".$item[0]."'><b>".(int)$item[1]."</b></span> ".escape($item[2])."</span>";
		}
		echo "</div>\n";
		echo "		</div>\n";
		echo "	</div>\n";
		echo "</div>\n";
	}
	echo "</div>\n";
	echo "<script>\n";
	echo "(function () {\n";
	echo "	if (typeof Chart === 'undefined') { return; }\n";
	echo "	var font = ".json_encode((string)e911_theme('dashboard_number_text_font', 'arial')).";\n";
	echo "	var size = ".json_encode((string)e911_theme('dashboard_chart_text_size', '30px')).";\n";
	echo "	var center_text = { id: 'e911_center', beforeDraw: function (chart, args, options) {\n";
	echo "		var ctx = chart.ctx, area = chart.chartArea;\n";
	echo "		ctx.save(); ctx.font = size + ' ' + font; ctx.textBaseline = 'middle'; ctx.textAlign = 'center';\n";
	echo "		ctx.fillStyle = ".json_encode((string)$number_color).";\n";
	echo "		ctx.fillText(options.text, area.left + area.width / 2, area.top + area.height / 2); ctx.restore();\n";
	echo "	} };\n";
	foreach ($widgets as $widget) {
		$data = array_map('intval', $widget['data']);
		$colors_js = $widget['colors'];
		if (array_sum($data) == 0) {
			$data = [1];
			$colors_js = [$sub_color];
		}
		echo "	new Chart(document.getElementById('".$widget['id']."').getContext('2d'), {\n";
		echo "		type: 'doughnut',\n";
		echo "		data: { datasets: [{ data: ".json_encode($data).", backgroundColor: ".json_encode($colors_js).", borderWidth: 0 }] },\n";
		echo "		options: { responsive: true, maintainAspectRatio: false, cutout: ".json_encode((string)e911_theme('dashboard_chart_cutout', '75%')).", events: [], plugins: { legend: { display: false }, tooltip: { enabled: false }, e911_center: { text: ".json_encode($widget['center'])." } } },\n";
		echo "		plugins: [center_text]\n";
		echo "	});\n";
	}
	echo "})();\n";
	echo "</script>\n";

//domain list
	e911_section($text['title-domains'], '', count($domain_uuids));
	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo th_order_by('domain_name', $text['label-domain'], $order_by, $order, '', '', $query_string);
	echo th_order_by('status', $text['label-e911_status'], $order_by, $order, '', '', $query_string);
	echo th_order_by('extensions_ok', $text['label-extensions_ok'], $order_by, $order, '', "class='center'", $query_string);
	echo th_order_by('records_used', $text['label-addresses_used'], $order_by, $order, '', "class='center'", $query_string);
	echo th_order_by('error', $text['label-errors'], $order_by, $order, '', "class='center'", $query_string);
	echo th_order_by('warning', $text['label-warnings'], $order_by, $order, '', "class='center'", $query_string);
	echo th_order_by('notice', $text['label-notices'], $order_by, $order, '', "class='center hide-sm-dn'", $query_string);
	echo th_order_by('exempt', $text['label-exempt'], $order_by, $order, '', "class='center hide-sm-dn'", $query_string);
	echo "</tr>\n";
	if (empty($domain_uuids)) {
		echo "<tr class='list-row'><td colspan='8'>".escape($search !== '' ? $text['message-nothing_to_show'] : $text['message-all_compliant'])."</td></tr>\n";
	}
	foreach ($domain_uuids as $domain_uuid) {
		$counts = $audit->summary[$domain_uuid];
		$url = 'e911_addresses.php?domain_uuid='.urlencode($domain_uuid);
		$count_cell = function ($value, $class) {
			return $value ? "<span class='".$class."'><b>".$value."</b></span>" : "<span class='e911-muted'>0</span>";
		};
		echo "<tr class='list-row' href='".escape($url)."'>\n";
		echo "	<td><a href='".escape($url)."'>".escape($audit->domain_name($domain_uuid))."</a></td>\n";
		echo "	<td>".e911_audit::status_badge($counts['status'])."</td>\n";
		echo "	<td class='center'>".$counts['extensions_ok']." / ".$counts['extensions']."</td>\n";
		echo "	<td class='center'>".$counts['records_used']." / ".$counts['records']."</td>\n";
		echo "	<td class='center'>".$count_cell($counts['error'], 'e911-error')."</td>\n";
		echo "	<td class='center'>".$count_cell($counts['warning'], 'e911-warning')."</td>\n";
		echo "	<td class='center hide-sm-dn'>".$count_cell($counts['notice'], 'e911-notice')."</td>\n";
		echo "	<td class='center hide-sm-dn'>".$count_cell($counts['exempt'], 'e911-exempt')."</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";

//issue list
	e911_section($text['title-issues'], '', count($issues));
	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>".escape($text['label-severity'])."</th>\n";
	echo "	<th>".escape($text['label-domain'])."</th>\n";
	echo "	<th>".escape($text['label-issue'])."</th>\n";
	echo "</tr>\n";
	if (empty($issues)) {
		echo "<tr class='list-row'><td colspan='3'>".escape($text['message-nothing_to_show'])."</td></tr>\n";
	}
	foreach ($issues as $issue) {
		if ($issue['object'] == 'record') {
			$url = 'e911_address_edit.php?id='.urlencode($issue['ref']);
		}
		else {
			$url = 'e911_addresses.php?domain_uuid='.urlencode($issue['domain_uuid']);
		}
		echo "<tr class='list-row' href='".escape($url)."'>\n";
		echo "	<td class='no-wrap'>".e911_badge($issue['severity'])."</td>\n";
		echo "	<td class='no-wrap'>".escape($audit->domain_name($issue['domain_uuid']))."</td>\n";
		echo "	<td><a href='".escape($url)."'>".escape($issue['message'])."</a></td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>
