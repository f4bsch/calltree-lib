<?php


namespace Calltree\Static_\NodeVisitor;

use Calltree\Static_\toStr;
use Calltree\Static_\Traversing;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\PrettyPrinter;
use SebastianBergmann\CodeCoverage\Node\Builder;

class SeparateDeclarationsVisitor extends BaseResolver {
	/** @var  Stmt\Function_[] */
	public $functions = [];

	/** @var Stmt\ClassLike[] */
	public $classLikes = [];

	public $uses = [];

	/** @var Stmt\If_[] */
	private $ifStack = [];

	public function enterNode( Node $node ) {
		parent::enterNode( $node );

		if ( $node instanceof Stmt\Function_ || $node instanceof Stmt\ClassLike ) {
			$numNestedIfs = count( $this->ifStack );
			if ( $numNestedIfs > 3 ) { // TODO was 1
				throw $this->error( "class/function nested in more than one if block", $node );
			}

			if ( $numNestedIfs === 1 && $node instanceof Stmt\Function_ ) {
				if ( $this->functionCollector->isInternal( $node->name ) ) {
					echo "polyfill $node->name, removed!\n";
					$node->setAttribute( 'remove', true );

					return NodeTraverser::DONT_TRAVERSE_CHILDREN;
				}
			}

			return NodeTraverser::DONT_TRAVERSE_CHILDREN;
		} elseif ( $node instanceof Stmt\If_ ) {

			// lookahead
			$classes = Traversing::filter( $node->stmts, function ( Node $node ) {
				return $node instanceof Stmt\ClassLike;
			} );

			$functions = Traversing::filter( $node->stmts, function ( Node $node ) {
				if ( $node instanceof Stmt\Class_ ) // dont find functions nested inside methods
				{
					return NodeTraverser::DONT_TRAVERSE_CHILDREN;
				}

				return $node instanceof Stmt\Function_; // no methods, no closures!
			} );

			// lookahead
			$includes = Traversing::filter( $node->stmts, function ( Node $node ) {
				return $node instanceof Node\Expr\Include_;
			} );


			if ( ! empty( $classes ) ) {
				$node->setAttribute( 'nestedClassNames', array_map( function ( Stmt\ClassLike $c ) {
					return $c->name;
				}, $classes ) );

				$node->setAttribute( 'nestedClassesExtend', array_map( function ( Stmt\ClassLike $c ) {
					return empty($c->extends) ? '' : $c->extends;
				}, $classes ) );
			}

			if ( ! empty( $functions ) ) {
				$node->setAttribute( 'nestedFunctionNames', array_map( function ( Stmt\Function_ $f ) {
					return $f->name;
				}, $functions ) );
			}

			if ( ! empty( $includes ) ) {
				$node->setAttribute( 'nestedIncludes', array_map( function ( Node\Expr\Include_ $inc ) {
					return $inc->expr;
				}, $includes ) );
			}
			$this->ifStack[] = $node;
		}

		return null;
	}

	public function leaveNode( Node $node ) {
		parent::leaveNode( $node );

		if ( $node instanceof Stmt\Function_ ) {

			// TODO add interface for ignored functions
			if(preg_match('/wp_cache_/',$node->name)) {
				echo "ignoring function $node->name\n";
				return null;
			}

			if ( ! $node->getAttribute( 'remove' ) ) {
				$this->functions[] = $node;
			}

			return NodeTraverser::REMOVE_NODE;
		} elseif ( $node instanceof Stmt\ClassLike ) {
			$this->classLikes[] = $node;

			return NodeTraverser::REMOVE_NODE;
		} elseif ( $node instanceof Stmt\Use_ ) {
			$this->uses[] = $node;
		} elseif ( $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
		           && trim( $node->name->toString(), '\\' ) === 'class_exists'
		) {


			$nl = count( $this->ifStack );

			if($nl >= 2) // TODO
				return null;

			if ( $nl !== 1 ) {
				throw $this->error( "class_exists only in condition of non-nested if block (nesting level $nl)", $node );
			}



			$nestedClasses       = $this->ifStack[0]->getAttribute( 'nestedClassNames' );
			$nestedClassesExtend = $this->ifStack[0]->getAttribute( 'nestedClassesExtend' );
			$nestedIncludes      = $this->ifStack[0]->getAttribute( 'nestedIncludes' );

			$incsOnly = count( $this->ifStack[0]->stmts ) === count( $nestedIncludes );

			if ( empty( $nestedClasses ) && empty( $nestedIncludes ) ) {

				throw $this->error( "class_exists only in condition of if block wrapping a class " . toStr::expr( $this->ifStack ), $node );
			}

			$condClassName = (string) $node->args[0]->value->value;
			if ( ! in_array( $condClassName, (array) $nestedClasses ) ) {

				if ( $nestedClassesExtend && ! in_array( $condClassName, $nestedClassesExtend ) ) {
					echo "warn: class_exists($condClassName) at unexpected position\n";
				}

				return null;
				//throw new \RuntimeException("class_exists($condClassName) only in condition of if block wrapping same class ");
			}

			$prettyPrinter = new PrettyPrinter\Standard;

			// disable autload for class_exists(,$autoload)
			$false = new Node\Expr\ConstFetch( new Node\Name\FullyQualified( 'false' ) );
			$this->ifStack[0]->setDocComment( new Doc( '/* was ' . $prettyPrinter->prettyPrintExpr( $node ) . ' */' ) );

			return $false;
			/*$args = $node->args;
			$args[1] = new Node\Arg($false);
			return new Node\Expr\FuncCall(new Node\Name\FullyQualified('class_exists'), $args);
			*/
		} elseif ( $node instanceof Stmt\If_ ) {
			array_pop( $this->ifStack );
		}

		return null;
	}
}