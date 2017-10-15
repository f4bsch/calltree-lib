<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 08.10.2017
 * Time: 10:06
 */

namespace Calltree\Static_;


use Calltree\Static_\NodeVisitor\ThrowableVisitor;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Throwable;


class Traversing {

	public static function withCallback( $stmts ) {

	}

	public static function find( $nodes, $callback ) {
		$traverser = new NodeTraverser();
		$visitor   = new FindVisitor( $callback );
		$traverser->addVisitor( $visitor );

		try {
			$traverser->traverse( $nodes );
		} catch ( FoundException $e ) {
			return $e->node;
		}

		return null;
	}


	public static function filter( $nodes, $callback ) {
		$traverser = new NodeTraverser();
		$visitor   = new FilterVisitor( $callback );
		$traverser->addVisitor( $visitor );
		$traverser->traverse( $nodes );
		return $visitor->foundNodes;
	}
}


class FilterVisitor extends NodeVisitorAbstract {
	private $callback = null;
	public $foundNodes = [];

	public function __construct( $callback ) {
		$this->callback = $callback;
	}

	public function enterNode( Node $node ) {
		$res = call_user_func( $this->callback, $node );
		if($res === NodeTraverser::DONT_TRAVERSE_CHILDREN)
			return $res;
		if ( $res === true) {
			$this->foundNodes[] = $node;
			return NodeTraverser::DONT_TRAVERSE_CHILDREN;
		} elseif($res !== false) {
			throw new \LogicException("invalid return type of filter function");
		}
	}
}


class FindVisitor extends NodeVisitorAbstract {
	private $callback = null;
	public $foundNode = null;

	public function __construct( $callback ) {
		$this->callback = $callback;
	}

	public function enterNode( Node $node ) {
		parent::enterNode( $node );
		if ( call_user_func( $this->callback, $node ) !== false ) {
			$this->foundNode = $node;
			throw new FoundException($node);
		}
	}
}

class FoundException extends \Exception {
	/**
	 * @var Node
	 */
	public $node;

	/**
	 * FoundException constructor.
	 *
	 * @param \PhpParser\Node $node
	 */
	public function __construct( $node ) {
		parent::__construct( "", 0, null );
		$this->node = $node;
	}
}

