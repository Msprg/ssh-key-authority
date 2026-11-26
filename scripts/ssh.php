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
		?string &$host_key
	): SSH {
		try {
			$ssh = self::build_connection($host, $port, $jumphosts);
			$ssh->connection->setKeepAlive(30);
			$received_key = $ssh->connection->getServerPublicHostKey();
		} catch(SSHException | ErrorException $e) {
			throw new SSHException("SSH connection failed");
		}
		if ($received_key === false) {
			$err = "Could not receive host key from target server";
			if ($ssh->jump_cmd_stderr !== null) {
				$stderr = stream_get_contents($ssh->jump_cmd_stderr);
				if ($stderr != "") {
					$err = "The tunnel connection via jumphost(s) failed";
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
	 * Create an SFTP instance, connected to the target server, but do not authenticate.
	 *
	 * @param string $host Hostname of the target server
	 * @param int $port Port number of the target server
	 * @param array $jumphosts An array of jumphosts where each element contains "user", "host", "port".
	 * @return SFTP The connected SFTP instance
	 */
	private static function build_connection(string $host, int $port, array $jumphosts): SSH {
		if (empty($jumphosts)) {
			return new SSH(new SFTP($host, $port));
		}
		$fix_options = "-o StrictHostKeyChecking=off -o UserKnownHostsFile=/dev/null -i config/keys-sync";
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
	 * Execute the given command and return its output
	 *
	 * @param string $command Shell command to execute
	 * @throws SSHException If starting the command fails
	 * @return string The output of the command as one string
	 */
	public function exec(string $command): string {
		$result = $this->guard("Failed to execute the command: $command", function() use ($command) {
			return $this->connection->exec($command);
		});
		if (!is_string($result)) {
			// The library reports some failures (e.g. a closed channel) by returning false
			throw new SSHException("Failed to execute the command: $command");
		}
		return $result;
	}

	/**
	 * Like exec(), but fail if the command does not finish within the timeout of the library, instead of
	 * returning the output received so far as if it were complete. The channel of the unfinished command is
	 * closed, so that the connection stays usable for further commands.
	 *
	 * @param string $command Shell command to execute
	 * @throws SSHException If starting the command fails or it does not finish in time
	 * @return string The complete output of the command as one string
	 */
	public function exec_complete(string $command): string {
		// A command that timed out earlier on this connection has left its channel open
		$this->close_timed_out_command();
		try {
			$result = $this->exec($command);
		} catch (SSHException $e) {
			// The message of exec() quotes the whole command, which is of no use to whoever reads this one
			$cause = $e->getPrevious();
			throw new SSHException("The command could not be run".($cause === null ? "" : ": ".$cause->getMessage()));
		}
		if ($this->connection->isTimeout()) {
			$this->close_timed_out_command();
			throw new SSHException("The command did not finish in time");
		}
		return $result;
	}

	/**
	 * If the last command ran into the timeout of the library, close its channel so that the connection can
	 * run further commands.
	 */
	private function close_timed_out_command() {
		try {
			if ($this->connection->isTimeout()) {
				$this->connection->reset(\phpseclib3\Net\SSH2::CHANNEL_EXEC);
			}
		} catch (Throwable $e) {
			// The next command will report that the connection is unusable
		}
	}

	/**
	 * Get the identification string that the ssh server sent when connecting.
	 * It usually names the server software and its version, like "SSH-2.0-OpenSSH_9.2p1 Debian-2".
	 *
	 * @return string|null The identification string, null if it is not available
	 */
	public function get_server_identification(): ?string {
		try {
			$identification = $this->connection->getServerIdentification();
		} catch (Exception $e) {
			return null;
		}
		return is_string($identification) ? $identification : null;
	}

	/**
	 * Run an operation of the underlying ssh library and turn everything it throws into an SSHException.
	 *
	 * phpseclib signals failures with RuntimeExceptions, and its own notices and warnings arrive as
	 * ErrorExceptions through the error handler in core.php. Callers only handle SSHException, so anything
	 * else would abort the whole sync process without reporting a sync status.
	 *
	 * @param string $failure Message for the SSHException if the operation throws
	 * @param callable $operation The library call to run
	 * @throws SSHException If the operation throws
	 * @return mixed The return value of the operation
	 */
	private function guard(string $failure, callable $operation) {
		try {
			return $operation();
		} catch (SSHException $e) {
			throw $e;
		} catch (Exception $e) {
			throw new SSHException($failure, 0, $e);
		}
	}

	/**
	 * Like guard(), for operations that go through the SFTP channel. If the SSH connection itself is still
	 * alive, the failure message points at the SFTP subsystem of the target server, because the library
	 * error for a rejected or immediately closed SFTP channel does not say so ("Undefined array key 256").
	 *
	 * @param string $failure Message for the SSHException if the operation throws
	 * @param callable $operation The library call to run
	 * @throws SSHException If the operation throws
	 * @return mixed The return value of the operation
	 */
	private function guard_sftp(string $failure, callable $operation) {
		try {
			return $this->guard($failure, $operation);
		} catch (SSHException $e) {
			if ($e->getPrevious() !== null && $this->connection->isConnected()) {
				throw new SSHException(
					"$failure (SFTP session failed, check that the sftp subsystem works for the sync user on this server)",
					0,
					$e->getPrevious()
				);
			}
			throw $e;
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
		$result = $this->guard_sftp("Could not read file $filename", function() use ($filename) {
			return $this->connection->get($filename);
		});
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
		$result = $this->guard_sftp("Could not write to file $filename", function() use ($filename, $content) {
			return $this->connection->put($filename, $content);
		});
		if ($result === false) {
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
		$result = $this->guard_sftp("Could not unlink file $filename", function() use ($filename) {
			return $this->connection->delete($filename);
		});
		if ($result === false) {
			throw new SSHException("Could not unlink file $filename");
		}
	}
}
