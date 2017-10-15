<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 08.10.2017
 * Time: 11:35
 */

namespace Calltree\Static_\NodeVisitor;



use Calltree\Static_\VariableInfo;
use Calltree\Static_\toStr;
use PhpParser\Node;
use PhpParser\Node\Name;


trait ResolvingUtils {

	public static function nameIsRequestVar( $name ) {
		return $name === '_GET' || $name === '_REQUEST' || $name === '_POST';
	}


	/**
	 * Here we resolve:
	 *  - `new self` to `new N\C`;
	 *  - `$var = apply_filters(..., <expr>)` to `$var = <expr>`
	 *  - `$a = $b`
	 *
	 * We dont resolve function return types here yet!
	 *
	 * @param $expr
	 *
	 * @param null $value
	 *
	 * @return string
	 */

	// todo it is important to collect variable types and values as we proceed, and upgrade them
	private function resolveExpressionTypeVar( $expr, &$value = null ) {

		// "strings" (with value)
		if ( $expr instanceof Node\Scalar\String_ ) {
			$value = $expr->value;

			return "string";
		}

		// "strings with $encapsed"
		if ( $expr instanceof Node\Scalar\Encapsed ) {
			return 'string';
		}

		// `self`, `C`, `f`
		// TODO here we loss important info about whether it is a func or class
		if ( $expr instanceof Name ) {
			$nameStr = $expr->toString();
			if ( strtolower( $nameStr ) === 'self' ) {
				// TODO expect 'parent' 'static'
				return $this->getCurrentClassLikeName();
			}

			return $nameStr; ///T  TODO
		}

		// array()s, []
		if ( $expr instanceof Node\Expr\Array_ ) {
			$arrItems = [];
			$arrTypes = [];
			foreach ( $expr->items as $item ) {
				$itemT = $this->resolveExpressionTypeVar( $item->value, $itemV );
				if ( ! empty( $item->key ) ) {
					$keyType             = $this->resolveExpressionTypeVar( $item->key, $keyVal );
					$arrItems[ $keyVal ] = $itemV;
					$arrTypes[ $keyVal ] = $itemT;
				} else {
					$arrItems[] = $itemV;
					$arrTypes[] = $itemT;
				}
			}

			$value    = $arrItems;
			$arrTypes = array_unique( $arrTypes );
			if ( count( $arrTypes ) === 1 ) {
				// single typed array
				//print_r($value);
				return reset( $arrTypes ) . '[]';
			}

			return "array";
		}

		// maybeTodo dig into expression
		if ( $expr instanceof Node\Expr\Cast\Array_ ) {
			return "array";
		}

		// null false true (with value)
		if ( $expr instanceof Node\Expr\ConstFetch ) {
			//$constFetchName = $expr->name->toString();
			switch ( $expr->name->toString() ) {
				case 'null':
					$value = null;

					return 'null';
				case 'true':
					$value = true;

					return 'bool';
				case 'false':
					$value = false;

					return 'bool';
			}

			if ( $expr->name->toString() ) {
				// assume SOME_CONSTANT is always string
				// todo resolve constants values
				return 'string';
			}
			$e = new Error( "const fetch", $expr );
			$e->getErrorMessage();
			print_r( [ $e, $expr->name ] );
			exit;
		}

		// (int)
		if ( $expr instanceof Node\Expr\Cast\Int_ ) {
			return 'int';
		}

		// (string)
		if ( $expr instanceof Node\Expr\Cast\String_ ) {
			new Error( "TODO find value", $expr );

			return 'string';
		}

		// (bool), !, any binary operator (==,!=)
		if ( $expr instanceof Node\Expr\Cast\Bool_ || $expr instanceof Node\Expr\BooleanNot ) {
			return 'bool';
		}


		if ( $expr instanceof Node\Scalar\LNumber ) {
			$value = + $expr->value;

			return 'int';
		}

		if ( $expr instanceof Node\Scalar\DNumber ) {
			$value = + $expr->value;

			return 'float';
		}

		if ( $expr instanceof Node\Expr\UnaryMinus ) {
			return $this->resolveExpressionTypeVar( $expr->expr, $value );
		}

		// local variables (with value if possible)
		if ( $expr instanceof Node\Expr\Variable ) {
			if ( $expr->name === 'this' ) {
				$value = $this->getCurrentClassLikeName(); // TODO?

				return $this->getCurrentClassLikeName();
			}

			// _GET, _POST,....
			if ( self::nameIsRequestVar( $expr->name ) ) {
				return 'string';
			}

			// TODO global variables
			if ( $expr->name === 'wpdb' ) {
				$value = 'wpdb';

				return 'wpdb';
			}

			if ( $expr->name === 'wp_object_cache' ) {
				$value = 'WP_Object_Cache';

				return 'WP_Object_Cache';
			}

			// TODO variable variables
			$varName = $expr->name;
			if ( ! isset( $this->variables[ $varName ] ) ) {
				if ( ! isset( $this->variables ) ) {
					throw new \LogicException( "ResolvingUtils need a Visitor with context for variables!" );
				}

				if ( ! empty( $this->functionLike ) ) {
					throw $this->error( "unknown variable $varName", $expr );
				} elseif ( isset( $this->functionCollector->globalVariables[ $varName ] ) ) {
					$this->variables[ $varName ] = $this->functionCollector->globalVariables[ $varName ]; // import
					$this->addWarning($this->error( "implicitly global variable $varName", $expr ),1);
				} else {
					//print_r($this->variables);
					//print_r($this->fileVariables);
					//print_r($this->functionCollector->fileVariables[$this->fileName]);
					throw ( $this->error( "unknown variable $varName in global file context ", $expr ) );
				}
			}
			$this->variables[ $varName ]->maybeUpgradeFrom( $this->variables[ $varName ] );

			return $this->variables[ $varName ]->getType();
		}

		// .
		$binOp = ( $expr instanceof Node\Expr\BinaryOp );

		// $a ? $b : $c
		if ( $binOp || ( $expr instanceof Node\Expr\Ternary ) ) {
			$types = [
				$this->resolveExpressionTypeVar( $binOp ? $expr->left : $expr->if, $val1 ),
				$this->resolveExpressionTypeVar( $binOp ? $expr->right : $expr->else, $val2 )
			];

			// use the stronger type
			if ( VariableInfo::getTypeStrength( $types[1] ) > VariableInfo::getTypeStrength( $types[0] ) ) {
				$value = $val2;

				return $types[1];
			} else {
				$value = $val1; // todo value array

				return $types[0];
			}
		}


		// isset(), we dont care about the value (yet maybeTodo)
		if ( $expr instanceof Node\Expr\Isset_ || $expr instanceof Node\Expr\Empty_ ) {
			return "bool";
		}


		// function calls
		if ( $expr instanceof Node\Expr\FuncCall ) {
			if ( ! ( $expr->name instanceof Name ) ) {
				// `$f()`, `$a[0]()`
				$this->resolveExpressionTypeVar( $expr->name, $valCalleeName );
			} else {
				$valCalleeName = $this->nameToString( $expr->name );
			}

			// resolve apply_filters(..., <expr>) => <expr>
			if ( $this->compareFuncName( $valCalleeName, 'apply_filters' ) ) {
				return $this->resolveExpressionTypeVar( $expr->args[1]->value, $value );
			} elseif ( $this->compareFuncName( $valCalleeName, 'call_user_func' ) ) {
				return "*(" . $this->exprToFunctionName( $expr->args[0]->value ) . ")";
			}

			return "*($valCalleeName)";
		}

		// self::f(), C::f()
		$isStaticCall = ( $expr instanceof Node\Expr\StaticCall );

		// $this->f(), $other->f(), f()->m()
		if ( $isStaticCall || ( $expr instanceof Node\Expr\MethodCall ) ) {
			$fullFuncName = $this->bindAndFuncExprToFunctionName( $isStaticCall ? $expr->class : $expr->var, $expr->name );

			// TODO maybe add class name? $this->maybeAddClassName(
			// but bindAndFuncExprToFunctionName resolves full class names!

			return "*($fullFuncName)";
		}

		// $array[...]
		if ( $expr instanceof Node\Expr\ArrayDimFetch ) {
			if ( isset( $expr->var->name ) && self::nameIsRequestVar( $expr->var->name ) ) {
				// always consider _GET, _POST... to be strings (although they can be arrays too)
				return 'string';
			}

			// we cant resolve this
			// TODO?
			return 'mixed';
		}


		// $this->that, $other->that, func()->that
		if ( $expr instanceof Node\Expr\PropertyFetch ) {
			if ( is_string( $expr->name ) ) {
				$typeFetchVar = $this->resolveExpressionTypeVar( $expr->var, $valFetchVar );

				return ( "*($typeFetchVar::\$$expr->name)" ); // TODO add the default type hint
			}
		}

		// self::$p, C::$p
		if ( $expr instanceof Node\Expr\StaticPropertyFetch ) {
			if ( is_string( $expr->name ) && $expr->class instanceof Name ) {
				$className = self::nameToString( $expr->class );

				return ( "*($className::\$$expr->name)" ); // TODO add the default type hint
			}
		}

		if ( $expr instanceof Node\Expr\ClassConstFetch ) {
			// todo class constants
			return 'string';
		}


		// new C(), new $class()
		if ( $expr instanceof Node\Expr\New_ ) {
			// maybe we are lucky and can resolve a `new $class()`
			if ( $expr->class instanceof Node\Expr\Variable ) {
				$this->resolveExpressionTypeVar( $expr->class, $valClassName );

				// its an object at least
				if ( empty( $valClassName ) ) {
					return 'object';
				}

				return $valClassName;
			}

			// resolve `self`
			if ( $expr->class instanceof Name ) {
				return $this->nameToString( $expr->class );
			}
		}

		// $a = $b = $c;
		if ( $expr instanceof Node\Expr\Assign ) {
			return $this->resolveExpressionTypeVar( $expr->expr, $value );
		}

		//@$hopefully['there']
		if ( $expr instanceof Node\Expr\ErrorSuppress ) {
			return $this->resolveExpressionTypeVar( $expr->expr, $value );
		}

		// __CLASS___
		if ( $expr instanceof Node\Scalar\MagicConst\Class_ ) {
			$value = $this->getCurrentClassLikeName(); // TODO?

			return $this->getCurrentClassLikeName();
		}

		if ( $expr instanceof Node\Scalar\MagicConst\File ) {
			return 'string';
		}

		if ( $expr instanceof Node\Scalar\MagicConst\Line ) {
			return 'int';
		}

		if ( $expr instanceof Node\Expr\Clone_ ) {
			return $this->resolveExpressionTypeVar( $expr->expr, $value );
		}

		if ( $expr instanceof Node\Expr\Cast\Object_ ) {
			return 'object';
		}


		if ( ! is_object( $expr ) ) {
			var_dump( $expr );
			throw new \LogicException( "resolveExpressionTypeVar called with non-object" );
		}
		throw new Error( "cant resolve expression type (" . get_class( $expr ) . ")", $expr );
	}


