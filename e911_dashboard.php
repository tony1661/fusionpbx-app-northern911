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
	if (!permission_exists('e911_dashboard_view')) {
		echo "access denied";
		exit;
	}

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
	$show = $_GET['show'] ?? 'problems';
	$severity = $_GET['severity'] ?? 'error';
	if (!in_array($severity, ['error', 'warning', 'notice', 'all'])) {
		$severity = 'error';
	}

//totals
	$totals = ['domains' => 0, 'compliant' => 0, 'error' => 0, 'warning' => 0, 'extensions' => 0, 'extensions_bad' => 0, 'records' => 0, 'unused' => 0, 'pending' => 0];
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
		$totals['extensions_bad'] += $counts['extensions'] - $counts['extensions_ok'];
		$totals['records'] += $counts['records'];
	}
	foreach ($audit->issues as $issue) {
		if ($issue['type'] == 'rec_unused') {
			$totals['unused']++;
		}
		if ($issue['type'] == 'rec_not_sent' || $issue['type'] == 'rec_changed') {
			$totals['pending']++;
		}
	}

//sort domains, worst first
	$order = ['error' => 0, 'warning' => 1, 'ok' => 2, 'empty' => 3];
	$domain_uuids = array_keys($audit->summary);
	usort($domain_uuids, function ($a, $b) use ($audit, $order) {
		$sa = $audit->summary[$a];
		$sb = $audit->summary[$b];
		if ($order[$sa['status']] != $order[$sb['status']]) {
			return $order[$sa['status']] - $order[$sb['status']];
		}
		if ($sa['error'] != $sb['error']) {
			return $sb['error'] - $sa['error'];
		}
		return strcmp($audit->domain_name($a), $audit->domain_name($b));
	});

//include the header
	$document['title'] = 'E911 Dashboard';
	require_once "resources/header.php";
	e911_styles();

	$buttons = e911_button('Addresses', 'e911_addresses.php', 'list');
	if (permission_exists('e911_import')) {
		$buttons .= e911_button('Import', 'e911_import.php', 'upload');
	}
	if (permission_exists('e911_export')) {
		$buttons .= e911_button('Export', 'e911_export.php', 'download');
	}
	$buttons .= e911_button('Audit CSV', 'e911_dashboard.php?download=issues', 'file-csv');
	e911_action_bar('E911 Dashboard', $buttons);
	e911_customer_number_banner();

//cards
	echo "<div class='e911-cards'>\n";
	echo "<div class='e911-card'><div class='e911-num'>".$totals['compliant']." / ".$totals['domains']."</div><div class='e911-lbl'>Domains compliant</div></div>\n";
	echo "<div class='e911-card'><div class='e911-num' style='color: #c0392b;'>".$totals['error']."</div><div class='e911-lbl'>Domains not compliant</div></div>\n";
	echo "<div class='e911-card'><div class='e911-num' style='color: #d68910;'>".$totals['warning']."</div><div class='e911-lbl'>Domains to review</div></div>\n";
	echo "<div class='e911-card'><div class='e911-num'>".$totals['extensions_bad']." / ".$totals['extensions']."</div><div class='e911-lbl'>Extensions failing</div></div>\n";
	echo "<div class='e911-card'><div class='e911-num'>".$totals['unused']." / ".$totals['records']."</div><div class='e911-lbl'>Addresses not used</div></div>\n";
	echo "<div class='e911-card'><div class='e911-num'>".$totals['pending']."</div><div class='e911-lbl'>Waiting for carrier export</div></div>\n";
	echo "</div>\n";

//domain table
	echo "<div class='e911-section'>Domains &nbsp;";
	echo "<span style='font-weight: normal; font-size: 12px;'>";
	echo $show == 'all' ? "<a href='?show=problems&severity=".escape($severity)."'>Show problems only</a>" : "<a href='?show=all&severity=".escape($severity)."'>Show all domains</a>";
	echo "</span></div>\n";
	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>Domain</th>\n";
	echo "	<th>Status</th>\n";
	echo "	<th>Extensions OK</th>\n";
	echo "	<th>Addresses Used</th>\n";
	echo "	<th>Errors</th>\n";
	echo "	<th>Warnings</th>\n";
	echo "	<th class='hide-sm-dn'>Notices</th>\n";
	echo "	<th class='hide-sm-dn'>Exempt</th>\n";
	echo "</tr>\n";
	$shown = 0;
	foreach ($domain_uuids as $domain_uuid) {
		$counts = $audit->summary[$domain_uuid];
		if ($show != 'all' && ($counts['status'] == 'ok' || $counts['status'] == 'empty')) {
			continue;
		}
		$shown++;
		$url = 'e911_addresses.php?domain_uuid='.urlencode($domain_uuid);
		echo "<tr class='list-row'>\n";
		echo "	<td><a href='".escape($url)."'>".escape($audit->domain_name($domain_uuid))."</a></td>\n";
		echo "	<td>".e911_audit::status_badge($counts['status'])."</td>\n";
		echo "	<td>".$counts['extensions_ok']." / ".$counts['extensions']."</td>\n";
		echo "	<td>".$counts['records_used']." / ".$counts['records']."</td>\n";
		echo "	<td>".($counts['error'] ? "<b style='color: #c0392b;'>".$counts['error']."</b>" : '0')."</td>\n";
		echo "	<td>".($counts['warning'] ? "<b style='color: #d68910;'>".$counts['warning']."</b>" : '0')."</td>\n";
		echo "	<td class='hide-sm-dn'>".$counts['notice']."</td>\n";
		echo "	<td class='hide-sm-dn'>".$counts['exempt']."</td>\n";
		echo "</tr>\n";
	}
	if ($shown == 0) {
		echo "<tr class='list-row'><td colspan='8'>Every domain is compliant.</td></tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";

//issue list
	echo "<div class='e911-section'>Issues &nbsp;<span style='font-weight: normal; font-size: 12px;'>";
	$links = [];
	foreach (['error' => 'Errors', 'warning' => 'Warnings', 'notice' => 'Notices', 'all' => 'All'] as $key => $label) {
		$links[] = $key == $severity ? "<b>".$label."</b>" : "<a href='?show=".escape($show)."&severity=".$key."'>".$label."</a>";
	}
	echo implode(' | ', $links);
	echo "</span></div>\n";
	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>Severity</th>\n";
	echo "	<th>Domain</th>\n";
	echo "	<th>Issue</th>\n";
	echo "</tr>\n";
	$shown = 0;
	foreach ($audit->issues as $issue) {
		if ($severity != 'all' && $issue['severity'] != $severity) {
			continue;
		}
		$shown++;
		if ($issue['object'] == 'record') {
			$url = 'e911_address_edit.php?id='.urlencode($issue['ref']);
		}
		else {
			$url = 'e911_addresses.php?domain_uuid='.urlencode($issue['domain_uuid']);
		}
		echo "<tr class='list-row'>\n";
		echo "	<td>".e911_badge($issue['severity'])."</td>\n";
		echo "	<td class='no-wrap'>".escape($audit->domain_name($issue['domain_uuid']))."</td>\n";
		echo "	<td><a href='".escape($url)."'>".escape($issue['message'])."</a></td>\n";
		echo "</tr>\n";
	}
	if ($shown == 0) {
		echo "<tr class='list-row'><td colspan='3'>Nothing to show.</td></tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br>\n";

//include the footer
	require_once "resources/footer.php";

?>
