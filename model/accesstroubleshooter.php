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

require_once(__DIR__.'/../keyfile_common.php');

/**
 * Explains why a user (or the owner of an SSH key) can or cannot log in to an account on a server.
 *
 * It goes through what the sync does when it builds the key file of the account, and records every decision
 * instead of only producing text. Which rules apply to an account and whom a rule covers comes from the same
 * functions that the sync uses (keyfile_common.php), and the verdict from the key file that they generate.
 *
 * It then compares with the facts collected from the target server (see ServerHostFacts): whether the file has
 * arrived there, and whether the sshd configuration lets the key be used.
 *
 * This class only reads. Texts are returned as arrays of "parts": plain strings, entity objects (which the
 * template turns into links) and small arrays such as array('code' => ...). Nothing in them is HTML.
 */
class AccessTroubleshooter {
	/** Route of the tool, used for links that re-run it with other input */
	const URL = '/tools/troubleshoot_access';

	private $who;
	private $server;
	private $login;
	/** @var Entity|null The user or server account whose access is examined */
	private $subject = null;
	/** @var PublicKey[] Active keys of the subject that are considered, indexed by key id */
	private $keys = array();
	/** @var ServerAccount|null The managed account behind the login, if SKA writes a key file for it */
	private $account = null;
	/** Name of the key file below /var/local/keys-sync */
	private $keyfile_name;
	/** @var string|null Content that SKA would write to the key file now, null if it writes none */
	private $expected_keyfile = null;
	/** @var array key id => lines of the key file (line number => text) that contain this key */
	private $expected_lines = array();
	private $sections = array();
	/** Reasons why SKA does not grant access, shown with the verdict */
	private $reasons = array();
	/** @var array index in $reasons => further lines (each an array of parts) that say what can be done */
	private $reason_details = array();
	private $host_blockers = array();
	private $host_warnings = array();
	private $outside = array();
	private $host_checked = false;
	private $host_incomplete = false;
	private $delivery = 'unknown';
	/** Whether the subject's keys are only in the key file because somebody else registered the same key */
	private $foreign_registration = false;
	/** Numbers of rules found, for tests */
	private $trace = array();
	/** Whether one of the examined keys was found in a home directory on the server */
	private $external_key_found = false;
	/** @var array id of a group => whom a rule for that group covers, see list_access_grantees() */
	private $grantees_cache = array();
	/** @var array sshd directives in Match blocks that may or may not apply, see SshdConfig::evaluate() */
	private $conditional = array();
	/** Whether part of the sshd configuration could not be read, so that conclusions from it are not certain */
	private $config_incomplete = false;
	/** @var string|null How old the information from the server is, in words */
	private $age_text = null;
	/** @var array|null Parts that say why syncs to the server do not get through, if they do not */
	private $sync_problem = null;
	/**
	 * @var array What connects the subject to the login, for the diagram: 'type' is 'rule' (with 'set' and
	 *            'rule'), 'ldap' (the user's own key file) or 'none' (with 'label' saying why not)
	 */
	private $link = array('type' => 'none', 'label' => '');
	/** @var array ids of the considered keys that destination rules keep away from the account */
	private $blocked_keys = array();
	private $facts = null;

	/**
	 * Work out what the "who" input of the tool refers to: a user name, a public key in OpenSSH format, or
	 * a key fingerprint. Nothing is created or changed; in particular an unknown user is not looked up in LDAP.
	 *
	 * @param string $input The text that was entered
	 * @return array with 'kind' ('empty', 'user', 'unknown_user', 'key', 'unknown_key' or 'unrecognised'), 'canonical' (short
	 *               form of the input that is safe to keep in a URL) and, depending on the kind, 'user',
	 *               'keys' (PublicKey objects with that fingerprint) and 'fingerprint'
	 */
	public static function resolve_who(string $input): array {
		global $user_dir, $pubkey_dir;
		$input = trim(str_replace(array("\r", "\n"), ' ', $input));
		if($input === '') {
			return array('kind' => 'empty', 'canonical' => '');
		}
		$fingerprint = null;
		if(preg_match('/(?:^|[\s,])((?:ssh|ecdsa|sk)-[A-Za-z0-9@.-]+)\s+([A-Za-z0-9+\/]{40,}={0,2})(?:\s|$)/', $input, $matches)) {
			// A public key line, possibly with options in front
			$blob = base64_decode($matches[2], true);
			if($blob !== false && $blob !== '') {
				$fingerprint = rtrim(base64_encode(hash('sha256', $blob, true)), '=');
				$canonical = 'SHA256:'.$fingerprint;
			}
		} elseif(preg_match('/(?:^|\s)SHA256:([A-Za-z0-9+\/]{43})=?(?=[\s.,;)]|$)/i', $input, $matches)) {
			// A fingerprint, also in the middle of a line copied from ssh-keygen or the sshd log
			$fingerprint = $matches[1];
			$canonical = 'SHA256:'.$fingerprint;
		} elseif(preg_match('/(?:^|\s)(?:MD5:)?((?:[0-9a-f]{2}:){15}[0-9a-f]{2})(?=[\s.,;)]|$)/i', $input, $matches)) {
			$fingerprint = strtolower($matches[1]);
			$canonical = 'MD5:'.$fingerprint;
		}
		if(!is_null($fingerprint)) {
			// The database compares without regard to case; a fingerprint that differs in case is another key
			$keys = array_values(array_filter($pubkey_dir->list_public_keys(array(), array('fingerprint' => $fingerprint)), function($key) use ($fingerprint) {
				return $key->fingerprint_sha256 === $fingerprint || $key->fingerprint_md5 === $fingerprint;
			}));
			// Keys still in use first, then deleted ones
			usort($keys, function($a, $b) {
				return array(!is_null($a->deletion_date), $a->id) <=> array(!is_null($b->deletion_date), $b->id);
			});
			return array(
				'kind' => count($keys) > 0 ? 'key' : 'unknown_key',
				'canonical' => $canonical,
				'fingerprint' => $canonical,
				'keys' => $keys,
			);
		}
		if(is_null($fingerprint) && preg_match('/^([A-Za-z0-9+\/]{43})=?$/', $input, $matches)) {
			// SKA itself shows SHA256 fingerprints without the "SHA256:" in front. It could also be a user name;
			// a user of that name takes precedence.
			$users = $input === '0' ? array() : $user_dir->list_users(array(), array('uid' => $input));
			if(count($users) == 0) {
				return self::resolve_who('SHA256:'.$matches[1]);
			}
		}
		if(strlen($input) > 50 || preg_match('/\s/', $input) || preg_match('/^(SHA256|MD5):/i', $input)) {
			// Not a user name (those have at most 50 characters and no spaces). Whatever was pasted, it must not
			// be carried along in URLs: it could be key material in a format that is not recognised.
			return array('kind' => 'unrecognised', 'canonical' => '');
		}
		if($input !== '0') { // list_users ignores a filter value that PHP considers empty
			foreach($user_dir->list_users(array(), array('uid' => $input)) as $user) {
				return array('kind' => 'user', 'canonical' => $user->uid, 'user' => $user);
			}
		}
		return array('kind' => 'unknown_user', 'canonical' => $input);
	}

	/**
	 * Relative URL that runs the tool with the given input.
	 */
	public static function url(string $who, string $hostname, string $login): string {
		return self::URL.'?'.http_build_query(array('who' => $who, 'server' => $hostname, 'account' => $login));
	}

	/**
	 * @param array $who Result of resolve_who()
	 * @param Server $server The server that is logged in to
	 * @param string $login The Linux login name that is used
	 */
	public function __construct(array $who, Server $server, string $login) {
		$this->who = $who;
		$this->server = $server;
		$this->login = $login;
		$this->keyfile_name = str_replace('/', '', $login);
	}

	/**
	 * Run the analysis.
	 *
	 * @return array with 'verdict', 'sections' and the objects that were examined, for the template
	 */
	public function run(): array {
		// One request, one picture of the rules: the groups are the same for every account that is looked at
		remember_access_rules(true);
		try {
			return $this->examine();
		} finally {
			remember_access_rules(false);
		}
	}

	private function examine(): array {
		$this->examine_who();
		$this->examine_server();
		if($this->server->key_management == 'keys') {
			$this->examine_login();
		}
		$this->examine_delivery_and_host();
		$this->examine_other_accounts();
		return array(
			'who' => $this->who,
			'subject' => $this->subject,
			'server' => $this->server,
			'login' => $this->login,
			'account' => $this->account,
			'keyfile_path' => ServerHostFacts::KEYDIR.'/'.$this->keyfile_name,
			'expected_keyfile' => $this->expected_keyfile,
			'expected_lines' => $this->expected_lines,
			'facts' => $this->facts,
			'verdict' => $this->verdict(),
			'diagram' => $this->diagram(),
			'trace' => $this->trace + array('keys' => count($this->keys) - count($this->blocked_keys)),
			'sections' => array_values($this->sections),
		);
	}

	// ---------------------------------------------------------------------------------------------------------
	// Recording of findings

	private function section(string $id, string $title) {
		if(!isset($this->sections[$id])) {
			$this->sections[$id] = array('id' => $id, 'title' => $title, 'findings' => array(), 'status' => 'info');
		}
	}

	/**
	 * Add a finding to a section.
	 *
	 * @param string $id Section to add to
	 * @param string $status 'ok', 'fail' (stops the access), 'warn' (may stop it), 'unknown' or 'info'
	 * @param array $text Parts of the text
	 * @param array $details Further lines, each an array of parts
	 */
	private function add(string $id, string $status, array $text, array $details = array()) {
		$this->sections[$id]['findings'][] = array('status' => $status, 'text' => $text, 'details' => $details);
		$rank = array('info' => 0, 'ok' => 1, 'unknown' => 2, 'warn' => 3, 'fail' => 4);
		if($rank[$status] > $rank[$this->sections[$id]['status']]) {
			$this->sections[$id]['status'] = $status;
		}
	}

	/**
	 * Add a finding that means SKA does not grant the access, and remember it as a reason for the verdict.
	 */
	private function deny(string $id, array $text, array $details = array()) {
		$this->add($id, 'fail', $text, $details);
		$this->reasons[] = $text;
		// What to do about it belongs next to the reason
		$this->reason_details[count($this->reasons) - 1] = array_slice($details, 0, 6);
	}

	/**
	 * Like deny(), for a finding that merely follows from an earlier one: it is only given as a reason for the
	 * verdict if nothing else explains it yet.
	 */
	private function because(string $id, array $text, array $details = array()) {
		if(count($this->reasons) == 0) {
			$this->deny($id, $text, $details);
		} else {
			$this->add($id, 'fail', $text, $details);
		}
	}

	/**
	 * Name the user or account whose access rules are examined.
	 */
	private function subject_label(): array {
		if(is_null($this->subject)) {
			return array($this->who['kind'] == 'unknown_user' ? 'this user' : 'this key');
		}
		return array($this->subject);
	}

	/**
	 * Name who the verdict is about. If a key was given, that is whoever holds this key: the user that it is
	 * registered for may well get in with other keys.
	 */
	private function who_label(): array {
		if($this->who['kind'] == 'key' && !is_null($this->subject)) {
			return array('the holder of this key (registered for ', $this->subject, ')');
		}
		return $this->subject_label();
	}

	/**
	 * Whether a single key is examined, rather than all keys of a user.
	 */
	private function by_key(): bool {
		return $this->who['kind'] == 'key';
	}

	private function same_entity(Entity $a, Entity $b): bool {
		return get_class($a) == get_class($b) && $a->id == $b->id;
	}

	private static function plural(int $count, string $singular, ?string $plural = null): string {
		return $count.' '.($count == 1 ? $singular : ($plural ?? $singular.'s'));
	}

	// ---------------------------------------------------------------------------------------------------------
	// Who

