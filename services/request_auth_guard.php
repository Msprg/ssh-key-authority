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
			$_SESSION['redirect_after_login'] = $request_context->request_url;
			redirect('/login');
		}

		if($active_user && $request_path === '/login') {
			$redirect_url = $_SESSION['redirect_after_login'] ?? '/';
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
}
