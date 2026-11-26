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

/**
 * Static evaluation of sshd_config files, following the rules of OpenSSH's servconf.c.
 *
 * SKA can only read the configuration files of a target server as the unprivileged sync user, so "sshd -T" is
 * not available. This class works out from the raw directives which value sshd would use for one login name:
 * - the first value obtained for a keyword wins, except for the Allow and Deny lists, which accumulate
 * - Include is processed in place; a Match block opened in an included file ends with that file
 * - directives in a matching Match block take precedence over the global ones
 *
 * Match criteria that depend on the connection (Address, Host, ...) cannot be decided here. Directives in such
 * blocks are reported separately as "conditional" instead of being applied.
 */
class SshdConfig {
	/**
	 * Keywords that the host facts keep (lowercase). Everything else in the files is discarded when collecting.
	 */
	const KEPT_KEYWORDS = array(
		'include', 'match',
		'authorizedkeysfile', 'authorizedkeyscommand', 'authorizedkeyscommanduser', 'authorizedprincipalsfile',
		'trustedusercakeys', 'revokedkeys',
		'pubkeyauthentication', 'pubkeyacceptedalgorithms', 'requiredrsasize',
		'allowusers', 'denyusers', 'allowgroups', 'denygroups',
		'permitrootlogin', 'authenticationmethods', 'maxauthtries', 'passwordauthentication',
		'strictmodes', 'usepam', 'permituserenvironment',
		'chrootdirectory', 'forcecommand',
	);

	/**
	 * Keywords of which only the presence matters. Their arguments (file paths, commands) are not stored.
	 */
	const PRESENCE_ONLY_KEYWORDS = array('authorizedkeyscommand', 'authorizedprincipalsfile', 'trustedusercakeys', 'revokedkeys', 'chrootdirectory');

	/** Upper limits for the directives and included files of one configuration, as a guard against Include loops */
	const MAX_DIRECTIVES = 5000;
	const MAX_FILES_WALKED = 500;
	/** Upper limit for the work spent on matching patterns per request; patterns and names come from target servers */
	const MAX_MATCH_STEPS = 5000000;

	private static $match_steps = 0;

	/**
	 * Whether pattern matching was cut short because it took too much work. Results obtained since the start
	 * of the request are then not reliable.
	 */
	public static function match_budget_exceeded(): bool {
		return self::$match_steps > self::MAX_MATCH_STEPS;
	}

	/**
	 * Keywords where every occurrence adds to a list instead of the first one winning.
	 */
	const LIST_KEYWORDS = array('allowusers', 'denyusers', 'allowgroups', 'denygroups');

	/**
	 * Keywords that sshd only accepts outside of Match blocks.
	 */
	const GLOBAL_ONLY_KEYWORDS = array('strictmodes', 'usepam', 'permituserenvironment');

	/**
	 * Old spellings of keywords, mapped to the current name.
	 */
	const KEYWORD_ALIASES = array(
		'pubkeyacceptedkeytypes' => 'pubkeyacceptedalgorithms',
		'dsaauthentication' => 'pubkeyauthentication',
	);

	/**
	 * Keywords in the spelling of the sshd_config manual, for display.
	 */
	const DISPLAY_NAMES = array(
		'include' => 'Include', 'match' => 'Match',
		'authorizedkeysfile' => 'AuthorizedKeysFile', 'authorizedkeyscommand' => 'AuthorizedKeysCommand',
		'authorizedkeyscommanduser' => 'AuthorizedKeysCommandUser', 'authorizedprincipalsfile' => 'AuthorizedPrincipalsFile',
		'trustedusercakeys' => 'TrustedUserCAKeys', 'revokedkeys' => 'RevokedKeys',
		'pubkeyauthentication' => 'PubkeyAuthentication', 'pubkeyacceptedalgorithms' => 'PubkeyAcceptedAlgorithms',
		'requiredrsasize' => 'RequiredRSASize',
		'allowusers' => 'AllowUsers', 'denyusers' => 'DenyUsers', 'allowgroups' => 'AllowGroups', 'denygroups' => 'DenyGroups',
		'permitrootlogin' => 'PermitRootLogin', 'authenticationmethods' => 'AuthenticationMethods',
		'maxauthtries' => 'MaxAuthTries', 'passwordauthentication' => 'PasswordAuthentication',
		'strictmodes' => 'StrictModes', 'usepam' => 'UsePAM', 'permituserenvironment' => 'PermitUserEnvironment',
		'chrootdirectory' => 'ChrootDirectory', 'forcecommand' => 'ForceCommand',
	);