	private function examine_who() {
		$id = 'who';
		$this->section($id, 'Who is logging in');
		switch($this->who['kind']) {
		case 'user':
			$this->subject = $this->who['user'];
			foreach($this->subject->list_public_keys(null, null, false) as $key) {
				$this->keys[$key->id] = $key;
			}
			$this->examine_user($id, $this->subject);
			if(count($this->keys) > 0) {
				$parts = array($this->subject, ' has '.self::plural(count($this->keys), 'public key').' in SKA: ');
				foreach(array_values($this->keys) as $index => $key) {
					if($index > 0) $parts[] = ', ';
					$parts[] = $key;
				}
				$this->add($id, 'ok', $parts);
			} else {
				$deleted = $this->subject->count_deleted_public_keys();
				$this->deny($id, array($this->subject, ' has no public key in SKA'.($deleted > 0 ? ' ('.self::plural($deleted, 'key').' deleted)' : '').', so there is nothing that SKA could put on a server.'));
			}
			break;
		case 'key':
			$found = $this->who['keys'];
			$active = array_values(array_filter($found, function($key) { return is_null($key->deletion_date); }));
			$key = count($active) > 0 ? $active[0] : $found[0];
			$this->subject = $key->owner;
			if(count($active) > 0) {
				$this->keys[$key->id] = $key;
				$this->add($id, 'ok', array('Key ', $key, ' is registered in SKA and belongs to ', $this->subject, '.'));
			} else {
				$this->deny($id, array('Key ', $key, ' of ', $this->subject, ' was deleted from SKA on '.$key->deletion_date.'. Deleted keys are removed from all servers.'));
			}
			foreach($found as $other) {
				if($other->id == $key->id) continue;
				if($this->same_entity($other->owner, $this->subject)) {
					if(!is_null($other->deletion_date)) {
						$this->add($id, 'info', array($this->subject, ' uploaded the same key once before and deleted that entry on '.$other->deletion_date.'.'));
					}
					continue;
				}
				$this->add($id, 'warn', array_merge(
					array('The same key is also registered for ', $other->owner, is_null($other->deletion_date) ? '' : ' (deleted there on '.$other->deletion_date.')', '. The access rules of ', $this->subject, ' are examined here'),
					$other->owner instanceof User ? array('; ', array('url' => self::url($other->owner->uid, $this->server->hostname, $this->login), 'text' => 'examine '.$other->owner->uid.' instead')) : array(),
					array('.')
				));
			}
			if($this->subject instanceof User) {
				$this->add($id, 'info', array('Only this key is examined. ', array('url' => self::url($this->subject->uid, $this->server->hostname, $this->login), 'text' => 'Examine all keys of '.$this->subject->uid), ' to see whether the user gets in with another key.'));
			}
			if($this->subject instanceof User) {
				$this->examine_user($id, $this->subject);
			} elseif($this->subject instanceof ServerAccount) {
				$this->add($id, 'info', array('This is a server-to-server key: it belongs to an account on a server, not to a person.'));
				if($this->subject->server->key_management == 'decommissioned') {
					$this->deny($id, array('The server of ', $this->subject, ' is decommissioned, so its keys are no longer written to any server.'));
				}
			}
			break;
		case 'unknown_key':
			$this->deny($id, array('The key ', array('code' => $this->who['fingerprint']), ' is not registered in SKA. SKA only distributes keys that a user has uploaded.'));
			break;
		case 'unknown_user':
			$this->deny($id, array('There is no user ', array('code' => $this->who['canonical']), ' in SKA. Users appear in SKA when they log in for the first time or are synced from LDAP.'));
			break;
		default:
			$this->deny($id, array('The input was not recognised as a user name, a public key or a key fingerprint.'));
		}
	}

	private function examine_user(string $id, User $user) {
		if($user->active) {
			$this->add($id, 'ok', array($user, ' ('.$user->name.') is an active user.'));
		} else {
			$this->deny($id, array($user, ' is inactive (disabled or no longer found in LDAP). SKA writes no keys for inactive users and removes them from the LDAP groups.'));
		}
		if($user->force_disable) {
			$this->add($id, 'info', array($user, ' is blocked from logging in to SKA itself. That does not stop the keys from being distributed.'));
		}
	}

	// ---------------------------------------------------------------------------------------------------------
	// Server

	private function examine_server() {
		$id = 'server';
		$this->section($id, 'Server');
		$server = $this->server;
		switch($server->key_management) {
		case 'keys':
			$this->add($id, 'ok', array($server, ' has its SSH keys managed by SKA.'));
			break;
		case 'decommissioned':
			$this->deny($id, array($server, ' is decommissioned. SKA removes all key files from decommissioned servers.'));
			break;
		case 'other':
			$this->deny($id, array($server, ' is set to be managed by another system. SKA writes no key files to it.'));
			break;
		default:
			$this->deny($id, array($server, ' has SSH key management disabled. SKA writes no key files to it and leaves existing ones alone.'));
		}
		if($server->key_management != 'keys') {
			$this->link['label'] = 'server not managed by SKA';
			return;
		}
		if($server->authorization != 'manual') {
			$this->add($id, 'info', array('Accounts are authorized through LDAP ('.$server->authorization.'): every SKA user with a key also gets a key file under their own user name, without any access rule.'));
		}
		$event = $server->get_last_sync_event();
		$message = null;
		if(!is_null($event)) {
			$details = json_decode($event->details);
			$message = is_object($details) && isset($details->value) ? ' "'.$details->value.'" (unchanged since '.$event->date.' UTC)' : null;
		}
		switch($server->sync_status) {
		case 'sync success':
			$this->add($id, 'ok', array('The last sync to this server succeeded.'));
			break;
		case 'sync failure':
			$this->add($id, 'fail', array('The last sync to this server failed:', $message ?? '', '. Until a sync succeeds, changes made in SKA do not reach the server, which keeps the key files of the last successful sync.'));
			$this->sync_problem = array('Syncs to this server are failing:', $message ?? '', '. Changes made in SKA do not reach it; it keeps the key files of the last successful sync. See ', array('url' => '/help#sync_error', 'text' => 'sync errors'), '.');
			break;
		case 'not synced yet':
			$this->add($id, 'warn', array('This server has not been synced yet, so it may have no key files.'));
			$this->sync_problem = array('This server has not been synced yet, so it may have no key files.');
			break;
		default:
			$this->add($id, 'warn', array('Sync status: '.$server->sync_status, $message ?? '', '.'));
		}
		if(count($server->list_sync_requests()) > 0) {
			$this->add($id, 'info', array('A sync of this server is waiting to be run right now.'));
		}
	}

	// ---------------------------------------------------------------------------------------------------------
	// Login account and access rules

	private function examine_login() {
		$id = 'account';
		$this->section($id, 'Login account');
		$server = $this->server;
		$file = array('code' => ServerHostFacts::KEYDIR.'/'.$this->keyfile_name);
		if($this->keyfile_name === 'keys-sync') {
			$this->deny($id, array(array('code' => 'keys-sync'), ' is the account that SKA itself uses to reach the server. Its key file is set up by hand when the server is added and is never written by the sync.'));
			$this->link['label'] = 'reserved for SKA itself';
			return;
		}
		// The account that the sync writes this file for, if any
		$keyfile_accounts = list_keyfile_accounts($server);
		if(isset($keyfile_accounts[$this->keyfile_name])) {
			$this->account = $keyfile_accounts[$this->keyfile_name];
			$this->add($id, 'ok', array($this->account, ' is an account managed by SKA. Its keys are written to ', $file, '.'));
			$this->examine_rules();
			return;
		}
		try {
			$account = $server->get_account_by_name($this->login);
			if(!$account->active) {
				$this->add($id, 'info', array('The account ', array('code' => $this->login), ' was managed by SKA on this server before, but has been removed.'));
			} elseif($account->sync_status == 'proposed') {
				$this->add($id, 'info', array($account, ' has only been requested so far. Until an access request for it is approved, SKA writes no key file for it.'));
			}
		} catch(ServerAccountNotFoundException $e) {
		}
		if($server->authorization != 'manual') {
			$this->examine_ldap_login($id, $file);
			return;
		}
		$this->link['label'] = 'not managed by SKA';
		$names = array();
		foreach($keyfile_accounts as $filename => $account) {
			if(count($names) > 0) $names[] = ', ';
			// The login is the name of the key file; an account name may contain slashes, a login cannot
			$names[] = $this->rerun_link((string)$filename, $account->name);
		}
		$this->deny($id, array(array('code' => $this->login), ' is not an account that SKA manages on ', $server, '. SKA writes no key file for it, so it grants nobody access to it.'),
			array(
				count($names) > 0 ? array_merge(array('Managed accounts on this server: '), $names, array('.')) : array('SKA manages no accounts on this server.'),
				array('To manage it, add the account on the ', array('url' => '/servers/'.urlencode($server->hostname).'#accounts', 'text' => 'Accounts tab of the server'), '.'),
			)
		);
	}

	/**
	 * On servers with LDAP authorization, a login that is no managed account gets the keys of the SKA user of
	 * the same name.
	 */
	private function examine_ldap_login(string $id, array $file) {
		global $user_dir;
		// As in the sync: of the users whose name maps to this file name, the first one that has a key for this
		// server gets the file.
		$owner = null;
		$keyfile = null;
		try {
			foreach($user_dir->list_users() as $user) {
				if(str_replace('/', '', $user->uid) !== $this->keyfile_name) continue;
				$candidate = build_ldap_user_keyfile($this->server, $user);
				if(is_null($owner) || !is_null($candidate)) {
					$owner = $user;
					$keyfile = $candidate;
				}
				if(!is_null($candidate)) break;
			}
		} catch(Throwable $e) {
			$this->deny($id, array('SKA cannot generate the key file for ', array('code' => $this->login), ': '.$e->getMessage()));
			return;
		}
		$this->link['label'] = 'not managed by SKA';
		if(is_null($owner)) {
			$this->deny($id, array(array('code' => $this->login), ' is neither a managed account on ', $this->server, ' nor the name of a SKA user, so SKA writes no key file for it.'));
			return;
		}
		$this->link['label'] = 'only holds the keys of '.$owner->uid;
		$this->add($id, 'ok', array(array('code' => $this->login), ' is the user name of ', $owner, '. On this server every SKA user gets a key file with their own keys: ', $file, '.'));
		$this->expected_keyfile = $keyfile;
		$this->collect_expected_lines();
		if(is_null($this->subject) || !$this->same_entity($this->subject, $owner)) {
			$text = array('That file only ever contains the keys of ', $owner, ' and no access rules apply to it. To let somebody else log in as ', array('code' => $this->login), ', add it as a managed account and grant access to it.');
			if(count($this->expected_lines) > 0) {
				// The same key material is registered for the owner of the file as well
				$this->add($id, 'info', $text);
				$this->foreign_registration = true;
				$this->add($id, 'warn', array_merge(array('One of those keys is the same key that is registered for '), $this->subject_label(), array(', so the file contains it. Whoever holds the private key can log in.')));
			} else {
				$this->deny($id, $text);
			}
			return;
		}
		$this->link = array('type' => 'ldap', 'label' => 'own key file');
		if(is_null($this->expected_keyfile)) {
			$this->deny($id, array($owner, ' has no key that may be used for ', array('code' => $this->login.'@'.$this->server->hostname), ', so SKA writes no file.'));
			return;
		}
		$this->examine_keys($id, $owner, $this->keyfile_name);
		if(count($this->expected_lines) > 0) {
			$this->add($id, 'ok', $this->written_text(array('this file')));
		} else {
			$this->because($id, $this->not_written_text(array('this file')));
		}
		if($this->server->authorization == 'manual LDAP') {
			$this->add($id, 'info', array('With "manual LDAP" authorization the sync only keeps the file if the Linux user exists on the server.'));
		}
	}

