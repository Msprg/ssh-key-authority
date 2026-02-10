<?php
##
## Copyright 2021 Leitwerk AG
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

use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;

class SSHException extends Exception {}

/**
 * An SSH wrapper as an adapter to the actual ssh implementation.
 * Allows to change the used ssh library more easily in future.
 * Currently, the php module ssh2 is used.
 */
class SSH {
	/**
	 * The ssh connection handle, instance of phpseclib3\Net\SFTP. Must be set by the constructor.
	 */
	private $connection;

	/**
	 * Child command handle.
	 * Event though this field is never read, it needs to be stored here to keep the child
	 * process alive.
	 */
	private $jump_cmd_child;

	/**
	 * Only set, if jumphosts are used. This contains the reading end of a pipe to receive
	 * stderr output of the ssh jumphost command. If the handshake to the target fails,
	 * this stream could tell the reason.
	 */
	private $jump_cmd_stderr;

	/**
	 * Create a new ssh connection instance using the given handle
	 * @param resource $connection The opened ssh connection handle
	 */
	private function __construct($connection) {
		$this->connection = $connection;
	}

	/**
	 * Open an ssh connection to the given server using public-key authentication.
	 * The host key is given by reference. Initialize it with null for the first connection.
	 * If null is given, it is modified to the actual host key. In future versions,
	 * it might also be modified if the format or algorithm for the host key changes.
	 *
	 * @param string $host Hostname of the ssh server
	 * @param int $port Port number of the ssh server
	 * @param array $jumphosts An array of jumphosts where each element contains "user", "host", "port".
	 * @param string $pubkey_file_path Location of the public key file to use
	 * @param string $privkey_file_path Location of the private key file to use
	 * @param string &$host_key Reference to the host key value
	 * @throws SSHException If the connection fails (e.g. host unreachable, wrong fingerprint, failed to authenticate)
	 */
	public static function connect_with_pubkey(
		string $host,
		int $port,
		array $jumphosts,
		string $username,
		string $pubkey_file_path,
		string $privkey_file_path,
		?string &$host_key,
		array $jumphost_security_options = array()
	): SSH {
		try {
			$ssh = self::build_connection($host, $port, $jumphosts, $jumphost_security_options);
			$ssh->connection->setKeepAlive(30);
			$received_key = $ssh->connection->getServerPublicHostKey();
		} catch(SSHException | ErrorException $e) {
			throw new SSHException("SSH connection failed", 0, $e);
		}
		if ($received_key === false) {
			$err = "Could not receive host key from target server";
			if ($ssh->jump_cmd_stderr !== null) {
				$stderr_summary = self::summarize_stderr(stream_get_contents($ssh->jump_cmd_stderr));
				if ($stderr_summary !== "") {
					$err = "The tunnel connection via jumphost(s) failed: {$stderr_summary}";
				}
			}
			throw new SSHException($err);
		} else if ($host_key === null || $host_key === "") {
			$host_key = $received_key;
		} else if ($host_key != $received_key) {
			throw new SSHException("SSH host key verification failed");
		}
		$key = PublicKeyLoader::load(file_get_contents("config/keys-sync"));
		if (!$ssh->connection->login($username, $key)) {
			throw new SSHException("SSH authentication failed");
		}
		return $ssh;
	}

	/**
	 * Build jumphost SSH command options from configuration.
	 *
	 * @param array $config SKA application config
	 * @return array string options:
	 *  - strict_host_key_checking: yes|no
	 *  - user_known_hosts_file: absolute path or /dev/null
	 */
	public static function build_jumphost_security_options(array $config): array {
		$strict_checking = isset($config['security']['jumphost_strict_host_key_checking'])
			&& (int)$config['security']['jumphost_strict_host_key_checking'] === 1;

		$known_hosts_file = '/dev/null';
		if($strict_checking) {
			$known_hosts_file = '/etc/ssh/ssh_known_hosts';
		}

		if(isset($config['security']['jumphost_known_hosts_file'])) {
			$configured_file = trim((string)$config['security']['jumphost_known_hosts_file']);
			if($configured_file !== '') {
				$known_hosts_file = $configured_file;
			}
		}

		return array(
			'strict_host_key_checking' => $strict_checking ? 'yes' : 'no',
			'user_known_hosts_file' => $known_hosts_file,
		);
	}

	/**
	 * @param array $config SKA application config
	 * @return array diagnostics for sync runtime reports
	 */
	public static function diagnostics(array $config): array {
		$options = self::build_jumphost_security_options($config);

		return array(
			'jumphost_strict_host_key_checking' => $options['strict_host_key_checking'],
			'jumphost_known_hosts_file' => $options['user_known_hosts_file'],
		);
	}