	/**
	 * Give a directive the way it would be written in a configuration file.
	 *
	 * @param string $keyword Lowercase keyword
	 * @param array $args Arguments of the directive
	 * @return string e.g. "PermitRootLogin no"
	 */
	public static function format_directive(string $keyword, array $args): string {
		return rtrim((self::DISPLAY_NAMES[$keyword] ?? $keyword).' '.implode(' ', $args));
	}

	/**
	 * Split one line of an sshd_config file into the keyword and its arguments.
	 *
	 * @param string $line The raw line
	 * @return array|null array(lowercase keyword, array of arguments), or null for blank lines and comments
	 */
	public static function parse_line(string $line): ?array {
		$line = ltrim(rtrim($line, "\r\n"), " \t");
		if($line === '' || $line[0] === '#') {
			return null;
		}
		$len = strcspn($line, " \t=");
		$keyword = strtolower(substr($line, 0, $len));
		$rest = ltrim(substr($line, $len), " \t");
		if($rest !== '' && $rest[0] === '=') {
			$rest = substr($rest, 1);
		}
		if(isset(self::KEYWORD_ALIASES[$keyword])) {
			$keyword = self::KEYWORD_ALIASES[$keyword];
		}
		return array($keyword, self::split_arguments($rest));
	}

	/**
	 * Split the argument part of a line like OpenSSH's argv_split(): whitespace separates, single and double
	 * quotes group, a backslash escapes quotes and backslashes, and a '#' at the start of an argument starts
	 * a comment.
	 *
	 * @param string $string The part of the line after the keyword
	 * @return array of argument strings
	 */
	public static function split_arguments(string $string): array {
		$args = array();
		$n = strlen($string);
		$i = 0;
		while(true) {
			while($i < $n && ($string[$i] === ' ' || $string[$i] === "\t")) $i++;
			if($i >= $n || $string[$i] === '#') {
				break;
			}
			$arg = '';
			$quote = null;
			for(; $i < $n; $i++) {
				$c = $string[$i];
				if($c === '\\' && $i + 1 < $n && ($string[$i + 1] === '"' || $string[$i + 1] === "'" || $string[$i + 1] === '\\' || ($quote === null && $string[$i + 1] === ' '))) {
					$arg .= $string[$i + 1];
					$i++;
				} elseif($quote === null && ($c === ' ' || $c === "\t")) {
					break;
				} elseif($quote === null && ($c === '"' || $c === "'")) {
					$quote = $c;
				} elseif($quote !== null && $c === $quote) {
					$quote = null;
				} else {
					$arg .= $c;
				}
			}
			$args[] = $arg;
		}
		return $args;
	}

	/**
	 * Match a string against one OpenSSH pattern ('*' and '?' wildcards, case-sensitive).
	 * The patterns come from target servers, so this must not be able to run away on a crafted pattern:
	 * it only ever goes back to the last '*'.
	 */
	public static function match_pattern(string $pattern, string $string): bool {
		if($pattern === '') {
			return false;
		}
		$p = 0;
		$s = 0;
		$pattern_length = strlen($pattern);
		$string_length = strlen($string);
		$star = -1;
		$star_string = 0;
		while($s < $string_length) {
			if(++self::$match_steps > self::MAX_MATCH_STEPS) {
				return false;
			}
			if($p < $pattern_length && $pattern[$p] === '*') {
				$star = $p++;
				$star_string = $s;
			} elseif($p < $pattern_length && ($pattern[$p] === '?' || $pattern[$p] === $string[$s])) {
				$p++;
				$s++;
			} elseif($star >= 0) {
				// Let the last '*' take one more character and try again from there
				$p = $star + 1;
				$s = ++$star_string;
			} else {
				return false;
			}
		}
		while($p < $pattern_length && $pattern[$p] === '*') $p++;
		return $p === $pattern_length;
	}

	/**
	 * Match a string against a comma-separated OpenSSH pattern list.
	 *
	 * @return int 1 if a pattern matched, -1 if a negated ('!') pattern matched (which vetoes the list), else 0
	 */
	public static function match_pattern_list(string $list, string $string): int {
		$result = 0;
		foreach(explode(',', $list) as $pattern) {
			$negated = false;
			if($pattern !== '' && $pattern[0] === '!') {
				$negated = true;
				$pattern = substr($pattern, 1);
			}
			if(self::match_pattern($pattern, $string)) {
				if($negated) {
					return -1;
				}
				$result = 1;
			}
		}
		return $result;
	}

