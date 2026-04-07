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

try {
	$user = $user_dir->get_user_by_uid($router->vars['username']);
} catch(UserNotFoundException $e) {
	require('views/error404.php');
	die;
}

$is_target_active_user = $active_user && $active_user->entity_id == $user->entity_id;
$can_admin_add_for_user = $active_user && $active_user->admin && !$is_target_active_user;
$can_submit_key = $is_target_active_user || $can_admin_add_for_user;
$key_lifecycle_service = new KeyLifecycleService();

if(isset($_POST['add_public_key'])) {
	if(!$can_submit_key) {
		require('views/error403.php');
		die;
	}
	try {
		$key_lifecycle_service->add_user_public_key($user, $_POST['add_public_key']);
		redirect();
	} catch(InvalidArgumentException $e) {
		$content = new PageSection('key_upload_fail');
		$error_message = $e->getMessage();
		if(preg_match('/^Insufficient bits in public key: (\d+) < (\d+)$/', $error_message, $matches)) {
			$actual_bits = $matches[1];
			$required_bits = $matches[2];
			$content->set('message', "The public key you submitted is of insufficient strength; it has {$actual_bits} bits but must be at least {$required_bits} bits.");
		} else {
			$content->set('message', "The public key you submitted doesn't look valid.");
		}
	} catch(BadMethodCallException $e) {
		$content = new PageSection('key_upload_fail');
		$content->set('message', "Unable to add public key: " . $e->getMessage());
	}
}

$pubkeys = $user->list_public_keys();
if(isset($router->vars['format']) && $router->vars['format'] == 'txt') {
	$page = new PageSection('entity_pubkeys_txt');
	$page->set('pubkeys', $pubkeys);
	header('Content-type: text/plain; charset=utf-8');
	echo $page->generate();
} elseif(isset($router->vars['format']) && $router->vars['format'] == 'json') {
	$page = new PageSection('entity_pubkeys_json');
	$page->set('pubkeys', $pubkeys);
	header('Content-type: application/json; charset=utf-8');
	echo $page->generate();
} else {
	$head = '<link rel="alternate" type="application/json" href="pubkeys.json" title="JSON for this page">' . "\n";
	$head .= '<link rel="alternate" type="text/plain" href="pubkeys.txt" title="TXT format for this page">' . "\n";

	if(!isset($content)) {
		$content = new PageSection('user_pubkeys');
		$content->set('user', $user);
		$content->set('pubkeys', $pubkeys);
		$content->set('admin', $active_user ? $active_user->admin : false);
		$content->set('allow_admin_add', $can_admin_add_for_user);
	}

	$page = new PageSection('base');
	$page->set('title', 'Public keys for ' . $user->name);
	$page->set('head', $head);
	$page->set('content', $content);
	$page->set('alerts', $active_user->pop_alerts());
	echo $page->generate();
}
