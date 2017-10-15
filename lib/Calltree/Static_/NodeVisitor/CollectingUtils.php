<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 07.10.2017
 * Time: 21:01
 */

namespace Calltree\Static_\NodeVisitor;

use Calltree\Static_\toStr;


trait CollectingUtils {
	protected function addToArrayMap( &$map, $key, $el ) {
		if ( ! is_string( $key )  ) {
			throw new \LogicException( "key must be string!" );
		}

		if ( ! is_string( $el )  ) {
			throw new \LogicException( "el must be string, is:" .toStr::expr($el) );
		}

		if ( ! isset( $map[ $key ] ) ) {
			$map[ $key ] = [ $el => $el ];
		} else {
			$map[ $key ][ $el ] = $el;
		}
	}


}