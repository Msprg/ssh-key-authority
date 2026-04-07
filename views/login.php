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

$login_flow = new LoginFlowService($auth_service);
$login_state = $login_flow->handle_request($_SERVER['REQUEST_METHOD'], $_POST);
$error_message = $login_state['error_message'];
$success_message = $login_state['success_message'];

// Create the login content
$login_content = new PageSection('login');
$login_content->set('error_message', $error_message);
$login_content->set('success_message', $success_message);
$login_content->set('csrf_token', $login_state['csrf_token']);

// Create the main page
$page = new PageSection('base');
$page->set('title', 'Login - SSH Key Authority');
$page->set('content', $login_content);
$page->set('alerts', array());
$page->set('active_user', null); // No active user on login page
$page->set('menu_items', array()); // No menu on login page

echo $page->generate();