	/**
	 * Find the chains of groups through which an entity is a member of each of its groups. Which groups those
	 * are, and so which rules apply, is decided by list_account_rule_sets(); this only supplies the way there,
	 * for display.
	 *
	 * @return array group id => array of Group, from the group that the entity is directly in to that group
	 */
	private function membership_chains(Entity $entity, array $chain = array(), array &$found = array()): array {
		global $database;
		$stmt = $database->prepare("
			SELECT `group`.entity_id
			FROM group_member
			INNER JOIN `group` ON `group`.entity_id = group_member.group
			WHERE group_member.entity_id = ?
			ORDER BY `group`.name
		");
		$stmt->bind_param('d', $entity->id);
		$stmt->execute();
		$ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'entity_id');
		$stmt->close();
		foreach($ids as $group_id) {
			$group = new Group($group_id);
			$in_chain = false;
			foreach($chain as $via) {
				if($via->id == $group_id) $in_chain = true;
			}
			$this_chain = array_merge($chain, array($group));
			if(!isset($found[$group_id])) {
				$found[$group_id] = $this_chain;
			}
			if(!$in_chain) {
				$this->membership_chains($group, $this_chain, $found);
			}
		}
		return $found;
	}

	/**
	 * Decide whether an access rule covers the subject. Whom a rule covers is worked out by
	 * expand_access_source(), which the sync writes the key files with.
	 *
	 * @param Access $access The rule
	 * @return array with 'covers' (the rule names the subject, directly or through a group), 'effective'
	 *               (the sync writes keys because of it), 'paths' (for group rules: for each way in which the
	 *               subject is a member, the nested groups between the group of the rule and the subject)
	 *               and 'note' (parts)
	 */
	private function match_rule(Access $access): array {
		$source = $access->source_entity;
		$result = array('covers' => false, 'effective' => false, 'paths' => array(), 'note' => array());
		if($source instanceof Group) {
			if(!isset($this->grantees_cache[$source->id])) {
				// Also for an inactive group, to be able to say that the rule would cover the subject
				$this->grantees_cache[$source->id] = list_access_grantees(expand_access_source($source, true));
			}
			foreach($this->grantees_cache[$source->id] as $grantee) {
				if(!is_null($this->subject) && $this->same_entity($grantee['entity'], $this->subject)) {
					$result['paths'][] = $grantee['via'];
				}
			}
			$result['covers'] = count($result['paths']) > 0;
			if(!$source->active) {
				$result['note'] = array('This group is inactive, so the sync ignores the rule.');
			} elseif($result['covers']) {
				$result['effective'] = true;
			}
		} elseif(!is_null($this->subject) && $this->same_entity($source, $this->subject)) {
			$result['covers'] = true;
			$result['effective'] = true;
		}
		if($result['effective'] && $this->subject instanceof User && !$this->subject->active) {
			$result['effective'] = false;
			$result['note'] = array('The user is inactive, so no keys are written.');
		}
		if($result['effective'] && $this->subject instanceof ServerAccount && $this->subject->server->key_management == 'decommissioned') {
			$result['effective'] = false;
			$result['note'] = array('The server of this account is decommissioned, so no keys are written.');
		}
		return $result;
	}

	/**
	 * Collect the rules that apply to an account, in the order in which the sync processes them, and how each
	 * relates to the subject. The rules come from list_account_rule_sets(), like those of the sync.
	 *
	 * @return array of array('target' => ServerAccount|Group, 'chain' => groups leading from the account to the
	 *               target, 'active' => whether the sync uses the rules of this target, 'rules' => array of
	 *               array('access', 'options', 'match'))
	 */
	private function collect_rule_sets(ServerAccount $account): array {
		$sets = array();
		$chains = $this->membership_chains($account);
		$listed = array();
		// With the rules of inactive groups, to show that they exist and are not applied
		foreach(list_account_rule_sets($account, true) as $rule_set) {
			$entity = $rule_set['target'];
			$key = get_class($entity).$entity->id;
			// A group that the account is in by more than one way has its rules written again; shown once here
			if(isset($listed[$key])) continue;
			$listed[$key] = true;
			$set = array(
				'target' => $entity,
				'chain' => $entity instanceof Group ? ($chains[$entity->id] ?? array($entity)) : array(),
				'active' => $rule_set['in_effect'],
				'rules' => array(),
			);
			foreach($rule_set['rules'] as $access) {
				$match = $this->match_rule($access);
				if(!$set['active']) {
					$match['effective'] = false;
				}
				$set['rules'][] = array('access' => $access, 'options' => $access->list_options(), 'match' => $match);
			}
			$sets[] = $set;
		}
		return $sets;
	}

	private function examine_rules() {
		$id = 'rules';
		$this->section($id, 'Access rules');
		$account = $this->account;
		// The file is written for the account whoever is granted access, so this does not depend on the subject
		try {
			$this->expected_keyfile = build_account_keyfile($this->server, $account);
		} catch(Throwable $e) {
			$this->deny($id, array('SKA cannot generate the key file of ', $account, ': '.$e->getMessage().'. The sync of this server fails for the same reason.'));
		}
		$this->collect_expected_lines();
		$sets = $this->collect_rule_sets($account);
		$this->sections[$id]['rule_sets'] = $sets;

		$groups = array();
		$rule_count = 0;
		$covering = array();
		$effective = array();
		foreach($sets as $set) {
			if($set['target'] instanceof Group) {
				if(count($groups) > 0) $groups[] = ', ';
				$groups[] = $set['target'];
				if(!$set['active']) {
					$this->add($id, 'warn', array('Group ', $set['target'], ' is inactive. The sync ignores the access rules of inactive groups.'));
				}
			}
			foreach($set['rules'] as $rule) {
				$rule_count++;
				if($rule['match']['covers']) $covering[] = array($set, $rule);
				if($rule['match']['effective']) $effective[] = array($set, $rule);
			}
		}
		if(count($groups) > 0) {
			$this->add($id, 'info', array_merge(array($account, ' is a member of: '), $groups, array('. The access rules of these groups apply to it in addition to its own.')));
		} else {
			$this->add($id, 'info', array($account, ' is not a member of any group, so only rules granted to the account itself apply.'));
		}

		$this->link['label'] = is_null($this->subject) ? '' : 'no access rule covers this '.($this->subject instanceof ServerAccount ? 'account' : 'user');
		if($rule_count == 0) {
			$this->link['label'] = 'nobody has been granted access';
			$where = array();
			foreach($sets as $set) {
				if(!($set['target'] instanceof Group)) continue;
				$where[] = array('Group ', $set['target'], ' has no access rules. Add one on its ', array('url' => '/groups/'.urlencode($set['target']->name).'#access', 'text' => 'Access tab'), ' to grant access to all accounts in the group.');
			}
			$where[] = array('Or grant access to this account alone on its ', array('url' => '/servers/'.urlencode($this->server->hostname).'/accounts/'.urlencode($account->name).'#access', 'text' => 'Access tab'), '.');
			$this->deny($id, array('Nobody has been granted access to ', $account, ': neither the account nor any of its groups has an access rule.'), $where);
			return;
		}
		if(is_null($this->subject)) {
			$this->add($id, 'info', array(self::plural($rule_count, 'access rule applies', 'access rules apply').' to ', $account, ' (listed below).'));
			return;
		}
		// Where access could be granted, for the cases in which it is not
		$grant_hints = array(array('To grant access, add a rule on the ', array('url' => '/servers/'.urlencode($this->server->hostname).'/accounts/'.urlencode($account->name).'#access', 'text' => 'Access tab of the account'), '.'));
		foreach($sets as $set) {
			if($set['target'] instanceof Group && $set['active']) {
				$grant_hints[] = array('A rule on the ', array('url' => '/groups/'.urlencode($set['target']->name).'#access', 'text' => 'Access tab'), ' of group ', $set['target'], ' grants access to all accounts in that group.');
			}
		}
		if(count($covering) == 0) {
			global $group_dir;
			$details = array();
			foreach($sets as $set) {
				foreach($set['rules'] as $rule) {
					$source = $rule['access']->source_entity;
					if($source instanceof Group && $this->subject instanceof User) {
						// Group objects that were not loaded by the directory do not know their "system" flag
						$ldap = $group_dir->get_group_by_id($source->id)->system;
						$details[] = array($this->subject, ' is not a member of ', $source, $ldap ? '. Membership of that group comes from LDAP and is changed there.' : '. Adding the user to that group would grant access.');
					}
				}
			}
			$text = $rule_count == 1
				? array_merge(array('The only access rule for ', $account, ' does not cover '), $this->subject_label(), array('.'))
				: array_merge(array('None of the '.$rule_count.' access rules for ', $account, ' covers '), $this->subject_label(), array('.'));
			if(count($this->expected_lines) > 0) {
				$this->add($id, 'warn', $text, $details);
				$this->examine_foreign_registration($id, $this->keys);
			} else {
				$this->deny($id, $text, array_merge($details, $grant_hints));
			}
			return;
		}
		foreach($covering as list($set, $rule)) {
			$text = array_merge($this->subject_label(), $this->rule_description($set, $rule));
			if($rule['match']['effective']) {
				$this->add($id, 'ok', $text);
			} else {
				$note = $rule['match']['note'];
				if(!$set['active']) {
					$note = array('Group ', $set['target'], ' is inactive, so the sync ignores this rule.');
				}
				$this->add($id, 'warn', array_merge($text, array(' '), $note));
			}
		}
		$this->trace = array('covering' => count($covering), 'effective' => count($effective));
		$this->link['label'] = 'access rule not in effect';
		if(count($effective) > 0) {
			$this->link = array('type' => 'rule', 'label' => '', 'set' => $effective[0][0], 'rule' => $effective[0][1], 'more' => count($effective) - 1);
		}
		if(count($effective) == 0) {
			$text = array_merge(array('No access rule that covers '), $this->subject_label(), array(' is in effect.'));
			if(count($this->expected_lines) > 0) {
				$this->add($id, 'warn', $text);
				$this->examine_foreign_registration($id, $this->keys);
			} else {
				$this->because($id, $text);
			}
			return;
		}

		$this->section('keys', 'Keys written for this access');
		$this->examine_keys('keys', $this->subject, $account->name);
		foreach($effective as list($set, $rule)) {
			$restricting = array();
			foreach($rule['options'] as $option) {
				$restricting[] = array('code' => $option->option.(is_null($option->value) ? '' : '="'.$option->value.'"'));
				$restricting[] = ' ';
			}
			if(count($restricting) > 0) {
				$this->add('keys', 'info', array_merge(array('The rule for ', $rule['access']->source_entity, ' on ', $set['target'], ' adds these options to the key lines: '), $restricting, array('— they restrict what the login may do (see ', array('url' => $this->access_options_url($set, $rule), 'text' => 'access options'), ').')));
			}
		}
		$file = array(array('code' => ServerHostFacts::KEYDIR.'/'.$this->keyfile_name));
		// Lines of keys that destination rules keep away can only come from another registration of the same
		// key: one by somebody else, or a second upload by the same user that the rules let through
		$own = array_diff_key($this->expected_lines, $this->blocked_keys);
		$own_material = array();
		foreach($this->subject->list_public_keys($account->name, $this->server->hostname, false) as $key) {
			$own_material[$key->type.' '.$key->keydata] = true;
		}
		$foreign = array();
		foreach(array_intersect_key($this->keys, $this->blocked_keys, $this->expected_lines) as $key) {
			if(isset($own_material[$key->type.' '.$key->keydata])) {
				$this->add('keys', 'info', array('Key ', $key, ' is written all the same: ', $this->subject, ' has uploaded the same key a second time, without that restriction.'));
				$own[$key->id] = $this->expected_lines[$key->id];
			} else {
				$foreign[$key->id] = $key;
			}
		}
		if(count($foreign) > 0) {
			// Only if nothing is written for the subject's own registrations is the access not their own
			$this->examine_foreign_registration('keys', $foreign, count($own) == 0);
		}
		if(count($own) > 0) {
			$this->add('keys', 'ok', $this->written_text($file));
		} elseif(count($foreign) == 0 && !is_null($this->expected_keyfile)) {
			$this->because('keys', $this->not_written_text($file));
		}
	}

