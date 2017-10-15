<?php

namespace Calltree\Cache;

class Disk {

	private static function hash( $str ) {
		return hash( 'md4', $str );
	}

	private static function getFile( $key, &$dir = null ) {
		$hash = self::hash( $key );
		$dir  = '.cache/'
		        . substr( $hash, 0, 2 ) . '/'
		        . substr( $hash, 2, 2 );

		return $dir . '/' . substr( $hash, 4 );
	}

	public function put( $key, $value ) {
		$file = self::getFile( $key, $dir );
		is_dir( $dir ) || mkdir( $dir, 0777, true );

		return file_put_contents( $file, serialize( $value ) );
	}

	public function has( $key ) {
		$file = self::getFile( $key );

		return is_file( $file );
	}

	public function get( $key, &$found = null ) {
		$file  = self::getFile( $key );
		$found = is_file( $file );
		if ( ! $found ) {
			return false;
		}

		return unserialize( file_get_contents( $file ) );
	}

	public function auto( $key, $generateFunction ) {
		if ( $key ) {
			$data = $this->get( $key, $found );
			if ( $found ) {
				return $data;
			}
		}

		$data = call_user_func( $generateFunction );
		if ( $key ) {
			$this->put( $key, $data );
		}

		return $data;
	}

	public function del( $key ) {
		return @unlink( self::getFile( $key, $dir ) );
	}
}