#!/usr/bin/php
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

chdir(__DIR__);
require('../core.php');
require_once(__DIR__.'/../history_username_env_common.php');
require('sync-common.php');
require('ssh.php');

// Parse the command-line arguments
$options = getopt('h:i:au:p', array('help', 'host:', 'id:', 'all', 'user:', 'preview', 'diagnostics'));
if(isset($options['help'])) {
	show_help();
	exit(0);
}
if(isset($options['diagnostics'])) {
	show_diagnostics();
	exit(0);
}
$short_to_long = array(
	'h' => 'host',
	'i' => 'id',
	'a' => 'all',
	'u' => 'user',
	'p' => 'preview'
);
foreach($short_to_long as $short => $long) {
	if(isset($options[$short]) && isset($options[$long])) {
		echo "Error: short form -$short and long form --$long both specified\n";
		show_help();
		exit(1);
	}
	if(isset($options[$short])) $options[$long] = $options[$short];
}
$hostopts = 0;
if(isset($options['host'])) $hostopts++;
if(isset($options['id'])) $hostopts++;
if(isset($options['all'])) $hostopts++;
if($hostopts != 1) {
	echo "Error: must specify exactly one of --host, --id, or --all\n";
	show_help();
	exit(1);
}
if(isset($options['user'])) {
	$username = $options['user'];
} else {
	$username = null;
}
$preview = isset($options['preview']);

$required_files = array('config/keys-sync', 'config/keys-sync.pub');
foreach($required_files as $file) {
	if(!file_exists($file)) die("Sync cannot start - $file not found.\n");
}

// Use 'keys-sync' user as the active user (create if it does not yet exist)
try {
	$active_user = $user_dir->get_user_by_uid('keys-sync');
} catch(UserNotFoundException $e) {
	$active_user = new User;
	$active_user->uid = 'keys-sync';
	$active_user->name = 'Synchronization script';
	$active_user->email = '';
	$active_user->active = 1;
	$active_user->admin = 1;
	$active_user->developer = 0;
	$user_dir->add_user($active_user);
}


// Build list of servers to sync
if(isset($options['all'])) {
	$servers = $server_dir->list_servers();
} elseif(isset($options['host'])) {
	$servers = array();
	$hostnames = explode(",", $options['host']);
	foreach($hostnames as $hostname) {
		$hostname = trim($hostname);
		try {
			$servers[] = $server_dir->get_server_by_hostname($hostname);
		} catch(ServerNotFoundException $e) {
			echo "Error: hostname '$hostname' not found\n";
			exit(1);
		}
	}
} elseif(isset($options['id'])) {
	sync_server($options['id'], $username, $preview);
	exit(0);
}

$pending_syncs = array();
foreach($servers as $server) {
	if($server->key_management != 'keys' && $server->key_management != 'decommissioned') {
		continue;
	}
	$pending_syncs[$server->hostname] = $server;
}

$sync_procs = array();
define('MAX_PROCS', 20);
while(count($sync_procs) > 0 || count($pending_syncs) > 0) {
	while(count($sync_procs) < MAX_PROCS && count($pending_syncs) > 0) {
		$server = reset($pending_syncs);
		$hostname = key($pending_syncs);
		$args = array();
		$args[] = '--id';
		$args[] = $server->id;
		if(!is_null($username)) {
			$args[] = '--user';
			$args[] = $username;
		}
		if($preview) {
			$args[] = '--preview';
		}
		$sync_procs[] = new SyncProcess(__FILE__, $args);
		unset($pending_syncs[$hostname]);
	}
	foreach($sync_procs as $ref => $sync_proc) {
		$data = $sync_proc->get_data();
		if(!empty($data)) {
			echo $data['output'];
			unset($sync_procs[$ref]);
		}
	}
	usleep(200000);
}

