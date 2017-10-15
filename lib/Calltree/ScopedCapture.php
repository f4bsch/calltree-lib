<?php
namespace Calltree;

use WPHookProfiler\HookProfiler;

/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 12.10.2017
 * Time: 10:51
 */
class ScopedCapture {

	/** @var  \WPHookProfiler\HookProfiler */
	private $hookProf;
	private $profile;

	//private $capturer;

	/**
	 * ScopedCapture constructor.
	 *
	 * @param string $funcName
	 * @param $profiler
	 */
	function __construct($funcName, HookProfiler $profiler) {
		$this->hookProf = $profiler;
		$this->profile = $this->hookProf->profilePreCall( $funcName, "scoped_capture" );
	}

	public function __destruct() {
		$this->hookProf->profilePostCall( $this->profile );
		//$this->capturer->add($this);
	}
}