<?php

namespace Calltree;


use WPHookProfiler\HookProfiler;


class ScopedCapturer {
	/**
	 * @var HookProfiler
	 */
	private $profiler;

	public function __construct( HookProfiler $profiler ) {
		$this->profiler = $profiler;
		$GLOBALS['$p']  = $this;

		// TODO php bug?
		require_once dirname( __FILE__ ) . "/ScopedCapture.php";
	}

	public function f( $function ) {
		return new ScopedCapture( $function, $this->profiler );
	}

	public function m( $class, $method ) {
		return new ScopedCapture( "$class::$method", $this->profiler );
	}
}