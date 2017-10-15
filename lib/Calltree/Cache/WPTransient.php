<?php

namespace Calltree\Cache;


class WPTransient {
	const PREFIX = __CLASS__;
	const FALSE = '__FALSE__'; // map false to '__FALSE__'

	public function put( $key, $value ) {
		if($value === false) $value = self::FALSE;
		return set_transient(self::PREFIX.$key, $value );
	}

	public function has($key) {
		return get_transient(self::PREFIX.$key) !== false;
	}

	public function get( $key, &$found = null ) {
		$val = get_transient(self::PREFIX.$key);
		$found = ($val !== false);
		return ($val === self::FALSE) ? false : $val;
	}

	public function del( $key ) {
		return delete_transient(self::PREFIX.$key);
	}
}