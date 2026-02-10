<?php

class ResponseSecurityHeaders {
	private const DEFAULT_CSP = "default-src 'self'";

	/**
	 * Apply baseline security headers for all web responses.
	 *
	 * @param array $config Application configuration
	 */
	public function apply(array $config) {
		if(headers_sent()) {
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
		if(!$hsts_enabled || !$this->is_https_request()) {
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
	private function is_https_request() {
		if(isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && $_SERVER['HTTPS'] !== '') {
			return true;
		}
		if(isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
			return true;
		}
		if(isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
			$proto = strtolower(trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO']));
			if($proto === 'https') {
				return true;
			}
		}
		return false;
	}
}
