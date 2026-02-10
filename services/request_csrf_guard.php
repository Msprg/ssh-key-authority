<?php

class RequestCsrfGuard {
	public function validate($active_user, $request_context, $post_data) {
		if(!empty($post_data) && $active_user) {
			if($request_context->bypass_csrf_protection) {
				return;
			}
			if(!$active_user->check_csrf_token($post_data['csrf_token'])) {
				require('views/csrf.php');
				die;
			}
		}
	}
}