function show_help() {
?>
Usage: sync.php [OPTIONS]
Syncs public keys to the specified hosts.

Mandatory arguments to long options are mandatory for short options too.
  -a, --all              sync with all active hosts in the database
  -h, --host=HOSTNAME    sync only the specified host(s)
                         (specified by name, comma-separated)
  -i, --id=ID            sync only the specified single host
                         (specified by id)
  -u, --user             sync only the specified user account
  -p, --preview          perform no changes, display content of all
                         keyfiles
      --diagnostics      display sync runtime diagnostics and exit
      --help             display this help and exit
<?php
}

function show_diagnostics() {
	global $config;

	$diag = SyncRuntime::diagnostics($config);
	$ssh_diag = SSH::diagnostics($config);
	$failure_diag = SyncFailureReporter::diagnostics();
	echo "sync_runtime.timeout_util=".$diag['timeout_util']."\n";
	echo "sync_runtime.timeout_binary=".$diag['timeout_binary']."\n";
	echo "sync_runtime.timeout_seconds=".$diag['timeout_seconds']."\n";
	echo "sync_runtime.jumphost_strict_host_key_checking=".$ssh_diag['jumphost_strict_host_key_checking']."\n";
	echo "sync_runtime.jumphost_known_hosts_file=".$ssh_diag['jumphost_known_hosts_file']."\n";
	echo "sync_runtime.reschedule_delay_minutes=".$failure_diag['reschedule_delay_minutes']."\n";
}

/**
 * Establish an SSH connection while handling hanging connection attempts uniformly.
 */
function connect_ssh_with_timeout_handling($server, $hostname, callable $on_timeout, callable $on_connect_failure) {
	// ssh2 sometimes hangs on connect; use SIGTERM from wrapper timeout to bail out cleanly
	declare(ticks = 1);
	pcntl_signal(SIGTERM, function($signal) use($server, $hostname, $on_timeout) {
		echo date('c')." {$hostname}: SSH connection timed out.\n";
		$on_timeout();
		exit(0);
	});

	echo date('c')." {$hostname}: Attempting to connect.\n";
	try {
		$connection = $server->connect_ssh();
	} catch (SSHException $e) {
		$reason = describe_oneline($e);
		echo date('c')." {$hostname}: $reason\n";
		$on_connect_failure($reason);
		return null;
	}

	// From this point on, catch SIGTERM and ignore. SIGINT or SIGKILL is required to stop, so timeout wrapper won't
	// cause a partial sync/decommission
	pcntl_signal(SIGTERM, SIG_IGN);

	return $connection;
}

