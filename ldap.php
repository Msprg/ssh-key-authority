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

class LDAP {
	private $conn;
	private $host;
	private $starttls;
	private $bind_dn;
	private $bind_password;
	private $options;

	private $config;
	private $config_file = 'config/config.ini';

	private function loadconfigfile() {
		if(file_exists($this->config_file)) {
		        $this->config = parse_ini_file($this->config_file, true);
		} else {
		        throw new Exception("Config file $this->config_file does not exist.");
		}
	}


	public function __construct($host, $starttls, $bind_dn, #[\SensitiveParameter] $bind_password, $options) {
		$this->conn = null;
		$this->host = $host;
		$this->starttls = $starttls;
		$this->bind_dn = $bind_dn;
		$this->bind_password = $bind_password;
		$this->options = $options;
	}

	/**
	 * Connect to the LDAP server and bind with the configured credentials.
	 *
	 * Failures are reported as typed exceptions so that callers (the login flow in particular) can tell
	 * "the server is unreachable" apart from "the server refused these credentials":
	 *  - LDAPServerUnavailableException: could not reach the server or establish TLS
	 *  - LDAPBindFailedException: the server answered and refused the bind (carries the LDAP error
	 *    number and the server's diagnostic message)
	 *  - LDAPConnectionFailureException: anything else (both of the above extend it)
	 *
	 * @throws LDAPConnectionFailureException
	 */
	public function connect() {
		// $this->conn is only set once the bind has worked, so a failed attempt is retried (and reported
		// with its real cause) by the next search instead of running on an unbound connection.
		$this->conn = null;
		$conn = self::attempt(fn() => ldap_connect($this->host));
		if($conn === false) throw new LDAPConnectionFailureException('Invalid LDAP connection settings');
		if($this->starttls && !self::attempt(fn() => ldap_start_tls($conn))) {
			throw new LDAPServerUnavailableException('Could not initiate TLS connection to LDAP server: '.self::describe_last_error($conn));
		}
		foreach($this->options as $option => $value) {
			if(!self::attempt(fn() => ldap_set_option($conn, $option, $value))) {
				throw new LDAPConnectionFailureException("Could not set LDAP option $option");
			}
		}
		if(!empty($this->bind_dn) && !self::attempt(fn() => ldap_bind($conn, $this->bind_dn, $this->bind_password))) {
			$errno = ldap_errno($conn);
			$description = self::describe_last_error($conn);
			if(self::is_unavailable_errno($errno)) {
				throw new LDAPServerUnavailableException('Could not contact LDAP server: '.$description);
			}
			if($errno < 0) {
				// Client-side failure that is not about reaching the server: the request could not be made
				throw new LDAPConnectionFailureException('Could not bind to LDAP server: '.$description);
			}
			throw new LDAPBindFailedException('Could not bind to LDAP server: '.$description, $errno, self::get_diagnostic_message($conn));
		}
		$this->conn = $conn;
	}

	/**
	 * Run one ldap_* call and return its result, or false if it failed.
	 * A failing ldap_* function raises a PHP warning, which the handler in core.php turns into an
	 * ErrorException (the "@" operator does not prevent that). Callers here want the false return value,
	 * so that they can ask the connection what went wrong and throw one of the typed exceptions.
	 *
	 * @param callable $call the ldap_* call
	 * @return mixed
	 */
	private static function attempt(callable $call) {
		try {
			return $call();
		} catch(ErrorException $e) {
			return false;
		}
	}

	/**
	 * Does this LDAP error number mean the server could not be reached or is not able to answer, as
	 * opposed to the server answering with a refusal or the request itself being wrong?
	 * -1 "Can't contact LDAP server", -5 "Timed out" and -11 "Connect error" are libldap's client-side
	 * connection errors; 51 and 52 are the server reporting busy / unavailable. Other negative numbers
	 * (bad filter, parameter error, not supported, ...) are permanent client-side errors, not outages.
	 *
	 * @param int $errno LDAP error number
	 * @return bool
	 */
	private static function is_unavailable_errno($errno) {
		return in_array($errno, array(-1, -5, -11, 51, 52), true);
	}

	/**
	 * @return string extended error text sent by the server for the last operation, or '' if none
	 */
	private static function get_diagnostic_message($conn) {
		$diagnostic = '';
		$ok = self::attempt(function() use ($conn, &$diagnostic) {
			return ldap_get_option($conn, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diagnostic);
		});
		return ($ok && is_string($diagnostic)) ? $diagnostic : '';
	}

	/**
	 * @return string description of the last LDAP error on the connection, for log messages
	 */
	private static function describe_last_error($conn) {
		$description = ldap_error($conn).' ('.ldap_errno($conn).')';
		$diagnostic = self::get_diagnostic_message($conn);
		if($diagnostic !== '') $description .= ': '.$diagnostic;
		return $description;
	}

	/**
	 * Throw the exception matching the last error of a failed search step.
	 */
	private function fail_search($basedn) {
		$description = self::describe_last_error($this->conn);
		if(self::is_unavailable_errno(ldap_errno($this->conn))) {
			// The connection is gone; reconnect on the next call
			$this->conn = null;
			throw new LDAPServerUnavailableException('Lost contact with LDAP server during search: '.$description);
		}
		throw new LDAPSearchFailedException("LDAP search in '$basedn' failed: ".$description);
	}

