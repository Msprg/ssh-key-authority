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
ob_start();
set_exception_handler('exception_handler');

// Helper function to check if a route is public
function isPublicRoute($request_path) {
    global $public_routes;
    foreach ($public_routes as $route => $is_public) {
        if ($is_public) {
            // Convert route pattern to regex for matching
            $pattern = preg_replace('/\{[^}]+\}/', '[^/]+', $route);
            if (preg_match('|^' . $pattern . '$|', $request_path)) {
                return true;
            }
        }
    }
    return false;
}

// Work out where we are on the server
$base_url = dirname($_SERVER['SCRIPT_NAME']);
$request_url = $_SERVER['REQUEST_URI'];
$relative_request_url = preg_replace('/^'.preg_quote($base_url, '/').'/', '/', $request_url);
$absolute_request_url = 'http'.(isset($_SERVER['HTTPS']) ? 's' : '').'://'.$_SERVER['HTTP_HOST'].$request_url;

// Initialize authentication service
$auth_service = new AuthService($ldap, $user_dir, $config);

// Check if user is authenticated
$active_user = $auth_service->getCurrentUser();

// If no active user and not on a public route, redirect to login
if (!$active_user && !isPublicRoute($relative_request_url)) {
    // Store the current URL to redirect back after login
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    redirect('/login');
}

// Prevent authenticated users from accessing login page (they're already logged in)
if ($active_user && $relative_request_url === '/login') {
    $redirect_url = $_SESSION['redirect_after_login'] ?? '/';
    unset($_SESSION['redirect_after_login']);
    redirect($redirect_url);
}

// Prevent logged out users from accessing logout page (they're already logged out)
if (!$active_user && $relative_request_url === '/logout') {
    // They're already logged out, just redirect to login
    redirect('/login');
}

if(empty($config['web']['enabled'])) {
	require('views/error503.php');
	die;
}

if($active_user && !$active_user->active) {
	require('views/error403.php');
}

if(!empty($_POST) && $active_user) {
	// Check CSRF token
	if(isset($_SERVER['HTTP_X_BYPASS_CSRF_PROTECTION']) && $_SERVER['HTTP_X_BYPASS_CSRF_PROTECTION'] == 1) {
		// This is being called from script, not a web browser
	} elseif(!$active_user->check_csrf_token($_POST['csrf_token'])) {
		require('views/csrf.php');
		die;
	}
}

// Route request to the correct view
$router = new Router;
foreach($routes as $path => $service) {
	$public = array_key_exists($path, $public_routes);
	$router->add_route($path, $service, $public);
}
$router->handle_request($relative_request_url);
if(isset($router->view)) {
	$view = path_join($base_path, 'views', $router->view.'.php');
	if(file_exists($view)) {
		if($router->public || ($active_user && $active_user->auth_realm == 'LDAP')) {
			require($view);
		} else {
			require('views/error403.php');
		}
	} else {
		throw new Exception("View file $view missing.");
	}
}

// Handler for uncaught exceptions
function exception_handler($e) {
	global $active_user, $config;
	$error_number = time();
	error_log("$error_number: ".str_replace("\n", "\n$error_number: ", $e));
	while(ob_get_length()) {
		ob_end_clean();
	}
	require('views/error500.php');
	die;
}