function decommission_server($id, $preview = false) {
	global $server_dir;

	$keydir = '/var/local/keys-sync';
	$server = $server_dir->get_server_by_id($id);
	$hostname = $server->hostname;
	echo date('c')." {$hostname}: Server is decommissioned, removing all keys (preserving keys-sync access).\n";
	
	if($preview) {
		echo date('c')." {$hostname}: [PREVIEW] Would remove all key files from {$keydir}/ except 'keys-sync' and '.hostnames'\n";
		return;
	}

	$connection = connect_ssh_with_timeout_handling(
		$server,
		$hostname,
		function() use ($server) {
			SyncFailureReporter::report_server_failure(
				$server,
				'SSH connection timed out during decommission',
				null,
				'ssh_timeout',
				true
			);
		},
		function($reason) use ($server) {
			SyncFailureReporter::report_server_failure(
				$server,
				'Failed to connect during decommission',
				$reason,
				null,
				true
			);
		}
	);
	if (is_null($connection)) {
		return;
	}

	$cleanup_errors = 0;
	$removed_count = 0;

	// First verify the directory exists and is accessible (don't suppress errors)
	try {
		$connection->exec('test -d '.escapeshellarg($keydir).' && test -r '.escapeshellarg($keydir));
	} catch (SSHException $e) {
		$cleanup_errors++;
		echo date('c')." {$hostname}: Cannot access key directory: ".describe_oneline($e)."\n";
		SyncFailureReporter::report_server_failure(
			$server,
			'Cannot access key directory during decommission',
			describe_oneline($e),
			'key_directory_access_failed',
			true
		);
		return;
	}

	// Get list of all files in the key directory (don't suppress errors)
	try {
		// Try sha1sum first without suppressing stderr
		$output = $connection->exec('/usr/bin/sha1sum '.escapeshellarg($keydir).'/* 2>&1');
		$entries = explode("\n", $output);
		$files_to_check = array();
		foreach($entries as $entry) {
			// Check for error messages
			if(strpos($entry, 'No such file') !== false || strpos($entry, 'Permission denied') !== false) {
				// sha1sum failed due to access issues, try ls instead
				break;
			}
			if(preg_match('|^([0-9a-f]{40})  '.preg_quote($keydir, '|').'/(.*)$|', $entry, $matches)) {
				$files_to_check[] = $matches[2];
			}
		}
		
		// If sha1sum didn't work or found no files, try ls directly (don't suppress errors)
		if(empty($files_to_check)) {
			$output = $connection->exec('ls -1 '.escapeshellarg($keydir).' 2>&1');
			// Check if ls output contains error messages
			if(strpos($output, 'No such file') !== false || strpos($output, 'Permission denied') !== false) {
				throw new SSHException("Failed to list files in key directory: {$output}");
			}
			$files_to_check = array_filter(explode("\n", trim($output)), function($f) { return trim($f) !== ''; });
		}
		
		// Remove all files except keys-sync and .hostnames
		foreach($files_to_check as $file) {
			$file = trim($file);
			if($file == '' || $file == 'keys-sync' || $file == '.hostnames') {
				continue;
			}
			try {
				$connection->unlink("$keydir/$file");
				echo date('c')." {$hostname}: Removed key file: {$file}\n";
				$removed_count++;
			} catch (SSHException $e) {
				$cleanup_errors++;
				echo date('c')." {$hostname}: Couldn't remove key file {$file}: ".describe_oneline($e)."\n";
			}
		}
		
		if($removed_count == 0) {
			echo date('c')." {$hostname}: No key files found to remove (directory is empty or contains only protected files)\n";
		}
	} catch (SSHException $e) {
		$cleanup_errors++;
		echo date('c')." {$hostname}: Error listing key files: ".describe_oneline($e)."\n";
	}

	// Update status
	if($cleanup_errors > 0) {
		SyncFailureReporter::report_server_failure(
			$server,
			'Failed to remove '.$cleanup_errors.' key file'.($cleanup_errors == 1 ? '' : 's').' during decommission',
			null,
			'decommission_cleanup_failed',
			true
		);
	} else {
		$server->sync_report('sync success', 'Decommissioned: removed '.$removed_count.' key file'.($removed_count == 1 ? '' : 's').' (keys-sync access preserved)');
	}
	
	try {
		$server->update_status_file($connection);
	} catch (SSHException $e) {
		$reason = describe_oneline($e);
		echo date('c')." {$hostname}: Warning: monitoring status file update failed during decommission: {$reason}\n";
		SyncFailureReporter::log_server_nonfatal_issue(
			$server,
			'Monitoring status file update failed during decommission',
			$reason,
			'monitoring_status_write_failed'
		);
	}
	
	echo date('c')." {$hostname}: Decommission completed\n";
}