	/**
	 * Decide whether a Match line applies to a login.
	 *
	 * @param array $args Arguments of the Match line
	 * @param string $user Login name
	 * @param array|null $groups Names of the primary and supplementary groups of the login, null if unknown
	 * @return string 'yes', 'no', or 'maybe' if it depends on something that is not known here
	 */
	public static function evaluate_match(array $args, string $user, ?array $groups): string {
		$criteria = array();
		for($i = 0; $i < count($args); $i++) {
			$arg = $args[$i];
			if(strpos($arg, '=') !== false) {
				list($name, $value) = explode('=', $arg, 2);
			} elseif(strtolower($arg) === 'all' || strtolower($arg) === 'invalid-user') {
				// Criteria without an argument
				$name = strtolower($arg);
				$value = '';
			} else {
				$name = $arg;
				$value = $args[++$i] ?? '';
			}
			$criteria[] = array(strtolower($name), $value);
		}
		if(count($criteria) == 0) {
			return 'maybe';
		}
		$result = 'yes';
		foreach($criteria as list($name, $value)) {
			switch($name) {
			case 'all':
				$verdict = 'yes';
				break;
			case 'invalid-user':
				// True only for login names that do not exist on the server; those cannot log in anyway
				$verdict = 'no';
				break;
			case 'user':
				$verdict = self::match_pattern_list($value, $user) === 1 ? 'yes' : 'no';
				break;
			case 'group':
				if(is_null($groups)) {
					$verdict = 'maybe';
				} else {
					$verdict = 'no';
					foreach($groups as $group) {
						$match = self::match_pattern_list($value, $group);
						if($match === -1) {
							$verdict = 'no';
							break;
						} elseif($match === 1) {
							$verdict = 'yes';
						}
					}
				}
				break;
			default:
				// Host, Address, LocalAddress, LocalPort, RDomain, ...: depends on the connection
				$verdict = 'maybe';
			}
			if($verdict === 'no') {
				return 'no';
			}
			if($verdict === 'maybe') {
				$result = 'maybe';
			}
		}
		return $result;
	}

	/**
	 * Flatten the configuration into the sequence of directives that sshd sees, each with the Match state
	 * that it stands in.
	 *
	 * @param array $config The 'sshd_config' part of the host facts
	 * @param string $path File to walk
	 * @param array $state Match state at the point where the file is read
	 * @param string $user Login name
	 * @param array|null $groups Group names of the login, null if unknown
	 * @param array &$events Directives found so far
	 * @param array &$problems Files that could not be taken into account
	 * @param int $depth Include nesting depth
	 */
	private static function walk(array $config, string $path, array $state, string $user, ?array $groups, array &$events, array &$problems, int $depth, array $stack = array(), array &$budget = array()) {
		if(in_array($path, $stack, true)) {
			$problems[] = array('path' => $path, 'status' => 'includes itself');
			return;
		}
		$budget['files'] = ($budget['files'] ?? 0) + 1;
		if($budget['files'] > self::MAX_FILES_WALKED || count($events) >= self::MAX_DIRECTIVES) {
			if(empty($budget['stopped'])) {
				$budget['stopped'] = true;
				$problems[] = array('path' => $path, 'status' => 'the configuration is too large, the rest was left out');
			}
			return;
		}
		$stack[] = $path;
		$file = $config['files'][$path] ?? null;
		if(!is_array($file) || ($file['status'] ?? '') !== 'ok') {
			$problems[] = array('path' => $path, 'status' => is_array($file) ? ($file['status'] ?? 'unknown') : 'not collected');
			if(!is_array($file) || !isset($file['entries'])) {
				return;
			}
		}
		if($depth > 16) {
			return;
		}
		foreach($file['entries'] ?? array() as $entry) {
			if(!is_array($entry) || count($entry) < 3 || !is_array($entry[2])) continue;
			list($line, $keyword, $args) = $entry;
			if($keyword === 'match') {
				if($state['inherited'] && $state['parent_verdict'] === 'no') {
					// Included from a block that does not match: nothing in this file can match
					continue;
				}
				$verdict = self::evaluate_match($args, $user, $groups);
				$header = implode(' ', $args);
				if($state['inherited'] && $state['parent_verdict'] === 'maybe') {
					// Whether this block applies also hangs on the block that includes this file
					if($verdict === 'yes') $verdict = 'maybe';
					$header .= ' (in a file included under Match '.$state['parent_header'].')';
				}
				$state = array(
					'in_match' => true,
					'verdict' => $verdict,
					'header' => $header,
					'parent_header' => $state['parent_header'],
					'inherited' => $state['inherited'],
					'parent_verdict' => $state['parent_verdict'],
					// "Match all" also applies when sshd starts, so it may hold settings that are otherwise
					// only allowed outside of Match blocks
					'startup' => count($args) == 1 && strtolower($args[0]) === 'all' && (!$state['inherited'] || $state['parent_startup']),
					'parent_startup' => $state['parent_startup'],
				);
			} elseif($keyword === 'include') {
				$child_state = $state;
				if($state['in_match']) {
					$child_state['inherited'] = true;
					$child_state['parent_verdict'] = $state['verdict'];
					$child_state['parent_startup'] = $state['startup'];
					$child_state['parent_header'] = $state['header'];
				}
				foreach($entry[3] ?? array() as $resolved) {
					if(!is_array($resolved)) continue;
					if(($resolved['status'] ?? 'ok') !== 'ok') {
						$problems[] = array('path' => (string)($resolved['pattern'] ?? '?'), 'status' => (string)$resolved['status']);
					}
					foreach($resolved['files'] ?? array() as $child) {
						self::walk($config, (string)$child, $child_state, $user, $groups, $events, $problems, $depth + 1, $stack, $budget);
					}
				}
			} else {
				$events[] = array(
					'keyword' => (string)$keyword,
					'args' => array_map('strval', $args),
					'file' => $path,
					'line' => (int)$line,
					'in_match' => $state['in_match'],
					'startup' => !$state['in_match'] || $state['startup'],
					'verdict' => $state['in_match'] ? $state['verdict'] : 'yes',
					'header' => $state['in_match'] ? $state['header'] : null,
				);
			}
		}
	}

