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

// Generate CSRF token for this session
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize rate limiting
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [];
}

// Handle login form submission
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = 'Invalid request token. Please refresh the page and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Validate username format (alphanumeric, dots, hyphens, underscores)
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
            $error_message = 'Invalid username format. Username can only contain letters, numbers, dots, hyphens, and underscores.';
        } elseif (empty($username) || empty($password)) {
            $error_message = 'Please enter both username and password.';
        } else {
            // Check rate limiting
            $current_time = time();
            $user_attempts = &$_SESSION['login_attempts'][$username];
            
            if (isset($user_attempts) && 
                $user_attempts['count'] >= 5 && 
                ($current_time - $user_attempts['time']) < 900) { // 15 minutes = 900 seconds
                
                $remaining_time = 900 - ($current_time - $user_attempts['time']);
                $error_message = "Too many login attempts. Please try again in " . ceil($remaining_time / 60) . " minutes.";
            } else {
                try {
                    $auth_service = new AuthService($ldap, $user_dir, $config);
                    $user = $auth_service->authenticate($username, $password);
                    
                    if ($user) {
                        // Clear login attempts on successful authentication
                        unset($_SESSION['login_attempts'][$username]);
                        
                        // Redirect to the page they were trying to access, or home
                        $redirect_url = $_SESSION['redirect_after_login'] ?? '/';
                        unset($_SESSION['redirect_after_login']);
                        redirect($redirect_url);
                    } else {
                        // Increment failed login attempts
                        if (!isset($user_attempts)) {
                            $user_attempts = ['count' => 0, 'time' => $current_time];
                        }
                        $user_attempts['count']++;
                        $user_attempts['time'] = $current_time;
                        
                        $error_message = 'Invalid username or password.';
                    }
                } catch (Exception $e) {
                    $error_message = 'Authentication error. Please try again.';
                }
            }
        }
    }
    
    // Regenerate CSRF token after form submission
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Create the login content
$login_content = new PageSection('login');
$login_content->set('error_message', $error_message);
$login_content->set('success_message', $success_message);
$login_content->set('csrf_token', $_SESSION['csrf_token']);

// Create the main page
$page = new PageSection('base');
$page->set('title', 'Login - SSH Key Authority');
$page->set('content', $login_content);
$page->set('alerts', array());
$page->set('active_user', null); // No active user on login page
$page->set('menu_items', array()); // No menu on login page

echo $page->generate();
