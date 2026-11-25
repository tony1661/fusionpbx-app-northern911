<?php
/*
	E911 for FusionPBX
	Export the carrier upload file in the same 13 column layout it was imported from.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";
	require_once __DIR__ . "/resources/e911.php";

//check permissions
	if (!permission_exists('e911_export')) {
		echo "access denied";
		exit;
	}

//load the records for an export
	function e911_export_rows($domain_uuid, $scope, $skip_incomplete, &$left_out) {
		$sql = "select a.*, cast(a.e911_address_exported as text) as exported_text, d.domain_name ";
		$sql .= "from v_e911_addresses as a join v_domains as d on d.domain_uuid = a.domain_uuid ";
		$sql .= "where coalesce(a.e911_address_enabled, 'true') <> 'false' ";
		$parameters = null;
		if (is_uuid($domain_uuid)) {
			$sql .= "and a.domain_uuid = :domain_uuid ";
			$parameters = ['domain_uuid' => $domain_uuid];
		}
		$sql .= "order by a.e911_address_name asc, a.e911_address_number asc";

		$rows = [];
		$left_out = [];
		foreach (e911_select($sql, $parameters) as $row) {
			$changed = $row['update_date'] ?: $row['insert_date'];
			$pending = empty($row['exported_text']) || ($changed && strtotime($changed) > strtotime($row['exported_text']));
			if ($scope == 'pending' && !$pending) {
				continue;
			}
			$number = e911_audit::normalize($row['e911_address_number']);
			$missing = e911_audit::missing_fields($row);
			if (!e911_audit::valid_number($number)) {
				$missing[] = 'valid phone number';
			}
			if ($skip_incomplete && $missing) {
				$left_out[] = e911_format_number($number).' ('.$row['domain_name'].'): missing '.implode(', ', $missing);
				continue;
			}
			$rows[] = $row;
		}
		return $rows;
	}

//download
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add('Invalid token, please try again', 'negative');
			header('Location: e911_export.php');
			exit;
		}
		$customer_number = e911_customer_number();
		if ($customer_number === '') {
			message::add('Set the E911 customer number in Default Settings before exporting.', 'negative');
			header('Location: e911_export.php');
			exit;
		}
		$domain_uuid = $_POST['domain_uuid'] ?? '';
		if (!permission_exists('e911_address_all')) {
			$domain_uuid = $_SESSION['domain_uuid'];
		}
		$scope = ($_POST['scope'] ?? '') == 'pending' ? 'pending' : 'all';
		$left_out = [];
		$rows = e911_export_rows($domain_uuid, $scope, !empty($_POST['skip_incomplete']), $left_out);

		if (empty($rows)) {
			message::add('Nothing to export with those options.', 'negative');
			header('Location: e911_export.php');
			exit;
		}

		$csv = '';
		if (!empty($_POST['header_row'])) {
			$csv .= e911_csv_line(['Customer Number', 'Action', 'Phone', 'Name', 'Blank', 'Street Number', 'Unit', 'Street Name', 'City', 'Province', 'Postal Code', '911 Instructions', 'Flag']);
		}
		$uuids = [];
		foreach ($rows as $row) {
			$csv .= e911_csv_line(e911_carrier_row($row, $customer_number));
			$uuids[] = $row['e911_address_uuid'];
		}

		if (!empty($_POST['mark_exported'])) {
			e911_mark_exported($uuids);
		}

		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="e911-carrier-'.date('Y-m-d-Hi').'.csv"');
		header('Content-Length: '.strlen($csv));
		echo $csv;
		exit;
	}

//preview counts
	$domain_uuid = permission_exists('e911_address_all') ? ($_GET['domain_uuid'] ?? '') : $_SESSION['domain_uuid'];
	$left_out_all = [];
	$count_all = count(e911_export_rows($domain_uuid, 'all', true, $left_out_all));
	$left_out_pending = [];
	$count_pending = count(e911_export_rows($domain_uuid, 'pending', true, $left_out_pending));

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = 'E911 Export';
	require_once "resources/header.php";
	e911_styles();

	echo "<form method='post'>\n";
	$back = permission_exists('e911_dashboard_view') ? 'e911_dashboard.php' : 'e911_addresses.php';
	$buttons = e911_button('Back', $back, 'chevron-left');
	if (e911_customer_number() !== '') {
		$buttons .= "<button type='submit' class='btn btn-default'><span class='fas fa-download fa-fw'></span> Download CSV</button>\n";
	}
	e911_action_bar('E911 Export', $buttons);
	e911_customer_number_banner();

	echo "<p>The file uses the same 13 column layout as your carrier upload. Disabled addresses are never exported.</p>\n";

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	if (permission_exists('e911_address_all')) {
		echo "<tr><td class='vncell' width='30%'>Domain</td><td class='vtable' width='70%'>\n";
		echo "<select class='formfld' name='domain_uuid' onchange=\"window.location.href='e911_export.php?domain_uuid='+this.value;\">\n";
		echo "<option value=''>All domains</option>\n";
		foreach (e911_domains() as $domain) {
			$selected = ($domain['domain_uuid'] == $domain_uuid) ? " selected='selected'" : '';
			echo "<option value='".escape($domain['domain_uuid'])."'".$selected.">".escape($domain['domain_name'])."</option>\n";
		}
		echo "</select></td></tr>\n";
	}

	echo "<tr><td class='vncell' width='30%'>Records</td><td class='vtable' width='70%'>\n";
	echo "<label><input type='radio' name='scope' value='pending' checked='checked'> New or changed since the last export (".$count_pending.")</label><br>\n";
	echo "<label><input type='radio' name='scope' value='all'> Everything (".$count_all.")</label>\n";
	echo "</td></tr>\n";

	echo "<tr><td class='vncell'>Options</td><td class='vtable'>\n";
	echo "<label><input type='checkbox' name='skip_incomplete' value='1' checked='checked'> Leave out addresses with missing fields</label><br>\n";
	echo "<label><input type='checkbox' name='mark_exported' value='1' checked='checked'> Mark exported records as sent to the carrier</label><br>\n";
	echo "<label><input type='checkbox' name='header_row' value='1'> Add a header row (your carrier file normally has none)</label>\n";
	echo "</td></tr>\n";

	echo "</table>\n";
	echo "</div>\n";

	if ($left_out_all) {
		echo "<p>".e911_badge('error')." These will be left out until they are fixed:</p>\n<ul>\n";
		foreach ($left_out_all as $line) {
			echo "<li>".escape($line)."</li>\n";
		}
		echo "</ul>\n";
	}

	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";
	echo "<br>\n";

//include the footer
	require_once "resources/footer.php";

?>
