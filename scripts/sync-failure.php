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

class SyncFailureReporter {
	public const RESCHEDULE_DELAY_MINUTES = 30;

	/**
	 * Update sync status with a classified failure message and optionally reschedule.
	 *
	 * @param Server $server
	 * @param string $summary
	 * @param string|null $reason
	 * @param string|null $code
	 * @param bool $reschedule
	 */
	public static function report_server_failure(Server $server, $summary, $reason = null, $code = null, $reschedule = true) {
		$message = self::compose_message(
			$summary,
			$reason,
			$code ?? self::classify_reason($reason),
			$reschedule
		);

		$server->sync_report('sync failure', $message);
		if($reschedule) {
			$server->reschedule_sync_request();
		}
	}

	/**
	 * @param string|null $reason
	 * @return string
	 */
	public static function classify_reason($reason) {
		$normalized = strtolower(self::normalize_reason($reason));
		if($normalized === '') {
			return 'sync_failure';
		}
		if(strpos($normalized, 'timed out') !== false) {
			return 'ssh_timeout';
		}
		if(strpos($normalized, 'host key verification failed') !== false) {
			return 'host_key_verification_failed';
		}
		if(strpos($normalized, 'multiple hosts with same host key') !== false) {
			return 'host_key_collision';
		}
		if(strpos($normalized, 'multiple hosts with same ip address') !== false) {
			return 'ip_collision';
		}
		if(strpos($normalized, 'hostname check failed') !== false) {
			return 'hostname_verification_failed';
		}
		if(strpos($normalized, 'could not read /var/local/keys-sync/.hostnames') !== false) {
			return 'hostname_allowlist_unreadable';
		}
		if(strpos($normalized, 'ssh authentication failed') !== false) {
			return 'ssh_authentication_failed';
		}
		if(strpos($normalized, 'tunnel connection via jumphost') !== false) {
			return 'jumphost_tunnel_failed';
		}
		if(strpos($normalized, 'could not receive host key') !== false) {
			return 'host_key_unavailable';
		}
		if(strpos($normalized, 'cannot access key directory') !== false) {
			return 'key_directory_access_failed';
		}
		if(strpos($normalized, 'internal error during sync') !== false) {
			return 'worker_process_error';
		}

		return 'ssh_connection_failed';
	}

	/**
	 * @return array
	 */
	public static function diagnostics() {
		return array(
			'reschedule_delay_minutes' => self::RESCHEDULE_DELAY_MINUTES,
		);
	}

	/**
	 * Build a classified sync message.
	 *
	 * @param string $summary
	 * @param string|null $reason
	 * @param string $code
	 * @param bool $reschedule
	 * @return string
	 */
	public static function build_message($summary, $reason = null, $code = 'sync_info', $reschedule = false) {
		return self::compose_message($summary, $reason, $code, $reschedule);
	}

	/**
	 * Record a classified non-fatal sync issue in server log events.
	 *
	 * @param Server $server
	 * @param string $summary
	 * @param string|null $reason
	 * @param string $code
	 */
	public static function log_server_nonfatal_issue(Server $server, $summary, $reason = null, $code = 'sync_nonfatal_issue') {
		$message = self::compose_message($summary, $reason, $code, false);
		$server->log(
			array(
				'action' => 'Sync non-fatal issue',
				'value' => $message,
			),
			LOG_WARNING
		);
	}

	/**
	 * @param string $summary
	 * @param string|null $reason
	 * @param string $code
	 * @param bool $reschedule
	 * @return string
	 */
	private static function compose_message($summary, $reason, $code, $reschedule) {
		$parts = array();
		$parts[] = trim((string)$summary);
		if(self::normalize_reason($reason) !== '') {
			$parts[] = 'reason='.self::normalize_reason($reason);
		}
		$parts[] = 'code='.$code;
		if($reschedule) {
			$parts[] = 'retry='.self::RESCHEDULE_DELAY_MINUTES.'m';
		}
		return implode('; ', $parts);
	}

	/**
	 * @param string|null $reason
	 * @return string
	 */
	private static function normalize_reason($reason) {
		$text = trim((string)$reason);
		$text = trim((string)preg_replace('/\s+/', ' ', $text));
		if($text === '') {
			return '';
		}
		if(strlen($text) > 240) {
			return substr($text, 0, 240).'...';
		}
		return $text;
	}
}