	/**
	 * Work out the configuration that sshd applies to one login.
	 *
	 * @param array $config The 'sshd_config' part of the host facts
	 * @param string $user Login name
	 * @param array|null $groups Names of the primary and supplementary groups of the login, null if unknown
	 * @return array with the keys
	 *  - 'values': keyword => directive (array with 'args', 'file', 'line', 'header') for single-value keywords
	 *  - 'lists': keyword => array of directives, for the Allow and Deny lists that apply
	 *  - 'conditional': directives in Match blocks that may or may not apply, depending on the connection
	 *  - 'misplaced': global-only directives found inside Match blocks (sshd refuses to start with these)
	 *  - 'problems': files that could not be read; the result is incomplete if this is not empty
	 *  - 'all': every directive in the order sshd reads them, each with 'verdict' ('yes', 'no' or 'maybe')
	 *    telling whether its Match block applies, to explain why a setting is not in effect
	 */
	public static function evaluate(array $config, string $user, ?array $groups): array {
		$events = array();
		$problems = array();
		$main = $config['main'] ?? null;
		if(is_string($main)) {
			$state = array('in_match' => false, 'verdict' => 'yes', 'header' => null, 'inherited' => false, 'parent_verdict' => 'yes', 'parent_header' => null, 'startup' => true, 'parent_startup' => true);
			$budget = array();
			self::walk($config, $main, $state, $user, $groups, $events, $problems, 0, array(), $budget);
		} else {
			$problems[] = array('path' => '/etc/ssh/sshd_config', 'status' => 'missing');
		}
		$global = array();
		$matched = array();
		$global_lists = array();
		$matched_lists = array();
		$conditional = array();
		$misplaced = array();
		foreach($events as $event) {
			$keyword = $event['keyword'];
			if($event['in_match'] && in_array($keyword, self::GLOBAL_ONLY_KEYWORDS)) {
				if($event['startup']) {
					// Inside "Match all": read like a setting outside of any block
					if(!isset($global[$keyword])) $global[$keyword] = $event;
				} else {
					$misplaced[] = $event;
				}
				continue;
			}
			if($event['verdict'] === 'no') {
				continue;
			}
			if($event['verdict'] === 'maybe') {
				// A block that certainly applies and comes earlier has already decided a single-value setting
				if(in_array($keyword, self::LIST_KEYWORDS) || !isset($matched[$keyword])) {
					$conditional[] = $event;
				}
				continue;
			}
			if(in_array($keyword, self::LIST_KEYWORDS)) {
				if($event['in_match']) {
					$matched_lists[$keyword][] = $event;
				} else {
					$global_lists[$keyword][] = $event;
				}
			} elseif($event['in_match']) {
				if(!isset($matched[$keyword])) $matched[$keyword] = $event;
			} else {
				if(!isset($global[$keyword])) $global[$keyword] = $event;
			}
		}
		return array(
			// A value from a matching Match block replaces the global one, lists included
			'values' => $matched + $global,
			'lists' => $matched_lists + $global_lists,
			'conditional' => $conditional,
			'misplaced' => $misplaced,
			'problems' => $problems,
			'all' => $events,
		);
	}

