<?php

function history_username_env_format_is_valid($format) {
	if($format === '') {
		return false;
	}
	if(preg_match('/[\r\n,\'"\\\\]/', $format)) {
		return false;
	}
	if(!preg_match('/^[A-Za-z0-9 ._@:+={}-]+$/', $format)) {
		return false;
	}
	if(strpos($format, '{uid}') === false) {
		return false;
	}
	$without_uid = str_replace('{uid}', '', $format);
	return strpos($without_uid, '{') === false && strpos($without_uid, '}') === false;
}