	/**
	 * Say that keys are written, in words that fit what was asked for (a user or a single key).
	 *
	 * @param array $file Parts that name the key file
	 */
	private function written_text(array $file): array {
		$lines = self::plural($this->count_expected_lines(), 'line');
		if($this->by_key()) {
			return array_merge(array('SKA writes this key to '), $file, array(' ('.$lines.').'));
		}
		return array_merge(array('SKA writes '.$lines.' with keys of '), $this->subject_label(), array(' to '), $file, array('.'));
	}

	private function not_written_text(array $file): array {
		if($this->by_key()) {
			return array_merge(array('SKA does not write this key to '), $file, array('.'));
		}
		return array_merge(array('SKA writes no key of '), $this->subject_label(), array(' to '), $file, array('.'));
	}

	/**
	 * Tell whether any access rule for an account is in effect for the given user or server account.
	 */
	private function has_effective_rule(Entity $entity, ServerAccount $account): bool {
		$subject = $this->subject;
		$this->subject = $entity;
		try {
			foreach($this->collect_rule_sets($account) as $set) {
				foreach($set['rules'] as $rule) {
					if($rule['match']['effective']) return true;
				}
			}
			return false;
		} finally {
			$this->subject = $subject;
		}
	}

	/**
	 * The key file contains keys of the subject although no rule (or no destination rule) lets the subject's
	 * own registration of them through. That happens when the same key is registered a second time, for
	 * somebody who does have access.
	 *
	 * @param string $id Section to report in
	 * @param array $keys The keys concerned, indexed by key id
	 * @param bool $only_access Whether this is the only way in which the subject's keys get into the file
	 */
	private function examine_foreign_registration(string $id, array $keys, bool $only_access = true) {
		global $pubkey_dir;
		if($only_access) {
			$this->foreign_registration = true;
		}
		$others = array();
		foreach($keys as $key) {
			if(!isset($this->expected_lines[$key->id])) continue;
			foreach($pubkey_dir->list_public_keys(array(), array('fingerprint' => $key->fingerprint_sha256)) as $other) {
				if($other->id == $key->id || !is_null($other->deletion_date) || $other->fingerprint_sha256 !== $key->fingerprint_sha256) continue;
				if(!is_null($this->subject) && $this->same_entity($other->owner, $this->subject)) continue;
				// Name only those for whom the key is really written here: they need an access rule that is in
				// effect, and their registration of the key must be allowed for this account
				if(!$this->has_effective_rule($other->owner, $this->account)) continue;
				$allowed = false;
				foreach($other->owner->list_public_keys($this->account->name, $this->server->hostname, false) as $candidate) {
					if($candidate->id == $other->id) $allowed = true;
				}
				if(!$allowed) continue;
				$others[get_class($other->owner).$other->owner->id] = $other->owner;
			}
		}
		$names = array();
		foreach(array_values($others) as $index => $owner) {
			if($index > 0) $names[] = ', ';
			$names[] = $owner;
		}
		$this->add($id, 'warn', array_merge(
			array('The key file of ', $this->account, ' nevertheless contains '.self::plural($this->count_expected_lines($keys), 'line').' with '.($this->by_key() ? 'this key' : 'a key of '.($this->subject instanceof User ? $this->subject->uid : 'this account')).'. The same key is also registered for '),
			count($names) > 0 ? $names : array('somebody else'),
			array(count($others) > 1 ? ', who have access, and it is written for that reason.' : ', who has access, and it is written for that reason.', ' Whoever holds the private key can log in.')
		));
	}

	/**
	 * Describe how a rule reaches from the subject to the account, as parts that follow the subject's name.
	 */
	private function rule_description(array $set, array $rule): array {
		$access = $rule['access'];
		$source = $access->source_entity;
		$parts = array();
		if($source instanceof Group) {
			$path = $rule['match']['paths'][0] ?? array();
			$parts[] = ' is a member of ';
			foreach(array_reverse($path) as $nested) {
				$parts[] = $nested;
				$parts[] = ', which is a member of ';
			}
			$parts[] = $source;
			$parts[] = '. That group was granted access to ';
		} else {
			$parts[] = ' was granted access to ';
		}
		$parts[] = $set['target'];
		$parts[] = ' by ';
		$parts[] = $access->granted_by;
		$parts[] = ' on '.$access->grant_date.' UTC';
		if($set['target'] instanceof Group) {
			$parts[] = ', and ';
			$parts[] = $this->account;
			$parts[] = ' is a member of ';
			$chain = $set['chain'];
			foreach($chain as $index => $group) {
				if($index > 0) $parts[] = ', which is a member of ';
				$parts[] = $group;
			}
		}
		$parts[] = '.';
		return $parts;
	}

	private function access_options_url(array $set, array $rule): string {
		$target = $set['target'];
		if($target instanceof Group) {
			return '/groups/'.urlencode($target->name).'/access_rules/'.urlencode($rule['access']->id);
		}
		return '/servers/'.urlencode($target->server->hostname).'/accounts/'.urlencode($target->name).'/access_rules/'.urlencode($rule['access']->id);
	}

	/**
	 * Check which of the considered keys may be used for the account: a key with destination rules is only
	 * written where one of them matches.
	 *
	 * @param string $id Section to report in
	 * @param Entity $owner Owner of the keys
	 * @param string $account_name Account name that the destination rules are compared with
	 */
	private function examine_keys(string $id, Entity $owner, string $account_name) {
		$allowed = array();
		foreach($owner->list_public_keys($account_name, $this->server->hostname, false) as $key) {
			$allowed[$key->id] = true;
		}
		foreach($this->keys as $key) {
			if(isset($allowed[$key->id])) continue;
			$rules = array();
			foreach($key->list_destination_rules() as $rule) {
				$rules[] = array('Allowed for account ', array('code' => $rule->account_name_filter), ' on server ', array('code' => $rule->hostname_filter), '.');
			}
			$this->add($id, 'warn', array('Key ', $key, ' is not written for this access: its ', array('url' => '/pubkeys/'.urlencode($key->id).'#dest', 'text' => 'destination rules'), ' do not match ', array('code' => $account_name.'@'.$this->server->hostname), '.'), $rules);
			$this->blocked_keys[$key->id] = true;
		}
		if(count($this->keys) > 0 && count($this->blocked_keys) == count($this->keys) && count($this->expected_lines) == 0) {
			$target = array('code' => $account_name.'@'.$this->server->hostname);
			$this->deny($id, $this->by_key() ? array('Destination rules keep this key away from ', $target, '.') : array_merge(array('Destination rules keep every key of '), $this->subject_label(), array(' away from ', $target, '.')));
		}
	}

	/**
	 * Find the lines of the considered keys in the key file that the sync code generates.
	 */
	private function collect_expected_lines() {
		$this->expected_lines = array();
		if(is_null($this->expected_keyfile)) {
			return;
		}
		foreach(explode("\n", $this->expected_keyfile) as $number => $line) {
			if($line === '' || $line[0] === '#') continue;
			foreach($this->keys as $key) {
				$needle = $key->type.' '.$key->keydata;
				$pos = strpos($line, $needle);
				if($pos === false) continue;
				$after = substr($line, $pos + strlen($needle), 1);
				if($after === '' || $after === ' ') {
					$this->expected_lines[$key->id][$number] = $line;
				}
			}
		}
	}

	/**
	 * Count the lines of the key file that hold one of the considered keys. A key that was uploaded twice
	 * matches the same lines twice; each line counts once.
	 */
	private function count_expected_lines(?array $keys = null): int {
		$lines = array();
		foreach($this->expected_lines as $key_id => $key_lines) {
			if(!is_null($keys) && !isset($keys[$key_id])) continue;
			foreach($key_lines as $number => $line) {
				$lines[$number] = true;
			}
		}
		return count($lines);
	}

	private function rerun_link(string $login, string $text): array {
		return array('url' => self::url($this->who['canonical'], $this->server->hostname, $login), 'text' => $text);
	}

	/**
	 * Point out other accounts on the server that the subject does get keys for. Somebody who "always had
	 * access" often had it to a different account.
	 */
	private function examine_other_accounts() {
		if(is_null($this->subject) || $this->server->key_management != 'keys') {
			return;
		}
		$accounts = array();
		foreach(list_keyfile_accounts($this->server) as $filename => $account) {
			if(!is_null($this->account) && $account->entity_id == $this->account->entity_id) continue;
			foreach($this->collect_rule_sets($account) as $set) {
				foreach($set['rules'] as $rule) {
					if($rule['match']['effective']) {
						$accounts[(string)$filename] = $account;
					}
				}
			}
		}
		if(count($accounts) == 0) {
			return;
		}
		$this->section('other', 'Other accounts on this server');
		$parts = array_merge($this->subject_label(), array(' is covered by access rules for: '));
		$index = 0;
		foreach($accounts as $filename => $account) {
			if($index++ > 0) $parts[] = ', ';
			$parts[] = $account;
			$parts[] = ' (';
			$parts[] = $this->rerun_link((string)$filename, 'explain');
			$parts[] = ')';
		}
		$parts[] = '.';
		$this->add('other', 'info', $parts);
	}

	// ---------------------------------------------------------------------------------------------------------
	// Target server

