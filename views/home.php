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

$public_keys = $active_user->list_public_keys();
$admined_servers = $active_user->list_admined_servers(array('pending_requests', 'admins'));
$key_lifecycle_service = new KeyLifecycleService();

if(isset($_POST['add_public_key'])) {
	try {
		$key_lifecycle_service->add_user_public_key($active_user, $_POST['add_public_key']);
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
	}
} elseif(isset($_POST['delete_public_key'])) {
	$key_lifecycle_service->delete_entity_public_key($active_user, $public_keys, $_POST['delete_public_key']);
	redirect();
} else {
	$content = new PageSection('home');
	$content->set('user_keys', $public_keys);
	$content->set('admined_servers', $admined_servers);
	$content->set('allowed_access', $active_user->find_accessible_server_accounts());
	$content->set('uid', $active_user->uid);
}

$page = new PageSection('base');
$page->set('title', 'Keys management');
$page->set('content', $content);
$page->set('alerts', $active_user->pop_alerts());
echo $page->generate();