	protected function addWarning( \Exception $err, $critical = 0 ) {
		$message = ( $err instanceof Error ) ? $err->getErrorMessage() : $err->getMessage();
		echo "WARNING: " . strtok( $message, "\n" ) . "\n";
	}


	protected function fullClassName( $namespace, $class ) {
		$nsPrefix = $namespace ? ( $namespace->toString() . '\\' ) : '';

		return $nsPrefix . ( $class ? ( $class->name ) : '' );
	}


	/**
	 * Maybe add namespace
	 *
	 * @param \PhpParser\Node\Name $calleeName
	 *
	 * @return string
	 */
	protected function fullCalleeName( \PhpParser\Node\Name $calleeName ) {
		if ( $calleeName->isFullyQualified() ) {
			return $calleeName->toString();
		}
		$name = $calleeName->toString();

		// maybe add namespace
		if ( ! $this->functionCollector->isKnown( $name ) && $this->namespace ) {
			$name = $this->namespace->toString() . '\\' . $calleeName;
		}

		return $name;
	}


	/**
	 * @param Node\Expr|Name\FullyQualified $bindExpr
	 * @param Node\Expr|string $funcExp
	 *
	 * @return string
	 */
	protected function bindAndFuncExprToFunctionName( $bindExpr, $funcExp, $traverseClassHierachy = false ) {
		if ( $bindExpr instanceof Node\Name ) {
			// static
			$bindVal  = $this->nameToString( $bindExpr );
			$bindType = 'string';
		} else {
			$bindType = $this->resolveExpressionTypeVar( $bindExpr, $bindVal );
		}

		// BIG TODO
		if ( $bindVal == "parent" ) {
			$nsPrefix = $this->namespace ? ( $this->namespace->toString() . '\\' ) : '';
			$bindVal  = $nsPrefix . $this->classLike->extends;
		}

		if ( is_string( $funcExp ) ) {
			$funcVal = $funcExp;
		} else {
			$this->resolveExpressionTypeVar( $funcExp, $funcVal );
		}


		if ( empty( $bindVal ) ) {


			/** @var FunctionCollector $funcCol */
			$funcCol = $this->functionCollector;

			// recover: if $bindType is a name of a known class, take it!
			if ( ! empty( $bindType ) ) {
				$knownClass = $funcCol->isKnownClass( $bindType );

				if ( $funcVal === 'exists' ) {
					echo "!!!!!!!!!get_error_code ($knownClass)  $bindType \n\n";
				}

				if ( $knownClass ) {
					$bindVal = $bindType;
				}

				if ( $bindType{0} === '*' ) {
					$bindVal = $bindType;
				}
			}


			$funcValLow = strtolower( $funcVal );

			// recover loose
			if ( empty( $bindVal ) && isset( $funcCol->mapMethodNameToClasses[ $funcValLow ] ) ) {
				$cc = $funcCol->mapMethodNameToClasses[ $funcValLow ];
				if ( count( $cc ) === 1 ) {
					$bindVal = $cc[0];
				} else {
					list( $ci, $cs ) = $this->getMaxSimiliar( $cc, $bindExpr );
					if ( strlen( $funcVal ) > 3 && $cs > 15 || ( strlen( $funcVal ) === 3 && $cs > /*70*/
					                                                                         54 )
					) { // todo

						$bindVal = $cc[ $ci ];
						$this->addWarning( $this->error( "recoverd most similiar match ::$funcVal to be of class $bindVal", $bindExpr ) );

						//echo $cc[ $ci ] . " <-$cs-> " . toStr::expr( $bindExpr );
						//print_r( $cc );
						//exit;
						//echo "$funcVal $this->fileName " . toStr::expr($bindExpr);
						//print_r($funcCol->mapMethodNameToClasses[$funcVal]);
						//exit;
					} elseif ( $bindExpr instanceof Node\Expr\Variable && isset( $this->variables[ $bindExpr->name ] ) && ( $t = $this->variables[ $bindExpr->name ]->isClassType() )
						//&& $this->functionCollector->isAddedFunction("$t::$funcVal")
					) {
						$bindVal = $this->variables[ $bindExpr->name ]->getType();
						//print_r($this->variables[$bindExpr->name]);
						//exit;
					} else {
						$bindVal = $this->tryCustomResolvers( $funcVal, $bindExpr, $funcCol->mapMethodNameToClasses[ $funcValLow ] );
						if ( empty( $bindVal ) ) {
							$this->addWarning( $this->error( "tried to recover class for ::$funcVal(...), but found multiple. (max sim=$cs, {$cc[$ci]}):" . implode( ',', array_slice( $funcCol->mapMethodNameToClasses[ $funcValLow ], 0, 4 ) ), $bindExpr ));
							return "::$funcVal"; // TODO
							//throw $this->error( "tried to recover class for ::$funcVal(...), but found multiple. (max sim=$cs, {$cc[$ci]}):" . implode( ',', array_slice( $funcCol->mapMethodNameToClasses[ $funcValLow ], 0, 4 ) ), $bindExpr );
						}

					}
				}
			}

			if ( empty( $bindVal ) ) {
				//print_r($funcCol->mapMethodNameToClasses);
				$this->addWarning($this->error( "empty bindVal (type $bindType) ::$funcVal(...)", $bindExpr ),1);
				return "::$funcVal";
			}
		}

		// remap to impelementation is class hierarchy
		// TODO move this
		if ( $traverseClassHierachy ) {
			$bindVal = $this->functionCollector->resolveMethodInClassHierarchy( $bindVal, $funcVal );
		}

		return $bindVal . '::' . $funcVal;
	}

