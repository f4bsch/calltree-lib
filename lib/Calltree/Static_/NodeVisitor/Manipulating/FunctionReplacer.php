<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 10.10.2017
 * Time: 11:17
 */

namespace Calltree\Static_\NodeVisitor;


use Calltree\Static_\FunctionCollector;
use PhpParser\ErrorHandler;
use PhpParser\Node;

class FunctionReplacer extends BaseResolver {

	/**
	 * @var string[]
	 */
	private $map;

	public function __construct( $map, FunctionCollector $functionCollector ) {
		parent::__construct( $functionCollector );
		$this->map = $map;
	}

	public function leaveNode( Node $node ) {
		if ( $node instanceof Node\Expr\FuncCall ) {
			if ( $node->name instanceof Node\Name ) {
				$name = trim( $node->name->toString(), '/' );
				if ( isset( $this->map[ $name ] ) ) {
					$node->name = new Node\Name( $this->map[ $name ] );
				}
			}
		}
	}
}