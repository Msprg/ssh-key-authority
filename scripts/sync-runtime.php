<?php
##
## Copyright 2026
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

class SyncRuntime {
	/**
	 * Build the shell command for running sync subprocesses with timeout handling.
	 *
	 * @param string $command absolute command path
	 * @param array $args command arguments
	 * @param array $config loaded application config
	 * @param int $timeout_seconds timeout in seconds
	 * @return string shell command line
	 */
	public static function build_timeout_wrapped_command($command, array $args, array $config, $timeout_seconds = 60) {
		$timeout_util = self::resolve_timeout_util($config);
		$timeout_binary = self::resolve_timeout_binary($config);
		$escaped_args = implode(' ', array_map('escapeshellarg', $args));
		$escaped_command = escapeshellarg($command);

		if($timeout_util === 'BusyBox') {
			return escapeshellarg($timeout_binary).' -t '.(int)$timeout_seconds.' '.$escaped_command.' '.$escaped_args;
		}

		return escapeshellarg($timeout_binary).' '.(int)$timeout_seconds.'s '.$escaped_command.' '.$escaped_args;
	}

	/**
	 * @param array $config loaded application config
	 * @return array runtime diagnostics for sync process execution
	 */
	public static function diagnostics(array $config) {
		return array(
			'timeout_util' => self::resolve_timeout_util($config),
			'timeout_binary' => self::resolve_timeout_binary($config),
			'timeout_seconds' => 60,
		);
	}

	/**
	 * @param array $config
	 * @return string GNU or BusyBox
	 */
	private static function resolve_timeout_util(array $config) {
		$configured = isset($config['general']['timeout_util']) ? trim((string)$config['general']['timeout_util']) : '';
		if($configured === 'BusyBox') {
			return 'BusyBox';
		}
		return 'GNU';
	}

	/**
	 * @param array $config
	 * @return string
	 */
	private static function resolve_timeout_binary(array $config) {
		if(isset($config['general']['timeout_binary'])) {
			$configured = trim((string)$config['general']['timeout_binary']);
			if($configured !== '') {
				$validated = self::validate_timeout_binary($configured);
				if($validated !== null) {
					return $validated;
				}
			}
		}

		$validated = self::validate_timeout_binary('/usr/bin/timeout');
		if($validated !== null) {
			return $validated;
		}

		$validated = self::validate_timeout_binary('/bin/timeout');
		if($validated !== null) {
			return $validated;
		}

		$validated = self::validate_timeout_binary('timeout');
		if($validated !== null) {
			return $validated;
		}

		error_log('SyncRuntime could not resolve a validated timeout binary.');
		throw new RuntimeException('Unable to resolve a validated timeout binary for sync execution.');
	}

	/**
	 * @param string $candidate
	 * @return string|null
	 */
	private static function validate_timeout_binary($candidate) {
		if($candidate === '') {
			return null;
		}
		if(strpos($candidate, '/') !== false) {
			return (is_file($candidate) && is_executable($candidate)) ? $candidate : null;
		}
		$resolved = self::resolve_binary_from_path($candidate);
		return $resolved !== '' ? $resolved : null;
	}

	/**
	 * @param string $binary_name
	 * @return string
	 */
	private static function resolve_binary_from_path($binary_name) {
		if(!preg_match('/^[A-Za-z0-9._-]+$/', $binary_name)) {
			return '';
		}
		$output = array();
		$return_code = 1;
		exec('command -v '.escapeshellarg($binary_name).' 2>/dev/null', $output, $return_code);
		if($return_code !== 0 || empty($output[0])) {
			return '';
		}
		$resolved = trim((string)$output[0]);
		if($resolved === '' || !is_executable($resolved)) {
			return '';
		}
		return $resolved;
	}
}
