<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 08.10.2017
 * Time: 12:19
 */

namespace Calltree\Static_\NodeVisitor;


trait SerializationUtils {
	protected function serializeProperties($names) {
		$ser = [];
		foreach($names as $name) {
			$ser[$name] = self::ser($this->$name);
		}
		return $ser;
	}

	private static function ser($data) {
		if(!is_array($data))
			return $data;

		$firstVal = reset($data);
		$keysEqVal = 0;
		foreach($data as $key => $val) {
			if($key === $val || $firstVal === $val) {
				++$keysEqVal;
				if($keysEqVal > 20 )
					break;
			} else {
				$keysEqVal = false;
				break;
			}
		}

		if($keysEqVal) {
			return array_keys($data);
		}

		return $data;
	}
}