	function tryCustomResolvers( $funcName, $bindNode, array $candidates ) {
		if ( $bindNode instanceof Node\Expr\Variable ) {
			if ( $funcName === 'get_error_code' && strpos( $bindNode->name, 'result' ) !== false ) {
				echo $funcName;
				print_r( $bindNode );

				//exit;
				return 'wp_error';
			}

			if ( strpos( $bindNode->name, 'blog' ) !== false && in_array( 'wp_site', $candidates ) ) {
				return 'wp_site';
			}

			if ( strpos( $bindNode->name, 'feed' ) !== false && in_array( 'simplepie', $candidates ) ) {
				return 'simplepie';
			}
		}

		$bindStr = toStr::expr($bindNode);
		if ( strpos( $bindStr, 'role_objects' ) !== false && in_array( 'wp_role', $candidates ) ) {
			return 'wp_role';
		}
	}





	protected function getMaxSimiliar( $arr, $str ) {
		if ( $str instanceof Node\Expr ) {
			$str = preg_replace( '/[^a-z_]/', '', strtolower( toStr::expr( $str ) ) );
		}
		$maxS = - INF;
		$minI = 0;
		foreach ( $arr as $i => $a ) {
			similar_text( $a, $str, $s );
			if ( $s > $maxS ) {
				$maxS = $s;
				$minI = $i;
			}
		}

		return [ $minI, round( $maxS ) ];
	}