	/**
	 * Create an SFTP instance, connected to the target server, but do not authenticate.
	 *
	 * @param string $host Hostname of the target server
	 * @param int $port Port number of the target server
	 * @param array $jumphosts An array of jumphosts where each element contains "user", "host", "port".
	 * @param array $jumphost_security_options SSH command options for jumphost chain.
	 * @return SFTP The connected SFTP instance
	 */
	private static function build_connection(string $host, int $port, array $jumphosts, array $jumphost_security_options): SSH {
		if (empty($jumphosts)) {
			return new SSH(new SFTP($host, $port));
		}
		$strict_host_key_checking = isset($jumphost_security_options['strict_host_key_checking']) ? $jumphost_security_options['strict_host_key_checking'] : 'no';
		$user_known_hosts_file = isset($jumphost_security_options['user_known_hosts_file']) ? $jumphost_security_options['user_known_hosts_file'] : '/dev/null';
		$fix_options = " -o BatchMode=yes -o StrictHostKeyChecking=".escapeshellarg($strict_host_key_checking)." -o UserKnownHostsFile=".escapeshellarg($user_known_hosts_file)." -i config/keys-sync";
		$jumphosts[] = [
			"user" => "keys-sync",
			"host" => $host,
			"port" => $port,
		];

		for ($i = 0; $i < (count($jumphosts) - 1); $i++) {
			$target = escapeshellarg("{$jumphosts[$i+1]["host"]}:{$jumphosts[$i+1]["port"]}");
			$port = escapeshellarg($jumphosts[$i]["port"]);
			$host_desc = escapeshellarg("{$jumphosts[$i]["user"]}@{$jumphosts[$i]["host"]}");
			$proxy_command = "";
			if ($i > 0) {
				$proxy_command = " -o ProxyCommand=" . escapeshellarg($conn_cmd);
			}
			$conn_cmd = "ssh $fix_options$proxy_command -W $target -p $port $host_desc";
		}

		$sock_pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
		$child_stream = $sock_pair[0];
		$parent_stream = $sock_pair[1];
		$descriptorspec = array(
				0 => $child_stream,
				1 => $child_stream,
				2 => ["pipe", "w"],
		);
		$child = proc_open($conn_cmd, $descriptorspec, $pipes);
		fclose($child_stream);
		$ssh = new SSH(new SFTP($parent_stream));
		$ssh->jump_cmd_stderr = $pipes[2];
		$ssh->jump_cmd_child = $child;
		return $ssh;
	}

	/**
	 * Keep stderr reporting useful and bounded when tunnel setup fails.
	 *
	 * @param string $stderr
	 * @return string
	 */
	private static function summarize_stderr(string $stderr): string {
		$single_line = trim((string)preg_replace('/\s+/', ' ', $stderr));
		if($single_line === '') {
			return '';
		}
		if(strlen($single_line) > 200) {
			return substr($single_line, 0, 200).'...';
		}
		return $single_line;
	}

	/**
	 * Execute the given command and return its output
	 *
	 * @param string $command Shell command to execute
	 * @throws SSHException If starting the command fails
	 * @return string The output of the command as one string
	 */
	public function exec(string $command): string {
		try {
			return $this->connection->exec($command);
		} catch (ErrorException $e) {
			throw new SSHException("Failed to execute the command: $command", 0, $e);
		}
	}

	/**
	 * Load the given file from the ssh server
	 *
	 * @param string $filename Name of the file to load
	 * @throws SSHException If the file does not exist or is not accessible
	 * @return string The file content
	 */
	public function file_get_contents(string $filename): string {
		$result = $this->connection->get($filename);
		if ($result === false) {
			throw new SSHException("Could not read file $filename");
		}
		return $result;
	}

	/**
	 * Load the given file from the ssh server and split at linefeed characters.
	 * The linefeed characters themselves are not included in the returned strings.
	 * One linefeed at the end of file (which should be there, by convention) will
	 * not lead to an empty last element.
	 *
	 * @param string $filename The full path of the file on the target server
	 * @throws SSHException If the file does not exist or is not accessible
	 * @return array All the contained lines, as array of strings
	 */
	public function file_get_lines(string $filename): array {
		$content = $this->file_get_contents($filename);
		$lines = explode("\n", $content);
		if (end($lines) === "") {
			// remove last, empty line
			array_pop($lines);
		}
		reset($lines);
		return $lines;
	}

	/**
	 * Create or overwrite a file on the target server.
	 *
	 * @param string $filename The full file path on the target server
	 * @param string $content The content to store
	 * @throws SSHException If the operation fails
	 */
	public function file_put_contents(string $filename, string $content) {
		if ($this->connection->put($filename, $content) === false) {
			throw new SSHException("Could not write to file $filename");
		}
	}

	/**
	 * Delete the given file from the target server
	 *
	 * @param string $filename The full file path on the target server
	 * @throws SSHException If the delete operation fails
	 */
	public function unlink(string $filename) {
		if ($this->connection->delete($filename) === false) {
			throw new SSHException("Could not unlink file $filename");
		}
	}
}