function sync_server($id, $only_username = null, $preview = false) {
	global $config;
	global $server_dir;
	global $user_dir;

	$keydir = '/var/local/keys-sync';
	$header = "## Auto generated keys file for %s
## Do not edit this file! Modify at %s
";
	$header_no_link = "## Auto generated keys file for %s
## Do not edit this file!
";
	$comment = (int)$config['privacy']['comment_key_files'];

	$server = $server_dir->get_server_by_id($id);
	$hostname = $server->hostname;
	echo date('c')." {$hostname}: Preparing sync.\n";
	
	// Handle decommissioned servers: remove all keys except keys-sync
	if($server->key_management == 'decommissioned') {
		decommission_server($id, $preview);
		return;
	}
	
	if($server->key_management != 'keys') return;
	$accounts = $server->list_accounts();
	$keyfiles = array();
	$sync_warning = false;
	// Generate keyfiles for each account
	foreach($accounts as $account) {
		if($account->active == 0 || $account->sync_status == 'proposed') continue;
		$username = str_replace('/', '', $account->name);
			$keyfile = sprintf($header, "account '{$account->name}'", $config['web']['baseurl']."/servers/".urlencode($hostname)."/accounts/".urlencode($account->name));
			// Collect a set of all groups that the account is a member of (directly or indirectly) and the account itself
			$sets = $account->list_group_membership();
			$sets[] = $account;
			foreach($sets as $set) {
				if(get_class($set) == 'Group') {
					if($set->active == 0) continue; // Rules for inactive groups should be ignored
					if ($comment == 1) {
						$keyfile .= "# === Start of rules applied due to membership in {$set->name} group ===\n";
					}
				}
				$access_rules = $set->list_access();
				$keyfile .= get_keys($access_rules, $account->name, $hostname, $comment, $server);
				if(get_class($set) == 'Group' && $comment == 1) {
					$keyfile .= "# === End of rules applied due to membership in {$set->name} group ===\n\n";
				}
			}
			$keyfiles[$username] = array('keyfile' => $keyfile, 'check' => false, 'account' => $account);
		}
	if($server->authorization == 'automatic LDAP' || $server->authorization == 'manual LDAP') {
		// Generate keyfiles for LDAP users
		$optiontext = array();
		foreach($server->list_ldap_access_options() as $option) {
			$optiontext[] = $option->option.(is_null($option->value) ? '' : '="'.str_replace('"', '\\"', $option->value).'"');
		}
		$prefix = implode(',', $optiontext);
		if($prefix !== '') $prefix .= ' ';
		$users = $user_dir->list_users();
		foreach($users as $user) {
			$username = str_replace('/', '', $user->uid);
			if(is_null($only_username) || $username == $only_username) {
				if(!isset($keyfiles[$username])) {
					$keyfile = sprintf($header, "LDAP user '{$user->uid}'", $config['web']['baseurl']);
					$keys = $user->list_public_keys($username, $hostname, false);
					if(count($keys) > 0) {
						if($user->active) {
							$user_prefix = add_user_history_username_env_option($prefix, $user, $server);
							foreach($keys as $key) {
								$keyfile .= $user_prefix.$key->export_userkey_with_fixed_comment($user, $comment)."\n";
							}
						} elseif ($comment == 1) {
							$keyfile .= "# Account disabled\n";
						}
						$keyfiles[$username] = array('keyfile' => $keyfile, 'check' => ($server->authorization == 'manual LDAP'));
					}
				}
			}
		}
	}
	if(array_key_exists('keys-sync', $keyfiles)) {
		// keys-sync account should never be synced
		unset($keyfiles['keys-sync']);
	}
	if($preview) {
		foreach($keyfiles as $username => $keyfile) {
			echo date('c')." {$hostname}: account '$username':\n\n\033[1;34m{$keyfile['keyfile']}\033[0m\n\n";
		}
		return;
	}

	$connection = connect_ssh_with_timeout_handling(
		$server,
		$hostname,
		function() use ($server, $keyfiles) {
			SyncFailureReporter::report_server_failure(
				$server,
				'SSH connection timed out',
				null,
				'ssh_timeout',
				true
			);
			report_all_accounts_failed($keyfiles);
		},
		function($reason) use ($server, $keyfiles) {
			SyncFailureReporter::report_server_failure(
				$server,
				'Failed to connect',
				$reason,
				null,
				true
			);
			report_all_accounts_failed($keyfiles);
		}
	);
	if (is_null($connection)) {
		return;
	}

	$account_errors = 0;
	$cleanup_errors = 0;

	// Sync
	$output = $connection->exec('/usr/bin/sha1sum '.escapeshellarg($keydir).'/*');
	$entries = explode("\n", $output);
	$sha1sums = array();
	foreach($entries as $entry) {
		if(preg_match('|^([0-9a-f]{40})  '.preg_quote($keydir, '|').'/(.*)$|', $entry, $matches)) {
			$sha1sums[$matches[2]] = $matches[1];
		}
	}
	foreach($keyfiles as $username => $keyfile) {
		if(is_null($only_username) || $username == $only_username) {
			if(isset($sha1sums[$username])) {
				unset($sha1sums[$username]);
			}
			try {
				$remote_filename = "$keydir/$username";
				$create = true;
				if($keyfile['check']) {
					$output = $connection->exec('id '.escapeshellarg($username));
					if(empty($output)) $create = false;
				}
				if($create) {
					if(isset($sha1sums[$username]) && $sha1sums[$username] == sha1($keyfile['keyfile'])) {
						echo date('c')." {$hostname}: No changes required for {$username}\n";
					} else {
						$connection->file_put_contents($remote_filename, $keyfile['keyfile']);
						$connection->exec('chown keys-sync: '.escapeshellarg($remote_filename));
						echo date('c')." {$hostname}: Updated {$username}\n";
					}
				} else {
					$connection->unlink($remote_filename);
				}
				if(isset($keyfile['account'])) {
					if($sync_warning && $username != 'root') {
						// File was synced, but will not work due to configuration on server
						$keyfile['account']->sync_report('sync warning');
					} else {
						$keyfile['account']->sync_report('sync success');
					}
				}
			} catch(SSHException $e) {
				$account_errors++;
				echo "{$hostname}: Sync command execution failed for $username, ".describe_oneline($e)."\n";
				if(isset($keyfile['account'])) {
					$keyfile['account']->sync_report('sync failure');
				}
			}
		}
	}
	if(is_null($only_username)) {
		// Clean up directory
		foreach($sha1sums as $file => $sha1sum) {
			if($file != '' && $file != 'keys-sync' && $file != '.hostnames') {
				try {
					$connection->unlink("$keydir/$file");
					echo date('c')." {$hostname}: Removed unknown file: {$file}\n";
				} catch (SSHException $e) {
					$cleanup_errors++;
					echo date('c')." {$hostname}: Couldn't remove unknown file: {$file}\n";
				}
			}
		}
	}
	try {
		$uuid = trim($connection->file_get_contents("/etc/uuid"));
		$server->uuid = $uuid;
		$server->update();
	} catch(SSHException $e) {
		// If the /etc/uuid file does not exist, silently ignore
	}
	$failure_occurred = false;
	if($cleanup_errors > 0) {
		SyncFailureReporter::report_server_failure(
			$server,
			'Failed to clean up '.$cleanup_errors.' file'.($cleanup_errors == 1 ? '' : 's'),
			null,
			'cleanup_failed',
			false
		);
		$failure_occurred = true;
	} elseif($account_errors > 0) {
		SyncFailureReporter::report_server_failure(
			$server,
			$account_errors.' account'.($account_errors == 1 ? '' : 's').' failed to sync',
			null,
			'account_sync_failed',
			false
		);
		$failure_occurred = true;
	} elseif($sync_warning) {
		$server->sync_report('sync warning', $sync_warning);
	} else {
		$server->sync_report('sync success', 'Synced successfully');
	}
	if ($failure_occurred) {
		$server->reschedule_sync_request();
	}
	$status_file_update_reason = null;
	try {
		$server->update_status_file($connection);
	} catch (SSHException $e) {
		$status_file_update_reason = describe_oneline($e);
		echo date('c')." {$hostname}: Warning: monitoring status file update failed: {$status_file_update_reason}\n";
	}
	if(!is_null($status_file_update_reason)) {
		if($failure_occurred || $sync_warning) {
			SyncFailureReporter::log_server_nonfatal_issue(
				$server,
				'Monitoring status file update failed',
				$status_file_update_reason,
				'monitoring_status_write_failed'
			);
		} else {
			$server->sync_report(
				'sync warning',
				SyncFailureReporter::build_message(
					'Monitoring status file update failed',
					$status_file_update_reason,
					'monitoring_status_write_failed',
					false
				)
			);
		}
	}
	echo date('c')." {$hostname}: Sync finished\n";
}

