<?php


namespace Calltree\Static_\NodeVisitor;


use Calltree\Static_\FunctionCollector;
use Calltree\Static_\VariableInfo;
use PhpParser\Node;


class TypeResolver1 extends BaseResolver {
	use VariableCollector;

	// just utilities
	use ResolvingUtils;
	use HookNodeUtils;
	use SerializationUtils;


	private $returnTypeStack = [];
	protected $returnTypes = [];

	public function __construct( FunctionCollector $functionCollector ) {
		parent::__construct( $functionCollector );
	}

	public function beforeTraverse( array $nodes ) {
		parent::beforeTraverse( $nodes );
		$this->beforeTraverseVariableCollector( $nodes );
	}

	public function enterNode( Node $node ) {
		parent::enterNode( $node );
		$this->enterNodeVariableCollector( $node );

		if ( $node instanceof Node\FunctionLike ) {
			$this->returnTypeStack[] = $this->returnTypes;
			$this->returnTypes       = [];
		} elseif ( $node instanceof Node\Stmt\Foreach_ ) {
// TODO this belongs in VariableCollector
			// register foreach key/value variables
			if ( $node->keyVar instanceof Node\Expr\Variable ) {
				$this->variables[ $node->keyVar->name ] = new VariableInfo();
			}

			if ( $node->valueVar instanceof Node\Expr\Variable ) {
				$this->variables[ $node->valueVar->name ] = new VariableInfo();

				// restore possible values for valueVar
				if($node->expr instanceof Node\Expr\Variable &&  isset($this->variables[ $node->expr->name])
				&& is_array($this->variables[ $node->expr->name]->value)) {
					$this->variables[$node->valueVar->name]->possibleValues = array_values($this->variables[ $node->expr->name]->value);
				}
			}
		} elseif ($node instanceof Node\Stmt\Catch_) {
			// TODO this belongs in VariableCollector
			if(!isset($this->variables[$node->var])) {
				$this->variables[$node->var] = new VariableInfo();
			}
			$this->variables[$node->var]->maybeUpgrade($node->types[0]->toString());
		}

	}


