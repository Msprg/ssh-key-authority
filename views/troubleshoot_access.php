<?php
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

if(!$active_user->admin) {
	require('views/error403.php');
	die;
}

/** How often the page reloads itself while it waits for a requested sync */
const TROUBLESHOOT_MAX_WAIT = 20;

$read = function(array $source, string $name): string {
	return isset($source[$name]) && is_string($source[$name]) ? trim($source[$name]) : '';
};
// Input that is neither a user name nor a recognisable key is dropped rather than carried along in the URL:
// somebody may have pasted the wrong thing, such as a private key.
$reject_unrecognised = function(array $who) use ($active_user) {
	if($who['kind'] == 'unrecognised') {
		$alert = new UserAlert;
		$alert->content = 'That was not recognised as a user name, a public key in OpenSSH format, or a key fingerprint (SHA256:... or MD5).';
		$alert->class = 'danger';
		$active_user->add_alert($alert);
	}
};

if(isset($_POST['troubleshoot']) || isset($_POST['refresh'])) {
	// The form is posted so that a pasted public key does not end up in URLs and logs. What is examined is
	// then addressed by a URL with the short form of the input (user name or key fingerprint).
	$who = AccessTroubleshooter::resolve_who($read($_POST, 'who'));
	$reject_unrecognised($who);
	$hostname = $read($_POST, 'server');
	$url = AccessTroubleshooter::url($who['canonical'], $hostname, $read($_POST, 'account'));
	if(isset($_POST['refresh'])) {
		// Facts about a server are collected at the end of each sync, so request one
		try {
			$server = $server_dir->get_server_by_hostname($hostname);
			if($server->key_management == 'keys') {
				$server->sync_access();
				$url .= '&wait=1';
			}
		} catch(ServerNotFoundException $e) {
		}
	}
	redirect($url);
}

$input = array('who' => $read($_GET, 'who'), 'server' => $read($_GET, 'server'), 'account' => $read($_GET, 'account'));
$who = AccessTroubleshooter::resolve_who($input['who']);
if($who['canonical'] !== $input['who']) {
	$reject_unrecognised($who);
	redirect(AccessTroubleshooter::url($who['canonical'], $input['server'], $input['account']));
}

$content = new PageSection('troubleshoot_access');
$content->set('input', $input);
$content->set('all_users', $user_dir->list_users());
$content->set('all_servers', $server_dir->list_servers());
$content->set('server_accounts', array());
$content->set('result', null);
$content->set('problems', array());
$content->set('waiting', false);
$head = '';

$problems = array();
$server = null;
if($input['server'] !== '') {
	try {
		$server = $server_dir->get_server_by_hostname($input['server']);
		$content->set('server_accounts', $server->list_accounts());
	} catch(ServerNotFoundException $e) {
		$problems[] = 'There is no server "'.$input['server'].'" in SKA.';
	}
}
if(strpos($input['account'], '/') !== false) {
	$problems[] = 'A Linux account name cannot contain "/". Enter the name as it is used with ssh, for example root.';
}
$content->set('problems', $problems);

// Links from other pages fill in only part of the form; the analysis needs all three
if(count($problems) == 0 && !is_null($server) && $input['who'] !== '' && $input['account'] !== '') {
	$wait = isset($_GET['wait']) && is_string($_GET['wait']) ? (int)$_GET['wait'] : 0;
	$url = AccessTroubleshooter::url($input['who'], $input['server'], $input['account']);
	if($wait > 0) {
		if($wait <= TROUBLESHOOT_MAX_WAIT && count($server->list_sync_requests()) > 0) {
			// The requested sync has not run yet: look again in a moment
			$head = '<meta http-equiv="refresh" content="3;url='.hesc(rrurl($url.'&wait='.($wait + 1))).'">'."\n";
			$content->set('waiting', true);
		} else {
			redirect($url);
		}
	}
	$troubleshooter = new AccessTroubleshooter($who, $server, $input['account']);
	$content->set('result', $troubleshooter->run());
}

$page = new PageSection('base');
$page->set('title', 'Troubleshoot access');
$page->set('head', $head);
$page->set('content', $content);
$page->set('alerts', $active_user->pop_alerts());
// Highlight the Tools menu entry
$page->set('relative_request_url', '/tools');
echo $page->generate();
