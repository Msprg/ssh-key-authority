<?php

class RequestExceptionHandler {
	private $active_user;
	private $config;

	public function __construct($active_user, &$config) {
		$this->active_user = $active_user;
		$this->config = &$config;
	}

	public function set_active_user(User $active_user): void {
		$this->active_user = $active_user;
	}

	public function handle(\Throwable $e) {
		try {
			$error_number = bin2hex(random_bytes(8));
		} catch(Exception $random_error) {
			$error_number = uniqid('', true);
		}
		error_log("$error_number: ".$e->getMessage()."\n$error_number: ".$e->getTraceAsString());
		while(ob_get_length()) {
			ob_end_clean();
		}
		http_response_code(500);
		$active_user = $this->active_user;
		$config = $this->config;
		require __DIR__.'/../views/error500.php';
		die;
	}
}