	public function leaveNode( Node $node ) {

		if ( $node instanceof Node\Expr\Assign || $node instanceof  Node\Expr\AssignRef) {
			if ( $node->var instanceof Node\Expr\Variable ) {
				if ( ! isset( $node->var->name ) ) {
					throw new Error( 'empty var name', $node );
				}

				// add variable if not there yet
				$name = $node->var->name;

				if ( ! is_string( $name ) ) {
					$this->addWarning( $this->error( "variable variable", $name ) );
				} else {

					if ( ! isset( $this->variables[ $name ] ) ) {
						$this->variables[ $name ] = new VariableInfo();
					}
					$varInfo = $this->variables[ $name ];

					try {
						// todo it is important to collect variable types and values as we proceed, and upgrade them
						$type = $this->resolveExpressionTypeVar( $node->expr, $value );
						$varInfo->maybeUpgrade( $type, $value );
					} catch ( Error $e ) {
						$e->setContext( $node );
						$this->addWarning( $e );
					}
				}
			} elseif ( $node->var instanceof Node\Expr\ArrayDimFetch ) {
				// dont resolve array element types
			} elseif ( $node->var instanceof Node\Expr\PropertyFetch

			) {
				if ( $node->var->var instanceof Node\Expr\Variable
				     && $node->var->var->name == 'this' // only $this
				     && is_string( $node->var->name )
				) { // no $this->$p
					// process `$this->var = ....;` to resolve property type

					// TODO atm we only use assignments to properties of the current class
					// for type specification. we could also use other classes

					$name = $node->var->name;


					// add property if not there yet
					if ( ! isset( $this->properties[ $name ] ) ) {
						$this->properties[ $name ] = new VariableInfo();
					}
					$propInfo = $this->properties[ $name ];

					try {
						// todo it is important to collect variable types and values as we proceed, and upgrade them
						$type = $this->resolveExpressionTypeVar( $node->expr, $value );
						$propInfo->maybeUpgrade( $type, $value );
					} catch ( Error $e ) {
						$e->setContext( $node );
						$this->addWarning( $e );
					}
				}
			} elseif($node->var instanceof Node\Expr\List_) {
				// list($var, $var2) = [];, or list(, , $sourceImageType)
				foreach ( $node->var->items as $item ) {
					// ignore list($a['k'], ,) = ...
					if ( !empty($item) && $item->value instanceof Node\Expr\Variable
					     && !empty($item->value->name)
					     && ! isset( $this->variables[ $item->value->name ] ) ) {
						$this->variables[ $item->value->name ] = new VariableInfo();
					}
				}
			} else {
				$this->addWarning( $this->error( "unknown assignment " . get_class( $node->var ), $node ) );
			}

		} elseif ( $node instanceof Node\Stmt\Return_ ) {
			if ( ! $node->expr ) {
				$this->returnTypes['void'] = VariableInfo::getTypeStrength( 'void' );
			} else {
				try {
					$t = $this->resolveExpressionTypeVar( $node->expr, $value );

					if ( ! isset( $this->returnTypes[ $t ] ) ) {
						$this->returnTypes[ $t ] = VariableInfo::getTypeStrength( $t );
					}
				} catch ( Error $e ) {
					$e->setContext( $node );
					$this->addWarning( $e );
				}
			}

		} elseif ( $node instanceof Node\FunctionLike ) {
			//print_r( [ $this->getCurrentFunctionLikeName(), $this->variables ] );

			$selfRef = "*({$this->getCurrentFunctionLikeName()})";

			// avoid type references to same function
			if ( isset( $this->returnTypes[ $selfRef ] ) ) {
				//print_r( $selfRef );
				unset( $this->returnTypes[ $selfRef ] );
			}

			// get strongest type
			arsort( $this->returnTypes );
			reset( $this->returnTypes );
			$retType = key( $this->returnTypes );


			// query type from DocComment
			$docText = $node->getDocComment() ? $node->getDocComment()->getText() : '';
			if($docText &&  preg_match( '/@return\s+([a-z0-9_\\\\|]+)/i', $docText, $m )) {
				$retType =  VariableInfo::strongerType($retType, VariableInfo::strongestType( $m[1], $strength ));
			}


			$this->functionCollector->addFunctionDetails(
				$this->getCurrentFunctionLikeName(),
				$this->variables,
				$retType );

			$this->returnTypes = array_pop( $this->returnTypeStack );
		} elseif ( $node instanceof Node\Stmt\ClassLike ) {
			$this->functionCollector->addClassProperties( $this->getCurrentClassLikeName(),
				$this->properties
			);
		} elseif ( $node instanceof Node\Stmt\Global_ ) {
			// TODO $GLOBALS
			foreach ( $node->vars as $var ) {
				/** @var Node\Expr\Variable $var */
				$name = $var->name;
				if ( ! is_string( $name ) ) {
					$this->addWarning( $this->error( "variable global variable", $node ), 1 );
				} elseif ( ! isset( $this->variables[ $name ] ) ) {
					// "import"
					$this->variables[ $name ] = $this->functionCollector->requestGlobalVariable( $name );
				} else {
					//$this->addWarning($this->error("global after assign", $node));
					//die('global after assign!');
					$this->addWarning( $this->error( "global variable declaration after access/assignment!", $node ), 1 );
				}
			}
		}

		$this->leaveNodeVariableCollector( $node ); // its important to leave here
		parent::leaveNode( $node );

	}

	public function afterTraverse( array $nodes ) {
		if(!empty($this->variables)) {
			$this->functionCollector->addFileVariables(
				$this->fileName,
				$this->variables);
		}
		parent::afterTraverse( $nodes ); // TODO: Change the autogenerated stub
	}


}