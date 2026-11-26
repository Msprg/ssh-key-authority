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
 * Facts about a target server that influence whether a synced key actually lets someone log in:
 * the sshd configuration, the state of the key directory, and the login accounts.
 *
 * Only the sync and supervision scripts hold an SSH connection to the target servers; the web frontend cannot
 * open one. They collect the facts while they are connected anyway and store them here, and the
 * "Troubleshoot access" tool reads this snapshot.
 *
 * Everything collected is text controlled by the target server. It is cut down to an allowlist of sshd
 * directives and a few fields per login (no GECOS, no key material) and must be escaped when displayed.
 */
class ServerHostFacts {
	/** Format version of the stored facts. Snapshots of another version are ignored. */
	const VERSION = 2;
	const KEYDIR = '/var/local/keys-sync';
	const MAIN_CONFIG = '/etc/ssh/sshd_config';
	/** openSUSE and SLE ship the defaults here; /etc/ssh/sshd_config replaces this file completely if present */
	const VENDOR_CONFIG = '/usr/etc/ssh/sshd_config';

	const MAX_CONFIG_FILES = 32;
	const MAX_CONFIG_FILE_BYTES = 65536;
	const MAX_CONFIG_ENTRIES = 500;
	const MAX_INCLUDE_DEPTH = 5;
	const MAX_INCLUDE_PATTERNS = 64;
	/**
	 * Seconds that one collection may take in total; it runs at the end of a sync, which must not be held up.
	 * No command is started unless a whole command timeout of the SSH library still fits in.
	 */
	const TIME_BUDGET = 25;
	const COMMAND_TIMEOUT = 11;
	const MAX_GROUPS = 200;
	const MAX_KEYFILES = 300;
	const MAX_LOGINS = 100;
	const MAX_FACTS_BYTES = 524288;

	/**
	 * Login names that are safe to pass to the target server. Others are not looked up there.
	 */
	const LOGIN_NAME_REGEX = '/^[A-Za-z0-9_][A-Za-z0-9_.@-]{0,63}$/';

