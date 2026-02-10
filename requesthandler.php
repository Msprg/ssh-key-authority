<?php
##
## Copyright 2013-2017 Opera Software AS
##
## Licensed under the Apache License, Version 2.0 (the "License");
## you may not use this file except in compliance with the License.
## You may obtain a copy of the License at
##
## http://www.apache.org/licenses/LICENSE-2.0
##
## Unless required by applicable law or agreed to in writing, software
## distributed under the License is distributed on an "AS IS" BASIS,
## WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
## See the License for the specific language governing permissions and
## limitations under the License.
##

chdir(dirname(__FILE__));
require('core.php');
require('services/request_context.php');
require('services/request_auth_guard.php');
require('services/request_csrf_guard.php');
require('services/login_flow.php');
require('services/request_policy_guard.php');
require('services/request_router_dispatcher.php');
require('services/request_exception_handler.php');
require('services/key_lifecycle_service.php');
require('services/access_rule_service.php');
require('services/relation_lifecycle_service.php');
require('services/response_security_headers.php');
ob_start();

$active_user = null;
$exception_handler = new RequestExceptionHandler($active_user, $config);
set_exception_handler(array($exception_handler, 'handle'));

$request_context = RequestContext::from_globals();
$base_url = $request_context->base_url;
$request_url = $request_context->request_url;
$relative_request_url = $request_context->relative_request_url;
$absolute_request_url = $request_context->absolute_request_url;
RuntimeState::set_many(array(
	'request_context' => $request_context,
	'base_url' => $base_url,
	'request_url' => $request_url,
	'relative_request_url' => $relative_request_url,
	'absolute_request_url' => $absolute_request_url,
));

$response_security_headers = new ResponseSecurityHeaders();
$response_security_headers->apply($config);

// Initialize authentication service
$auth_service = new AuthService($ldap, $user_dir, $config);
$auth_guard = new RequestAuthGuard($auth_service, $public_routes);
$csrf_guard = new RequestCsrfGuard();
$policy_guard = new RequestPolicyGuard();
$router_dispatcher = new RequestRouterDispatcher($policy_guard);

$active_user = $auth_guard->resolve_active_user($request_context);
RuntimeState::set('active_user', $active_user);

$policy_guard->enforce_web_enabled($config);
$policy_guard->enforce_active_user_status($active_user, $relative_request_url, $absolute_request_url);

$csrf_guard->validate($active_user, $request_context, $_POST);

$router = $router_dispatcher->build_router($routes, $public_routes);
$router->handle_request($relative_request_url);
$view = $router_dispatcher->resolve_view_path($router, $base_path);
if(!is_null($view)) {
	if($policy_guard->can_render_view($router->public, $active_user)) {
		require($view);
	} else {
		require('views/error403.php');
	}
}