	protected function exprToFunctionName( Node $funcExpr ) {
		if ( $funcExpr instanceof Node\Scalar\String_ ) {
			return $funcExpr->value; // add_action(...,'myFunc'), easy!

		} elseif ( $funcExpr instanceof Node\Expr\Array_ ) {

			// expect array with 2 elements, 2nd must be string!
			// 1st can be: `$this`, `__CLASS__` `$var`, `getObj()`
			// where we only support the first 2
			// TODO test
			if ( count( $funcExpr->items ) != 2 || ! ( $funcExpr->items[1]->value instanceof Node\Scalar\String_ ) ) {
				throw new Error( "invalid callback", $funcExpr );
			}

			$bindExpr = $funcExpr->items[0]->value;

			return $this->bindAndFuncExprToFunctionName( $bindExpr, $funcExpr->items[1]->value );


		} elseif ( $funcExpr instanceof Node\Expr\Closure ) {
			return 'closure@' . $funcExpr->getLine(); // its anonymous
		} elseif ( $funcExpr instanceof Node\Scalar\MagicConst\Function_ ) {
			// __FUNCTION__ is only the name!
			/** @noinspection PhpUndefinedFieldInspection */
			return $this->functionLike->name;
			//return $this->fullFunctionName($this->namespace, $this->inClassLike);
		}

		throw $this->error( "unknown callback", $funcExpr );
	}
}