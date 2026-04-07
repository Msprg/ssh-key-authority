<?php

class RuntimeState {
	private static $state = array();

	public static function set($key, $value) {
		self::$state[$key] = $value;
	}

	public static function set_many(array $values) {
		foreach($values as $key => $value) {
			self::$state[$key] = $value;
		}
	}

	public static function has($key) {
		return array_key_exists($key, self::$state);
	}

	public static function get($key, $default = null) {
		if(self::has($key)) {
			return self::$state[$key];
		}
		return $default;
	}
}
