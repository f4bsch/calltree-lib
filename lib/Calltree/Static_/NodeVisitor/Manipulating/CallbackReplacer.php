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

class CallbackReplacer extends BaseResolver {

	/**
	 * @var string[]
	 */
	private $map;

	public function __construct( $map, FunctionCollector $functionCollector ) {
		parent::__construct( $functionCollector );
		$this->map = $map;
	}

	public function leaveNode( Node $node ) {

		// TODO use the resolver

		// replace callbacks in function call arguments, such as add_action('tag', 'callback');
		if ( $node instanceof Node\Expr\FuncCall
		     && $node->name instanceof Node\Name
		     && count( $node->args ) > 0 ) {

			$name = trim( $node->name->toString(), '/' );

			if ( ! $this->functionCollector->isAddedOrInternalFunction( $name ) ) {
				echo "warn: unknown function $name\n";

				return null;
			}

			$callbackParam = $this->functionCollector->hasCallableParam( $name );
			if ( $callbackParam !== false && count( $node->args ) > $callbackParam ) {
				if ( $node->args[ $callbackParam ]->value instanceof Node\Scalar\String_
				     && isset( $this->map[ (string) $node->args[ $callbackParam ]->value->value ] )
				) {

					//print_r( $node->args[$callbackParam]->value );
					//die( "$name has callback param $callbackParam" );
					$node->args[ $callbackParam ]->value = new Node\Scalar\String_( $this->map[ (string) $node->args[ $callbackParam ]->value->value ] );
				}
			}
		}
	}
}