<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 07.10.2017
 * Time: 18:48
 */

namespace Calltree\Static_\NodeVisitor;


use Calltree\Static_\FunctionCollector;
use Calltree\VariableInfo;
use PhpParser\Node;

/**
 * Finds {Classes, Interfaces, Traits}=ClassLikes (or CIT) and (global) functions.
 * Collects their names (and namespace).
 * We will not resolve any types here.
 * TODO maybe we should already collect variables
 * @package Calltree
 */
class CollectClassLikesAndFunctions extends BaseResolver {
	use ResolvingUtils;
	use SerializationUtils;
	use HookNodeUtils;

	public $numClassLikes = 0;
	public $numNamedFunctions = 0;
	public $classes = [];
	public $interfaces = [];
	public $traits = [];
	public $miscCode = [];

	public function serialize() {
		return $this->serializeProperties( [
			'numClassLikes',
			'numNamedFunctions',
			'classes',
			'interfaces',
			'traits',
			'miscCode'
		] );
	}

	private $inIfUntil = null;
	private $nodeLevel = 0;


	public function __construct( FunctionCollector $functionCollector ) {
		parent::__construct( $functionCollector );
	}

	public function enterNode( Node $node ) {
		parent::enterNode( $node );

		if ( $this->inIfUntil === $node ) {
			$this->inIfUntil = null;
		}

		if ( ! $this->classLike && ! $this->functionLike ) {
			if ( $node instanceof Node\Stmt\If_ ) {
				// classes or functions can be wrapped with if() {}
				$this->inIfUntil = reset( $node->stmts );
			} elseif ( ! $this->inIfUntil ) {
				// this is global code
				if ( $this->nodeLevel === 0 && ! ( $node instanceof Node\Stmt\Nop ) ) {
					$this->miscCode[] = json_encode( $node );
				}
			}
		}


		++ $this->nodeLevel;
	}

	public function leaveNode( Node $node ) {
		// NameResolver provides namespace
		$nsPrefix = $this->namespace ? ( $this->namespace . '\\' ) : '';

		if ( $node instanceof Node\Stmt\ClassLike ) {
			++ $this->numClassLikes;
			$cln     = $nsPrefix . $node->name;
			$clt     = T_CLASS;
			$extends = "";

			if ( $node instanceof Node\Stmt\Class_ ) {
				$this->classes[ $cln ] = 1;
				$cln                   = T_CLASS;
				$extends               = $node->extends ? $node->extends->toString() : '';
			} elseif ( $node instanceof Node\Stmt\Interface_ ) {
				$this->interfaces[ $cln ] = 1;
				$cln                      = T_INTERFACE;
			} elseif ( $node instanceof Node\Stmt\Trait_ ) {
				$this->traits[ $cln ] = 1;
				$cln                  = T_TRAIT;
			}


			$this->functionCollector->addClassLike( $nsPrefix . $node->name, $cln, $extends );

		} elseif ( /*! $this->classLike && TODO allow functions insdie methods*/
			$node instanceof Node\Stmt\Function_
		) {
			if ( $this->classLike ) {
				$this->addWarning( new Error( "found function {$node->name} inside class " . $this->getCurrentClassLikeName() ) );
			}
			$this->functionCollector->addFunctionLike( $nsPrefix . $node->name, $node );
			++ $this->numNamedFunctions;
		} elseif ( $node instanceof Node\Expr\Closure ) {
			$name = "closure@" . $node->getLine();
			$this->functionCollector->addFunctionLike( $name, $node );
		} elseif ( $this->classLike && ( $node instanceof Node\Stmt\ClassMethod ) ) {
			$this->functionCollector->addFunctionLike( $nsPrefix . $this->classLike->name . '::' . $node->name, $node );
		}



		-- $this->nodeLevel;


		parent::leaveNode( $node );
	}
}