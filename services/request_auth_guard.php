<?php

class RequestAuthGuard {
	private $auth_service;
	private $public_routes;

	public function __construct($auth_service, $public_routes) {
		$this->auth_service = $auth_service;
		$this->public_routes = $public_routes;
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
				$pattern = preg_replace('/\{[^}]+\}/', '[^/]+', $route);
				if(preg_match('|^'.$pattern.'$|', $request_path)) {
					return true;
				}
			}
		}
		return false;
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
