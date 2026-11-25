<?php
/*
	E911 for FusionPBX
	Import the carrier CSV. Each number is matched to a domain, then reviewed before saving.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";
	require_once __DIR__ . "/resources/e911.php";

//check permissions
	if (!permission_exists('northern911_import')) {
		echo "access denied";
		exit;
	}

//letters and digits only, for name matching
	function e911_alnum($text) {
		return preg_replace('/[^a-z0-9]/', '', strtolower((string)$text));
	}

//read the carrier csv (no header, 13 columns)
	function e911_parse_csv($path) {
		$rows = [];
		$skipped = [];
		$handle = fopen($path, 'r');
		if (!$handle) {
			return [$rows, $skipped];
		}
		$line = 0;
		while (($cols = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
			$line++;
			if ($line == 1 && isset($cols[0])) {
				$cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cols[0]);
			}
			foreach ($cols as $i => $value) {
				$value = (string)$value;
				if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
					$value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
				}
				$cols[$i] = trim(preg_replace('/\s+/', ' ', $value));
			}
			if (implode('', $cols) === '') {
				continue;
			}
			$cols = array_pad($cols, 13, '');
			$number = e911_audit::normalize($cols[2]);
			if (!e911_audit::valid_number($number)) {
				if ($line > 1) {
					$skipped[] = 'Line '.$line.': "'.$cols[2].'" is not a valid phone number';
				}
				continue;
			}
			$rows[] = [
				'line' => $line,
				'northern911_address_number' => $number,
				'northern911_address_name' => $cols[3],
				'northern911_address_street_number' => $cols[5],
				'northern911_address_unit' => $cols[6],
				'northern911_address_street_name' => $cols[7],
				'northern911_address_city' => $cols[8],
				'northern911_address_province' => strtoupper($cols[9]),
				'northern911_address_postal_code' => strtoupper($cols[10]),
				'northern911_address_location_info' => $cols[11],
				'northern911_address_enabled' => 'true',
			];
		}
		fclose($handle);
		return [$rows, $skipped];
	}

//suggest a domain for each row, then group rows by customer and domain
	function e911_match_rows($rows) {
		$domains = [];
		foreach (e911_domains() as $domain) {
			$domains[$domain['domain_uuid']] = $domain;
		}

		$existing = [];
		foreach (e911_select("select domain_uuid, northern911_address_number from v_northern911_addresses") as $row) {
			$existing[e911_audit::normalize($row['northern911_address_number'])] = $row['domain_uuid'];
		}
		$emergency = [];
		$outbound = [];
		foreach (e911_select("select domain_uuid, emergency_caller_id_number, outbound_caller_id_number from v_extensions") as $row) {
			$n = e911_audit::normalize($row['emergency_caller_id_number']);
			if ($n !== '' && !isset($emergency[$n])) {
				$emergency[$n] = $row['domain_uuid'];
			}
			$n = e911_audit::normalize($row['outbound_caller_id_number']);
			if ($n !== '' && !isset($outbound[$n])) {
				$outbound[$n] = $row['domain_uuid'];
			}
		}
		$inbound = [];
		foreach (e911_select("select domain_uuid, destination_number from v_destinations where destination_type = 'inbound'") as $row) {
			$n = e911_audit::normalize($row['destination_number']);
			if ($n !== '' && !isset($inbound[$n])) {
				$inbound[$n] = $row['domain_uuid'];
			}
		}

		//first pass, evidence from the phone number
		foreach ($rows as $i => $row) {
			$n = $row['northern911_address_number'];
			$rows[$i]['domain_uuid'] = '';
			$rows[$i]['reason'] = '';
			foreach ([[$existing, 'Already in E911 list'], [$emergency, 'Extension emergency caller ID'], [$inbound, 'Inbound destination'], [$outbound, 'Extension outbound caller ID']] as $source) {
				if (isset($source[0][$n]) && isset($domains[$source[0][$n]])) {
					$rows[$i]['domain_uuid'] = $source[0][$n];
					$rows[$i]['reason'] = $source[1];
					break;
				}
			}
		}

		//second pass, numbers with no evidence follow other numbers of the same customer
		$customer_domain = [];
		foreach ($rows as $row) {
			if ($row['domain_uuid'] !== '') {
				$customer_domain[e911_alnum($row['northern911_address_name'])][$row['domain_uuid']] = ($customer_domain[e911_alnum($row['northern911_address_name'])][$row['domain_uuid']] ?? 0) + 1;
			}
		}
		foreach ($rows as $i => $row) {
			if ($row['domain_uuid'] !== '') {
				continue;
			}
			$key = e911_alnum($row['northern911_address_name']);
			if (!empty($customer_domain[$key])) {
				arsort($customer_domain[$key]);
				$rows[$i]['domain_uuid'] = array_key_first($customer_domain[$key]);
				$rows[$i]['reason'] = 'Same customer as other numbers';
				continue;
			}
			//last try, the customer name looks like the domain name
			$best = '';
			$best_length = 0;
			foreach ($domains as $domain_uuid => $domain) {
				$labels = [e911_alnum(explode('.', $domain['domain_name'])[0]), e911_alnum($domain['domain_description'])];
				foreach ($labels as $label) {
					if (strlen($label) < 4 || strlen($key) < 4) {
						continue;
					}
					if ((strpos($key, $label) !== false || strpos($label, $key) !== false) && strlen($label) > $best_length) {
						$best = $domain_uuid;
						$best_length = strlen($label);
					}
				}
			}
			if ($best !== '') {
				$rows[$i]['domain_uuid'] = $best;
				$rows[$i]['reason'] = 'Name looks like the domain, please check';
			}
		}

		//group
		$groups = [];
		foreach ($rows as $i => $row) {
			$key = md5(e911_alnum($row['northern911_address_name']).'|'.$row['domain_uuid']);
			if (!isset($groups[$key])) {
				$groups[$key] = ['name' => $row['northern911_address_name'], 'domain_uuid' => $row['domain_uuid'], 'reasons' => [], 'rows' => []];
			}
			$groups[$key]['rows'][] = $i;
			if ($row['reason'] !== '') {
				$groups[$key]['reasons'][$row['reason']] = true;
			}
		}
		uasort($groups, function ($a, $b) {
			if (($a['domain_uuid'] === '') != ($b['domain_uuid'] === '')) {
				return $a['domain_uuid'] === '' ? -1 : 1;
			}
			return strcasecmp($a['name'], $b['name']);
		});
		return [$rows, $groups];
	}

//handle posts
	$step = 'upload';
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add('Invalid token, please try again', 'negative');
			header('Location: e911_import.php');
			exit;
		}

		//file uploaded, build the preview
		if (($_POST['action'] ?? '') == 'upload') {
			if (empty($_FILES['import_file']['tmp_name']) || !is_uploaded_file($_FILES['import_file']['tmp_name'])) {
				message::add('Choose a CSV file to upload', 'negative');
				header('Location: e911_import.php');
				exit;
			}
			list($rows, $skipped) = e911_parse_csv($_FILES['import_file']['tmp_name']);
			if (empty($rows)) {
				message::add('No valid rows were found. Save the carrier sheet as CSV and try again.', 'negative');
				header('Location: e911_import.php');
				exit;
			}
			list($rows, $groups) = e911_match_rows($rows);
			$_SESSION['e911_import'] = [
				'rows' => $rows,
				'groups' => $groups,
				'skipped' => $skipped,
				'mark_exported' => !empty($_POST['mark_exported']),
			];
			$step = 'review';
		}

		//save the reviewed rows
		if (($_POST['action'] ?? '') == 'commit' && !empty($_SESSION['e911_import'])) {
			$import = $_SESSION['e911_import'];
			$existing = [];
			foreach (e911_select("select northern911_address_uuid, domain_uuid, northern911_address_number from v_northern911_addresses") as $row) {
				$existing[$row['domain_uuid']][e911_audit::normalize($row['northern911_address_number'])][] = $row['northern911_address_uuid'];
			}
			$added = 0;
			$incomplete = 0;
			$updated = 0;
			$skipped = 0;
			$saved = [];
			foreach ($import['groups'] as $key => $group) {
				$domain_uuid = $_POST['group'][$key] ?? '';
				if (!is_uuid($domain_uuid)) {
					$skipped += count($group['rows']);
					continue;
				}
				foreach ($group['rows'] as $i) {
					$row = $import['rows'][$i];
					//addresses with missing fields are never saved, fix them in the sheet or add them by hand
					if (e911_audit::missing_fields($row)) {
						$incomplete++;
						continue;
					}
					$n = $row['northern911_address_number'];
					$data = array_intersect_key($row, array_flip(e911_fields()));
					if (!empty($existing[$domain_uuid][$n])) {
						$uuid = array_shift($existing[$domain_uuid][$n]);
						e911_save_record($domain_uuid, $data, $uuid);
						$updated++;
					}
					else {
						$uuid = e911_save_record($domain_uuid, $data);
						$added++;
					}
					$saved[] = $uuid;
				}
			}
			if ($import['mark_exported']) {
				e911_mark_exported($saved);
			}
			unset($_SESSION['e911_import']);
			message::add('Import finished: '.$added.' added, '.$updated.' updated, '.$skipped.' skipped'.($incomplete ? ', '.$incomplete.' left out because of missing fields' : '').'.', $incomplete ? 'alert' : 'positive');
			header('Location: e911_addresses.php');
			exit;
		}
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = 'E911 Import';
	require_once "resources/header.php";
	e911_styles();

	if ($step == 'upload') {
		echo "<form method='post' enctype='multipart/form-data'>\n";
		$buttons = e911_button('Back', 'e911_addresses.php', 'chevron-left');
		$buttons .= button::create(['type' => 'submit', 'label' => 'Upload', 'icon' => e911_icon('upload', 'upload'), 'id' => 'btn_save', 'style' => 'margin-left: 15px;']);
		e911_action_bar('E911 Import', $buttons);
		e911_customer_number_banner();

		echo "<p>Upload the same CSV you send to the carrier: no header row, 13 columns, A to M. Columns A, B, E and M are ignored on import because the export always fills them in (customer number, A, blank, N).</p>\n";
		echo "<p>You will see a review screen before anything is saved. Each number is matched to a domain using existing E911 records, extension emergency and outbound caller ID, inbound destinations and finally the customer name. Numbers already in the E911 list for that domain are updated, the rest are added.</p>\n";
		echo "<div class='card'>\n";
		echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
		echo "<tr><td class='vncell' width='30%'>CSV File</td><td class='vtable' width='70%'><input type='file' name='import_file' accept='.csv,text/csv' class='formfld' required='required'></td></tr>\n";
		echo "<tr><td class='vncell'>Already With Carrier</td><td class='vtable'><label><input type='checkbox' name='mark_exported' value='1' checked='checked'> These numbers are already in the carrier database, so mark them as exported</label></td></tr>\n";
		echo "</table>\n";
		echo "</div>\n";
		echo "<input type='hidden' name='action' value='upload'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo "</form>\n";
	}

	if ($step == 'review') {
		$import = $_SESSION['e911_import'];
		$domains = e911_domains();
		$unmatched = 0;
		foreach ($import['groups'] as $group) {
			if ($group['domain_uuid'] === '') {
				$unmatched += count($group['rows']);
			}
		}

		echo "<form method='post'>\n";
		$buttons = e911_button('Cancel', 'e911_import.php', 'times');
		$buttons .= button::create(['type' => 'submit', 'label' => 'Import', 'icon' => e911_icon('save', 'check'), 'id' => 'btn_save', 'style' => 'margin-left: 15px;']);
		e911_action_bar('E911 Import: Review', $buttons);

		echo "<p>".count($import['rows'])." numbers in ".count($import['groups'])." groups. ";
		echo $unmatched ? "<b>".$unmatched." numbers could not be matched</b> and are listed first. Pick a domain or leave them on Skip." : "Every number was matched to a domain.";
		echo " Check the suggestions, especially any marked as a name match.</p>\n";
		if (!empty($import['skipped'])) {
			echo "<p>".e911_badge('warning')." ".count($import['skipped'])." lines were left out: ".escape(implode('; ', $import['skipped']))."</p>\n";
		}

		echo "<div class='card'>\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'>\n";
		echo "	<th>Customer</th>\n";
		echo "	<th>Numbers and Addresses</th>\n";
		echo "	<th>Domain</th>\n";
		echo "	<th>Matched By</th>\n";
		echo "</tr>\n";
		foreach ($import['groups'] as $key => $group) {
			echo "<tr class='list-row'>\n";
			echo "	<td valign='top'><b>".escape($group['name'])."</b></td>\n";
			echo "	<td valign='top'>";
			foreach ($group['rows'] as $i) {
				$row = $import['rows'][$i];
				$missing = e911_audit::missing_fields($row);
				echo escape(e911_format_number($row['northern911_address_number'])).": ".escape(e911_format_address($row));
				if ($missing) {
					echo " ".e911_badge('error', 'Missing '.implode(', ', $missing).', will be skipped');
				}
				echo "<br>";
			}
			echo "</td>\n";
			echo "	<td valign='top'><select class='formfld' name='group[".escape($key)."]'>\n";
			echo "		<option value=''>Skip</option>\n";
			foreach ($domains as $domain) {
				$selected = ($domain['domain_uuid'] == $group['domain_uuid']) ? " selected='selected'" : '';
				echo "		<option value='".escape($domain['domain_uuid'])."'".$selected.">".escape($domain['domain_name'].($domain['domain_enabled'] === 'true' ? '' : ' (disabled)'))."</option>\n";
			}
			echo "	</select></td>\n";
			echo "	<td valign='top'>".($group['domain_uuid'] === '' ? e911_badge('warning', 'No match') : escape(implode(', ', array_keys($group['reasons']))))."</td>\n";
			echo "</tr>\n";
		}
		echo "</table>\n";
		echo "</div>\n";
		echo "<input type='hidden' name='action' value='commit'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo "</form>\n";
	}

	echo "<br>\n";

//include the footer
	require_once "resources/footer.php";

?>