	private function examine_delivery_and_host() {
		$id = 'host';
		$server = $this->server;
		$stored = ServerHostFacts::load($server);
		if($server->key_management != 'keys' && is_null($stored)) {
			return;
		}
		$this->section('delivery', 'Key file on the server');
		if(!is_null($this->account) && $this->account->sync_status != 'sync success') {
			$this->add('delivery', 'warn', array('Sync status of ', $this->account, ': '.$this->account->sync_status.'.'));
		}
		if(is_null($stored)) {
			$this->add('delivery', 'unknown', array('SKA has no information from this server yet. It is collected whenever SKA connects to the server: at each sync, and during the regular scan for external keys.'));
			return;
		}
		$facts = $stored['facts'];
		$this->facts = $stored;
		$age = $stored['age'];
		if($age < 120) {
			$age_text = 'just now';
		} elseif($age < 7200) {
			$age_text = round($age / 60).' minutes ago';
		} elseif($age < 172800) {
			$age_text = round($age / 3600).' hours ago';
		} else {
			$age_text = round($age / 86400).' days ago';
		}
		$this->age_text = $age_text;
		$snapshot = ' (as of '.$stored['collected'].' UTC, '.$age_text.')';
		foreach($facts['errors'] ?? array() as $error) {
			$this->add('delivery', 'warn', array('While collecting information from the server: '.$error));
		}
		if(!empty($facts['refresh_failed']['time'])) {
			$this->add('delivery', 'warn', array('The last attempt to renew this information, on '.gmdate('Y-m-d H:i:s', (int)$facts['refresh_failed']['time']).' UTC, failed: '.implode('; ', array_map('strval', (array)($facts['refresh_failed']['errors'] ?? array()))).' What is shown here is the older information.'));
		} elseif(!empty($facts['refresh_requested'])) {
			$this->add('delivery', 'info', array('A sync has run since this information was collected, and it took too long to collect it again right away. It is renewed with the next regular scan of the server, within a few minutes.'));
		}

		// Is the file there, and is it the file that SKA would write now?
		$keyfile = null;
		if(empty($facts['complete'])) {
			$this->add('delivery', 'unknown', array('The key directory on the server could not be listed'.$snapshot.'.'));
		} else {
			$keyfile = $facts['keyfiles'][$this->keyfile_name] ?? null;
			$file = array('code' => ServerHostFacts::KEYDIR.'/'.$this->keyfile_name);
			$listing_incomplete = !empty($facts['keyfiles_truncated']);
			if($this->keyfile_name === 'keys-sync') {
				if(!is_null($keyfile)) {
					$this->add('delivery', 'info', array('The file ', $file, ' exists on the server'.$snapshot.'. It lets SKA itself in, is maintained by hand and is not compared with anything.'));
				}
			} elseif(is_null($keyfile) && $listing_incomplete) {
				$this->add('delivery', 'unknown', array('The key directory on the server could not be listed completely'.$snapshot.', so SKA cannot say whether ', $file, ' exists.'));
			} elseif(is_null($this->expected_keyfile)) {
				if(!is_null($keyfile)) {
					if($server->key_management == 'keys') {
						$fate = ' A full sync of the server removes files that SKA does not manage.';
					} elseif($server->key_management == 'decommissioned') {
						$fate = ' The sync of a decommissioned server removes it.';
					} else {
						$fate = ' SKA no longer touches this server, so the file stays in effect until somebody removes it by hand.';
					}
					$this->add('delivery', 'warn', array('There is a file ', $file, ' on the server'.$snapshot.' although SKA would not write one now. SKA does not know its content; it may still let somebody in.'.$fate));
					$this->delivery = 'stray';
				} elseif($server->key_management == 'keys') {
					$this->add('delivery', 'info', array('There is no file ', $file, ' on the server'.$snapshot.', as expected.'));
				}
			} elseif(is_null($keyfile)) {
				$this->add('delivery', 'fail', array('The file ', $file, ' does not exist on the server'.$snapshot.'. SKA has not delivered it yet.'));
				$this->delivery = 'missing';
			} elseif(is_null($keyfile['sha1'])) {
				$this->add('delivery', 'unknown', array('The file ', $file, ' exists on the server but could not be read to compare it'.$snapshot.'.'));
			} elseif($keyfile['sha1'] === sha1($this->expected_keyfile)) {
				$this->add('delivery', 'ok', array('The file ', $file, ' on the server is identical to what SKA generates now'.$snapshot.'.'));
				$this->delivery = 'current';
			} else {
				$this->add('delivery', 'warn', array('The file ', $file, ' on the server differs from what SKA generates now'.$snapshot.'. It was last written on '.gmdate('Y-m-d H:i:s', (int)$keyfile['mtime']).' UTC. Either a sync is still outstanding or failed, or the information from the server is older than the last change. Until it is replaced, the file on the server is the one that counts.'));
				$this->delivery = 'outdated';
			}
		}

		$this->section($id, 'Server configuration (sshd)');
		$this->host_checked = true;
		$os = $facts['os']['pretty_name'] ?? null;
		$banner = $facts['sshd_banner'] ?? null;
		$version = preg_match('/OpenSSH_(\d+)\.(\d+)/', (string)$banner, $matches) ? (int)$matches[1] * 100 + (int)$matches[2] : null;
		$this->add($id, 'info', array('Collected '.$stored['collected'].' UTC ('.$age_text.') during '.($facts['source'] ?? 'sync').'.'.(is_null($os) ? '' : ' System: '.$os.'.').(is_null($banner) ? '' : ' SSH server: '.$banner.'.').' This is the configuration on disk; sshd only uses it after it has been reloaded.'));

		// The Linux account
		$login = $facts['logins'][$this->login] ?? null;
		$uid = null;
		$home = null;
		$groups = null;
		if(is_null($login)) {
			$this->add($id, 'unknown', array('SKA has no information about a Linux account ', array('code' => $this->login), ' on the server. It only looks up the accounts it manages there, and only if the server answers the lookup.'));
			$this->host_incomplete = true;
		} elseif(empty($login['exists'])) {
			$this->host_block($id, array('There is no Linux account ', array('code' => $this->login), ' on the server. Nobody can log in as an account that does not exist, whatever the key file says.'));
		} else {
			$uid = (int)$login['uid'];
			$home = (string)$login['home'];
			$groups = $login['groups'] ?? null;
			$this->add($id, 'ok', array('The Linux account ', array('code' => $this->login), ' exists (uid '.$uid.', shell ', array('code' => $login['shell'] === '' ? '/bin/sh' : $login['shell']), ').'));
		}

		$config = $facts['sshd_config'] ?? null;
		if(!is_array($config) || !is_string($config['main'] ?? null)) {
			$this->add($id, 'unknown', array('The sshd configuration could not be read. ', array('code' => ServerHostFacts::MAIN_CONFIG), ' must be readable for the ', array('code' => 'keys-sync'), ' user for this check.'));
			$this->host_incomplete = true;
			return;
		}
		$effective = SshdConfig::evaluate($config, $this->login, $groups);
		$values = $effective['values'];
		$this->conditional = $effective['conditional'];
		foreach($effective['problems'] as $problem) {
			$this->add($id, 'unknown', array('Not taken into account: ', array('code' => $problem['path']), ' ('.$problem['status'].'). What follows may therefore be incomplete. The sshd configuration, including the files it includes, has to be readable for the ', array('code' => 'keys-sync'), ' user.'));
			$this->host_incomplete = true;
			$this->config_incomplete = true;
		}
		foreach($config['files'] as $path => $file) {
			if(!empty($file['truncated'])) {
				$this->add($id, 'unknown', array('Configuration file ', array('code' => $path), ' was too long to be read completely.'));
				$this->host_incomplete = true;
				$this->config_incomplete = true;
			}
		}
		foreach($effective['misplaced'] as $event) {
			$this->host_warn($id, array(array('code' => SshdConfig::format_directive($event['keyword'], array())), ' is set inside a Match block at ', self::where($event), '. sshd does not allow that and refuses to start with such a configuration.'));
		}

		// The shell. sshd does not look at it when the session is confined to a directory.
		if(!is_null($login) && !empty($login['exists'])) {
			$chroot = $values['chrootdirectory'] ?? null;
			$chrooted = !is_null($chroot) && ($chroot['args'][0] ?? '') !== 'none';
			$sftp_only = ($values['forcecommand']['args'][0] ?? '') === 'internal-sftp';
			if(($login['shell_ok'] ?? null) === false && !$chrooted) {
				$this->host_block_unless_conditional($id, array('chrootdirectory'), array('Its login shell ', array('code' => $login['shell']), ' does not exist or is not executable. sshd refuses such accounts.'));
			} elseif(preg_match('/\/(nologin|false)$/', $login['shell']) && !$sftp_only) {
				$this->host_warn($id, array('Its shell is ', array('code' => $login['shell']), ': the key is accepted, but an interactive session ends immediately.'));
			} elseif($sftp_only) {
				$this->add($id, 'info', array('This account is limited to file transfer: ', array('code' => 'ForceCommand internal-sftp'), ' (', self::where($values['forcecommand']), '). The key is accepted, but there is no shell.'));
			}
		}

		// Does sshd read the SKA key file?
		$ska_path = ServerHostFacts::KEYDIR.'/'.$this->keyfile_name;
		$directive = $values['authorizedkeysfile'] ?? null;
		$entries = is_null($directive) ? array('.ssh/authorized_keys', '.ssh/authorized_keys2') : $directive['args'];
		$reads_ska_file = false;
		$unresolved = false;
		$other_files = array();
		foreach($entries as $entry) {
			if(strtolower($entry) === 'none') continue;
			$path = SshdConfig::expand_authorized_keys_path($entry, $this->login, $home, is_null($uid) ? null : (string)$uid);
			if(is_null($path)) {
				// The home directory or uid is unknown; the SKA path does not depend on either
				$unresolved = true;
				$other_files[] = $entry;
			} elseif(preg_replace('#/+#', '/', $path) === $ska_path) {
				$reads_ska_file = true;
			} else {
				$other_files[] = $path;
			}
		}
		$value_text = array('code' => 'AuthorizedKeysFile '.implode(' ', $entries));
		$source_text = is_null($directive) ? array(' (the default, as it is not set anywhere)') : array(' (', self::where($directive), ')');
		if($reads_ska_file) {
			$this->add($id, 'ok', array_merge(array('sshd reads the SKA key file for this account: ', $value_text), $source_text, array('.')));
		} else {
			$details = array();
			foreach($effective['all'] as $event) {
				if($event['keyword'] !== 'authorizedkeysfile' || $event === $directive) continue;
				if($event['verdict'] === 'no') {
					$details[] = array('The setting at ', self::where($event), ' does not apply to this account.');
				} elseif($event['verdict'] === 'yes' && !$event['in_match'] && !is_null($directive) && $directive['in_match']) {
					$details[] = array('The setting at ', self::where($event), ' (', array('code' => implode(' ', $event['args'])), ') is overridden for this account by the Match block.');
				} elseif($event['verdict'] === 'yes') {
					$details[] = array('The setting at ', self::where($event), ' (', array('code' => implode(' ', $event['args'])), ') is ignored: sshd uses the first value it finds, and that is the one above.');
				}
			}
			$details[] = array('It has to contain ', array('code' => ServerHostFacts::KEYDIR.'/%u'), ', see ', array('url' => '/help#sync_setup', 'text' => 'setting up a server'), '.');
			$this->host_block_unless_conditional($id, array('authorizedkeysfile'), array_merge(array('sshd does not read the SKA key file ', array('code' => $ska_path), ' for this account. In effect is ', $value_text), $source_text, array('.')), $details);
		}
		if(count($other_files) > 0) {
			$parts = array('sshd also accepts keys from ');
			foreach($other_files as $index => $path) {
				if($index > 0) $parts[] = ', ';
				$parts[] = array('code' => $path);
			}
			$parts[] = '. Keys in those files work independently of SKA.';
			$this->outside[] = $parts;
		}

		$value = strtolower($values['pubkeyauthentication']['args'][0] ?? 'yes');
		if($value === 'no') {
			$this->host_block_unless_conditional($id, array('pubkeyauthentication'), array('Public key authentication is switched off: ', array('code' => 'PubkeyAuthentication no'), ' (', self::where($values['pubkeyauthentication']), ').'), array(), $values['pubkeyauthentication']['args']);
		}

		// StrictModes: sshd then wants the key file and every directory above it to be owned by the account or
		// by root and not to be writable for anybody else
		$directive = $values['strictmodes'] ?? null;
		$strict = strtolower($directive['args'][0] ?? 'yes') !== 'no';
		if($reads_ska_file && $strict) {
			$setting = is_null($directive) ? array('StrictModes is on (the default, as it is not set anywhere)') : array('StrictModes is on (', self::where($directive), ')');
			$rule = '. sshd then ignores a key file unless the file and the directories above it are owned by the account itself or by root and are not writable for group or others';
			$fix = array('. Set ', array('code' => 'StrictModes no'), ', see ', array('url' => '/help#sync_setup', 'text' => 'setting up a server'), '.');
			$items = array(ServerHostFacts::KEYDIR.'/'.$this->keyfile_name => $keyfile, ServerHostFacts::KEYDIR => $facts['dirs'][ServerHostFacts::KEYDIR] ?? null);
			if(is_null($home) || rtrim($home, '/') !== ServerHostFacts::KEYDIR) {
				// The check stops at the home directory of the account
				$items['/var/local'] = $facts['dirs']['/var/local'] ?? null;
			}
			$offending = array();
			$missing = is_null($uid);
			foreach($items as $path => $item) {
				if(!is_array($item) || !isset($item['uid'], $item['mode'])) {
					$missing = true;
					continue;
				}
				if(!is_null($uid) && $item['uid'] !== 0 && $item['uid'] !== $uid) {
					$offending[] = $path.' is owned by '.($item['owner'] ?? $item['uid']);
				} elseif(octdec($item['mode']) & 0022) {
					$offending[] = $path.' has mode '.$item['mode'];
				}
			}
			if(count($offending) > 0) {
				$this->host_block_unless_conditional($id, array(), array_merge($setting, array($rule.'. Here, '.implode(', and ', $offending)), $fix));
			} elseif($missing) {
				$this->host_warn($id, array_merge($setting, array($rule.', which is normally not the case for SKA key files'), $fix));
			}
		} elseif($reads_ska_file) {
			$this->add($id, 'ok', array('StrictModes is off (', self::where($directive), '), so sshd accepts key files owned by the sync user.'));
		}

		// The file is opened with the rights of the account
		if($reads_ska_file && !is_null($keyfile) && !is_null($uid) && $uid !== 0) {
			$dir = $facts['dirs'][ServerHostFacts::KEYDIR] ?? null;
			$file_readable = (octdec($keyfile['mode']) & 0004) || ($keyfile['uid'] === $uid && (octdec($keyfile['mode']) & 0400));
			$dir_searchable = is_null($dir) || (octdec($dir['mode']) & 0001) || ($dir['uid'] === $uid && (octdec($dir['mode']) & 0100));
			if(!$file_readable || !$dir_searchable) {
				$this->host_warn($id, array('sshd opens the key file with the rights of the account, and the permissions look too tight for that: file mode '.$keyfile['mode'].(is_null($dir) ? '' : ', directory mode '.$dir['mode']).'. Expected are 644 and 711 (unless group permissions allow it).'));
			}
		}

		// Root
		if($uid === 0) {
			$directive = $values['permitrootlogin'] ?? null;
			$value = strtolower($directive['args'][0] ?? 'prohibit-password');
			$source_text = is_null($directive) ? array(' (the default)') : array(' (', self::where($directive), ')');
			if($value === 'no') {
				$this->host_block_unless_conditional($id, array('permitrootlogin'), array_merge(array('Root logins are refused: ', array('code' => 'PermitRootLogin no')), $source_text, array('. Keys make no difference to that. Use ', array('code' => 'prohibit-password'), ' to allow root logins with keys only.')), array(), $directive['args'] ?? null);
			} elseif($value === 'forced-commands-only') {
				$has_command = false;
				foreach($this->expected_lines as $lines) {
					foreach($lines as $line) {
						if(preg_match('/(^|,)command="/', $line)) $has_command = true;
					}
				}
				if(!$has_command) {
					$this->host_block_unless_conditional($id, array('permitrootlogin'), array_merge(array('Root may only log in with keys that have a forced command: ', array('code' => 'PermitRootLogin forced-commands-only')), $source_text, array('. The key lines written here have none.')));
				}
			} else {
				$this->add($id, 'ok', array_merge(array('Root may log in with a key: ', array('code' => 'PermitRootLogin '.$value)), $source_text, array('.')));
			}
		}

		$this->examine_allow_deny($id, $effective, $groups);

		$directive = $values['authenticationmethods'] ?? null;
		if(!is_null($directive) && !in_array('any', array_map('strtolower', $directive['args']))) {
			$alone = false;
			$part = false;
			foreach($directive['args'] as $list) {
				$methods = array_map(function($method) { return strtolower(explode(':', $method)[0]); }, explode(',', $list));
				if($methods === array('publickey')) $alone = true;
				if(in_array('publickey', $methods)) $part = true;
			}
			$setting = array('code' => 'AuthenticationMethods '.implode(' ', $directive['args']));
			if(!$alone && $part) {
				$this->host_warn($id, array('A key alone is not enough to log in, a further step is required: ', $setting, ' (', self::where($directive), ').'));
			} elseif(!$alone) {
				$this->host_block_unless_conditional($id, array('authenticationmethods'), array('Logging in with a key is not among the permitted methods: ', $setting, ' (', self::where($directive), ').'), array(), $directive['args']);
			}
		}

		// Key types
		$directive = $values['pubkeyacceptedalgorithms'] ?? null;
		$accepted = is_null($directive) ? null : (string)($directive['args'][0] ?? '');
		foreach($this->keys as $key) {
			if(!isset($this->expected_lines[$key->id])) continue;
			if(!SshdConfig::key_type_accepted((string)$accepted, $key->type, !is_null($version) && $version < 708)) {
				$this->host_block_unless_conditional($id, array('pubkeyacceptedalgorithms'), is_null($directive)
					? array('Key ', $key, ' is of a type that SSH servers no longer accept by default.')
					: array('Key ', $key, ' is of a type that this server does not accept: ', array('code' => SshdConfig::format_directive('pubkeyacceptedalgorithms', $directive['args'])), ' (', self::where($directive), ').'));
			} elseif($key->type === 'ssh-rsa' && !is_null($version) && !SshdConfig::sha1_rsa_accepted($accepted, $version < 808)) {
				$this->add($id, 'info', array('Key ', $key, ' is an RSA key. This server does not accept the old SHA-1 signatures, so the login only works from clients that support ', array('code' => 'rsa-sha2'), ' (OpenSSH 7.2 or newer, PuTTY 0.75 or newer).'));
			}
			$size = $values['requiredrsasize'] ?? null;
			if(!is_null($size) && $key->type === 'ssh-rsa' && (int)$key->keysize > 0 && (int)$key->keysize < (int)($size['args'][0] ?? 0)) {
				$this->host_block_unless_conditional($id, array('requiredrsasize'), array('Key ', $key, ' has '.$key->keysize.' bits, but the server requires RSA keys of at least '.(int)$size['args'][0].' bits (', self::where($size), ').'));
			}
		}
		$directive = $values['revokedkeys'] ?? null;
		if(!is_null($directive) && ($directive['args'][0] ?? '') !== 'none') {
			$this->host_warn($id, array('The server keeps a list of revoked keys (', array('code' => 'RevokedKeys'), ', ', self::where($directive), ') that SKA cannot read. A key on that list is refused even if it is in the key file.'));
		}

		// PAM
		$directive = $values['usepam'] ?? null;
		$pam = strtolower($directive['args'][0] ?? 'no') === 'yes';
		if(!$pam) {
			$details = array(array('SKA cannot read ', array('code' => '/etc/shadow'), '. Check on the server with ', array('code' => 'passwd -S '.$this->login), ' (as root) whether the account is locked.'));
			$vendor = $config['vendor'] ?? null;
			if(is_string($vendor) && $config['main'] !== $vendor) {
				$vendor_pam = false;
				foreach($config['files'][$vendor]['entries'] ?? array() as $entry) {
					if($entry[1] === 'usepam' && strtolower($entry[2][0] ?? '') === 'yes') $vendor_pam = true;
				}
				$details[] = array('This system ships its defaults in ', array('code' => $vendor), $vendor_pam ? ', which sets UsePAM yes' : '', '. As soon as ', array('code' => $config['main']), ' exists, sshd reads only that file, so the shipped defaults are lost unless that file repeats them. Removing ', array('code' => $config['main']), ' (the drop-in directory is included by the shipped file) or adding ', array('code' => 'UsePAM yes'), ' restores the usual behaviour.');
			}
			$this->host_warn($id, array_merge(array('PAM is not used: '), is_null($directive) ? array(array('code' => 'UsePAM'), ' is not set anywhere and defaults to ', array('code' => 'no')) : array(array('code' => 'UsePAM '.implode(' ', $directive['args'])), ' (', self::where($directive), ')'), array('. Without PAM, sshd refuses accounts whose password is locked, even with a valid key. Accounts that were created without a password are locked.')), $details);
		}

		// Things that do not stop the login
		$uses_environment = false;
		foreach($this->expected_lines as $lines) {
			foreach($lines as $line) {
				if(preg_match('/(^|,)environment="/', $line)) $uses_environment = true;
			}
		}
		if($uses_environment && strtolower($values['permituserenvironment']['args'][0] ?? 'no') === 'no') {
			$this->add($id, 'info', array('The key lines set an environment variable (', array('code' => 'environment="..."'), '), but ', array('code' => 'PermitUserEnvironment'), ' is not enabled on the server, so sshd ignores it. The login works; only the variable is missing.'));
		}
		// sshd itself only knows /etc/nologin; with PAM, pam_nologin also looks at /run/nologin
		$nologin = array_values(array_filter($facts['nologin'] ?? array(), function($path) use ($pam) {
			return $pam || $path === '/etc/nologin';
		}));
		if(count($nologin) > 0 && $uid !== 0) {
			$this->host_warn($id, array('The file ', array('code' => $nologin[0]), ' exists on the server. While it does, only root can log in.'));
		}
		if(($facts['selinux'] ?? null) === 'enforcing') {
			$this->add($id, 'info', array('SELinux is enforcing on this server. The key directory needs the label ', array('code' => 'ssh_home_t'), ' for sshd to be allowed to read it; SKA cannot check that.'));
		}
		$max_tries = (int)($values['maxauthtries']['args'][0] ?? 6);
		if($max_tries > 0 && $max_tries <= 3) {
			$this->add($id, 'info', array('The server allows only '.$max_tries.' authentication attempts per connection (', array('code' => 'MaxAuthTries'), '). A client that offers several keys can be disconnected before it reaches the right one.'));
		}
		$watched = array('authorizedkeysfile', 'pubkeyauthentication', 'permitrootlogin', 'allowusers', 'denyusers', 'allowgroups', 'denygroups', 'authenticationmethods', 'pubkeyacceptedalgorithms', 'requiredrsasize', 'chrootdirectory', 'forcecommand');
		foreach($effective['conditional'] as $event) {
			if(!in_array($event['keyword'], $watched)) continue;
			$this->host_warn($id, array('Depending on the connection, a different setting applies: ', array('code' => 'Match '.$event['header']), ' sets ', array('code' => SshdConfig::format_directive($event['keyword'], $event['args'])), ' (', array('code' => $event['file'].':'.$event['line']), '). SKA cannot tell '.(is_null($groups) && stripos((string)$event['header'], 'group') !== false ? 'which groups the account is in' : 'where the login comes from').'.'));
		}

		foreach(array('authorizedkeyscommand' => 'a command that supplies keys (AuthorizedKeysCommand)', 'trustedusercakeys' => 'SSH certificates (TrustedUserCAKeys)') as $keyword => $label) {
			$directive = $values[$keyword] ?? null;
			if(!is_null($directive) && ($directive['args'][0] ?? '') !== 'none') {
				$this->outside[] = array('The server also accepts '.$label.', configured at ', self::where($directive), '. Access granted that way is independent of SKA.');
			}
		}
		$directive = $values['passwordauthentication'] ?? null;
		if(strtolower($directive['args'][0] ?? 'yes') !== 'no') {
			$this->outside[] = array_merge(array('Password logins are not switched off for this account'), is_null($directive) ? array(' (', array('code' => 'PasswordAuthentication'), ' is not set and defaults to yes)') : array(' (', self::where($directive), ')'), array(', so a login with the account password may work regardless of keys.'));
		}
		if(SshdConfig::match_budget_exceeded()) {
			$this->add($id, 'unknown', array('The configuration of this server contains so many or such long patterns that SKA gave up comparing them. The findings about ', array('code' => 'Match'), ' blocks and the Allow and Deny lists are not reliable.'));
			$this->host_incomplete = true;
		}
		$this->examine_external_keys($other_files, $unresolved);
		if(count($this->outside) > 0) {
			$this->section('outside', 'Access outside of SKA');
			foreach($this->outside as $parts) {
				$this->add('outside', 'info', $parts);
			}
		}
	}

