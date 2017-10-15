<?php

namespace Calltree;


class WallClock {
	private static $timeStampFrozen = 0.0;

	private static $offset = 0.0;

	public static function now() {
		return microtime( true ) + self::$offset;
	}

	public static function freeze() {
		if ( self::$timeStampFrozen > 1 ) {
			throw new \LogicException( "cannot freeze frozen Clock!" );
		}
		self::$timeStampFrozen = microtime( true );

		return self::$timeStampFrozen + self::$offset;
	}

	public static function unfreeze() {
		if ( self::$timeStampFrozen < 1 ) {
			throw new \LogicException( "cannot unfreeze non-frozen Clock!" );
		}
		$t                     = microtime( true );
		$timeSpanFrozen        = ( $t - self::$timeStampFrozen );
		self::$offset          -= $timeSpanFrozen;
		self::$timeStampFrozen = 0.0;

		// try to remove self overhead
		$t2                     = microtime( true );
		self::$offset -= ($t2 - $t)*3;

		return $t + self::$offset;
	}

	public static function getOffset() {
		return self::$offset;
	}
}