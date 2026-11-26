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
require_once(__DIR__.'/../keyfile_common.php');
require('sync-common.php');
require('ssh.php');
$required_files = array('config/keys-sync', 'config/keys-sync.pub');
foreach($required_files as $file) {
	if(!file_exists($file)) die("Sync cannot start - $file not found.\n");
}

// Parse the command-line arguments
$options = getopt('h:i:au:p', array('help', 'host:', 'id:', 'all', 'user:', 'preview'));
if(isset($options['help'])) {
	show_help();
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
      --help             display this help and exit
<?php
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
			$server->sync_report('sync failure', 'SSH connection timed out during decommission');
			$server->reschedule_sync_request();
		},
		function($reason) use ($server) {
			$server->sync_report('sync failure', 'Failed to connect during decommission: '.$reason);
			$server->reschedule_sync_request();
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
		$server->sync_report('sync failure', 'Cannot access key directory during decommission: '.describe_oneline($e));
		$server->reschedule_sync_request();
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
		$server->sync_report('sync failure', 'Failed to remove '.$cleanup_errors.' key file'.($cleanup_errors == 1 ? '' : 's').' during decommission');
		$server->reschedule_sync_request();
	} else {
		$server->sync_report('sync success', 'Decommissioned: removed '.$removed_count.' key file'.($removed_count == 1 ? '' : 's').' (keys-sync access preserved)');
	}
	
	try {
		$server->update_status_file($connection);
	} catch (SSHException $e) {
		// Ignore status file update errors during decommission
	}
	
	echo date('c')." {$hostname}: Decommission completed\n";
}

function sync_server($id, $only_username = null, $preview = false) {
	global $config;
	global $server_dir;
	global $user_dir;

	$keydir = '/var/local/keys-sync';
	$sync_start = time();

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
		$keyfile = build_account_keyfile($server, $account);
		$keyfiles[$username] = array('keyfile' => $keyfile, 'check' => false, 'account' => $account);
	}
	if($server->authorization == 'automatic LDAP' || $server->authorization == 'manual LDAP') {
		// Generate keyfiles for LDAP users
		$prefix = get_ldap_access_option_prefix($server);
		$users = $user_dir->list_users();
		foreach($users as $user) {
			$username = str_replace('/', '', $user->uid);
			if(is_null($only_username) || $username == $only_username) {
				if(!isset($keyfiles[$username])) {
					$keyfile = build_ldap_user_keyfile($server, $user, $prefix);
					if(!is_null($keyfile)) {
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
			$server->sync_report('sync failure', 'SSH connection timed out');
			$server->reschedule_sync_request();
			report_all_accounts_failed($keyfiles);
		},
		function($reason) use ($server, $keyfiles) {
			$server->sync_report('sync failure', $reason);
			$server->reschedule_sync_request();
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
			$exists_on_server = isset($sha1sums[$username]);
			if(isset($sha1sums[$username])) {
				unset($sha1sums[$username]);
			}
			try {
				$remote_filename = "$keydir/$username";
				$create = true;
				if($keyfile['check'] && !linux_account_exists($connection, $username)) {
					$create = false;
				}
				if($create) {
					if(isset($sha1sums[$username]) && $sha1sums[$username] == sha1($keyfile['keyfile'])) {
						echo date('c')." {$hostname}: No changes required for {$username}\n";
					} else {
						$connection->file_put_contents($remote_filename, $keyfile['keyfile']);
						$connection->exec('chown keys-sync: '.escapeshellarg($remote_filename));
						echo date('c')." {$hostname}: Updated {$username}\n";
					}
				} elseif($exists_on_server) {
					$connection->unlink($remote_filename);
					echo date('c')." {$hostname}: Removed {$username}, there is no such account on the server\n";
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
		$server->sync_report('sync failure', 'Failed to clean up '.$cleanup_errors.' file'.($cleanup_errors == 1 ? '' : 's'));
		$failure_occurred = true;
	} elseif($account_errors > 0) {
		$server->sync_report('sync failure', $account_errors.' account'.($account_errors == 1 ? '' : 's').' failed to sync');
		$failure_occurred = true;
	} elseif($sync_warning) {
		$server->sync_report('sync warning', $sync_warning);
	} else {
		$server->sync_report('sync success', 'Synced successfully');
	}
	if ($failure_occurred) {
		$server->reschedule_sync_request();
	}
	$server->update_status_file($connection);
	// Snapshot of sshd configuration and key directory for the "Troubleshoot access" tool. It never fails the
	// sync, and it is skipped when the sync itself took long: this process must end before the wrapper that
	// started it gives up on it (see SyncProcess), or a finished sync would be reported as failed.
	if(time() - $sync_start < 20) {
		ServerHostFacts::collect_and_store($server, $connection, 'sync');
	} else {
		// The snapshot no longer matches what was just written; have the next supervision run renew it
		ServerHostFacts::request_refresh($server);
	}
	echo date('c')." {$hostname}: Sync finished\n";
}

/**
 * Ask a target server whether it has a Linux account of the given name.
 *
 * The answer decides whether a key file is removed, so anything but a clear answer is an error: a lookup
 * that does not finish (a directory service that is slow or down) must not look like "no such account".
 *
 * @param SSH $connection Open connection to the server
 * @param string $username Account name to look up
 * @return bool true if the account exists
 * @throws SSHException if the server does not give a clear answer
 */
function linux_account_exists(SSH $connection, $username) {
	// The exit status tells "no such user" (1) apart from a lookup that could not be done. Error messages are
	// discarded: the SSH library returns them mixed into the output.
	$output = $connection->exec_complete('id -un -- '.escapeshellarg($username).' 2>/dev/null; echo "status=$?"');
	if(!preg_match('/^(.*?)\s*status=(\d+)\s*$/s', $output, $matches)) {
		throw new SSHException("Could not find out whether the account $username exists on the server");
	}
	if($matches[2] === '0') {
		// "id" takes an all-digit name for a user id if there is no account of that name; sshd does not
		return !ctype_digit((string)$username) || trim($matches[1]) === (string)$username;
	}
	if($matches[2] === '1') {
		return false;
	}
	throw new SSHException("Could not find out whether the account $username exists on the server (id exited with status {$matches[2]})");
}

function report_all_accounts_failed($keyfiles) {
	foreach($keyfiles as $keyfile) {
		if(isset($keyfile['account'])) {
			$keyfile['account']->sync_report('sync failure');
		}
	}
}