	/**
	 * Check the account against AllowUsers, DenyUsers, AllowGroups and DenyGroups.
	 */
	private function examine_allow_deny(string $id, array $effective, ?array $groups) {
		$lists = $effective['lists'];
		$describe = function(string $keyword) use ($lists) {
			$parts = array();
			foreach($lists[$keyword] as $index => $event) {
				if($index > 0) $parts[] = ', ';
				$parts[] = array('code' => SshdConfig::format_directive($event['keyword'], $event['args']));
				$parts[] = ' (';
				$parts[] = self::where($event);
				$parts[] = ')';
			}
			return $parts;
		};
		$entries = function(string $keyword) use ($lists) {
			$all = array();
			foreach($lists[$keyword] ?? array() as $event) {
				$all = array_merge($all, $event['args']);
			}
			return $all;
		};
		$name = array('code' => $this->login);

		$verdicts = array_map(function($entry) { return SshdConfig::match_user_entry($entry, $this->login); }, $entries('denyusers'));
		if(in_array('yes', $verdicts)) {
			$this->host_block_unless_conditional($id, array('denyusers'), array_merge(array('The account ', $name, ' is denied by '), $describe('denyusers'), array('.')));
		} elseif(in_array('maybe', $verdicts)) {
			$this->host_warn($id, array_merge(array('The account ', $name, ' is denied from certain client hosts by '), $describe('denyusers'), array('.')));
		}
		if(isset($lists['allowusers'])) {
			$verdicts = array_map(function($entry) { return SshdConfig::match_user_entry($entry, $this->login); }, $entries('allowusers'));
			if(in_array('yes', $verdicts)) {
				$this->add($id, 'ok', array_merge(array('The account ', $name, ' is listed in '), $describe('allowusers'), array('.')));
			} elseif(in_array('maybe', $verdicts)) {
				$this->host_warn($id, array_merge(array('The account ', $name, ' may only log in from certain client hosts: '), $describe('allowusers'), array('.')));
			} else {
				$this->host_block_unless_conditional($id, array('allowusers'), array_merge(array('Only listed accounts may log in, and ', $name, ' is not among them: '), $describe('allowusers'), array('.')));
			}
		}
		foreach(array('denygroups', 'allowgroups') as $keyword) {
			if(!isset($lists[$keyword])) continue;
			if(is_null($groups)) {
				$this->host_warn($id, array_merge(array('Logins are restricted by group, and SKA does not know the groups of ', $name, ': '), $describe($keyword), array('.')));
				$this->host_incomplete = true;
				continue;
			}
			$hit = false;
			foreach($entries($keyword) as $entry) {
				foreach($groups as $group) {
					if(SshdConfig::match_pattern($entry, $group)) {
						$hit = true;
						break 2;
					}
				}
			}
			if($keyword === 'denygroups' && $hit) {
				$this->host_block_unless_conditional($id, array('denygroups'), array_merge(array('The account ', $name, ' is in a group that is denied by '), $describe($keyword), array('.')));
			} elseif($keyword === 'allowgroups' && !$hit) {
				$this->host_block_unless_conditional($id, array('allowgroups'), array_merge(array('Only members of certain groups may log in, and ', $name, ' (groups: '.implode(', ', $groups).') is in none of them: '), $describe($keyword), array('.')));
			} elseif($keyword === 'allowgroups') {
				$this->add($id, 'ok', array_merge(array('The account ', $name, ' is in a group that is allowed by '), $describe($keyword), array('.')));
			}
		}
	}

