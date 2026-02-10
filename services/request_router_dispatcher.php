<?php

class RequestRouterDispatcher {
	private $policy_guard;

	public function __construct($policy_guard) {
		$this->policy_guard = $policy_guard;
	}

	public function build_router($routes, $public_routes) {
		$router = new Router;
		foreach($routes as $path => $service) {
			$public = array_key_exists($path, $public_routes);
			$router->add_route($path, $service, $public);
		}
		return $router;
	}

	public function resolve_view_path($router, $base_path) {
		if(!isset($router->view)) {
			return null;
		}
		$view = path_join($base_path, 'views', $router->view.'.php');
		if(!file_exists($view)) {
			throw new Exception("View file $view missing.");
		}
		return $view;
	}
}
