<?php

class RequestPolicyGuard {
	public function enforce_web_enabled($config) {
		if(empty($config['web']['enabled'])) {
			require('views/error503.php');
			die;
		}
	}

	public function enforce_active_user_status($active_user, $relative_request_url, $absolute_request_url) {
		if($active_user && (!$active_user->active || $active_user->force_disable)) {
			require('views/error403.php');
		}
	}

	public function can_render_view($is_public, $active_user) {
		return $is_public || ($active_user && $active_user->auth_realm == 'LDAP');
	}
}
