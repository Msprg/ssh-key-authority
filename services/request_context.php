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
		$context->base_url = dirname($_SERVER['SCRIPT_NAME']);
		$context->request_url = $_SERVER['REQUEST_URI'];
		$context->relative_request_url = preg_replace('/^'.preg_quote($context->base_url, '/').'/', '/', $context->request_url);
		$context->absolute_request_url = 'http'.(isset($_SERVER['HTTPS']) ? 's' : '').'://'.$_SERVER['HTTP_HOST'].$context->request_url;
		$context->request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$context->bypass_csrf_protection = isset($_SERVER['HTTP_X_BYPASS_CSRF_PROTECTION']) && $_SERVER['HTTP_X_BYPASS_CSRF_PROTECTION'] == 1;
		return $context;
	}
}
