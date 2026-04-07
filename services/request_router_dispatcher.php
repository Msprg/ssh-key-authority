<?php

class RequestRouterDispatcher {
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
		$view_name = (string)$router->view;
		if(!preg_match('/^[A-Za-z0-9_-]+$/', $view_name)) {
			throw new Exception('View not found.');
		}
		$view = path_join($base_path, 'views', $view_name.'.php');
		if(!file_exists($view)) {
			throw new Exception('View not found.');
		}
		return $view;
	}
}
