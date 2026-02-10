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

/**
 * Load server account entities from the database, based on user input.
 *
 * @param string $text The multi-line account list as given by the user
 * @param array $errors Reference to an array where errors can be appended
 * @return array Database entities of the server accounts
 */
function find_server_accounts(string $text, array &$errors): array {
	$server_dir = RuntimeState::get('server_dir');

	$lines = explode("\n", $text);
	$accounts = [];
	$line_num = 0;
	foreach ($lines as $line) {
		$line_num++;
		$line = trim($line);
		if ($line == "") {
			continue;
		}
		if (!preg_match('/^([^@]+)@([^@]+)$/', $line, $matches)) {
			$errors[] = "Line $line_num has an invalid format: \"$line\"";
			continue;
		}
		try {
			$server = $server_dir->get_server_by_hostname($matches[2]);
			$accounts[] = $server->get_account_by_name($matches[1]);
		} catch(ServerNotFoundException $e) {
			$errors[] = "Line $line_num: Server \"{$matches[2]}\" could not be found.";
		} catch(ServerAccountNotFoundException $e) {
			$errors[] = "Line $line_num: Account \"{$matches[1]}\" could not be found on server \"{$matches[2]}\".";
		}
	}
	return $accounts;
}

try {
	$group = $group_dir->get_group_by_name($router->vars['group']);
} catch(GroupNotFoundException $e) {
	require('views/error404.php');
	die;
}
$all_users = $user_dir->list_users();
$all_groups = $group_dir->list_groups();
$all_servers = $server_dir->list_servers();
$admined_servers = $active_user->list_admined_servers();
$group_members = $group->list_members();
$group_access = $group->list_access();
$group_remote_access = $group->list_remote_access();
$group_admins = $group->list_admins();
$group_admin = $active_user->admin_of($group);
$access_rule_service = new AccessRuleService();
$relation_lifecycle_service = new RelationLifecycleService();