	/**
	 * Find the user entries under the configured user subtree whose user_id attribute equals $uid.
	 * This is the one place that defines which LDAP entry belongs to a username.
	 *
	 * @param string $uid username
	 * @param array $fields attributes to fetch
	 * @return array matching entries (normally none or one)
	 */
	public function find_users_by_id($uid, $fields) {
		global $config;
		return $this->search($config['ldap']['dn_user'], LDAP::escape($config['ldap']['user_id']).'='.LDAP::escape($uid), $fields);
	}

	/**
	 * Decode a raw binary 16-byte guid (as given by ldap) to a readable
	 * guid string like 0d187752-3d90-4c9c-8db2-eb7003163a82
	 *
	 * @param string $encoded
	 * @return string
	 */
	private static function decode_guid(string $encoded) {
		return bin2hex(strrev(substr($encoded, 0, 4))) . '-' .
				bin2hex(strrev(substr($encoded, 4, 2))) . '-' .
				bin2hex(strrev(substr($encoded, 6, 2))) . '-' .
				bin2hex(substr($encoded, 8, 2)) . '-' .
				bin2hex(substr($encoded, 10, 6));
	}

	/**
	 * Example:
	 * Input: 0d187752-3d90-4c9c-8db2-eb7003163a82
	 * Output: \52\77\18\0d\90\3d\9c\4c\8d\b2\eb\70\03\16\3a\82
	 * This can be used for a search query, for example: ObjectGUID=\52\77...
	 *
	 * @param string $guid The input guid
	 * @return string The escaped guid usable for search queries
	 */
	public static function query_encode_guid($guid) {
		if (preg_match('/^([a-f0-9]{8})-([a-f0-9]{4})-([a-f0-9]{4})-([a-f0-9]{4})-([a-f0-9]{12})$/', $guid, $matches)) {
			$reverse_part = $matches[3] . $matches[2] . $matches[1];
			$forward_part = $matches[4] . $matches[5];
			$output_left = '';
			$output_right = '';
			for ($i=0; $i<8; $i++) {
				$output_left .= '\\' . substr($reverse_part, 14 - 2 * $i, 2);
				$output_right .= '\\' . substr($forward_part, 2 * $i, 2);
			}
			return $output_left . $output_right;
		}
		return '';
	}

	public function search($basedn, $filter, $fields = array(), $sort = array(), $onelevel = false) {
		global $config;

		if(is_null($this->conn)) $this->connect();
		if(empty($fields)) {
			$fields = ["*"];
		}
		$r = self::attempt(fn() => $onelevel
			? ldap_list($this->conn, $basedn, $filter, $fields)
			: ldap_search($this->conn, $basedn, $filter, $fields));
		if($r === false) $this->fail_search($basedn);
		$sort = array_reverse($sort);
		foreach($sort as $field) {
			@ldap_sort($this->conn, $r, $field);
		}
		if($r) {
			// Fetch entries
			$result = self::attempt(fn() => ldap_get_entries($this->conn, $r));
			if($result === false) $this->fail_search($basedn);
			unset($result['count']);
			$items = array();
			foreach($result as $item) {
				unset($item['count']);
				$itemResult = array();
				foreach($item as $key => $values) {
					if(!is_int($key)) {
						if(is_array($values)) {
							unset($values['count']);
							if(count($values) == 1) $values = $values[0];
						}
						if (strtolower($key) === strtolower($config['ldap']['group_num'])) {
							$values = self::decode_guid($values);
						}
						$itemResult[$key] = $values;
					}
				}
				$items[] = $itemResult;
			}
			return $items;
		}
		return false;
	}

	public static function escape($str = '') {
		$metaChars = array("\\00", "\\", "(", ")", "*");
		$quotedMetaChars = array();
		foreach($metaChars as $key => $value) {
			$quotedMetaChars[$key] = '\\'. dechex(ord($value));
		}
		$str = str_replace($metaChars, $quotedMetaChars, $str);
		return $str;
	}
}

class LDAPConnectionFailureException extends RuntimeException {}

/**
 * The LDAP server could not be reached (network failure, timeout, TLS failure) or reported itself as
 * busy/unavailable. Nothing is known about the credentials that were going to be used.
 */
class LDAPServerUnavailableException extends LDAPConnectionFailureException {}

/**
 * The LDAP server was reached and bound to, but refused or could not complete a search - typically a
 * configuration problem (wrong base DN, account without read permission, no bind_dn configured, ...).
 */
class LDAPSearchFailedException extends RuntimeException {}

/**
 * The LDAP server was reached and refused the bind.
 */
class LDAPBindFailedException extends LDAPConnectionFailureException {
	/**
	 * LDAP result code of the failed bind (49 = invalid credentials)
	 */
	public $ldap_errno;
	/**
	 * Extended error text from the server, may be empty
	 */
	public $diagnostic;

	public function __construct($message, $ldap_errno, $diagnostic) {
		parent::__construct($message);
		$this->ldap_errno = $ldap_errno;
		$this->diagnostic = $diagnostic;
	}

	/**
	 * Active Directory explains a refused bind with a hex sub-code in the diagnostic message, e.g.
	 * "80090308: LdapErr: DSID-0C090559, comment: AcceptSecurityContext error, data 532, v65f4".
	 *
	 * @return string|null lower-case sub-code such as '52e' or '532', null if the server did not send one
	 */
	public function get_ad_subcode() {
		if(preg_match('/\bdata ([0-9a-f]+)\b/i', (string)$this->diagnostic, $matches)) {
			return strtolower($matches[1]);
		}
		return null;
	}
}
