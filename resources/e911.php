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

	//settings stored under Default Settings, category northern911
	function e911_setting($subcategory, $default) {
		$sql = "select default_setting_value from v_default_settings ";
		$sql .= "where default_setting_category = 'northern911' and default_setting_subcategory = :subcategory ";
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
			'northern911_address_number', 'northern911_address_name', 'northern911_address_street_number', 'northern911_address_unit',
			'northern911_address_street_name', 'northern911_address_city', 'northern911_address_province', 'northern911_address_postal_code',
			'northern911_address_location_info', 'northern911_address_enabled', 'northern911_address_description',
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
			$sql = "update v_northern911_addresses set ".implode(', ', $sets).", update_date = now(), update_user = :update_user ";
			$sql .= "where northern911_address_uuid = :northern911_address_uuid";
			$parameters['update_user'] = $user_uuid;
			$parameters['northern911_address_uuid'] = $uuid;
			e911_execute($sql, $parameters);
			return $uuid;
		}

		$uuid = uuid();
		$parameters['northern911_address_uuid'] = $uuid;
		$columns = array_keys($parameters);
		$sql = "insert into v_northern911_addresses (".implode(', ', $columns).", insert_date, insert_user) ";
		$sql .= "values (:".implode(', :', $columns).", now(), :insert_user)";
		$parameters['insert_user'] = $user_uuid;
		e911_execute($sql, $parameters);
		return $uuid;
	}

	function e911_mark_exported($uuids) {
		foreach ($uuids as $uuid) {
			if (is_uuid($uuid)) {
				e911_execute("update v_northern911_addresses set northern911_address_exported = now() where northern911_address_uuid = :uuid", ['uuid' => $uuid]);
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
			e911_audit::normalize($row['northern911_address_number']),
			$row['northern911_address_name'],
			'',
			$row['northern911_address_street_number'],
			$row['northern911_address_unit'],
			$row['northern911_address_street_name'],
			$row['northern911_address_city'],
			$row['northern911_address_province'],
			$row['northern911_address_postal_code'],
			$row['northern911_address_location_info'],
			'N',
		];
	}

	//warning banner when the customer number is not set
	function e911_customer_number_banner() {
		if (e911_customer_number() !== '') {
			return;
		}
		echo "<div style='background: #fdecea; border: 1px solid #c0392b; color: #922b21; border-radius: 6px; padding: 12px 15px; margin: 0 0 15px 0;'>\n";
		echo "<b>Northern 911 customer number is not set.</b> Exports are blocked until it is. ";
		if (permission_exists('default_setting_view')) {
			echo "Go to <a href='".PROJECT_PATH."/core/default_settings/default_settings.php?search=northern911'>Advanced &gt; Default Settings</a>, find category <b>northern911</b>, subcategory <b>account_id</b>, enter your carrier customer number and enable it.";
		}
		else {
			echo "Ask your administrator to set it under Default Settings, category northern911, subcategory account_id.";
		}
		echo "\n</div>\n";
	}

	//theme setting, works with the settings object (current FusionPBX) and the session array (older 5.x)
	function e911_theme($subcategory, $default = null) {
		global $settings;
		$value = null;
		if (isset($settings) && is_object($settings) && method_exists($settings, 'get')) {
			$value = $settings->get('theme', $subcategory, $default);
		}
		elseif (isset($_SESSION['theme'][$subcategory])) {
			$value = $_SESSION['theme'][$subcategory];
			if (is_array($value)) {
				$value = $value['text'] ?? $value['boolean'] ?? reset($value);
			}
		}
		return ($value === null || $value === '') ? $default : $value;
	}

	function e911_theme_bool($subcategory) {
		$value = e911_theme($subcategory, false);
		return $value === true || $value === 'true';
	}

	//theme button icon, for example e911_icon('add', 'plus') reads theme > button_icon_add
	function e911_icon($name, $default) {
		return e911_theme('button_icon_'.$name, $default);
	}

	//language strings from app_languages.php plus the core globals, with English fallbacks for older versions
	function e911_text() {
		$language = new text;
		$text = $language->get();
		$text = is_array($text) ? $text : [];
		$fallback = [
			'button-add' => 'Add', 'button-back' => 'Back', 'button-cancel' => 'Cancel', 'button-continue' => 'Continue',
			'button-delete' => 'Delete', 'button-edit' => 'Edit', 'button-export' => 'Export', 'button-import' => 'Import',
			'button-save' => 'Save', 'button-search' => 'Search', 'button-show_all' => 'Show All', 'button-toggle' => 'Toggle',
			'button-download_csv' => 'Download CSV', 'label-search' => 'Search...', 'label-domain' => 'Domain',
			'label-enabled' => 'Enabled', 'label-true' => 'True', 'label-false' => 'False', 'label-none' => 'None', 'label-all' => 'All',
		];
		foreach ($fallback as $key => $value) {
			if (empty($text[$key])) {
				$text[$key] = $value;
			}
		}
		return $text;
	}

	//link to a page for a record in another domain: FusionPBX switches the session domain when it sees domain_change=true
	function e911_domain_link($url, $domain_uuid) {
		if (is_uuid($domain_uuid) && $domain_uuid != ($_SESSION['domain_uuid'] ?? '') && permission_exists('domain_select')) {
			$url .= (strpos($url, '?') === false ? '?' : '&').'domain_uuid='.urlencode($domain_uuid).'&domain_change=true';
		}
		return $url;
	}

	//doughnut widgets drawn like the FusionPBX dashboard
	//each widget: id, title, center (text in the middle), data (numbers), colors, legend ([severity class, number, label], ...)
	function e911_widgets($widgets) {
		$number_color = e911_theme('dashboard_number_text_color', '#2a2a2a');
		$empty_color = e911_theme('dashboard_missed_calls_chart_sub_color', '#d4d4d4');

		echo "<script src='".PROJECT_PATH."/resources/chartjs/chart.min.js'></script>\n";
		echo "<div class='e911-widgets'>\n";
		foreach ($widgets as $widget) {
			echo "<div class='widget'>\n";
			echo "	<div class='hud_box'>\n";
			echo "		<div class='hud_content'>\n";
			echo "			<span class='hud_title'>".escape($widget['title'])."</span>\n";
			echo "			<div class='hud_chart'><canvas id='".escape($widget['id'])."'></canvas></div>\n";
			echo "			<div class='e911-legend'>";
			foreach ($widget['legend'] as $item) {
				echo "<span><span class='e911-".escape($item[0])."'><b>".(int)$item[1]."</b></span> ".escape($item[2])."</span> ";
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
			$colors = $widget['colors'];
			if (array_sum($data) == 0) {
				$data = [1];
				$colors = [$empty_color];
			}
			echo "	new Chart(document.getElementById(".json_encode($widget['id']).").getContext('2d'), {\n";
			echo "		type: 'doughnut',\n";
			echo "		data: { datasets: [{ data: ".json_encode($data).", backgroundColor: ".json_encode($colors).", borderWidth: 0 }] },\n";
			echo "		options: { responsive: true, maintainAspectRatio: false, cutout: ".json_encode((string)e911_theme('dashboard_chart_cutout', '75%')).", events: [], plugins: { legend: { display: false }, tooltip: { enabled: false }, e911_center: { text: ".json_encode((string)$widget['center'])." } } },\n";
			echo "		plugins: [center_text]\n";
			echo "	});\n";
		}
		echo "})();\n";
		echo "</script>\n";
	}

	//standard FusionPBX link button
	function e911_button($label, $url, $icon, $options = []) {
		return button::create(array_merge(['type' => 'button', 'label' => $label, 'icon' => $icon, 'link' => $url], $options));
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
		$unit = trim((string)($row['northern911_address_unit'] ?? ''));
		$line = trim(($row['northern911_address_street_number'] ?? '').' '.($row['northern911_address_street_name'] ?? ''));
		if ($unit !== '' && strtoupper($unit) !== 'N/A') {
			$line .= ', '.(preg_match('/^\d/', $unit) ? 'Unit '.$unit : $unit);
		}
		$city = trim(($row['northern911_address_city'] ?? '').' '.($row['northern911_address_province'] ?? '').' '.($row['northern911_address_postal_code'] ?? ''));
		return trim($line.', '.$city, ', ');
	}

	//severity colours and icons, also used by the dashboard charts
	function e911_colors() {
		return ['error' => '#c0392b', 'warning' => '#d68910', 'notice' => '#5d6d7e', 'ok' => '#1e8449', 'exempt' => '#95a5a6', 'disabled' => '#95a5a6', 'empty' => '#95a5a6'];
	}

	//status as an icon and coloured text, the way FusionPBX shows states
	function e911_badge($severity, $text = null) {
		$labels = ['error' => 'Error', 'warning' => 'Warning', 'notice' => 'Notice', 'ok' => 'OK', 'exempt' => 'Exempt', 'disabled' => 'Disabled'];
		$icons = ['error' => 'exclamation-circle', 'warning' => 'exclamation-triangle', 'notice' => 'info-circle', 'ok' => 'check-circle', 'exempt' => 'minus-circle', 'disabled' => 'ban'];
		$text = $text ?? ($labels[$severity] ?? $severity);
		$icon = $icons[$severity] ?? 'circle';
		return "<span class='e911-status e911-".escape($severity)."'><span class='fas fa-".$icon." fa-fw'></span> ".escape($text)."</span>";
	}

	//worst issue as text, every issue in the tooltip
	function e911_issue_summary($issues, $prefix = '') {
		if (empty($issues)) {
			return e911_badge('ok');
		}
		$worst = e911_audit::worst($issues);
		$first = null;
		$all = [];
		foreach ($issues as $issue) {
			$text = $prefix !== '' ? preg_replace('/^'.preg_quote($prefix, '/').'(:)? ?/', '', $issue['message']) : $issue['message'];
			$text = ucfirst($text);
			$all[] = ucfirst($issue['severity']).': '.$text;
			if ($first === null && $issue['severity'] == $worst) {
				$first = $text;
			}
		}
		$html = "<span title=\"".escape(implode("\n", $all))."\">".e911_badge($worst, $first);
		if (count($issues) > 1) {
			$html .= " <span class='e911-muted'>+".(count($issues) - 1)." more</span>";
		}
		return $html."</span>";
	}

	function e911_action_bar($heading, $buttons = '', $count = null) {
		echo "<div class='action_bar' id='action_bar'>\n";
		echo "	<div class='heading'><b>".escape($heading)."</b>".($count !== null ? "<div class='count'>".number_format($count)."</div>" : '')."</div>\n";
		echo "	<div class='actions'>\n".$buttons."	</div>\n";
		echo "	<div style='clear: both;'></div>\n";
		echo "</div>\n";
	}

	//section heading inside a page, with optional buttons on the right
	function e911_section($heading, $buttons = '', $count = null) {
		echo "<div class='e911-section'>\n";
		echo "	<div class='e911-section-heading'><b>".escape($heading)."</b>".($count !== null ? " <span class='e911-muted'>(".number_format($count).")</span>" : '')."</div>\n";
		echo "	<div class='e911-section-actions'>".$buttons."</div>\n";
		echo "</div>\n";
	}

	function e911_styles() {
		echo "<style>\n";
		foreach (e911_colors() as $severity => $color) {
			echo ".e911-".$severity." { color: ".$color."; }\n";
		}
		echo ".e911-status { font-weight: 600; }\n";
		echo ".e911-status b, .e911-error b, .e911-warning b, .e911-notice b, .e911-ok b, .e911-exempt b, .e911-disabled b, .e911-summary b { color: inherit; }\n";
		echo "td.e911-col-address { min-width: 220px; }\n";
		echo "td.e911-col-status { min-width: 240px; max-width: 420px; }\n";
		echo ".e911-muted { opacity: 0.55; }\n";
		echo ".e911-section { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin: 25px 0 10px 0; }\n";
		echo ".e911-section-heading { font-size: 14px; }\n";
		echo ".e911-summary { margin: 0 0 5px 0; }\n";
		echo ".e911-summary > span { margin-right: 18px; white-space: nowrap; }\n";
		echo ".e911-widgets { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 15px; margin: 0 0 10px 0; }\n";
		echo ".e911-widgets div.hud_box, .e911-widgets div.hud_content { height: auto; }\n";
		echo ".e911-widgets div.hud_chart { height: 150px; padding-top: 7px; }\n";
		echo ".e911-widgets .e911-legend { padding: 8px 10px 12px 10px; font-size: 12px; line-height: 1.7; }\n";
		echo ".e911-widgets .e911-legend span { margin: 0 6px; white-space: nowrap; }\n";
		echo "</style>\n";
	}

	//compliance audit
	class e911_audit {

		public $domains = [];			//domain_uuid => domain row
		public $records = [];			//northern911_address_uuid => record row (all domains)
		public $extensions = [];		//extension_uuid => extension row (domains in scope)
		public $issues = [];			//list of issues
		public $summary = [];			//domain_uuid => counts and status
		public $record_usage = [];		//northern911_address_uuid => list of extensions using it
		public $record_issues = [];		//northern911_address_uuid => list of issues
		public $extension_status = [];	//extension_uuid => [severity, message]
		public $exempt_tag = '';		//lower case text that exempts an extension, from Default Settings

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
				'northern911_address_name' => 'name',
				'northern911_address_street_number' => 'street number',
				'northern911_address_street_name' => 'street name',
				'northern911_address_city' => 'city',
				'northern911_address_province' => 'province',
				'northern911_address_postal_code' => 'postal code',
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
			$this->exempt_tag = $exempt_tag;

			//every record, so numbers registered in another domain can be found
			$sql = "select *, cast(northern911_address_exported as text) as exported_text from v_northern911_addresses ";
			$sql .= "order by northern911_address_name asc, northern911_address_number asc";
			$by_number = [];
			$by_domain_number = [];
			$domains_with_records = [];
			foreach (e911_select($sql) as $row) {
				$uuid = $row['northern911_address_uuid'];
				$row['number'] = self::normalize($row['northern911_address_number']);
				$row['enabled'] = ($row['northern911_address_enabled'] !== 'false');
				$this->records[$uuid] = $row;
				$this->record_usage[$uuid] = [];
				$this->record_issues[$uuid] = [];
				$domains_with_records[$row['domain_uuid']] = true;
				if ($row['enabled']) {
					$by_number[$row['number']][] = $uuid;
					$by_domain_number[$row['domain_uuid']][$row['number']][] = $uuid;
				}
			}

			//domains in scope: every enabled domain, plus disabled domains that still have E911 addresses
			foreach (e911_domains() as $domain) {
				$this->domains[$domain['domain_uuid']] = $domain;
				$enabled = ($domain['domain_enabled'] === 'true');
				if ($only_domain_uuid) {
					$in_scope = ($domain['domain_uuid'] == $only_domain_uuid);
				}
				else {
					$in_scope = $enabled || isset($domains_with_records[$domain['domain_uuid']]);
				}
				if ($in_scope) {
					$this->summary[$domain['domain_uuid']] = [
						'records' => 0, 'records_used' => 0, 'extensions' => 0, 'extensions_ok' => 0,
						'exempt' => 0, 'error' => 0, 'warning' => 0, 'notice' => 0, 'status' => 'ok',
						'enabled' => $enabled,
					];
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
				if (!isset($this->summary[$domain_uuid]) || !$this->summary[$domain_uuid]['enabled']) {
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
				//the domain is disabled: never exported, and the carrier may still hold the address
				if (!$this->summary[$domain_uuid]['enabled']) {
					$domain_label = $this->domain_name($domain_uuid);
					if (!empty($row['exported_text'])) {
						$message = $label.' belongs to disabled domain '.$domain_label.' but was exported to the carrier. Remove it from Northern 911, then delete or disable it here';
						$this->add_issue($domain_uuid, 'error', 'rec_domain_disabled', 'record', $uuid, $label, $number, $message);
					}
					else {
						$message = $label.' belongs to disabled domain '.$domain_label.' and will not be exported. Delete or disable it';
						$this->add_issue($domain_uuid, 'warning', 'rec_domain_disabled', 'record', $uuid, $label, $number, $message);
					}
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
				$postal = self::postal_problem($row['northern911_address_province'], $row['northern911_address_postal_code']);
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
				if (!$counts['enabled']) {
					$status = 'disabled';
				}
				elseif ($counts['error'] > 0) {
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
				'disabled' => ['disabled', 'Domain disabled'],
			];
			$item = $map[$status] ?? ['notice', $status];
			return e911_badge($item[0], $item[1]);
		}
	}

}