	/**
	 * Load the stored facts of a server.
	 *
	 * @param Server $server The server to load the facts of
	 * @return array|null array with 'collected' (UTC date string), 'age' (seconds) and 'facts' (array),
	 *                    or null if no usable snapshot exists
	 */
	public static function load(Server $server): ?array {
		global $database;
		$stmt = $database->prepare("
			SELECT collected, TIMESTAMPDIFF(SECOND, collected, UTC_TIMESTAMP()) AS age, facts
			FROM server_host_facts
			WHERE server_id = ?
		");
		$stmt->bind_param('d', $server->id);
		$stmt->execute();
		$row = $stmt->get_result()->fetch_assoc();
		$stmt->close();
		if(!$row) {
			return null;
		}
		$facts = json_decode($row['facts'], true);
		if(!is_array($facts) || ($facts['version'] ?? null) !== self::VERSION) {
			return null;
		}
		return array('collected' => $row['collected'], 'age' => (int)$row['age'], 'facts' => $facts);
	}

	/**
	 * Store the facts of a server, replacing an earlier snapshot.
	 *
	 * @param Server $server The server that the facts belong to
	 * @param array $facts Facts as returned by collect()
	 * @return bool false if the facts are too large to store even after leaving out what can be left out
	 */
	public static function store(Server $server, array $facts): bool {
		global $database;
		$encode = function(array $facts) {
			return json_encode($facts, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
		};
		$json = $encode($facts);
		if(strlen($json) > self::MAX_FACTS_BYTES && isset($facts['logins'])) {
			// Group lists are the bulkiest part where accounts come from a directory, and the easiest to do without
			foreach($facts['logins'] as $name => $login) {
				if(isset($login['groups'])) $facts['logins'][$name]['groups'] = null;
			}
			$facts['errors'][] = 'The group memberships of the accounts on this server are too large to store';
			$json = $encode($facts);
		}
		if(strlen($json) > self::MAX_FACTS_BYTES) {
			$facts['sshd_config'] = array('main' => null, 'vendor' => null, 'files' => array());
			$facts['errors'][] = 'The sshd configuration of this server is too large to store';
			$json = $encode($facts);
		}
		if(strlen($json) > self::MAX_FACTS_BYTES) {
			return false;
		}
		$stmt = $database->prepare("
			INSERT INTO server_host_facts SET server_id = ?, collected = UTC_TIMESTAMP(), facts = ?
			ON DUPLICATE KEY UPDATE collected = VALUES(collected), facts = VALUES(facts)
		");
		$stmt->bind_param('ds', $server->id, $json);
		$stmt->execute();
		$stmt->close();
		return true;
	}

	/**
	 * Add notes to the stored snapshot of a server without replacing it or changing its age.
	 *
	 * @param Server $server The server whose snapshot is annotated
	 * @param array $fields Fields to set in the stored facts
	 */
	private static function annotate(Server $server, array $fields) {
		global $database;
		$stored = self::load($server);
		if(is_null($stored)) {
			return;
		}
		$json = json_encode($fields + $stored['facts'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
		$stmt = $database->prepare("UPDATE server_host_facts SET facts = ? WHERE server_id = ?");
		$stmt->bind_param('sd', $json, $server->id);
		$stmt->execute();
		$stmt->close();
	}

	/**
	 * Note that the stored snapshot of a server is known to be out of date, so that the next collection
	 * happens regardless of its age. Never throws.
	 */
	public static function request_refresh(Server $server) {
		try {
			self::annotate($server, array('refresh_requested' => true));
		} catch(Throwable $e) {
			error_log("Marking the host facts of {$server->hostname} as outdated failed: ".$e->getMessage());
		}
	}

	/**
	 * Collect the facts over an open connection and store them. This is a side job of sync and supervision
	 * and must never disturb them, so every failure is swallowed (the old snapshot then simply stays).
	 *
	 * @param Server $server The server that the connection leads to
	 * @param SSH $connection Open connection to the server
	 * @param string $source Which script collects ('sync' or 'supervision'), kept for display
	 * @param int|null $max_age If given, do nothing while the stored snapshot is younger than this many seconds
	 */
	public static function collect_and_store(Server $server, SSH $connection, string $source, ?int $max_age = null) {
		try {
			$stored = self::load($server);
			if(!is_null($max_age) && !is_null($stored) && empty($stored['facts']['refresh_requested'])) {
				// A failed attempt counts like a collection here, so that a server that cannot be read is not
				// tried again on every run
				$last = max(time() - $stored['age'], (int)($stored['facts']['refresh_failed']['time'] ?? 0));
				if($stored['age'] >= 0 && time() - $last < $max_age) {
					return;
				}
			}
			$facts = self::collect($server, $connection);
			$facts['source'] = $source;
			$complete_before = !is_null($stored) && empty($stored['facts']['failed']);
			if(empty($facts['failed']) || !$complete_before) {
				if(self::store($server, $facts)) {
					return;
				}
				$facts['errors'][] = 'The information collected from this server is too large to store';
				if(!$complete_before) {
					self::store($server, array('version' => self::VERSION, 'source' => $source, 'failed' => true, 'errors' => $facts['errors']));
					return;
				}
			}
			// Rather keep an older complete snapshot (the tool shows its age) than replace it with one that has
			// holes; but say that renewing it did not work
			error_log("Collecting host facts of {$server->hostname} failed: ".implode('; ', $facts['errors']));
			self::annotate($server, array('refresh_requested' => false, 'refresh_failed' => array('time' => time(), 'errors' => $facts['errors'])));
		} catch(Throwable $e) {
			error_log("Collecting host facts of {$server->hostname} failed: ".$e->getMessage());
		}
	}

	/**
	 * Collect the facts from a target server.
	 *
	 * @param Server $server The server that the connection leads to
	 * @param SSH $connection Open connection to the server
	 * @return array The facts, see the class description and the parse_* functions for the structure
	 */
	public static function collect(Server $server, SSH $connection): array {
		$marker = '@@SKA'.bin2hex(random_bytes(6));
		$facts = array('version' => self::VERSION, 'errors' => array(), 'failed' => false);
		try {
			$facts['sshd_banner'] = self::clean((string)$connection->get_server_identification(), 100);
		} catch(Throwable $e) {
			$facts['sshd_banner'] = null;
		}
		$deadline = microtime(true) + self::TIME_BUDGET;
		$run = function(string $script) use ($connection, $deadline) {
			if(microtime(true) + self::COMMAND_TIMEOUT > $deadline) {
				throw new RuntimeException('the time allowed for collecting was used up');
			}
			return $connection->exec_complete($script);
		};
		// A command that fails or does not finish leaves the following ones little chance, and this must not
		// hold up the caller: stop at the first failure.
		$step = 'Reading the key directory';
		try {
			$sections = self::split_sections($run(self::general_script($marker)), $marker);
			$facts += self::parse_general($sections);

			$step = 'Looking up the login accounts';
			$names = array();
			foreach($server->list_accounts() as $account) {
				$names[] = $account->name;
			}
			if($server->authorization != 'manual') {
				// Key files of LDAP users have no managed account behind them
				$names = array_merge($names, array_map('strval', array_keys($facts['keyfiles'])));
			}
			$names = array_values(array_unique(array_filter($names, function($name) {
				return preg_match(self::LOGIN_NAME_REGEX, $name) === 1;
			})));
			$names = array_slice($names, 0, self::MAX_LOGINS);
			$facts['logins'] = array();
			if(count($names) > 0) {
				$sections = self::split_sections($run(self::login_script($marker, $names)), $marker);
				$facts['logins'] = self::parse_logins($sections, $names);
			}

			$step = 'Reading the sshd configuration';
			$facts['sshd_config'] = self::collect_sshd_config($run, $marker);
		} catch(Throwable $e) {
			$facts['failed'] = true;
			$facts['errors'][] = $step.' failed: '.self::clean($e->getMessage(), 200);
		}
		return $facts;
	}

	/**
	 * Make text from a target server safe to store: valid UTF-8, no control characters, limited length.
	 */
	public static function clean(string $text, int $max_length): string {
		$text = mb_scrub($text, 'UTF-8');
		$text = preg_replace('/[\x00-\x1f\x7f]/u', '', $text) ?? '';
		return mb_substr($text, 0, $max_length, 'UTF-8');
	}

	/**
	 * Split the output of one of the collection scripts at its marker lines.
	 *
	 * @param string $output Output of the remote command
	 * @param string $marker Marker that starts a section header line
	 * @return array of array(section header without the marker, array of content lines)
	 */
	public static function split_sections(string $output, string $marker): array {
		$sections = array();
		$current = null;
		foreach(explode("\n", $output) as $line) {
			if(strncmp($line, $marker.' ', strlen($marker) + 1) === 0) {
				$sections[] = array(rtrim(substr($line, strlen($marker) + 1), "\r"), array());
				$current = count($sections) - 1;
			} elseif(!is_null($current)) {
				$sections[$current][1][] = $line;
			}
		}
		return $sections;
	}

	/**
	 * Shell script that reports the OS, the state of the key directory and what else may stop logins.
	 */
	private static function general_script(string $marker): string {
		$keydir = escapeshellarg(self::KEYDIR);
		$max = self::MAX_KEYFILES + 1;
		return "(
LC_ALL=C; export LC_ALL
M='$marker'
echo \"\$M os\"
grep -E '^(ID|VERSION_ID|PRETTY_NAME)=' /etc/os-release | head -c 600
echo \"\$M selinux\"
cat /sys/fs/selinux/enforce; echo
echo \"\$M nologin\"
for f in /etc/nologin /run/nologin; do [ -e \"\$f\" ] && echo \"\$f\"; done
echo \"\$M dirs\"
stat -c '%a %u %U %n' /var/local $keydir
echo \"\$M keyfiles\"
if cd $keydir; then
	n=0
	for f in * .[!.]* ..?*; do
		[ -f \"\$f\" ] || continue
		n=\$((n+1))
		if [ \$n -ge $max ]; then echo truncated; break; fi
		s=\$({ sha1sum < \"\$f\"; } 2>/dev/null | cut -c1-40)
		printf '%s %s %s\\n' \"\$(stat -c '%a %u %U %s %Y' -- \"\$f\")\" \"\${s:-unreadable}\" \"\$f\"
	done
fi
echo \"\$M end\"
) 2>/dev/null";
	}

	/**
	 * Interpret the output of general_script().
	 *
	 * @param array $sections Sections as returned by split_sections()
	 * @return array with the keys 'complete', 'os', 'selinux', 'nologin', 'dirs', 'keyfiles' and
	 *               'keyfiles_truncated' (the list of key files may lack files that exist)
	 */
	public static function parse_general(array $sections): array {
		$facts = array(
			'complete' => false,
			'os' => array(),
			'selinux' => null,
			'nologin' => array(),
			'dirs' => array(),
			'keyfiles' => array(),
			'keyfiles_truncated' => false,
		);
		foreach($sections as list($name, $lines)) {
			switch($name) {
			case 'os':
				foreach($lines as $line) {
					if(preg_match('/^(ID|VERSION_ID|PRETTY_NAME)=(.*)$/', $line, $matches)) {
						$facts['os'][strtolower($matches[1])] = self::clean(trim($matches[2], "\"' \r"), 100);
					}
				}
				break;
			case 'selinux':
				$value = trim($lines[0] ?? '');
				if($value === '1') $facts['selinux'] = 'enforcing';
				if($value === '0') $facts['selinux'] = 'permissive';
				break;
			case 'nologin':
				foreach($lines as $line) {
					if($line === '/etc/nologin' || $line === '/run/nologin') $facts['nologin'][] = $line;
				}
				break;
			case 'dirs':
				foreach($lines as $line) {
					if(preg_match('/^([0-7]{1,4}) (\d+) (\S+) (\/var\/local(?:\/keys-sync)?)$/', $line, $matches)) {
						$facts['dirs'][$matches[4]] = array('mode' => $matches[1], 'uid' => (int)$matches[2], 'owner' => self::clean($matches[3], 64));
					}
				}
				break;
			case 'keyfiles':
				foreach($lines as $line) {
					if($line === 'truncated' || count($facts['keyfiles']) >= self::MAX_KEYFILES) {
						$facts['keyfiles_truncated'] = true;
						break;
					}
					if(!preg_match('/^([0-7]{1,4}) (\d+) (\S+) (\d+) (\d+) ([0-9a-f]{40}|unreadable) (.+)$/', $line, $matches)) {
						// A file whose line cannot be read: the listing is not reliable for files that are missing from it
						if($line !== '') $facts['keyfiles_truncated'] = true;
						continue;
					}
					$filename = self::clean($matches[7], 255);
					if($filename !== $matches[7]) {
						$facts['keyfiles_truncated'] = true;
						continue;
					}
					$facts['keyfiles'][$filename] = array(
						'mode' => $matches[1],
						'uid' => (int)$matches[2],
						'owner' => self::clean($matches[3], 64),
						'size' => (int)$matches[4],
						'mtime' => (int)$matches[5],
						'sha1' => $matches[6] === 'unreadable' ? null : $matches[6],
					);
				}
				break;
			case 'end':
				$facts['complete'] = true;
				break;
			}
		}
		return $facts;
	}

	/**
	 * Shell script that looks up the given login names. Only uid, gid, home and shell are taken from the
	 * passwd entry. Groups are printed one per line, because group names from a directory service may
	 * contain spaces; where "id" cannot do that, they are only given if splitting at spaces is unambiguous.
	 *
	 * @param string $marker Marker for the section header lines
	 * @param array $names Login names, each matching LOGIN_NAME_REGEX (so none starts with a dash; "getent"
	 *                     is called without "--", which not every implementation understands)
	 */
	private static function login_script(string $marker, array $names): string {
		$list = implode(' ', array_map('escapeshellarg', $names));
		return "(
LC_ALL=C; export LC_ALL
M='$marker'
for u in $list; do
	echo \"\$M login \$u\"
	e=\$(getent passwd \"\$u\"); rc=\$?
	if [ \$rc -eq 2 ]; then echo absent; continue; fi
	e=\$(printf '%s\\n' \"\$e\" | head -n 1)
	[ -n \"\$e\" ] || continue
	printf 'passwd %s\\n' \"\$(printf '%s' \"\$e\" | cut -d: -f1,3,4,6,7)\"
	g=\$(id -Gnz -- \"\$u\" | tr '\\0' '\\n')
	if [ -n \"\$g\" ]; then
		printf '%s\\n' \"\$g\" | sed 's/^/group /'
	else
		g=\$(id -Gn -- \"\$u\")
		if [ \"\$(printf '%s' \"\$g\" | wc -w)\" -eq \"\$(id -G -- \"\$u\" | wc -w)\" ]; then
			for x in \$g; do echo \"group \$x\"; done
		fi
	fi
	sh=\$(printf '%s' \"\$e\" | cut -d: -f7)
	[ -n \"\$sh\" ] || sh=/bin/sh
	if t=\$(stat -L -c '%F %a' -- \"\$sh\"); then
		echo \"shell \$t\"
	else
		d=\${sh%/*}
		if [ -d \"\${d:-/}\" ] && [ -x \"\${d:-/}\" ]; then echo 'shell missing'; fi
	fi
done
echo \"\$M end\"
) 2>/dev/null";
	}

	/**
	 * Interpret the output of login_script().
	 *
	 * @param array $sections Sections as returned by split_sections()
	 * @param array $names The login names that were asked for
	 * @return array login name => array('exists' => false), or array with 'exists', 'uid', 'gid', 'home',
	 *               'shell', 'shell_ok' (null if it could not be examined) and 'groups' (array of group names,
	 *               null if they could not be listed). Logins that the output says nothing definite about are
	 *               left out.
	 * @throws RuntimeException if the output is incomplete
	 */
	public static function parse_logins(array $sections, array $names): array {
		$logins = array();
		$complete = false;
		foreach($sections as list($header, $lines)) {
			if($header === 'end') $complete = true;
			if(strncmp($header, 'login ', 6) !== 0) continue;
			$name = substr($header, 6);
			if(!in_array($name, $names, true)) continue;
			$login = null;
			foreach($lines as $line) {
				if($line === 'absent' && is_null($login)) {
					$login = array('exists' => false);
				} elseif(strncmp($line, 'passwd ', 7) === 0) {
					$fields = explode(':', substr($line, 7));
					// Tools resolve an all-digit name as a user id; sshd does not
					$other_account = ctype_digit($name) && $fields[0] !== $name;
					if(count($fields) == 5 && ctype_digit($fields[1]) && ctype_digit($fields[2]) && !$other_account) {
						$login = array(
							'exists' => true,
							'uid' => (int)$fields[1],
							'gid' => (int)$fields[2],
							'home' => self::clean($fields[3], 255),
							'shell' => self::clean($fields[4], 255),
							'shell_ok' => null,
							'groups' => null,
						);
					}
				} elseif(strncmp($line, 'group ', 6) === 0 && !empty($login['exists'])) {
					if(count($login['groups'] ?? array()) < self::MAX_GROUPS) {
						$login['groups'][] = self::clean(substr($line, 6), 64);
					}
				} elseif(strncmp($line, 'shell ', 6) === 0 && !empty($login['exists'])) {
					if(preg_match('/^shell (.*) ([0-7]{1,4})$/', $line, $matches)) {
						// sshd wants a regular file with an execute bit, whoever it is for
						$login['shell_ok'] = strncmp($matches[1], 'regular', 7) === 0 && (octdec($matches[2]) & 0111) != 0;
					} elseif($line === 'shell missing') {
						$login['shell_ok'] = false;
					}
				}
			}
			if(!is_null($login)) {
				$logins[$name] = $login;
			}
		}
		if(!$complete) {
			throw new RuntimeException('the list of accounts came back incomplete');
		}
		return $logins;
	}

	/**
	 * Decide whether an Include pattern may be expanded on the target server. The pattern ends up unquoted in
	 * a shell command there, so only plain glob characters are let through, and only in the directories where
	 * sshd configuration lives.
	 */
	public static function include_pattern_allowed(string $pattern): bool {
		if(!preg_match('/^[A-Za-z0-9_.\/*?\[\]@+,:-]+$/', $pattern)) return false;
		if(strpos($pattern, '..') !== false) return false;
		foreach(array('/etc/ssh/', '/usr/etc/ssh/', '/etc/crypto-policies/', '/usr/share/crypto-policies/') as $prefix) {
			if(strncmp($pattern, $prefix, strlen($prefix)) === 0) return true;
		}
		return false;
	}

	/**
	 * Shell script that prints the files matching the given patterns, in glob order.
	 *
	 * @param string $marker Marker for the section header lines
	 * @param array $patterns Patterns that passed include_pattern_allowed()
	 */
	private static function config_script(string $marker, array $patterns): string {
		$max_bytes = self::MAX_CONFIG_FILE_BYTES;
		$max_files = self::MAX_CONFIG_FILES;
		$script = "(
LC_ALL=C; export LC_ALL
M='$marker'
c=0
";
		foreach(array_values($patterns) as $index => $pattern) {
			$script .= "echo \"\$M pattern $index\"\n";
			$dir = dirname($pattern);
			if(strpbrk($dir, '*?[') === false) {
				// A directory that cannot be listed looks like one in which nothing matches. Look at the
				// directory itself or, if it is not visible, at the nearest one above it that is.
				$dir = escapeshellarg($dir);
				$script .= "d=$dir; while [ ! -e \"\$d\" ] && [ \"\$d\" != / ]; do d=\${d%/*}; d=\${d:-/}; done\n";
				$script .= "if ! { [ -r \"\$d\" ] && [ -x \"\$d\" ]; }; then echo \"\$M unlistable\"; fi\n";
			}
			$script .= "for f in $pattern; do
	[ -e \"\$f\" ] || continue
	c=\$((c+1)); [ \$c -le $max_files ] || { echo \"\$M toomany\"; break; }
	if [ -f \"\$f\" ] && [ -r \"\$f\" ]; then
		printf '%s\\n' \"\$M file ok \$f\"
		head -c $max_bytes -- \"\$f\"; echo
	else
		printf '%s\\n' \"\$M file unreadable \$f\"
	fi
done
";
		}
		$script .= "echo \"\$M end\"
) 2>/dev/null";
		return $script;
	}

	/**
	 * Read the given files or glob patterns from the target server.
	 *
	 * @param callable $run Function that runs a shell script on the target server and returns its output
	 * @param string $marker Marker for the section header lines
	 * @param array $patterns Patterns that passed include_pattern_allowed()
	 * @return array pattern => array('status' => 'ok' or the reason why the result is not reliable, 'files' =>
	 *               array of array('path' => ..., 'status' => 'ok'|'unreadable', 'content' => ...))
	 * @throws RuntimeException if the output is incomplete
	 */
	private static function fetch_config_files(callable $run, string $marker, array $patterns): array {
		$patterns = array_values($patterns);
		$sections = self::split_sections($run(self::config_script($marker, $patterns)), $marker);
		$result = array();
		$current = null;
		$complete = false;
		foreach($sections as list($header, $lines)) {
			if(preg_match('/^pattern (\d+)$/', $header, $matches) && isset($patterns[(int)$matches[1]])) {
				$current = $patterns[(int)$matches[1]];
				$result[$current] = array('status' => 'ok', 'files' => array());
			} elseif($header === 'end') {
				$current = null;
				$complete = true;
			} elseif(is_null($current)) {
				continue;
			} elseif($header === 'unlistable') {
				$result[$current]['status'] = 'directory not readable for the sync user';
			} elseif($header === 'toomany') {
				$result[$current]['status'] = 'too many files';
			} elseif(preg_match('/^file (ok|unreadable) (\/[\x21-\x7e]+)$/', $header, $matches)) {
				$result[$current]['files'][] = array('path' => $matches[2], 'status' => $matches[1], 'content' => implode("\n", $lines));
			} elseif(strncmp($header, 'file ', 5) === 0) {
				$result[$current]['status'] = 'contains a file whose name cannot be handled';
			}
		}
		if(!$complete) {
			throw new RuntimeException('the configuration files came back incomplete');
		}
		return $result;
	}

	/**
	 * Reduce the content of an sshd configuration file to the directives that matter for logins.
	 *
	 * @param string $content Raw file content
	 * @return array with 'entries' (array of array(line number, keyword, arguments)) and 'truncated'
	 */
	public static function parse_config_file(string $content): array {
		$entries = array();
		$truncated = strlen($content) >= self::MAX_CONFIG_FILE_BYTES;
		$content = substr($content, 0, self::MAX_CONFIG_FILE_BYTES);
		foreach(explode("\n", $content) as $index => $line) {
			if(strlen($line) > 4096) {
				$truncated = true;
				continue;
			}
			$parsed = SshdConfig::parse_line($line);
			if(is_null($parsed) || !in_array($parsed[0], SshdConfig::KEPT_KEYWORDS)) continue;
			if(count($entries) >= self::MAX_CONFIG_ENTRIES) {
				$truncated = true;
				break;
			}
			list($keyword, $args) = $parsed;
			if(in_array($keyword, SshdConfig::PRESENCE_ONLY_KEYWORDS)) {
				// Paths and commands are nobody's business here, only whether the feature is in use
				$args = (count($args) > 0 && strtolower($args[0]) === 'none') ? array('none') : array();
			} elseif($keyword === 'forcecommand') {
				// Of a forced command, only whether it is the built-in SFTP server
				$args = (count($args) > 0 && $args[0] === 'internal-sftp') ? array('internal-sftp') : array();
			}
			// The values are evaluated, so they must not be shortened; the line length is limited above
			$args = array_map(function($arg) {
				return self::clean($arg, 4096);
			}, $args);
			$entries[] = array($index + 1, $keyword, $args);
		}
		return array('entries' => $entries, 'truncated' => $truncated);
	}

	/**
	 * Read the sshd configuration of the target server, following Include directives.
	 *
	 * @return array with 'main' (path of the file that sshd starts with, null if none was found),
	 *               'vendor' (path of a vendor default file, if one exists) and 'files':
	 *               path => array('status' => 'ok'|'unreadable', 'entries' => ..., 'truncated' => ...).
	 *               Include entries carry a fourth element: the list of patterns with the files they expanded to.
	 */
	private static function collect_sshd_config(callable $run, string $marker): array {
		$files = array();
		$expanded = array(); // pattern => array('pattern', 'status', 'files')
		$patterns = array(self::MAIN_CONFIG, self::VENDOR_CONFIG);
		for($depth = 0; $depth <= self::MAX_INCLUDE_DEPTH && count($patterns) > 0; $depth++) {
			$fetched = self::fetch_config_files($run, $marker, $patterns);
			$next = array();
			foreach($patterns as $pattern) {
				$found = $fetched[$pattern] ?? array('status' => 'not read', 'files' => array());
				$expanded[$pattern] = array('pattern' => $pattern, 'status' => $found['status'], 'files' => array());
				foreach($found['files'] as $file) {
					$path = $file['path'];
					if(!isset($files[$path]) && count($files) >= self::MAX_CONFIG_FILES) {
						$expanded[$pattern]['status'] = 'too many files';
						break;
					}
					$expanded[$pattern]['files'][] = $path;
					if(isset($files[$path])) continue;
					if($file['status'] !== 'ok') {
						$files[$path] = array('status' => 'unreadable', 'entries' => array(), 'truncated' => false);
						continue;
					}
					$files[$path] = array('status' => 'ok') + self::parse_config_file($file['content']);
					foreach($files[$path]['entries'] as $entry) {
						if($entry[1] !== 'include') continue;
						foreach($entry[2] as $include) {
							$include = self::absolute_include_pattern($include);
							if(isset($expanded[$include]) || isset($next[$include]) || !self::include_pattern_allowed($include)) continue;
							if(count($expanded) + count($next) < self::MAX_INCLUDE_PATTERNS) {
								$next[$include] = true;
							}
						}
					}
				}
			}
			$patterns = array_keys($next);
		}
		// Attach to every Include what it expanded to
		foreach($files as $path => $file) {
			foreach($file['entries'] as $index => $entry) {
				if($entry[1] !== 'include') continue;
				$resolved = array();
				foreach($entry[2] as $include) {
					$include = self::absolute_include_pattern($include);
					$resolved[] = $expanded[$include] ?? array('pattern' => $include, 'status' => 'not followed', 'files' => array());
				}
				$files[$path]['entries'][$index][3] = $resolved;
			}
		}
		$main = null;
		if(isset($files[self::MAIN_CONFIG])) {
			$main = self::MAIN_CONFIG;
		} elseif(isset($files[self::VENDOR_CONFIG])) {
			$main = self::VENDOR_CONFIG;
		}
		return array(
			'main' => $main,
			'vendor' => isset($files[self::VENDOR_CONFIG]) ? self::VENDOR_CONFIG : null,
			'files' => $files,
		);
	}

	/**
	 * Include paths that are not absolute are relative to /etc/ssh.
	 */
	public static function absolute_include_pattern(string $pattern): string {
		return ($pattern !== '' && $pattern[0] === '/') ? $pattern : '/etc/ssh/'.$pattern;
	}
}
