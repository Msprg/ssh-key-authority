<?php

class RequestExceptionHandler {
	private $active_user;
	private $config;

	public function __construct(&$active_user, &$config) {
		$this->active_user = &$active_user;
		$this->config = &$config;
	}

	public function handle($e) {
		$error_number = time();
		error_log("$error_number: ".str_replace("\n", "\n$error_number: ", $e));
		while(ob_get_length()) {
			ob_end_clean();
		}
		$active_user = $this->active_user;
		$config = $this->config;
		require('views/error500.php');
		die;
	}
}