	/**
	 * Expand the tokens of one AuthorizedKeysFile entry for a login and make the path absolute.
	 *
	 * @param string $entry One argument of AuthorizedKeysFile
	 * @param string $user Login name
	 * @param string|null $home Home directory of the login, null if unknown
	 * @param string|null $uid Numeric user id of the login, null if unknown
	 * @return string|null The absolute path, or null if it cannot be determined (unknown home or uid)
	 */
	public static function expand_authorized_keys_path(string $entry, string $user, ?string $home, ?string $uid): ?string {
		$path = '';
		$n = strlen($entry);
		for($i = 0; $i < $n; $i++) {
			if($entry[$i] !== '%' || $i + 1 >= $n) {
				$path .= $entry[$i];
				continue;
			}
			$i++;
			switch($entry[$i]) {
			case '%': $path .= '%'; break;
			case 'u': $path .= $user; break;
			case 'h':
				if(is_null($home)) return null;
				$path .= $home;
				break;
			case 'U':
				if(is_null($uid)) return null;
				$path .= $uid;
				break;
			default:
				// Unknown token, sshd refuses such a configuration
				return null;
			}
		}
		if($path === '' || $path[0] !== '/') {
			if(is_null($home)) return null;
			$path = rtrim($home, '/').'/'.$path;
		}
		return $path;
	}

	/**
	 * Check a login against one entry of AllowUsers or DenyUsers.
	 *
	 * @return string 'yes', 'no', or 'maybe' if the entry is limited to certain client hosts (USER@HOST)
	 */
	public static function match_user_entry(string $entry, string $user): string {
		$at = strrpos($entry, '@');
		if($at === false) {
			return self::match_pattern($entry, $user) ? 'yes' : 'no';
		}
		return self::match_pattern(substr($entry, 0, $at), $user) ? 'maybe' : 'no';
	}

	/**
	 * Decide whether a public key of the given type passes a PubkeyAcceptedAlgorithms value.
	 *
	 * @param string $value The configured value (a pattern list, optionally starting with '+', '-' or '^')
	 * @param string $key_type Key type as stored by SKA, e.g. "ssh-rsa" or "ssh-ed25519"
	 * @param bool $lists_key_types Whether the server is older than OpenSSH 7.8, where the list names key types
	 *                              rather than signature algorithms
	 * @return bool false if no signature algorithm of this key type is accepted
	 */
	public static function key_type_accepted(string $value, string $key_type, bool $lists_key_types = false): bool {
		// From 7.8 on, RSA keys can sign with SHA-2 regardless of the "ssh-rsa" key type name
		$algorithms = ($key_type === 'ssh-rsa' && !$lists_key_types) ? array('rsa-sha2-512', 'rsa-sha2-256', 'ssh-rsa') : array($key_type);
		// DSA keys are not in the default set
		$in_default = $key_type !== 'ssh-dss';
		if($value === '') {
			return $in_default;
		}
		$any = function(string $list) use ($algorithms) {
			foreach($algorithms as $algorithm) {
				if(self::match_pattern_list($list, $algorithm) === 1) return true;
			}
			return false;
		};
		if($value[0] === '+' || $value[0] === '^') {
			// Adds to the default set, removes nothing
			return $in_default || $any(substr($value, 1));
		}
		if($value[0] === '-') {
			if(!$in_default) return false;
			$removed = substr($value, 1);
			foreach($algorithms as $algorithm) {
				if(self::match_pattern_list($removed, $algorithm) !== 1) {
					return true;
				}
			}
			return false;
		}
		return $any($value);
	}

	/**
	 * Decide whether the server still accepts RSA signatures made with SHA-1 (algorithm "ssh-rsa"), which is
	 * all that old clients can produce.
	 *
	 * @param string|null $value The configured PubkeyAcceptedAlgorithms value, null if it is not set
	 * @param bool $in_default Whether "ssh-rsa" is in the default set of the server (OpenSSH before 8.8)
	 */
	public static function sha1_rsa_accepted(?string $value, bool $in_default): bool {
		if(is_null($value) || $value === '') {
			return $in_default;
		}
		if($value[0] === '+' || $value[0] === '^') {
			return $in_default || self::match_pattern_list(substr($value, 1), 'ssh-rsa') === 1;
		}
		if($value[0] === '-') {
			return $in_default && self::match_pattern_list(substr($value, 1), 'ssh-rsa') !== 1;
		}
		return self::match_pattern_list($value, 'ssh-rsa') === 1;
	}
}
