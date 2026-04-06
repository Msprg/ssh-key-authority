<?php

class LoginFlowService {
	private $auth_service;

	public function __construct($auth_service) {
		$this->auth_service = $auth_service;
	}

	public function handle_request($request_method, $post_data) {
		$this->ensure_state();
		$error_message = '';
		$success_message = '';

		if($request_method === 'POST') {
			$session_csrf_token = $_SESSION['csrf_token'] ?? null;
			$submitted_csrf_token = $post_data['csrf_token'] ?? null;
			if(
				!is_string($session_csrf_token) ||
				!is_string($submitted_csrf_token) ||
				!hash_equals($session_csrf_token, $submitted_csrf_token)
			) {
				$error_message = 'Invalid request token. Please refresh the page and try again.';
			} else {
				$username = isset($post_data['username']) && is_string($post_data['username']) ? trim($post_data['username']) : '';
				$password = isset($post_data['password']) && is_string($post_data['password']) ? $post_data['password'] : '';

				if($username === '' || $password === '') {
					$error_message = 'Please enter both username and password.';
				} elseif(!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
					$error_message = 'Invalid username format. Username can only contain letters, numbers, dots, hyphens, and underscores.';
				} else {
					$current_time = time();
					$user_attempts = $_SESSION['login_attempts'][$username] ?? null;
					if(is_array($user_attempts) && isset($user_attempts['time']) && ($current_time - $user_attempts['time']) >= 900) {
						$_SESSION['login_attempts'][$username] = array('count' => 0, 'time' => $current_time);
						$user_attempts = $_SESSION['login_attempts'][$username];
					}

					if($this->is_rate_limited($user_attempts, $current_time)) {
						$remaining_time = 900 - ($current_time - $user_attempts['time']);
						$error_message = 'Too many login attempts. Please try again in '.ceil($remaining_time / 60).' minutes.';
						$this->audit_log('rate_limited', $username, 'Rate limit exceeded');
					} else {
						try {
							$user = $this->auth_service->authenticate($username, $password);
							if($user) {
								unset($_SESSION['login_attempts'][$username]);
								$this->audit_log('success', $username, 'Authentication successful');
								$redirect_url = $this->sanitize_redirect_path($_SESSION['redirect_after_login'] ?? '/');
								unset($_SESSION['redirect_after_login']);
								redirect($redirect_url);
							} else {
								$this->record_failed_attempt($username, $current_time);
								$this->audit_log('failure', $username, 'Invalid credentials');
								$error_message = 'Invalid username or password.';
							}
						} catch(Throwable $e) {
							$this->audit_log('failure', $username, 'Authentication error: '.$e->getMessage());
							$error_message = 'Authentication error. Please try again.';
						}
					}
				}
				$_SESSION['csrf_token'] = $this->generate_csrf_token();
			}
		}

		return array(
			'error_message' => $error_message,
			'success_message' => $success_message,
			'csrf_token' => $_SESSION['csrf_token'],
		);
	}

	private function ensure_state() {
		if(!isset($_SESSION['csrf_token'])) {
			$_SESSION['csrf_token'] = $this->generate_csrf_token();
		}
		if(!isset($_SESSION['login_attempts'])) {
			$_SESSION['login_attempts'] = array();
		}
	}

	private function generate_csrf_token() {
		return bin2hex(random_bytes(32));
	}

	private function is_rate_limited($user_attempts, $current_time) {
		if(!is_array($user_attempts)) {
			return false;
		}
		if(!isset($user_attempts['count']) || !isset($user_attempts['time'])) {
			return false;
		}
		return $user_attempts['count'] >= 5 && ($current_time - $user_attempts['time']) < 900;
	}

	private function record_failed_attempt($username, $current_time) {
		if(!isset($_SESSION['login_attempts'][$username])) {
			$_SESSION['login_attempts'][$username] = array('count' => 0, 'time' => $current_time);
		}
		$_SESSION['login_attempts'][$username]['count']++;
		$_SESSION['login_attempts'][$username]['time'] = $current_time;
	}

	private function sanitize_redirect_path($candidate) {
		if(!is_string($candidate) || $candidate === '') {
			return '/';
		}
		$parts = parse_url($candidate);
		if($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
			return '/';
		}
		$path = $parts['path'] ?? '/';
		if($path === '' || substr($path, 0, 1) !== '/' || substr($path, 0, 2) === '//') {
			return '/';
		}
		$query = isset($parts['query']) ? '?'.$parts['query'] : '';
		$fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';
		return $path.$query.$fragment;
	}

	private function audit_log($event_type, $username, $details) {
		$timestamp = date('Y-m-d H:i:s');
		$safe_username = preg_replace('/[^a-zA-Z0-9._-]/', '', $username);
		$details = $this->sanitize_log_details($details);
		$log_message = sprintf(
			'[LoginFlowService::handle_request] %s - Event: %s, Username: %s, Details: %s',
			$timestamp,
			$event_type,
			$safe_username,
			$details
		);
		error_log($log_message);
	}

	private function sanitize_log_details($details) {
		$details = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$details);
		$details = trim((string)preg_replace('/\s+/u', ' ', $details));
		$details = str_replace('%', '%%', $details);
		if(strlen($details) > 500) {
			$details = substr($details, 0, 500).'...';
		}
		return $details;
	}
}
