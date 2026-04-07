<?php

class RequestContext {
	public $base_url;
	public $request_url;
	public $relative_request_url;
	public $absolute_request_url;
	public $request_method;
	public $bypass_csrf_protection;

	public static function from_globals() {
		$context = new self();
		$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
		$context->base_url = str_replace('\\', '/', dirname($script_name));
		if($context->base_url === '.' || $context->base_url === '/') {
			$context->base_url = '';
		}
		$context->request_url = $_SERVER['REQUEST_URI'] ?? '';
		if($context->request_url === '') {
			$context->request_url = '/';
		}
		$context->relative_request_url = preg_replace('/^'.preg_quote($context->base_url, '/').'(?=\/|$)/', '', $context->request_url);
		if($context->relative_request_url === '' || $context->relative_request_url[0] !== '/') {
			$context->relative_request_url = '/'.$context->relative_request_url;
		}
		$scheme = 'http';
		if(self::is_https_request()) {
			$scheme = 'https';
		}
		$context->absolute_request_url = $scheme.'://'.self::resolve_request_host(self::get_runtime_config()).$context->request_url;
		$context->request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$context->bypass_csrf_protection = self::should_bypass_csrf_protection();
		return $context;
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

	private static function is_https_request() {
		if(class_exists('ResponseSecurityHeaders', false)) {
			return ResponseSecurityHeaders::is_https_request();
		}
		if(isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && $_SERVER['HTTPS'] !== '') {
			return true;
		}
		if(isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
			return true;
		}
		if(isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
			$proto = strtolower(trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO']));
			if($proto === 'https' && self::is_request_from_trusted_proxy(self::get_runtime_config(), $_SERVER['REMOTE_ADDR'] ?? '')) {
				return true;
			}
		}
		return false;
	}

	private static function should_bypass_csrf_protection() {
		if(!isset($_SERVER['HTTP_X_BYPASS_CSRF_PROTECTION']) || $_SERVER['HTTP_X_BYPASS_CSRF_PROTECTION'] != 1) {
			return false;
		}
		if(defined('APP_ENV')) {
			$app_env = constant('APP_ENV');
			if($app_env === 'development' || $app_env === 'test') {
				return true;
			}
		}
		return false;
	}

	private static function resolve_request_host($config) {
		$configured_authority = self::configured_authority_from_baseurl($config);
		$http_host = self::sanitize_host_candidate($_SERVER['HTTP_HOST'] ?? '');
		if($configured_authority !== '') {
			if($http_host === '' || strcasecmp($http_host, $configured_authority) !== 0) {
				return $configured_authority;
			}
			return $http_host;
		}
		if($http_host !== '') {
			return $http_host;
		}
		$server_name = self::sanitize_host_candidate($_SERVER['SERVER_NAME'] ?? '');
		if($server_name !== '') {
			return $server_name;
		}
		return 'localhost';
	}

	private static function configured_authority_from_baseurl($config) {
		if(!is_array($config) || empty($config['web']['baseurl'])) {
			return '';
		}
		$parts = parse_url((string)$config['web']['baseurl']);
		if($parts === false || empty($parts['host'])) {
			return '';
		}
		$host = self::normalize_host_for_url((string)$parts['host']);
		if($host === '') {
			return '';
		}
		if(isset($parts['port'])) {
			return $host.':'.(int)$parts['port'];
		}
		return $host;
	}

	private static function sanitize_host_candidate($candidate) {
		$candidate = trim((string)$candidate);
		if($candidate === '' || preg_match('/[\x00-\x20\/\\\\?#@]/', $candidate)) {
			return '';
		}
		$parts = parse_url('http://'.$candidate);
		if($parts === false || empty($parts['host'])) {
			return '';
		}
		$host = self::normalize_host_for_url((string)$parts['host']);
		if($host === '') {
			return '';
		}
		if(isset($parts['port'])) {
			return $host.':'.(int)$parts['port'];
		}
		return $host;
	}

	private static function normalize_host_for_url($host) {
		if(filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			return '['.strtolower($host).']';
		}
		if(filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			return $host;
		}
		if(!preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
			return '';
		}
		return strtolower($host);
	}

	private static function is_request_from_trusted_proxy($config, $remote_addr) {
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
			$packed_ip = inet_pton($ip);
			$packed_rule = inet_pton($rule);
			if($packed_ip !== false && $packed_rule !== false) {
				return $packed_ip === $packed_rule;
			}
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
