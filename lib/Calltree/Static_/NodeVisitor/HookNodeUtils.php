<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 07.10.2017
 * Time: 20:30
 */

namespace Calltree\Static_\NodeVisitor;

use Calltree\Static_\FunctionCollector;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\NodeVisitorAbstract;

trait HookNodeUtils {
	protected function isAddHookCall( Node $node, &$func = null ) {
		try {
			if ( $node instanceof Node\Expr\FuncCall
			     && $node->name instanceof Name
			     &&
			     ( $node->name->parts[0] === 'add_action' || $node->name->parts[0] === 'add_filter'
			     )
			) {
				$tagExpr = $node->args[0]->value;

				// expect add_action(_string_ $tag, ...)
				if ( ! ( $tagExpr instanceof Node\Scalar\String_ ) ) {
					throw new Error( "invalid tag expression in {$node->name->parts[0]}", $node );
				}

				$tag = $tagExpr->value;

				if ( func_num_args() === 1 ) {
					return $tag;
				}

				// ... and the 2nd argument: function
				$funcExpr = $node->args[1]->value;
				$func     = $this->exprToFunctionName( $funcExpr );

				return $tagExpr->value;
			}
		} catch ( Error $e ) {
			$e->replaceNode( $node );
			$this->addWarning( $e );

			return false;
		}

		return false;
	}

	protected function isDoHookCall( Node $node ) {
		if ( $node instanceof Node\Expr\FuncCall && $node->name instanceof Name ) {
			$name = $node->name->parts[0];
			if ( $this->isDoHookName( $name ) ) {
				$tagExpr = $node->args[0]->value;
				// expect do_action(_string_ $tag, ...)
				if($tagExpr instanceof  Expr\BinaryOp\Concat || $tagExpr instanceof  Node\Scalar\Encapsed) {
					return self::concatToWhildcard($tagExpr);
				} elseif ($tagExpr instanceof Expr\Variable && is_string($tagExpr->name) ) {
					if(isset($this->variables[$tagExpr->name]) && $this->variables[$tagExpr->name]->value) {
						return $this->variables[$tagExpr->name]->value;
					}

					if($this->functionLike)
						throw $this->error( "invalid tag variable", $node );
					else
						$this->addWarning($this->error( "invalid tag variable in file context", $node ));
				}
				elseif( ! ( $tagExpr instanceof Node\Scalar\String_ ) ) {
					throw $this->error( "invalid tag expression", $node );
				}

				if(empty($tagExpr->value)) {
					$this->addWarning( $this->error("tag expr has not value", $tagExpr) );
					return false;
				}

				return $tagExpr->value;
			}
		}

		return false;
	}

	/**
	 * @param Expr\BinaryOp\Concat $concat
	 */
	static function concatToWhildcard( Expr $concat ) {
		if ( $concat instanceof Node\Scalar\String_ ) {
			return (string) $concat->value;
		} elseif ( $concat instanceof Expr\BinaryOp\Concat ) {
			return self::concatToWhildcard( $concat->left ) . self::concatToWhildcard( $concat->right );
		} elseif ( $concat instanceof Expr\ArrayDimFetch ) {
			return '*';
		} elseif($concat instanceof  Node\Scalar\Encapsed) {
			$str = '';
			foreach($concat->parts as $part) {
				if($part instanceof  Node\Scalar\EncapsedStringPart) {
					$str .= $part->value;
				} else {
					$str .= '*';
				}
			}
			return $str;
		}else {
			return '*';
			//throw new Error( "invalid concat expression", $concat );
		}
	}

	protected function isDoHookName( $name ) {
		if ( $name{0} === '\\' ) {
			$name = \substr( $name, 1 );
		}

		return ( $name === 'do_action' ||
		         $name === 'do_action_ref_array' ||
		         $name === 'apply_filters' ||
		         $name === 'apply_filters_ref_array' );
	}

	protected function compareFuncName( $name, $name2 ) {
		if ( $name instanceof Node\Name ) {
			$name = $name->toString();
		}

		if ( $name2 instanceof Node\Name ) {
			$name2 = $name2->toString();
		}

		if ( $name{0} === '\\' ) {
			$name = \substr( $name, 1 );
		}
		if ( $name2{0} === '\\' ) {
			$name2 = \substr( $name2, 1 );
		}

		return strtolower( $name ) === strtolower( $name2 );
	}
}