if(isset($_POST['add_admin']) && ($active_user->admin)) {
	try {
		$user = $user_dir->get_user_by_uid($_POST['user_name']);
	} catch(UserNotFoundException $e) {
		$content = new PageSection('user_not_found');
	}
	if(isset($user)) {
		$group->add_admin($user);
		redirect('#admins');
	}
} elseif(isset($_POST['delete_admin']) && ($active_user->admin)) {
	$relation_lifecycle_service->delete_group_admin_by_id($group, $group_admins, $_POST['delete_admin']);
	redirect('#admins');
} elseif(isset($_POST['add_member']) && ($group_admin || $active_user->admin)) {
	if(isset($_POST['username'])) {
		try {
			$entity = $user_dir->get_user_by_uid(trim($_POST['username']));
		} catch(UserNotFoundException $e) {
			$content = new PageSection('user_not_found');
		}
	} elseif(isset($_POST['account'])) {
		try {
			$server = $server_dir->get_server_by_hostname(trim($_POST['hostname']));
			$entity = $server->get_account_by_name(trim($_POST['account']));
		} catch(ServerNotFoundException $e) {
			$content = new PageSection('server_not_found');
		} catch(ServerAccountNotFoundException $e) {
			$content = new PageSection('server_account_not_found');
		}
	}
	if(isset($entity) && !$group->system) {
		try {
			$group->add_member($entity);
			redirect('#members');
		} catch(InvalidArgumentException $e) {
			$content = new PageSection('not_admin');
		}
	}
} elseif (isset($_POST['add_members']) && ($group_admin || $active_user->admin)) {
	$errors = [];
	$server_accounts = find_server_accounts($_POST['accounts'], $errors);
	$result = $group->add_multiple_accounts($server_accounts, $errors);
	if ($result !== null) {
		$alert = new UserAlert;
		if ($result['added'] == 1) {
			$alert->content = "1 account has been added to the group";
		} else {
			$alert->content = "{$result['added']} accounts have been added to the group";
		}
		if ($result['existing'] == 1) {
			$alert->content .= ", 1 account was already member";
		} elseif ($result['existing'] > 1) {
			$alert->content .= ", {$result['existing']} accounts were already member";
		}
		$active_user->add_alert($alert);
	}
	if (!empty($errors)) {
		$alert = new UserAlert;
		$alert->content = "";
		if ($result === null) {
			$alert->content = "Could not add the server accounts to this group. ";
		}
		if (count($errors) == 1) {
			$alert->content .= "The following error occurred:<ul>";
		} else {
			$alert->content .= "The following errors occurred:<ul>";
		}
		foreach ($errors as $error) {
			$masked = hesc($error);
			$alert->content .= "<li>$masked</li>";
		}
		$alert->content .= "</ul>";
		$alert->class = "danger";
		$alert->escaping = ESC_NONE;
		$active_user->add_alert($alert);
	}
	redirect('#members');
} elseif(isset($_POST['delete_member']) && ($group_admin || $active_user->admin)) {
	$relation_lifecycle_service->delete_group_member_by_entity_id($group, $group_members, $_POST['delete_member']);
	redirect('#members');
} elseif(isset($_POST['add_access']) && ($group_admin || $active_user->admin)) {
	if(isset($_POST['username'])) {
		try {
			$entity = $user_dir->get_user_by_uid(trim($_POST['username']));
		} catch(UserNotFoundException $e) {
			$content = new PageSection('user_not_found');
		}
	} elseif(isset($_POST['account'])) {
		try {
			$server = $server_dir->get_server_by_hostname(trim($_POST['hostname']));
			$entity = $server->get_account_by_name(trim($_POST['account']));
		} catch(ServerNotFoundException $e) {
			$content = new PageSection('server_not_found');
		} catch(ServerAccountNotFoundException $e) {
			$content = new PageSection('server_account_not_found');
		}
	} elseif(isset($_POST['group'])) {
		try {
			$entity = $group_dir->get_group_by_name(trim($_POST['group']));
		} catch(GroupNotFoundException $e) {
			$content = new PageSection('group_not_found');
		}
	}
	if(isset($entity)) {
		if($_POST['add_access'] == '2') {
			$options_payload = isset($_POST['access_option']) && is_array($_POST['access_option']) ? $_POST['access_option'] : array();
			$options = $access_rule_service->build_access_options_from_payload($options_payload);
			$access_rule_service->add_group_access($group, $entity, $options);
			redirect('#access');
		} else {
			$content = new PageSection('access_options');
			$content->set('entity', $group);
			$content->set('remote_entity', $entity);
			$content->set('mode', 'create');
		}
	}
} elseif(isset($_POST['delete_access']) && ($group_admin || $active_user->admin)) {
	$access_rule_service->delete_group_access_by_id($group, $group_access, $_POST['delete_access']);
	redirect('#access');
} elseif(isset($_POST['edit_group']) && ($active_user->admin)) {
	$name = trim($_POST['name']);
	$group->name = $name;
	$group->active = $_POST['active'];
	try {
		$group->update();
		$alert = new UserAlert;
		$alert->content = "Settings saved.";
		$active_user->add_alert($alert);
		redirect('/groups/'.urlencode($name).'#settings'); // Must specify, since the name may have changed
	} catch(UniqueKeyViolationException $e) {
		$content = new PageSection('unique_key_violation');
		$content->set('exception', $e);
	}
} else {
	if(isset($router->vars['format']) && $router->vars['format'] == 'json') {
		$page = new PageSection('group_json');
		$page->set('group_members', $group_members);
		header('Content-type: application/json; charset=utf-8');
		echo $page->generate();
		exit;
	} else {
		$content = new PageSection('group');
		$content->set('group', $group);
		$content->set('admin', $active_user->admin);
		$content->set('group_admin', $group_admin);
		$content->set('group_admins', $group_admins);
		$content->set('group_members', $group_members);
		$content->set('group_access', $group_access);
		$content->set('group_remote_access', $group_remote_access);
		$content->set('group_log', $group->get_log());
		$content->set('all_users', $all_users);
		$content->set('all_groups', $all_groups);
		$content->set('all_servers', $all_servers);
		$content->set('admined_servers', $admined_servers);
	}
}

$page = new PageSection('base');
$page->set('title', $group->name);
$page->set('content', $content);
$page->set('alerts', $active_user->pop_alerts());
echo $page->generate();