function get_default_history_username_env_format() {
	return 'BASH_HISTORY_USERNAME={uid}';
}

function history_username_env_value_is_valid($value) {
	if($value === '') {
		return false;
	}
	if(preg_match('/[\r\n,\'"\\\\{}]/', $value)) {
		return false;
	}
	return preg_match('/^[A-Za-z0-9 ._@:+=-]+$/', $value) === 1;
}

function normalize_history_username_env_format($format) {
	$format = trim((string)$format);
	if(!history_username_env_format_is_valid($format)) {
		return get_default_history_username_env_format();
	}
	return $format;
}

function get_global_history_username_env_enabled() {
	global $config;
	if(!isset($config['privacy']) || !isset($config['privacy']['history_username_env_default'])) {
		return false;
	}
	return intval($config['privacy']['history_username_env_default']) === 1;
}

function get_global_history_username_env_format() {
	global $config;
	if(isset($config['privacy']) && isset($config['privacy']['history_username_env_format'])) {
		return normalize_history_username_env_format($config['privacy']['history_username_env_format']);
	}
	return get_default_history_username_env_format();
}

function get_server_history_username_env_mode($server) {
	try {
		$mode = $server->history_username_env_mode;
	} catch(Exception $e) {
		return 'inherit';
	}
	if($mode !== 'enabled' && $mode !== 'disabled') {
		return 'inherit';
	}
	return $mode;
}

