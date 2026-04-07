<?php

class RequestAuthGuard {
	private $auth_service;
	/**
	 * @var array<string,bool>
	 */
	private $public_routes;

	public function __construct($auth_service, $public_routes) {
		$this->auth_service = $auth_service;
		$this->public_routes = $this->normalize_public_routes($public_routes);
	}

	public function resolve_active_user($request_context) {
		$request_path = $request_context->relative_request_url;
		$active_user = $this->auth_service->getCurrentUser();

		if(!$active_user && !$this->is_public_route($request_path)) {
			$_SESSION['redirect_after_login'] = $this->sanitize_redirect_path($request_context->request_url);
			redirect('/login');
		}

		if($active_user && $request_path === '/login') {
			$redirect_url = $this->sanitize_redirect_path($_SESSION['redirect_after_login'] ?? '/');
			unset($_SESSION['redirect_after_login']);
			redirect($redirect_url);
		}

		if(!$active_user && $request_path === '/logout') {
			redirect('/login');
		}

		return $active_user;
	}

	private function is_public_route($request_path) {
		foreach($this->public_routes as $route => $is_public) {
			if($is_public) {
				$pattern = preg_quote($route, '|');
				$pattern = preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', $pattern);
				if(preg_match('|^'.$pattern.'$|', $request_path)) {
					return true;
				}
			}
		}
		return false;
	}

	private function normalize_public_routes($public_routes) {
		if(!is_array($public_routes)) {
			throw new InvalidArgumentException('Public routes must be an array of route strings or route=>bool entries.');
		}
		$normalized = array();
		foreach($public_routes as $key => $value) {
			if(is_int($key)) {
				if(!is_string($value) || $value === '') {
					throw new InvalidArgumentException('Indexed public route entries must be non-empty strings.');
				}
				$normalized[$value] = true;
				continue;
			}
			if(!is_string($key) || $key === '') {
				throw new InvalidArgumentException('Public route keys must be non-empty strings.');
			}
			if(is_bool($value)) {
				$normalized[$key] = $value;
				continue;
			}
			if(is_int($value) && ($value === 0 || $value === 1)) {
				$normalized[$key] = (bool)$value;
				continue;
			}
			if(is_string($value)) {
				$boolean_value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
				if($boolean_value !== null) {
					$normalized[$key] = $boolean_value;
					continue;
				}
			}
			throw new InvalidArgumentException('Public route value for "'.$key.'" must be boolean-like.');
		}
		return $normalized;
	}

	private function sanitize_redirect_path($candidate) {
		if(!is_string($candidate) || $candidate === '') {
			return '/';
		}
		if(strpos($candidate, "\r") !== false || strpos($candidate, "\n") !== false) {
			return '/';
		}
		$parts = parse_url($candidate);
		if($parts === false) {
			return '/';
		}
		if(isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
			return '/';
		}
		$path = $parts['path'] ?? '/';
		if($path === '' || substr($path, 0, 1) !== '/' || substr($path, 0, 2) === '//') {
			return '/';
		}
		$query = isset($parts['query']) ? '?'.$parts['query'] : '';
		$fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';
		return $path.$query.$fragment;
	}
}
