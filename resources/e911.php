<?php
/*
	E911 for FusionPBX
	Shared helpers and the compliance audit used by every page in this app.
*/

if (!function_exists('e911_db')) {

	//database access
	function e911_db() {
		static $db = null;
		if ($db === null) {
			$db = method_exists('database', 'new') ? database::new() : new database;
		}
		return $db;
	}

	function e911_select($sql, $parameters = null, $mode = 'all') {
		$result = e911_db()->select($sql, $parameters, $mode);
		if ($mode === 'all') {
			return is_array($result) ? $result : [];
		}
		return $result;
	}

	function e911_execute($sql, $parameters = null) {
		return e911_db()->execute($sql, $parameters);
	}

	//settings stored under Default Settings, category e911
	function e911_setting($subcategory, $default) {
		$sql = "select default_setting_value from v_default_settings ";
		$sql .= "where default_setting_category = 'e911' and default_setting_subcategory = :subcategory ";
		$sql .= "and cast(default_setting_enabled as text) = 'true' limit 1";
		$value = e911_select($sql, ['subcategory' => $subcategory], 'column');
		return ($value === false || $value === null || $value === '') ? $default : $value;
	}

	function e911_domains() {
		$sql = "select domain_uuid, domain_name, domain_description, cast(domain_enabled as text) as domain_enabled ";
		$sql .= "from v_domains order by domain_name asc";
		return e911_select($sql);
	}

	//record fields, in the order of the carrier file where it applies
	function e911_fields() {
		return [
			'e911_address_number', 'e911_address_name', 'e911_address_street_number', 'e911_address_unit',
			'e911_address_street_name', 'e911_address_city', 'e911_address_province', 'e911_address_postal_code',
			'e911_address_location_info', 'e911_address_enabled', 'e911_address_description',
		];
	}

	//insert or update one record, returns its uuid
	function e911_save_record($domain_uuid, $data, $uuid = null) {
		$parameters = [];
		foreach (e911_fields() as $field) {
			if (array_key_exists($field, $data)) {
				$value = str_replace(["\r", "\n"], ' ', (string)$data[$field]);
				$parameters[$field] = trim($value);
			}
		}
		$parameters['domain_uuid'] = $domain_uuid;
		$user_uuid = (isset($_SESSION['user_uuid']) && is_uuid($_SESSION['user_uuid'])) ? $_SESSION['user_uuid'] : null;

		if (!empty($uuid)) {
			$sets = [];
			foreach (array_keys($parameters) as $field) {
				$sets[] = $field." = :".$field;
			}
			$sql = "update v_e911_addresses set ".implode(', ', $sets).", update_date = now(), update_user = :update_user ";
			$sql .= "where e911_address_uuid = :e911_address_uuid";
			$parameters['update_user'] = $user_uuid;
			$parameters['e911_address_uuid'] = $uuid;
			e911_execute($sql, $parameters);
			return $uuid;
		}

		$uuid = uuid();
		$parameters['e911_address_uuid'] = $uuid;
		$columns = array_keys($parameters);
		$sql = "insert into v_e911_addresses (".implode(', ', $columns).", insert_date, insert_user) ";
		$sql .= "values (:".implode(', :', $columns).", now(), :insert_user)";
		$parameters['insert_user'] = $user_uuid;
		e911_execute($sql, $parameters);
		return $uuid;
	}

	function e911_mark_exported($uuids) {
		foreach ($uuids as $uuid) {
			if (is_uuid($uuid)) {
				e911_execute("update v_e911_addresses set e911_address_exported = now() where e911_address_uuid = :uuid", ['uuid' => $uuid]);
			}
		}
	}

	//carrier customer number, column A of the export
	function e911_customer_number() {
		return trim((string)e911_setting('account_id', ''));
	}

	//one row of the carrier file: A customer number, B always A, E always blank, M always N
	function e911_carrier_row($row, $customer_number) {
		return [
			$customer_number,
			'A',
			e911_audit::normalize($row['e911_address_number']),
			$row['e911_address_name'],
			'',
			$row['e911_address_street_number'],
			$row['e911_address_unit'],
			$row['e911_address_street_name'],
			$row['e911_address_city'],
			$row['e911_address_province'],
			$row['e911_address_postal_code'],
			$row['e911_address_location_info'],
			'N',
		];
	}

	//warning banner when the customer number is not set
	function e911_customer_number_banner() {
		if (e911_customer_number() !== '') {
			return;
		}
		echo "<div style='background: #fdecea; border: 1px solid #c0392b; color: #922b21; border-radius: 6px; padding: 12px 15px; margin: 0 0 15px 0;'>\n";
		echo "<b>E911 customer number is not set.</b> Exports are blocked until it is. ";
		if (permission_exists('default_setting_view')) {
			echo "Go to <a href='/core/default_settings/default_settings.php?search=e911'>Advanced &gt; Default Settings</a>, find category <b>e911</b>, subcategory <b>account_id</b>, enter your carrier customer number and enable it.";
		}
		else {
			echo "Ask your administrator to set it under Default Settings, category e911, subcategory account_id.";
		}
		echo "\n</div>\n";
	}

	//one csv line, quoting only when needed (same as Excel), ending in CRLF
	function e911_csv_line($values) {
		$cells = [];
		foreach ($values as $value) {
			$value = (string)$value;
			if (strpbrk($value, ",\"\r\n") !== false) {
				$value = '"'.str_replace('"', '""', $value).'"';
			}
			$cells[] = $value;
		}
		return implode(',', $cells)."\r\n";
	}

	//display helpers
	function e911_format_number($number) {
		return preg_match('/^(\d{3})(\d{3})(\d{4})$/', (string)$number, $m) ? $m[1].'-'.$m[2].'-'.$m[3] : (string)$number;
	}

	function e911_format_address($row) {
		$unit = trim((string)($row['e911_address_unit'] ?? ''));
		$line = trim(($row['e911_address_street_number'] ?? '').' '.($row['e911_address_street_name'] ?? ''));
		if ($unit !== '' && strtoupper($unit) !== 'N/A') {
			$line .= ', '.(preg_match('/^\d/', $unit) ? 'Unit '.$unit : $unit);
		}
		$city = trim(($row['e911_address_city'] ?? '').' '.($row['e911_address_province'] ?? '').' '.($row['e911_address_postal_code'] ?? ''));
		return trim($line.', '.$city, ', ');
	}

	function e911_badge($severity, $text = null) {
		$labels = ['error' => 'Error', 'warning' => 'Warning', 'notice' => 'Notice', 'ok' => 'OK', 'exempt' => 'Exempt', 'disabled' => 'Disabled'];
		$text = $text ?? ($labels[$severity] ?? $severity);
		return "<span class='e911-badge e911-".escape($severity)."'>".escape($text)."</span>";
	}

	function e911_button($label, $url, $icon = null) {
		$html = "<button type='button' class='btn btn-default' onclick=\"window.location.href='".escape($url)."';\">";
		if ($icon) {
			$html .= "<span class='fas fa-".escape($icon)." fa-fw'></span> ";
		}
		return $html.escape($label)."</button>\n";
	}

	function e911_action_bar($heading, $buttons = '') {
		echo "<div class='action_bar' id='action_bar'>\n";
		echo "	<div class='heading'><b>".escape($heading)."</b></div>\n";
		echo "	<div class='actions'>\n".$buttons."	</div>\n";
		echo "	<div style='clear: both;'></div>\n";
		echo "</div>\n";
	}

	function e911_styles() {
		echo "<style>\n";
		echo ".e911-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; color: #fff; white-space: nowrap; }\n";
		echo ".e911-error { background: #c0392b; } .e911-warning { background: #d68910; } .e911-notice { background: #5d6d7e; }\n";
		echo ".e911-ok { background: #1e8449; } .e911-exempt, .e911-disabled { background: #95a5a6; }\n";
		echo ".e911-cards { display: flex; flex-wrap: wrap; gap: 12px; margin: 5px 0 20px 0; }\n";
		echo ".e911-card { flex: 1 1 140px; border: 1px solid rgba(0,0,0,0.12); border-radius: 6px; padding: 12px 15px; }\n";
		echo ".e911-card .e911-num { font-size: 26px; font-weight: 700; line-height: 1.2; }\n";
		echo ".e911-card .e911-lbl { font-size: 12px; opacity: 0.75; }\n";
		echo ".e911-msgs { margin: 0; padding-left: 0; list-style: none; } .e911-msgs li { margin: 2px 0; }\n";
		echo ".e911-section { margin: 25px 0 8px 0; font-weight: 700; font-size: 14px; }\n";
		echo ".e911-muted { opacity: 0.55; }\n";
		echo "</style>\n";
	}

	//compliance audit
	class e911_audit {

		public $domains = [];			//domain_uuid => domain row
		public $records = [];			//e911_address_uuid => record row (all domains)
		public $extensions = [];		//extension_uuid => extension row (domains in scope)
		public $issues = [];			//list of issues
		public $summary = [];			//domain_uuid => counts and status
		public $record_usage = [];		//e911_address_uuid => list of extensions using it
		public $record_issues = [];		//e911_address_uuid => list of issues
		public $extension_status = [];	//extension_uuid => [severity, message]

		private static $rank = ['ok' => 0, 'exempt' => 0, 'disabled' => 0, 'notice' => 1, 'warning' => 2, 'error' => 3];
		private static $canada = ['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','QC','SK','YT'];

		public static function normalize($number) {
			$digits = preg_replace('/\D/', '', (string)$number);
			if (strlen($digits) == 11 && $digits[0] == '1') {
				$digits = substr($digits, 1);
			}
			return $digits;
		}

		public static function valid_number($number) {
			return (bool)preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', (string)$number);
		}

		public static function missing_fields($row) {
			$required = [
				'e911_address_name' => 'name',
				'e911_address_street_number' => 'street number',
				'e911_address_street_name' => 'street name',
				'e911_address_city' => 'city',
				'e911_address_province' => 'province',
				'e911_address_postal_code' => 'postal code',
			];
			$missing = [];
			foreach ($required as $field => $label) {
				if (trim((string)($row[$field] ?? '')) === '') {
					$missing[] = $label;
				}
			}
			return $missing;
		}

		//returns [severity, message] or null
		public static function postal_problem($province, $postal) {
			$province = strtoupper(trim((string)$province));
			$postal = strtoupper(trim((string)$postal));
			if ($postal === '') {
				return null;
			}
			if (in_array($province, self::$canada)) {
				if (preg_match('/^[A-Z]\d[A-Z] \d[A-Z]\d$/', $postal)) {
					return null;
				}
				if (preg_match('/^([A-Z]\d[A-Z])(\d[A-Z]\d)$/', $postal, $m)) {
					return ['notice', "Postal code ".$postal." has no space, carrier may expect ".$m[1]." ".$m[2]];
				}
				return ['error', "Postal code ".$postal." is not a valid Canadian postal code"];
			}
			if (strlen($province) == 2 && !preg_match('/^\d{5}(-\d{4})?$/', $postal)) {
				return ['warning', "Postal code ".$postal." does not look like a valid ZIP code for ".$province];
			}
			return null;
		}

		public function domain_name($domain_uuid) {
			return $this->domains[$domain_uuid]['domain_name'] ?? '';
		}

		private function add_issue($domain_uuid, $severity, $type, $object, $ref, $label, $number, $message) {
			$issue = [
				'domain_uuid' => $domain_uuid,
				'severity' => $severity,
				'type' => $type,
				'object' => $object,
				'ref' => $ref,
				'label' => $label,
				'number' => $number,
				'message' => $message,
			];
			$this->issues[] = $issue;
			if (isset($this->summary[$domain_uuid][$severity])) {
				$this->summary[$domain_uuid][$severity]++;
			}
			if ($object == 'record') {
				$this->record_issues[$ref][] = $issue;
			}
		}

		public static function worst($issues) {
			$worst = 'ok';
			foreach ($issues as $issue) {
				if (self::$rank[$issue['severity']] > self::$rank[$worst]) {
					$worst = $issue['severity'];
				}
			}
			return $worst;
		}

		//run the audit, for one domain or for every enabled domain
		public function run($only_domain_uuid = null) {
			$exempt_tag = strtolower(trim((string)e911_setting('exempt_tag', 'no911')));

			//domains in scope
			foreach (e911_domains() as $domain) {
				$this->domains[$domain['domain_uuid']] = $domain;
				$in_scope = $only_domain_uuid ? ($domain['domain_uuid'] == $only_domain_uuid) : ($domain['domain_enabled'] === 'true');
				if ($in_scope) {
					$this->summary[$domain['domain_uuid']] = [
						'records' => 0, 'records_used' => 0, 'extensions' => 0, 'extensions_ok' => 0,
						'exempt' => 0, 'error' => 0, 'warning' => 0, 'notice' => 0, 'status' => 'ok',
					];
				}
			}

			//every record, so numbers registered in another domain can be found
			$sql = "select *, cast(e911_address_exported as text) as exported_text from v_e911_addresses ";
			$sql .= "order by e911_address_name asc, e911_address_number asc";
			$by_number = [];
			$by_domain_number = [];
			foreach (e911_select($sql) as $row) {
				$uuid = $row['e911_address_uuid'];
				$row['number'] = self::normalize($row['e911_address_number']);
				$row['enabled'] = ($row['e911_address_enabled'] !== 'false');
				$this->records[$uuid] = $row;
				$this->record_usage[$uuid] = [];
				$this->record_issues[$uuid] = [];
				if ($row['enabled']) {
					$by_number[$row['number']][] = $uuid;
					$by_domain_number[$row['domain_uuid']][$row['number']][] = $uuid;
				}
			}

			//inbound destinations per domain
			$destinations = [];
			$sql = "select domain_uuid, destination_number from v_destinations where destination_type = 'inbound'";
			$parameters = null;
			if ($only_domain_uuid) {
				$sql .= " and domain_uuid = :domain_uuid";
				$parameters = ['domain_uuid' => $only_domain_uuid];
			}
			foreach (e911_select($sql, $parameters) as $row) {
				$number = self::normalize($row['destination_number']);
				if ($number !== '') {
					$destinations[$row['domain_uuid']][$number] = true;
				}
			}

			//enabled extensions in scope
			$sql = "select extension_uuid, domain_uuid, extension, effective_caller_id_name, ";
			$sql .= "emergency_caller_id_number, outbound_caller_id_number, description ";
			$sql .= "from v_extensions where cast(enabled as text) = 'true'";
			$parameters = null;
			if ($only_domain_uuid) {
				$sql .= " and domain_uuid = :domain_uuid";
				$parameters = ['domain_uuid' => $only_domain_uuid];
			}
			$extensions = e911_select($sql, $parameters);
			usort($extensions, function ($a, $b) { return strnatcmp($a['extension'], $b['extension']); });

			foreach ($extensions as $ext) {
				$domain_uuid = $ext['domain_uuid'];
				if (!isset($this->summary[$domain_uuid])) {
					continue;
				}
				$uuid = $ext['extension_uuid'];
				$this->extensions[$uuid] = $ext;
				$label = $ext['extension'].(trim((string)$ext['effective_caller_id_name']) !== '' ? ' ('.$ext['effective_caller_id_name'].')' : '');

				if ($exempt_tag !== '' && strpos(strtolower((string)$ext['description']), $exempt_tag) !== false) {
					$this->summary[$domain_uuid]['exempt']++;
					$this->extension_status[$uuid] = ['exempt', 'Skipped, description contains "'.$exempt_tag.'"'];
					continue;
				}
				$this->summary[$domain_uuid]['extensions']++;

				$raw = trim((string)$ext['emergency_caller_id_number']);
				$number = self::normalize($raw);
				if ($raw === '') {
					$message = 'Extension '.$label.' has no emergency caller ID number';
					$this->add_issue($domain_uuid, 'error', 'ext_missing', 'extension', $uuid, $label, '', $message);
					$this->extension_status[$uuid] = ['error', 'No emergency caller ID number set'];
				}
				elseif (!self::valid_number($number)) {
					$message = 'Extension '.$label.' emergency number "'.$raw.'" is not a valid 10 digit number';
					$this->add_issue($domain_uuid, 'error', 'ext_invalid', 'extension', $uuid, $label, $raw, $message);
					$this->extension_status[$uuid] = ['error', 'Emergency number "'.$raw.'" is not a valid 10 digit number'];
				}
				elseif (isset($by_domain_number[$domain_uuid][$number])) {
					foreach ($by_domain_number[$domain_uuid][$number] as $record_uuid) {
						$this->record_usage[$record_uuid][] = $label;
					}
					$this->summary[$domain_uuid]['extensions_ok']++;
					$this->extension_status[$uuid] = ['ok', 'Registered'];
				}
				elseif (isset($by_number[$number])) {
					$other = [];
					foreach ($by_number[$number] as $record_uuid) {
						$other[] = $this->domain_name($this->records[$record_uuid]['domain_uuid']);
					}
					$other = implode(', ', array_unique($other));
					$message = 'Extension '.$label.' uses '.e911_format_number($number).', which is registered under another domain ('.$other.')';
					$this->add_issue($domain_uuid, 'error', 'ext_other_domain', 'extension', $uuid, $label, $number, $message);
					$this->extension_status[$uuid] = ['error', e911_format_number($number).' is registered under '.$other];
				}
				else {
					$message = 'Extension '.$label.' uses '.e911_format_number($number).', which has no E911 address';
					$this->add_issue($domain_uuid, 'error', 'ext_unregistered', 'extension', $uuid, $label, $number, $message);
					$this->extension_status[$uuid] = ['error', e911_format_number($number).' has no E911 address'];
				}
			}

			//records in scope
			foreach ($this->records as $uuid => $row) {
				$domain_uuid = $row['domain_uuid'];
				if (!isset($this->summary[$domain_uuid])) {
					continue;
				}
				$number = $row['number'];
				$label = e911_format_number($number);
				$this->summary[$domain_uuid]['records']++;

				if (!$row['enabled']) {
					$this->add_issue($domain_uuid, 'notice', 'rec_disabled', 'record', $uuid, $label, $number, $label.' is disabled and is not counted or exported');
					continue;
				}
				if (!self::valid_number($number)) {
					$this->add_issue($domain_uuid, 'error', 'rec_invalid', 'record', $uuid, $label, $number, $label.' is not a valid 10 digit number');
				}
				if (count($by_number[$number] ?? []) > 1) {
					$where = [];
					foreach ($by_number[$number] as $dupe_uuid) {
						$where[] = $this->domain_name($this->records[$dupe_uuid]['domain_uuid']);
					}
					$message = $label.' is registered '.count($by_number[$number]).' times with different addresses ('.implode(', ', array_unique($where)).'). A number can only have one 911 address';
					$this->add_issue($domain_uuid, 'error', 'rec_duplicate', 'record', $uuid, $label, $number, $message);
				}
				$missing = self::missing_fields($row);
				if ($missing) {
					$this->add_issue($domain_uuid, 'error', 'rec_incomplete', 'record', $uuid, $label, $number, $label.' is missing: '.implode(', ', $missing));
				}
				$postal = self::postal_problem($row['e911_address_province'], $row['e911_address_postal_code']);
				if ($postal) {
					$this->add_issue($domain_uuid, $postal[0], 'rec_postal', 'record', $uuid, $label, $number, $label.': '.$postal[1]);
				}
				if (empty($this->record_usage[$uuid])) {
					$this->add_issue($domain_uuid, 'warning', 'rec_unused', 'record', $uuid, $label, $number, $label.' has an E911 address but no extension uses it as its emergency number');
				}
				else {
					$this->summary[$domain_uuid]['records_used']++;
				}
				if (!empty($destinations[$domain_uuid]) && !isset($destinations[$domain_uuid][$number])) {
					$this->add_issue($domain_uuid, 'warning', 'rec_not_did', 'record', $uuid, $label, $number, $label.' is not an inbound destination in this domain');
				}
				$changed = $row['update_date'] ?: $row['insert_date'];
				if (empty($row['exported_text'])) {
					$this->add_issue($domain_uuid, 'notice', 'rec_not_sent', 'record', $uuid, $label, $number, $label.' has not been exported to the carrier yet');
				}
				elseif ($changed && strtotime($changed) > strtotime($row['exported_text'])) {
					$this->add_issue($domain_uuid, 'notice', 'rec_changed', 'record', $uuid, $label, $number, $label.' changed after the last carrier export');
				}
			}

			//domain status
			foreach ($this->summary as $domain_uuid => $counts) {
				if ($counts['error'] > 0) {
					$status = 'error';
				}
				elseif ($counts['warning'] > 0) {
					$status = 'warning';
				}
				elseif ($counts['extensions'] == 0 && $counts['records'] == 0) {
					$status = 'empty';
				}
				else {
					$status = 'ok';
				}
				$this->summary[$domain_uuid]['status'] = $status;
			}

			//errors first
			usort($this->issues, function ($a, $b) {
				$rank = self::$rank[$b['severity']] - self::$rank[$a['severity']];
				if ($rank != 0) {
					return $rank;
				}
				$domain = strcmp($this->domain_name($a['domain_uuid']), $this->domain_name($b['domain_uuid']));
				return $domain != 0 ? $domain : strnatcmp($a['label'], $b['label']);
			});
		}

		public static function status_badge($status) {
			$map = [
				'error' => ['error', 'Not compliant'],
				'warning' => ['warning', 'Needs review'],
				'ok' => ['ok', 'Compliant'],
				'empty' => ['exempt', 'No extensions'],
			];
			$item = $map[$status] ?? ['notice', $status];
			return e911_badge($item[0], $item[1]);
		}
	}

}