function get_server_history_username_env_enabled($server) {
	$mode = get_server_history_username_env_mode($server);
	switch($mode) {
	case 'enabled':
		return true;
	case 'disabled':
		return false;
	default:
		return get_global_history_username_env_enabled();
	}
}

function get_server_history_username_env_format($server) {
	try {
		$format = trim((string)$server->history_username_env_format);
	} catch(Exception $e) {
		$format = '';
	}
	if($format !== '') {
		return normalize_history_username_env_format($format);
	}
	return get_global_history_username_env_format();
}

function escape_authorized_keys_option_value($value) {
	$value = preg_replace('/[[:cntrl:]]+/', '', (string)$value);
	if($value === null) {
		$value = '';
	}
	if(!history_username_env_value_is_valid($value)) {
		throw new InvalidArgumentException('Invalid history username environment value');
	}
	return str_replace(array('\\', '"'), array('\\\\', '\\"'), $value);
}

function append_authorized_keys_option($prefix, $option) {
	$prefix = trim((string)$prefix);
	if($prefix === '') {
		return $option.' ';
	}
	return rtrim($prefix, ',').','.$option.' ';
}

function get_user_history_username_env_option($user, $server) {
	if(!get_server_history_username_env_enabled($server)) {
		return null;
	}
	$value = str_replace('{uid}', $user->uid, get_server_history_username_env_format($server));
	if(!history_username_env_value_is_valid($value)) {
		return null;
	}
	try {
		return 'environment="'.escape_authorized_keys_option_value($value).'"';
	} catch(InvalidArgumentException $e) {
		return null;
	}
}

function add_user_history_username_env_option($prefix, $user, $server) {
	$option = get_user_history_username_env_option($user, $server);
	if(is_null($option)) {
		return $prefix;
	}
	return append_authorized_keys_option($prefix, $option);
}

function append_user_keys($keyfile, $entity, $prefix, $account_name, $hostname, $comment, $server, $grant_details = null) {
	if ($comment == 1) {
		$keyfile .= "# {$entity->uid}";
		if (!is_null($grant_details)) {
			$keyfile .= " {$grant_details}";
		}
		$keyfile .= "\n";
	}
	if($entity->active) {
		$prefix = add_user_history_username_env_option($prefix, $entity, $server);
		$keys = $entity->list_public_keys($account_name, $hostname, false);
		foreach($keys as $key) {
			$keyfile .= $prefix.$key->export_userkey_with_fixed_comment($entity, $comment)."\n";
		}
	} elseif ($comment == 1) {
		$keyfile .= "# Account disabled\n";
	}
	return $keyfile;
}

