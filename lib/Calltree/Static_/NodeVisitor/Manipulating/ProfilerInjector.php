<?php

namespace Calltree\Static_\NodeVisitor\Manipulating;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

class ProfilerInjector extends NodeVisitorAbstract {
	static $parser = null;

	private $injections = [];

	public function __construct() {
		if ( ! self::$parser ) {
			self::$parser = ( new ParserFactory )->create( ParserFactory::PREFER_PHP7 );
		}

		$this->injections = [
			'function' => self::$parser->parse( "<?php ! isset( \$GLOBALS['\$p'] ) ?: \$__p = \$GLOBALS['\$p']->f( __FUNCTION__ );" ),
			'method'   => self::$parser->parse( "<?php ! isset( \$GLOBALS['\$p'] ) ?: \$__p = \$GLOBALS['\$p']->m( __CLASS__, __METHOD__ );" ),
		];
	}

	public function enterNode( Node $node ) {
		if ( $node instanceof Node\FunctionLike && count( $node->getStmts() ) > 0 ) {
			$tag    = ( $node instanceof Node\Stmt\ClassMethod ) ? 'method' : 'function';
			$inject = $this->injections[ $tag ][0];
			array_unshift( $node->stmts, $inject );
		}
	}
}