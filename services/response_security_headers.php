<?php

class ResponseSecurityHeaders {
	private const DEFAULT_CSP = "default-src 'self'";

	/**
	 * Apply baseline security headers for all web responses.
	 *
	 * @param array $config Application configuration
	 */
	public function apply(array $config) {
		if(headers_sent($file, $line)) {
			error_log(sprintf(
				'[ResponseSecurityHeaders::apply] Headers already sent in %s on line %d. Unable to apply security headers (CSP, HSTS, etc.). Current headers: %s',
				$file,
				$line,
				json_encode(headers_list())
			));
			return;
		}

		header('X-Frame-Options: DENY');
		header('X-Content-Type-Options: nosniff');
		header('Referrer-Policy: strict-origin-when-cross-origin');
		header('X-XSS-Protection: 0');
		header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()');

		$csp = $this->build_content_security_policy($config);
		if($csp === '') {
			$this->apply_hsts_if_enabled($config);
			return;
		}

		header_remove('Content-Security-Policy');
		header_remove('Content-Security-Policy-Report-Only');

		if($this->is_csp_report_only($config)) {
			header('Content-Security-Policy-Report-Only: '.$csp);
		} else {
			header('Content-Security-Policy: '.$csp);
		}

		$this->apply_hsts_if_enabled($config);
	}

	/**
	 * @param array $config
	 * @return string
	 */
	private function build_content_security_policy(array $config) {
		$policy = self::DEFAULT_CSP;
		if(isset($config['security']['content_security_policy'])) {
			$configured = trim((string)$config['security']['content_security_policy']);
			if($configured !== '') {
				$policy = $configured;
			}
		}

		$report_uri = '';
		if(isset($config['security']['content_security_policy_report_uri'])) {
			$report_uri = trim((string)$config['security']['content_security_policy_report_uri']);
		}
		if($report_uri !== '' && stripos($policy, 'report-uri ') === false && stripos($policy, 'report-to ') === false) {
			$policy .= '; report-uri '.$report_uri;
		}

		return $policy;
	}

	/**
	 * @param array $config
	 * @return bool
	 */
	private function is_csp_report_only(array $config) {
		return isset($config['security']['csp_report_only']) && (int)$config['security']['csp_report_only'] === 1;
	}

	/**
	 * @param array $config
	 */
	private function apply_hsts_if_enabled(array $config) {
		$hsts_enabled = isset($config['security']['hsts_enabled']) && (int)$config['security']['hsts_enabled'] === 1;
		if(!$hsts_enabled || !self::is_https_request()) {
			return;
		}

		$max_age = 31536000;
		if(isset($config['security']['hsts_max_age']) && is_numeric($config['security']['hsts_max_age'])) {
			$candidate = (int)$config['security']['hsts_max_age'];
			if($candidate > 0) {
				$max_age = $candidate;
			}
		}

		$hsts = 'max-age='.$max_age;
		if(isset($config['security']['hsts_include_subdomains']) && (int)$config['security']['hsts_include_subdomains'] === 1) {
			$hsts .= '; includeSubDomains';
		}
		if(isset($config['security']['hsts_preload']) && (int)$config['security']['hsts_preload'] === 1) {
			$hsts .= '; preload';
		}

		header('Strict-Transport-Security: '.$hsts);
	}

	/**
	 * @return bool
	 */
	public static function is_https_request() {
		if(isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && $_SERVER['HTTPS'] !== '') {
			return true;
		}
		if(isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
			return true;
		}
		if(isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
			$proto = strtolower(trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO']));
			if($proto === 'https' && self::is_request_from_trusted_proxy(self::get_runtime_config())) {
				return true;
			}
		}
		return false;
	}

	private static function get_runtime_config() {
		if(array_key_exists('config', $GLOBALS)) {
			return $GLOBALS['config'];
		}
		if(class_exists('RuntimeState', false)) {
			return RuntimeState::get('config');
		}
		return null;
	}

	private static function is_request_from_trusted_proxy($config) {
		$remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
		if($remote_addr === '') {
			return false;
		}
		foreach(self::trusted_proxy_rules($config) as $rule) {
			if(self::ip_matches_rule($remote_addr, $rule)) {
				return true;
			}
		}
		return false;
	}

	private static function trusted_proxy_rules($config) {
		if(!is_array($config)) {
			return array();
		}
		$raw_rules = $config['security']['trusted_proxies'] ?? array();
		if(!is_array($raw_rules)) {
			$raw_rules = explode(',', (string)$raw_rules);
		}
		$rules = array();
		foreach($raw_rules as $rule) {
			$rule = trim((string)$rule);
			if($rule !== '') {
				$rules[] = $rule;
			}
		}
		return $rules;
	}

	private static function ip_matches_rule($ip, $rule) {
		if(strpos($rule, '/') === false) {
			return $ip === $rule;
		}
		return self::ip_in_cidr($ip, $rule);
	}

	private static function ip_in_cidr($ip, $cidr) {
		$parts = explode('/', $cidr, 2);
		if(count($parts) !== 2 || $parts[1] === '') {
			return false;
		}
		$network = inet_pton($parts[0]);
		$packed_ip = inet_pton($ip);
		if($network === false || $packed_ip === false || strlen($network) !== strlen($packed_ip)) {
			return false;
		}
		$prefix = (int)$parts[1];
		$max_prefix = strlen($network) * 8;
		if($prefix < 0 || $prefix > $max_prefix) {
			return false;
		}
		$full_bytes = intdiv($prefix, 8);
		$remaining_bits = $prefix % 8;
		if($full_bytes > 0 && substr($packed_ip, 0, $full_bytes) !== substr($network, 0, $full_bytes)) {
			return false;
		}
		if($remaining_bits === 0) {
			return true;
		}
		$mask = (0xFF << (8 - $remaining_bits)) & 0xFF;
		return (ord($packed_ip[$full_bytes]) & $mask) === (ord($network[$full_bytes]) & $mask);
	}
}