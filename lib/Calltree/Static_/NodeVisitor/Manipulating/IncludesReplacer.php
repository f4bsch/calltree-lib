<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 10.10.2017
 * Time: 15:15
 */

namespace Calltree\Static_\NodeVisitor;


use Calltree\Static_\toStr;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\NodeVisitorAbstract;

class IncludesReplacer extends NodeVisitorAbstract {

	private $funcName = '';
	private $ignoreExprs = [];

	function __construct( $funcName, $ignoreExprs ) {
		$this->funcName = $funcName;

		$this->ignoreExprs = array_flip( array_map( '\Calltree\Static_\toStr::expr', $ignoreExprs ) );
		//print_r( $ignoreExprs );
	}


	public function leaveNode( Node $node ) {
		parent::leaveNode( $node );

		if ( $node instanceof Node\Expr\Include_ ) {

			if ( isset( $this->ignoreExprs[ toStr::expr( $node->expr ) ] ) ) {
				echo "ignore include ". toStr::expr( $node->expr );
				return null;
			}

			if(strpos(toStr::expr( $node->expr ) ,'wp-load.php')) {
				echo "ignore include ". toStr::expr( $node->expr )."\n";
				return null;
			}

			$args = [ new Arg( $node->expr ), new Arg( new Node\Scalar\LNumber( $node->type ) ) ];

			return new FuncCall( new Node\Name( $this->funcName ), $args );
			/*$node->type
			 *     const TYPE_INCLUDE      = 1;
					const TYPE_INCLUDE_ONCE = 2;
					const TYPE_REQUIRE      = 3;
					const TYPE_REQUIRE_ONCE = 4;
			 */

		}

		return null;
	}
}