function append_serveraccount_keys($keyfile, $entity, $prefix, $account_name, $hostname, $comment, $grant_details = null) {
	if ($comment == 1) {
		$keyfile .= "# {$entity->name}@{$entity->server->hostname}";
		if (!is_null($grant_details)) {
			$keyfile .= " {$grant_details}";
		}
		$keyfile .= "\n";
	}
	if($entity->server->key_management != 'decommissioned') {
		$keys = $entity->list_public_keys($account_name, $hostname, false);
		foreach($keys as $key) {
			$keyfile .= $prefix.$key->export_serverkey_with_fixed_comment($entity, $comment)."\n";
		}
	} elseif ($comment == 1) {
		$keyfile .= "# Decommissioned server\n";
	}
	return $keyfile;
}

function get_keys($access_rules, $account_name, $hostname, $comment, $server) {
	$keyfile = '';
	foreach($access_rules as $access) {
		$grant_date = new DateTime($access->grant_date);
		$grant_date_full = $grant_date->format('c');
		$entity = $access->source_entity;
		$optiontext = array();
		foreach($access->list_options() as $option) {
			$optiontext[] = $option->option.(is_null($option->value) ? '' : '="'.str_replace('"', '\\"', $option->value).'"');
		}
		$prefix = implode(',', $optiontext);
		if($prefix !== '') $prefix .= ' ';
		switch(get_class($entity)) {
		case 'User':
			$keyfile = append_user_keys(
				$keyfile,
				$entity,
				$prefix,
				$account_name,
				$hostname,
				$comment,
				$server,
				"granted access by {$access->granted_by->uid} on {$grant_date_full}"
			);
			break;
		case 'ServerAccount':
			$keyfile = append_serveraccount_keys(
				$keyfile,
				$entity,
				$prefix,
				$account_name,
				$hostname,
				$comment,
				"granted access by {$access->granted_by->uid} on {$grant_date_full}"
			);
			break;
		case 'Group':
			// Recurse!
			$seen = array($entity->name => true);
			if ($comment == 1) {
				$keyfile .= "# {$entity->name} group";
				$keyfile .= " granted access by {$access->granted_by->uid} on {$grant_date_full}";
				$keyfile .= "\n";
			}
			if($entity->active) {
				if ($comment == 1) {
					$keyfile .= "# == Start of {$entity->name} group members ==\n";
				}
				$keyfile .= get_group_keys($entity->list_members(), $account_name, $hostname, $prefix, $seen, $comment, $server);
				if ($comment == 1) {
					$keyfile .= "# == End of {$entity->name} group members ==\n";
				}
			} elseif ($comment == 1) {
				$keyfile .= "# Inactive group\n";
			}
			break;
		}
	}
	return $keyfile;
}

function get_group_keys($entities, $account_name, $hostname, $prefix, &$seen, $comment, $server) {
	$keyfile = '';
	foreach($entities as $entity) {
		switch(get_class($entity)) {
		case 'User':
			$keyfile = append_user_keys(
				$keyfile,
				$entity,
				$prefix,
				$account_name,
				$hostname,
				$comment,
				$server
			);
			break;
		case 'ServerAccount':
			$keyfile = append_serveraccount_keys(
				$keyfile,
				$entity,
				$prefix,
				$account_name,
				$hostname,
				$comment
			);
			break;
		case 'Group':
			// Recurse!
			if(!isset($seen[$entity->name])) {
				$seen[$entity->name] = true;
				if ($comment == 1) {
					$keyfile .= "# {$entity->name} group";
					$keyfile .= "\n";
					$keyfile .= "# == Start of {$entity->name} group members ==\n";
				}
				$keyfile .= get_group_keys($entity->list_members(), $account_name, $hostname, $prefix, $seen, $comment, $server);
				if ($comment == 1) {
					$keyfile .= "# == End of {$entity->name} group members ==\n";
				}
			}
			break;
		}
	}
	return $keyfile;
}

function report_all_accounts_failed($keyfiles) {
	foreach($keyfiles as $keyfile) {
		if(isset($keyfile['account'])) {
			$keyfile['account']->sync_report('sync failure');
		}
	}
}
