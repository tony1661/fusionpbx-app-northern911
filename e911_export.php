<?php
/*
	E911 for FusionPBX
	Export the Northern 911 upload file: 13 columns, no header row.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";
	require_once __DIR__ . "/resources/e911.php";

//check permissions
	if (!permission_exists('northern911_export')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$text = e911_text();

//load the records for an export, addresses in disabled domains and incomplete addresses are always left out
	function e911_export_rows($domain_uuid, $scope, &$left_out) {
		$sql = "select a.*, cast(a.northern911_address_exported as text) as exported_text, d.domain_name, cast(d.domain_enabled as text) as domain_enabled ";
		$sql .= "from v_northern911_addresses as a join v_domains as d on d.domain_uuid = a.domain_uuid ";
		$sql .= "where coalesce(a.northern911_address_enabled, 'true') <> 'false' ";
		$parameters = null;
		if (is_uuid($domain_uuid)) {
			$sql .= "and a.domain_uuid = :domain_uuid ";
			$parameters = ['domain_uuid' => $domain_uuid];
		}
		$sql .= "order by a.northern911_address_name asc, a.northern911_address_number asc";

		$rows = [];
		$left_out = [];
		foreach (e911_select($sql, $parameters) as $row) {
			$changed = $row['update_date'] ?: $row['insert_date'];
			$pending = empty($row['exported_text']) || ($changed && strtotime($changed) > strtotime($row['exported_text']));
			if ($scope == 'pending' && !$pending) {
				continue;
			}
			$number = e911_audit::normalize($row['northern911_address_number']);
			if ($row['domain_enabled'] !== 'true') {
				$left_out[] = e911_format_number($number).' ('.$row['domain_name'].'): domain is disabled';
				continue;
			}
			$missing = e911_audit::missing_fields($row);
			if (!e911_audit::valid_number($number)) {
				$missing[] = 'valid phone number';
			}
			if ($missing) {
				$left_out[] = e911_format_number($number).' ('.$row['domain_name'].'): missing '.implode(', ', $missing);
				continue;
			}
			$rows[] = $row;
		}
		return $rows;
	}

//domain: every domain by default
	$can_all = permission_exists('northern911_address_all');

//download
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add($text['message-invalid_token'] ?? 'Invalid token, please try again', 'negative');
			header('Location: e911_export.php');
			exit;
		}
		$customer_number = e911_customer_number();
		if ($customer_number === '') {
			message::add('Set the Northern 911 customer number in Default Settings before exporting.', 'negative');
			header('Location: e911_export.php');
			exit;
		}
		$domain_uuid = $can_all ? ($_POST['domain_uuid'] ?? '') : $_SESSION['domain_uuid'];
		$scope = ($_POST['scope'] ?? '') == 'pending' ? 'pending' : 'all';
		$left_out = [];
		$rows = e911_export_rows($domain_uuid, $scope, $left_out);

		if (empty($rows)) {
			message::add('Nothing to export with those options.', 'negative');
			header('Location: e911_export.php');
			exit;
		}

		$csv = '';
		$uuids = [];
		foreach ($rows as $row) {
			$csv .= e911_csv_line(e911_carrier_row($row, $customer_number));
			$uuids[] = $row['northern911_address_uuid'];
		}

		if (!empty($_POST['mark_exported'])) {
			e911_mark_exported($uuids);
		}

		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="northern911-'.date('Y-m-d-Hi').'.csv"');
		header('Content-Length: '.strlen($csv));
		echo $csv;
		exit;
	}

//preview counts
	$domain_uuid = $can_all ? ($_GET['domain_uuid'] ?? '') : $_SESSION['domain_uuid'];
	$left_out_all = [];
	$count_all = count(e911_export_rows($domain_uuid, 'all', $left_out_all));
	$left_out_pending = [];
	$count_pending = count(e911_export_rows($domain_uuid, 'pending', $left_out_pending));

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-e911_export'];
	require_once "resources/header.php";
	e911_styles();

	echo "<form method='post'>\n";
	$back = 'e911_addresses.php';
	$buttons = e911_button($text['button-back'], $back, e911_icon('back', 'chevron-left'), ['id' => 'btn_back']);
	if (e911_customer_number() !== '') {
		$buttons .= button::create(['type' => 'submit', 'label' => $text['button-download_csv'], 'icon' => e911_icon('download', 'download'), 'id' => 'btn_save', 'style' => 'margin-left: 15px;']);
	}
	e911_action_bar($text['title-e911_export'], $buttons);
	e911_customer_number_banner();

	echo $text['title_description-e911_export']."\n";
	echo "<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	if ($can_all) {
		echo "<tr>\n";
		echo "	<td class='vncell' width='30%' valign='top' align='left' nowrap='nowrap'>".escape($text['label-domain'])."</td>\n";
		echo "	<td class='vtable' width='70%' align='left'>\n";
		echo "		<select class='formfld' name='domain_uuid' onchange=\"window.location.href='e911_export.php?domain_uuid='+encodeURIComponent(this.value);\">\n";
		echo "			<option value=''>".escape($text['label-all_domains'])."</option>\n";
		foreach (e911_domains() as $domain) {
			$selected = ($domain['domain_uuid'] == $domain_uuid) ? " selected='selected'" : '';
			echo "			<option value='".escape($domain['domain_uuid'])."'".$selected.">".escape($domain['domain_name'].($domain['domain_enabled'] === 'true' ? '' : ' (disabled)'))."</option>\n";
		}
		echo "		</select>\n";
		echo "	</td>\n";
		echo "</tr>\n";
	}

	echo "<tr>\n";
	echo "	<td class='vncell' valign='top' align='left' nowrap='nowrap'>".escape($text['label-records'])."</td>\n";
	echo "	<td class='vtable' align='left'>\n";
	echo "		<label><input type='radio' name='scope' value='all' checked='checked'> ".escape($text['label-scope_all'])." (".$count_all.")</label><br />\n";
	echo "		<label><input type='radio' name='scope' value='pending'> ".escape($text['label-scope_pending'])." (".$count_pending.")</label>\n";
	echo "	</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "	<td class='vncell' valign='top' align='left' nowrap='nowrap'>".escape($text['label-options'])."</td>\n";
	echo "	<td class='vtable' align='left'>\n";
	echo "		<label><input type='checkbox' name='mark_exported' value='1' checked='checked'> ".escape($text['label-mark_exported'])."</label>\n";
	echo "	</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";

	if ($left_out_all) {
		echo "<br />\n";
		echo e911_badge('error', $text['message-left_out'])."\n";
		echo "<ul>\n";
		foreach ($left_out_all as $line) {
			echo "<li>".escape($line)."</li>\n";
		}
		echo "</ul>\n";
	}

	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";
	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>
