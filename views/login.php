<?php
##
## Copyright 2013-2017 Opera Software AS
## Modifications Copyright 2021 Leitwerk AG
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

// Handle login form submission
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error_message = 'Please enter both username and password.';
    } else {
        try {
            $auth_service = new AuthService($ldap, $user_dir, $config);
            $user = $auth_service->authenticate($username, $password);
            
            if ($user) {
                // Redirect to the page they were trying to access, or home
                $redirect_url = $_SESSION['redirect_after_login'] ?? '/';
                unset($_SESSION['redirect_after_login']);
                redirect($redirect_url);
            } else {
                $error_message = 'Invalid username or password.';
            }
        } catch (Exception $e) {
            $error_message = 'Authentication error. Please try again.';
        }
    }
}

// Create the login content
$login_content = new PageSection('login');
$login_content->set('error_message', $error_message);
$login_content->set('success_message', $success_message);

// Create the main page
$page = new PageSection('base');
$page->set('title', 'Login - Leitwerk Key Authority');
$page->set('content', $login_content);
$page->set('alerts', array());
$page->set('active_user', null); // No active user on login page
$page->set('menu_items', array()); // No menu on login page

echo $page->generate();
