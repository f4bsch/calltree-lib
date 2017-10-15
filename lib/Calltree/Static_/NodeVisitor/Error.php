<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 07.10.2017
 * Time: 20:40
 */

namespace Calltree\Static_\NodeVisitor;


use Calltree\Static_\toStr;
use PhpParser\Node;
use PhpParser\PrettyPrinter;

class Error extends \RuntimeException {

	protected $message;
	private $node;
	private $context;

	/**
	 * Error constructor.
	 *
	 * @param string $message
	 * @param Node $node
	 * @param Node $context
	 * @param string $fileName
	 */
	public function __construct( $message = "", Node $node = null, Node $context = null, $fileName='' ) {
		parent::__construct( $message, 0, null );

		$this->node = $node;
		$this->context = $context;

		if($fileName) {
			$message .= " in file '$fileName'";
			if($node)
				$message .= ":".$node->getLine();
		}
		$this->message = $message;
	}

	public function replaceNode($node) {
		$this->node = $node;
	}

	public function setContext($node) {
		$this->context = $node;
	}
	/**
	 * @return mixed
	 */
	public function getErrorMessage() {
		$message = $this->message;

		$code = toStr::expr($this->node);
		$codeContext = toStr::expr($this->context);

		if(strlen($codeContext) > 60)
			$codeContext = substr($codeContext, 0, 40).' ...';

		if(empty($code)) {
			$code = $codeContext;
			$codeContext = '';
		}



		$message .= " `$code` in context `$codeContext`";// . print_r( $this->node, true );


		$this->message = $message;

		return $message;
	}
}