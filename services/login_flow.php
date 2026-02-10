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
			if(!isset($post_data['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $post_data['csrf_token'])) {
				$error_message = 'Invalid request token. Please refresh the page and try again.';
			} else {
				$username = trim($post_data['username'] ?? '');
				$password = $post_data['password'] ?? '';

				if(!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
					$error_message = 'Invalid username format. Username can only contain letters, numbers, dots, hyphens, and underscores.';
				} elseif($username === '' || $password === '') {
					$error_message = 'Please enter both username and password.';
				} else {
					$current_time = time();
					$user_attempts = $_SESSION['login_attempts'][$username] ?? null;

					if($this->is_rate_limited($user_attempts, $current_time)) {
						$remaining_time = 900 - ($current_time - $user_attempts['time']);
						$error_message = 'Too many login attempts. Please try again in '.ceil($remaining_time / 60).' minutes.';
					} else {
						try {
							$user = $this->auth_service->authenticate($username, $password);
							if($user) {
								unset($_SESSION['login_attempts'][$username]);
								$redirect_url = $_SESSION['redirect_after_login'] ?? '/';
								unset($_SESSION['redirect_after_login']);
								redirect($redirect_url);
							}
							$this->record_failed_attempt($username, $current_time);
							$error_message = 'Invalid username or password.';
						} catch(Exception $e) {
							$error_message = 'Authentication error. Please try again.';
						}
					}
				}
			}
			$_SESSION['csrf_token'] = $this->generate_csrf_token();
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
}