	/**
	 * Look for the considered keys among the keys that the supervision found in authorized_keys files in home
	 * directories on this server. Those work without SKA granting anything, if sshd reads those files.
	 *
	 * @param array $other_files Key files other than the SKA file that sshd reads for the account
	 * @param bool $unresolved Whether some of them could not be resolved to a path
	 */
	private function examine_external_keys(array $other_files, bool $unresolved) {
		global $database;
		$reads_home_files = false;
		foreach($other_files as $path) {
			if(strpos($path, '.ssh/authorized_keys') !== false) $reads_home_files = true;
		}
		if(!$reads_home_files) {
			return;
		}
		$stmt = $database->prepare("
			SELECT external_key.id, external_key.status, external_key.type, external_key.keydata, external_key_occurrence.appeared
			FROM external_key_occurrence
			INNER JOIN external_key ON external_key.id = external_key_occurrence.`key`
			WHERE external_key_occurrence.server = ? AND external_key_occurrence.account_name = ?
		");
		$stmt->bind_param('ds', $this->server->id, $this->login);
		$stmt->execute();
		$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
		$stmt->close();
		$wanted = array();
		foreach($this->keys as $key) {
			$wanted[$key->fingerprint_sha256] = $key;
		}
		if($this->who['kind'] == 'key' || $this->who['kind'] == 'unknown_key') {
			$wanted[preg_replace('/^(SHA256|MD5):/', '', $this->who['fingerprint'])] = null;
		}
		$found = false;
		foreach($rows as $row) {
			$blob = base64_decode($row['keydata'], true);
			if($blob === false) continue;
			$sha256 = rtrim(base64_encode(hash('sha256', $blob, true)), '=');
			$md5 = rtrim(chunk_split(md5($blob), 2, ':'), ':');
			if(!array_key_exists($sha256, $wanted) && !array_key_exists($md5, $wanted)) continue;
			$found = true;
			$key = $wanted[$sha256] ?? ($wanted[$md5] ?? null);
			$this->outside[] = array_merge(
				is_null($key) ? array('This key') : array('Key ', $key),
				array(' is in an ', array('code' => 'authorized_keys'), ' file in the home directory of ', array('code' => $this->login), ' on the server (first seen '.$row['appeared'].', '),
				array(array('url' => '/pubkeys#'.(in_array($row['status'], array('new', 'allowed', 'denied')) ? $row['status'] : 'new'), 'text' => 'external key, status "'.$row['status'].'"'), '). sshd reads that file, so this key works for the login without SKA granting anything.')
			);
			$this->external_key_found = true;
		}
		if(!$found) {
			if($this->server->key_scan == 'off' || ($this->server->key_scan == 'rootonly' && $this->login !== 'root')) {
				$this->outside[] = array('SKA does not scan the ', array('code' => 'authorized_keys'), ' files of this account (key scan setting of the server), so it cannot say whether the key is in one of them.');
			} elseif(!is_null($this->server->key_supervision_error) && strpos($this->server->key_supervision_error, '/.ssh/authorized_keys') !== false) {
				$this->outside[] = array('SKA could not read all ', array('code' => 'authorized_keys'), ' files on this server (see the ', array('url' => '/servers/'.urlencode($this->server->hostname), 'text' => 'server page'), '), so it cannot rule out that the key is in one of them.');
			} elseif(count($wanted) > 0) {
				$this->outside[] = array('The scan for external keys found none of the examined keys in the home directory of ', array('code' => $this->login), '.');
			}
		}
	}

	private function host_block(string $id, array $text, array $details = array()) {
		$this->add($id, 'fail', $text, $details);
		$this->host_blockers[] = $text;
	}

	private function host_warn(string $id, array $text, array $details = array()) {
		$this->add($id, 'warn', $text, $details);
		$this->host_warnings[] = $text;
	}

	/**
	 * Record a setting that stops the login. If a Match block that depends on the connection sets the same
	 * thing differently, the login is not stopped for sure, so it only counts as a warning then.
	 *
	 * @param string $id Section to report in
	 * @param array $keywords The sshd keywords that the finding rests on
	 * @param array $text Parts of the text
	 * @param array $details Further lines, each an array of parts
	 * @param array|null $effective_args Arguments of the directive in effect, if the finding rests on a single one
	 */
	private function host_block_unless_conditional(string $id, array $keywords, array $text, array $details = array(), ?array $effective_args = null) {
		if($this->config_incomplete) {
			$details[] = array('The configuration could not be read completely, so this may be set differently in a part that SKA did not see.');
			$this->host_warn($id, $text, $details);
			return;
		}
		foreach($this->conditional as $event) {
			if(!in_array($event['keyword'], $keywords)) continue;
			// A block that sets the same thing again changes nothing
			if(!is_null($effective_args) && $event['args'] === $effective_args) continue;
			$details[] = array('For some connections this is set differently, see ', array('code' => 'Match '.$event['header']), ' at ', array('code' => $event['file'].':'.$event['line']), ' below.');
			$this->host_warn($id, $text, $details);
			return;
		}
		$this->host_block($id, $text, $details);
	}

	/**
	 * Describe where a directive comes from.
	 */
	private static function where(array $event): array {
		return array('code' => $event['file'].':'.$event['line'].(is_null($event['header'] ?? null) ? '' : ', inside Match '.$event['header']));
	}

	// ---------------------------------------------------------------------------------------------------------
	// Diagram

	/**
	 * Lay out the way from whoever logs in to the login as a chain of nodes, for a picture of the result:
	 * the user or key, the groups and the access rule that lead to the account, the key file, the server and
	 * the login itself. Where the chain breaks, the connection is marked and what follows is switched off.
	 *
	 * @return array of nodes, each an array with 'icon' (glyphicon name), 'status' ('ok', 'warn', 'fail',
	 *               'unknown' or 'off'), 'title' (parts), 'caption' (string) and 'edge' (the connection from the
	 *               node before: array with 'status' and 'label', or null for the first node)
	 */
	private function diagram(): array {
		$nodes = array();
		$add = function(string $icon, string $status, array $title, string $caption, ?array $edge = null) use (&$nodes) {
			$nodes[] = array('icon' => $icon, 'status' => $status, 'title' => $title, 'caption' => $caption, 'edge' => $edge);
		};
		$edge = function(string $status, string $label) {
			// A break is marked on the connection only where there is something to say about it; otherwise the
			// box on either side shows what is wrong
			return array('status' => ($status == 'fail' && $label === '') ? 'off' : $status, 'label' => $label);
		};
		$group_node = function(Group $group, string $label) use ($add, $edge) {
			$add('list-alt', 'ok', array($group), 'group', $edge('ok', $label));
		};
		$target = $this->login.'@'.$this->server->hostname;
		$ska_grants = count($this->expected_lines) > 0;
		$managed = $this->server->key_management == 'keys';

		// Who
		$subject = $this->subject;
		$subject_status = 'ok';
		$subject_caption = '';
		if($subject instanceof User) {
			if(!$subject->active) {
				$subject_status = 'fail';
				$subject_caption = 'inactive user';
			} elseif($this->by_key()) {
				$subject_caption = 'user';
			} elseif(count($this->keys) == 0) {
				$subject_status = 'fail';
				$subject_caption = 'no public key';
			} else {
				$subject_caption = self::plural(count($this->keys), 'public key');
			}
		} elseif($subject instanceof ServerAccount) {
			$subject_caption = $subject->server->id == $this->server->id ? 'account on this server' : 'account on another server';
			if($subject->server->key_management == 'decommissioned') {
				$subject_status = 'fail';
				$subject_caption = 'account on a decommissioned server';
			}
		}
		switch($this->who['kind']) {
		case 'key':
			$key = $this->who['keys'][0];
			$deleted = count($this->keys) == 0;
			$add('lock', $deleted ? 'fail' : 'ok', array(array('url' => '/pubkeys/'.urlencode($key->id), 'text' => $key->type.' '.substr($key->fingerprint_sha256, 0, 8).'…')), $deleted ? 'deleted key' : 'public key');
			$add($subject instanceof User ? 'user' : 'log-in', $subject_status, array($subject), $subject_caption, $edge($deleted ? 'fail' : 'ok', 'registered for'));
			if($deleted) $subject_status = 'fail';
			break;
		case 'user':
			$add('user', $subject_status, array($subject), $subject_caption);
			break;
		case 'unknown_key':
			$add('lock', 'fail', array(array('code' => substr($this->who['fingerprint'], 0, 15).'…')), 'key not registered in SKA');
			$subject_status = 'fail';
			break;
		default:
			$add('user', 'fail', array(array('code' => $this->who['canonical'] === '' ? '?' : $this->who['canonical'])), 'not a SKA user');
			$subject_status = 'fail';
		}

		// The way to the account
		$account_title = is_null($this->account) ? array(array('code' => $target)) : array($this->account);
		if($this->link['type'] == 'rule') {
			$set = $this->link['set'];
			$rule = $this->link['rule'];
			$source = $rule['access']->source_entity;
			$label = 'has access to';
			if($source instanceof Group) {
				foreach(array_reverse($rule['match']['paths'][0] ?? array()) as $nested) {
					$group_node($nested, 'member of');
				}
				$group_node($source, 'member of');
			}
			if($set['target'] instanceof Group) {
				$group_node($set['target'], $label);
				foreach(array_reverse(array_slice($set['chain'], 0, -1)) as $via) {
					$group_node($via, 'contains');
				}
				$label = 'contains';
			}
			$more = $this->link['more'] > 0 ? ' · '.self::plural($this->link['more'], 'more rule').' in the report' : '';
			$add('log-in', 'ok', $account_title, 'managed account'.$more, $edge('ok', $label));
		} elseif($this->link['type'] == 'ldap') {
			$add('log-in', 'ok', $account_title, 'own account of the user (LDAP authorization)', $edge('ok', 'logs in as'));
		} elseif(!$managed) {
			// SKA does not look at accounts and rules of a server that it writes no key files to
			$add('log-in', 'off', $account_title, 'not examined', $edge('off', ''));
		} else {
			// If the key is written all the same, another registration of it has access
			$status = $ska_grants ? 'warn' : 'fail';
			$label = $ska_grants ? 'only through another registration of the key' : $this->link['label'];
			$add('log-in', is_null($this->account) && !$ska_grants ? 'fail' : 'ok', $account_title, is_null($this->account) ? 'not a managed account' : 'managed account', $edge($status, $label));
		}

		// The key file, the server and the login. After a break, they are only shown for orientation, unless
		// something about them needs attention.
		$file = array(array('code' => ServerHostFacts::KEYDIR.'/'.$this->keyfile_name));
		$what = $this->by_key() || $this->who['kind'] == 'unknown_key' ? 'key' : 'user';
		if($ska_grants) {
			$foreign = $this->foreign_registration && $this->link['type'] != 'none';
			$add('file', 'ok', $file, self::plural($this->count_expected_lines(), 'line').' written by SKA', $edge($this->foreign_registration ? 'warn' : 'ok', $foreign ? 'only through another registration of the key' : 'keys written'));
		} elseif($subject_status == 'ok' && $this->link['type'] != 'none') {
			// The way to the account is intact, so this is where it ends
			if(count($this->keys) > 0 && count($this->blocked_keys) == count($this->keys)) {
				$reason = 'destination rules keep the key'.($this->by_key() ? '' : 's').' away';
			} elseif(is_null($this->expected_keyfile) && $this->link['type'] == 'ldap') {
				$reason = 'no key for this account';
			} elseif(is_null($this->expected_keyfile)) {
				$reason = 'key file cannot be generated';
			} else {
				$reason = 'no key written';
			}
			$add('file', 'fail', $file, 'nothing written for this '.$what, $edge('fail', $reason));
		} else {
			$add('file', 'off', $file, is_null($this->expected_keyfile) ? 'no key file written' : 'nothing written for this '.$what, $edge('off', ''));
		}
		$age = is_null($this->age_text) ? '' : ' · '.$this->age_text;
		$not_synced = $this->server->sync_status == 'not synced yet';
		// As in the verdict: what SKA knows about the server is not current if it cannot reach it or last looked long ago
		$stale = !is_null($this->facts) && (!is_null($this->sync_problem) || $this->facts['age'] > 3600);
		$sync_note = is_null($this->sync_problem) ? '' : ($not_synced ? ' · not synced yet' : ' · sync is failing');
		switch($this->delivery) {
		case 'current':
			$server = array(!$ska_grants ? 'off' : ($stale ? 'unknown' : 'ok'), ($stale ? 'key file was in place' : 'key file is in place').$age.$sync_note);
			break;
		case 'outdated':
			$server = array('warn', 'has a different key file'.$age.$sync_note);
			break;
		case 'missing':
			$server = array($ska_grants ? 'fail' : 'off', 'key file is not there'.$age.$sync_note);
			break;
		case 'stray':
			$server = array('warn', 'has a key file that SKA would not write'.$age);
			break;
		default:
			if($not_synced) {
				$server = array($ska_grants ? 'unknown' : 'off', 'not synced yet');
			} elseif(!is_null($this->sync_problem)) {
				$server = array($ska_grants ? 'fail' : 'off', 'sync is failing');
			} else {
				$server = array($ska_grants ? 'unknown' : 'off', is_null($this->facts) ? 'no information from the server yet' : 'key file not compared'.$age);
			}
		}
		if(!$managed) {
			// This is where it ends for such a server, whatever else is known about it
			$captions = array('decommissioned' => 'decommissioned', 'other' => 'managed by another system', 'none' => 'key management disabled');
			$server = array('fail', ($captions[$this->server->key_management] ?? 'not managed by SKA').($this->delivery == 'stray' ? ' · still has a key file for this account'.$age : ''));
		}
		// "synced to" only where something is synced
		$server_label = $server[0] == 'off' ? '' : ($ska_grants ? 'synced to' : ($managed ? 'on the server' : ''));
		$add('hdd', $server[0], array($this->server), $server[1], $edge($server[0], $server_label));
		if(!$ska_grants) {
			$login = $this->external_key_found ? array('warn', 'key found in a file that SKA does not manage') : array('off', 'no access through SKA');
		} elseif(count($this->host_blockers) > 0) {
			$login = array('fail', 'refused by the server configuration');
		} elseif(!$this->host_checked) {
			$login = array('unknown', 'server configuration not checked');
		} elseif($this->host_incomplete) {
			$login = array('unknown', 'server configuration only partly checked');
		} elseif(count($this->host_warnings) > 0) {
			$login = array('warn', self::plural(count($this->host_warnings), 'thing').' on the server may stop it');
		} else {
			$login = array('ok', 'accepted by the server configuration');
		}
		if($ska_grants && $server[0] == 'fail' && $login[0] == 'ok') {
			// The configuration is fine, but the key file it would read is not there
			$login = array('unknown', 'server configuration would accept it');
		} elseif($ska_grants && $server[0] == 'unknown' && $stale && $login[0] == 'ok') {
			$login = array('unknown', 'server configuration accepted it then');
		}
		$add('console', $login[0], array(array('code' => 'ssh '.$target)), $login[1], $edge($login[0], $login[0] == 'off' ? '' : 'sshd'));
		return $nodes;
	}

	// ---------------------------------------------------------------------------------------------------------
	// Verdict

	/**
	 * Sum up the findings.
	 *
	 * @return array with 'class' (Bootstrap alert class), 'title' (parts), 'lines' (array of parts) and
	 *               'ska_grants' (bool)
	 */
	private function verdict(): array {
		$target = array('code' => $this->login.'@'.$this->server->hostname);
		$who = $this->who_label();
		$ska_grants = count($this->expected_lines) > 0;
		$lines = array();
		$recheck = array('Use "Sync this server now and re-check" below.');
		// The picture of the server is not current if SKA cannot reach it or the snapshot is old
		$stale = !is_null($this->facts) && (!is_null($this->sync_problem) || $this->facts['age'] > 3600);
		if(!$ska_grants) {
			$class = 'danger';
			$title = array_merge(array('SKA does not give '), $who, array(' access to ', $target, '.'));
			$lines = $this->reasons;
			$line_details = $this->reason_details;
			if(count($lines) == 0) {
				$lines[] = array('See the detailed report below.');
			}
			if($this->external_key_found) {
				$class = 'warning';
				$lines[] = array('The login may work nevertheless: the key was found in a file on the server that SKA does not manage (see "Access outside of SKA").');
			}
			if($this->delivery === 'stray') {
				$class = 'warning';
				$lines[] = array('A key file for this account exists on the server although SKA would not write one. Its content is unknown and may still let somebody in.');
			} elseif($this->delivery === 'outdated') {
				// For instance right after access was taken away: the old file is still what counts
				$class = 'warning';
				$lines[] = array('The key file on the server is not the one that SKA generates now. Until a sync replaces it, the older file stays in effect, and it may still contain the key.');
				$lines[] = is_null($this->sync_problem) ? $recheck : $this->sync_problem;
			} elseif(!is_null($this->sync_problem) && $this->server->key_management == 'keys') {
				$lines[] = $this->sync_problem;
			}
		} elseif(count($this->host_blockers) > 0) {
			$class = 'danger';
			$title = array_merge(array('SKA gives '), $who, array(' access to ', $target, ', but the server does not let the login through.'));
			$lines = $this->host_blockers;
			if($this->delivery === 'missing' || $this->delivery === 'outdated') {
				$lines[] = array('The key file on the server is not up to date either.');
				$lines[] = is_null($this->sync_problem) ? $recheck : $this->sync_problem;
			} elseif(!is_null($this->sync_problem)) {
				$lines[] = $this->sync_problem;
			} elseif($stale) {
				$lines[] = array('The information from the server is from '.$this->age_text.'; it may have changed since. ', $recheck[0]);
			}
		} elseif($this->delivery === 'missing' || $this->delivery === 'outdated') {
			$class = 'warning';
			$title = array_merge(array('SKA gives '), $who, array(' access to ', $target, ', but the key file on the server is not up to date.'));
			$lines[] = is_null($this->sync_problem) ? $recheck : $this->sync_problem;
			$lines = array_merge($lines, $this->host_warnings);
		} elseif(!$this->host_checked || $this->host_incomplete || $this->delivery !== 'current') {
			$class = is_null($this->sync_problem) ? 'info' : 'warning';
			$title = array_merge(array('SKA gives '), $who, array(' access to ', $target, '. Whether the server accepts the login could not be checked completely.'));
			if(!is_null($this->sync_problem)) $lines[] = $this->sync_problem;
			$lines = array_merge($lines, $this->host_warnings);
		} elseif(count($this->host_warnings) > 0) {
			$class = 'warning';
			$title = array_merge(array('SKA gives '), $who, array(' access to ', $target, $stale ? ' and the key file was in place when SKA last looked, but something on the server may stop the login.' : ' and the key file is in place, but something on the server may still stop the login.'));
			$lines = $this->host_warnings;
		} elseif($stale) {
			$class = 'info';
			$title = array_merge(array('SKA gives '), $who, array(' access to ', $target, '. When SKA last looked at the server, the key file was in place and nothing in its configuration stood in the way.'));
		} else {
			$class = 'success';
			$title = array_merge(array('SKA gives '), $who, array(' access to ', $target, ', the key file is in place, and nothing in the server configuration stands in the way.'));
		}
		if($ska_grants && $stale && in_array($class, array('info', 'warning')) && $this->delivery === 'current' && !$this->host_incomplete) {
			array_unshift($lines, is_null($this->sync_problem)
				? array('The information from the server is from '.$this->age_text.'; it may have changed since. ', $recheck[0])
				: array_merge(array('The information from the server is from '.$this->age_text.'. '), $this->sync_problem));
		}
		if($ska_grants && $this->foreign_registration) {
			array_unshift($lines, array_merge($this->subject_label(), array(' has no access of their own here. The key is written because the same key is also registered for somebody else who has access.')));
			if($class === 'success') $class = 'warning';
		}
		// Lines are only ever appended after the reasons of a denial, so their details keep their place
		return array('class' => $class, 'title' => $title, 'lines' => $lines, 'line_details' => $ska_grants ? array() : ($line_details ?? array()), 'ska_grants' => $ska_grants);
	}
}
