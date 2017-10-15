<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 07.10.2017
 * Time: 23:57
 */

namespace Calltree\Static_;

use Calltree\Static_\NodeVisitor\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use function PHPSTORM_META\type;

class FunctionInfo {

	/** @var Node\FunctionLike */
	public $func;
	public $hasCallableParam = false;
	public $returnType = '';

	/** @var VariableInfo[] */
	public $variables = [];

	function __construct( $func ) {
		$this->func = $func;
	}
}


class ClassInfo {
	/** @var VariableInfo[] */
	public $properties = [];

	public $extends = "";
}

/**
 * Collects FunctionLikes (functions and methods and maybe closures)
 * @package Calltree
 */
class FunctionCollector {

	static $internalsMapCallableParam = [];

	/**
	 * @var FunctionInfo[]
	 */
	public $addedFunctions = [];

	/** @var ClassInfo[] */
	public $addedClasses = [];

	public $mapPropertyNameToClasses = [];

	public $mapMethodNameToClasses = [];


	public $lastFile = '';


	/*
	static function strContainsAny($haystack, $needles=array()) {
		foreach($needles as $needle) {
			if(strpos($haystack, $needle) !== false)
				return true;
		}
		return false;
	}
	*/

	const BOOL = 'boolean';

	static function mightBeCallableParameterName( $name ) {
		return strpos( $name, 'callback' ) !== false ||
		       strpos( $name, 'handler' ) !== false ||
		       strpos( $name, 'function' ) !== false;
	}

	public function __construct() {
		if ( empty( self::$internalsMapCallableParam ) ) {
			self::$internalsMapCallableParam = \array_flip( \get_defined_functions()['internal'] );


			foreach ( self::$internalsMapCallableParam as $func => &$hasCallableParam ) {
				$rf = new \ReflectionFunction( $func );

				foreach ( $rf->getParameters() as $i => $param ) {
					// dont guess if we have a type
					if ( $param->hasType() ? $param->isCallable() : self::mightBeCallableParameterName( $param->name ) ) {
						$hasCallableParam = $i;
						continue 2;
					}
				}
				$hasCallableParam = false;
			}
		}
	}

	/**
	 * @param  FunctionInfo $info
	 *
	 * @return mixed
	 */
	private function resolveReturnType( $info ) {
		static $level = 0;

		// todo register custom function return type resolver
		// TODO use FunctionLike::_returnType
		// TODO use DocComments!

		if ( $level > 10 ) {
			//$this->lo
			echo "resolveReturnType level is more than 10! ($info->returnType) falling back to 'bool'\n";

			//throw new \RuntimeException("resolveReturnType level is more than 10! ($info->returnType)");

			return $info->returnType = "bool";
		}


		$level ++;
		if ( $info->returnType{0} === '*' ) {
			$funcOrProp = trim( strtolower( substr( $info->returnType, 1 ) ), '()' );
			$isLoose    = ( $funcOrProp{0} === ':' );

			if ( strpos( $funcOrProp, '::$' ) === false ) {
				if ( $this->isInternal( $funcOrProp ) ) {
					$info->returnType = $this->getReturnTypeInternalFunc( $funcOrProp );
				} elseif ( $this->isAddedFunction( $funcOrProp ) ) {
					$info->returnType = $this->resolveReturnType( $this->addedFunctions[ $funcOrProp ] );
				} else {
					throw new \RuntimeException( "unknown function $funcOrProp" );
				}
			} else {
				if ( $isLoose ) {
					$propName = substr( $funcOrProp, 3 );
					if ( empty( $this->mapPropertyNameToClasses[ $propName ] ) ) {
						throw new \RuntimeException( "unknown loose property $funcOrProp" );
					}

					$classNames = $this->mapPropertyNameToClasses[ $propName ];
					if ( count( $classNames ) > 1 ) {
						throw new \RuntimeException( "multiple class matches for loose property $funcOrProp: " . implode( ',', $classNames ) );
					}

					$info->returnType = $this->addedClasses[ $classNames[0] ]->properties[ $propName ]->getType();
				} elseif ( ( $propInfo = $this->isAddedClassProperty( $funcOrProp ) ) ) {
					$info->returnType = $propInfo->getType();
				} else {
					throw new \RuntimeException( "unknown property $funcOrProp" );
				}
			}
		}

		$level --;

		return $info->returnType;
	}


	private function resolveType2( $type ) {
		static $level = 0;
		$type = strtolower( $type );


		// todo register custom function return type resolver
		// TODO use FunctionLike::_returnType
		// TODO use DocComments!

		if ( $level > 200 ) {
			//$this->lo
			echo "resolveType2 level is more than 10! ($type) falling back to ''\n";

			//throw new \RuntimeException("resolveType2 level is more than 10! ($info->returnType)");
			return "";
		}

		if ( empty( $type ) ) {
			return "";
		}

		//*(*(bbpress)::$basename)
		//  *(bbpress)

		//"*(*(*(*(bbpress)::$theme_compat)::$theme)::$id)"
		//"  *(*(*(bbpress)::$theme_compat)::$theme) "
		//"    *(*(bbpress)::$theme_compat) "


		//"*(*(bbpress)::$theme_compat)::func
		// *(*(bbpress)::$theme_compat)::func


		//*(bbpress)::$basename

		//"*(*(*(*(bbpress)::$theme_compat)::$theme)::$id)"
		//"*(*(*(bbpress)::$theme_compat)::$theme)::$id"
		if ( $type{0} !== '*' ) {
			return $type;
		}

		$level ++;

		//echo "resolveType $type \n";

		//$resolveUntil = strrpos($type,')');
		// remove *()
		$type = substr( $type, 2, - 1 );
		if ( empty( $type ) ) {
			return "";
		} // TODO WARN
		//$type = substr($type,2,strrpos($type,')'),-2);

		if ( $type{0} === '*' ) {
			$innerEnd = strrpos( $type, ')' ) + 1;
			$inner    = substr( $type, 0, $innerEnd );
			$type     = $this->resolveType2( $inner ) . substr( $type, $innerEnd );
		}
		$inner =
			//$innerMostStart = strrpos($type, '*') + 2;
			//$innerMostEnd = strpos($type, ')', $innerMostStart);
			//$innerMost = substr($type, $innerMostStart, $innerMostEnd - $innerMostStart);

			//$outerStart = 2;
			//$outerEnd = strrpos($type, ')');
			//$outer = substr($type, $outerStart, $outerEnd - $outerStart);


		$funcOrProp = $type;

		$isLoose = 0;
		if ( $funcOrProp{0} === ':' ) {
			$isLoose = 2;
		} elseif ( strncmp( $funcOrProp, 'stdclass', 8 ) === 0 ) {
			$isLoose = 8 + 2;
		} elseif ( strncmp( $funcOrProp, 'string', 6 ) === 0 ) {
			$isLoose = 6 + 2;
		} elseif ( strncmp( $funcOrProp, 'object', 6 ) === 0 ) {
			$isLoose = 6 + 2;
		}

		if ( strpos( $funcOrProp, '::$' ) === false ) {
			if ( $isLoose ) {
				$funcName = substr( $funcOrProp, $isLoose  );
				if ( empty( $this->mapMethodNameToClasses[ $funcName ] ) ) {
					$this->warn( "unknown loose method $funcOrProp" );
				} elseif ( count( $classNames = $this->mapMethodNameToClasses[ $funcName ] ) > 1 ) {
					// TODO smarter resolve
					$this->warn( "multiple class matches for loose method $funcOrProp: " . implode( ',', array_slice( $classNames, 0, 4 ) ) );
				} else {
					$funcOrProp = $classNames[0].'::'.$funcName;
					$isLoose = false;
				}
			}

			if ( $this->isInternal( $funcOrProp ) ) {
				$type = $this->getReturnTypeInternalFunc( $funcOrProp );
			} elseif ( $this->isAddedFunction( $funcOrProp ) ) {
				$fi   = $this->addedFunctions[ $funcOrProp ];
				$type = $fi->returnType = $this->resolveType2( $fi->returnType ); // resolve&store, so we dont have to resolve this again
			} else {
				if(!$isLoose && strpos($funcOrProp,'::') !== false) {
					list($cn, $mn) = explode('::', $funcOrProp);
					$pcn = $this->resolveMethodInClassHierarchy( $cn, $mn );
					if($pcn !== $cn) {
						$type = "$pcn::$cn";
					} else {
						$this->warn( "unknown function/method $funcOrProp" );
					}
				}  else {
					$this->warn( "unknown function/method $funcOrProp" );
				}
				//throw new \RuntimeException( "unknown function $funcOrProp" );
			}
		} else {
			if ( $isLoose ) {
				$propName = substr( $funcOrProp, $isLoose + 1 );
				if ( empty( $this->mapPropertyNameToClasses[ $propName ] ) ) {
					$this->warn( "unknown loose property $funcOrProp" );
					//throw new \RuntimeException( "unknown loose property $funcOrProp" );
				} elseif ( count( $classNames = $this->mapPropertyNameToClasses[ $propName ] ) > 1 ) {
					// TODO smarter resolve
					$this->warn( "multiple class matches for loose property $funcOrProp: " . implode( ',', array_slice( $classNames, 0, 4 ) ) );
					//throw new \RuntimeException( "multiple class matches for loose property $funcOrProp: " . implode( ',', $classNames ) );
				} else {
					$pi   = $this->addedClasses[ $classNames[0] ]->properties[ $propName ];
					$type = $this->resolveType2( $pi->getType() );
					$pi->maybeUpgrade($type); // resolve and store, so we dont have to resolve this again
				}
			} elseif ( ( $propInfo = $this->isAddedClassProperty( $funcOrProp ) ) ) {
				$type = $this->resolveType2( $propInfo->getType() );
				$propInfo->maybeUpgrade($type);
			} else {
				$this->warn( "unknown property $funcOrProp" );
				//throw new \RuntimeException( "unknown property $funcOrProp" );
			}
		}
		$level --;


		return $type;
	}


	private function warn( $msg ) {
		echo "WARNING: ", $msg, "\n";
	}

	/**
	 * @param $fullPropName
	 *
	 * @return VariableInfo|null
	 */
	public function isAddedClassProperty( $fullPropName ) {
		$p         = strpos( $fullPropName, '::$' );
		$className = substr( $fullPropName, 0, $p );
		$propName  = substr( $fullPropName, $p + 3 );
		if ( ! isset( $this->addedClasses[ $className ] ) ) {
			return null;
		}
		$classInfo = $this->addedClasses[ $className ];

		if ( ! isset( $classInfo->properties[ $propName ] ) ) {
			return null;
		}

		return $classInfo->properties[ $propName ];
	}

	public function resolveTypeRefs() {
		foreach ( $this->addedFunctions as $info ) {
			$info->returnType = $this->resolveType2( $info->returnType );
		}

		foreach ( $this->addedFunctions as $info ) {
			foreach ( $info->variables as $name => $vi ) {
				if ( ($t=$vi->getType()) && $t{0} === '*' ) {
					/** @var VariableInfo $vi */
					$vi->maybeUpgrade( $this->resolveType2( $t ) );
				}
			}
		}

		foreach($this->fileVariables as $vars) {
			foreach ( $vars as $name => $vi ) {
				if ( ($t=$vi->getType()) && $t{0} === '*' ) {
					/** @var VariableInfo $vi */
					$vi->maybeUpgrade( $this->resolveType2( $t) );
				}
			}
		}
	}


	public function isKnownClass( $name ) {
		/* if($name === '*(wp_get_current_user)' || $name === '*(_wp_get_current_user)') {
			echo("isKnownClass( $name )");
			$c = 1;
		}*/
		// dont resolve yet!!
		if($name{0} === '*')
			return $name;

		$name = $this->resolveType2( $name );

		//isset($c) && die(" ...$name...");

		if ( isset( $this->addedClasses[ $name ] ) ) {
			return $name;
		} elseif ( class_exists( $name ) ) {
			// internal
			// TODO: via reflection!!!
			return $name;

			// *(BBP_Converter_Base::$opdb)
			//die("unknown $name ".key($this->addedClasses));
		}
	}


	public function getReturnTypeInternalFunc( $func ) {

		switch ( $func ) {
			case 'sprintf':
			case 'date':
				return 'string';
			case 'create_function':
				return 'callable';

			case 'call_user_func':
				throw new Error( "tried to resolve return type of `call_user_func`" );
		}

		if ( strncmp( $func, 'array_', 6 ) === 0 ) {
			return 'array';
		}

		if ( strncmp( $func, 'html_', 5 ) === 0 ) {
			return 'string';
		}


		// todo from somehwere
		//throw new Error("dont know return type of $func");
		return 'mixed';
	}

	public function serialize() {
		$ser = [];
		foreach ( $this->addedFunctions as $fullName => $info ) {
			$ser[ $fullName ] = [
				'hasCallableParam' => $info->hasCallableParam,
				'returnType'       => $info->returnType, //$this->getReturnClass( $fullName ) // this will resolve!
				'variables'        => $info->variables,
			];
		}

		return $ser;
	}

	public function resolveFunction( $term ) {
		$p = strrpos( $term, ')' );
		if ( $p === false ) {
			return $term;
		}

		return $this->resolveType2( substr( $term, 0, $p + 1 ) ) . substr( $term, $p + 1 );
	}

	public function hasCallableParam( $funcName ) {
		$funcName = strtolower( $funcName );

		$unresolved = $funcName;
		$funcName   = $this->resolveFunction( $funcName );

		if ( $funcName{0} === '\\' ) {
			$funcName = \substr( $funcName, 1 );
		}
		if ( isset( self::$internalsMapCallableParam[ $funcName ] ) ) {
			return self::$internalsMapCallableParam[ $funcName ];
		}

		if ( isset( $this->addedFunctions[ $funcName ] ) ) {
			return $this->addedFunctions[ $funcName ]->hasCallableParam;
		}

		//print_r($this->addedFunctions);
		if ( $funcName === $unresolved ) {
			$unresolved = '';
		}

		throw new \RuntimeException( "unknown func $funcName $unresolved" );
	}

	private $collected = false;

	public function collected( $set = null ) {
		if ( is_bool( $set ) ) {
			$this->collected = $set;
		}

		return $this->collected;
	}

	public function resolveMethodInClassHierarchy( $className, $funcName ) {

		if ( ! is_string( $className ) ) {
			throw new \InvalidArgumentException( "className must be string" );
		}


		$className = $this->resolveType2( $className );

		$originalClass = $className;
		while ( ! $this->isAdded( "$className::$funcName" ) ) {
			//die("traversing $className for $funcName");
			if ( ! isset( $this->addedClasses[ $className ] ) ) {
				$this->warn( "unknown class '$className'" );

				return $originalClass;
			}
			$className = strtolower( $this->addedClasses[ $className ]->extends );
			if ( ! $className ) {
				return $originalClass;
			}

			//die("resolved $originalClass::$funcName to $className::$funcName");
		}

		return $className;
	}

	public function isFunctionExists( $funcName ) {
		$funcName = strtolower( $funcName );

		if ( $funcName{0} === '\\' ) {
			$funcName = \substr( $funcName, 1 );
		}

		return $funcName === 'function_exists' || $funcName === 'is_callable';
	}

	public function isInternal( $funcName ) {
		if ( empty( $funcName ) ) {
			return false;
		} // TODO WARN
		$funcName = strtolower( $funcName );

		if ( $funcName{0} === '\\' ) {
			$funcName = \substr( $funcName, 1 );
		}

		return isset( self::$internalsMapCallableParam[ $funcName ] );
	}

	public function isAdded( $funcName ) {
		$funcName = strtolower( $funcName );

		// dont strip for user functions!
		//if($funcName{0} === '\\') $funcName = \substr($funcName, 1);
		return isset( $this->addedFunctions[ $funcName ] );
	}

	public function isAddedOrInternalFunction( $funcName ) {
		return isset( self::$internalsMapCallableParam[ $funcName ] )
		       || isset( $this->addedFunctions[ $funcName ] );
	}

	public function isAddedFunction( $funcName ) {
		$funcName = strtolower( $funcName );

		// TODO check for ClassLikes too!

		return $this->isInternal( $funcName ) || $this->isAdded( $funcName );
	}

	/**
	 * Add a function or closure or method
	 *
	 * @param $fullyQualifiedName
	 * @param Node\FunctionLike $function
	 */
	public function addFunctionLike( $fullyQualifiedName, Node\FunctionLike $function ) {
		$fullyQualifiedName = strtolower( $fullyQualifiedName );
		$returnType         = $function->getReturnType(); // PHP7 return `function() : Type { ...}`, its super strong

		$fi                                          = new FunctionInfo( null );
		$fi->hasCallableParam                        = self::functionLikeHasCallbackParameter( $function );
		$fi->returnType                              = ( is_string( $returnType ) && $returnType ) ? $returnType : null; // resolve later if null
		$this->addedFunctions[ $fullyQualifiedName ] = $fi;


		if ( $function instanceof Node\Stmt\ClassMethod ) {
			list( $class, $method ) = explode( '::', $fullyQualifiedName );

			if ( ! isset( $this->mapMethodNameToClasses[ $method ] ) ) {
				$this->mapMethodNameToClasses[ $method ] = [];
			}
			if ( ! in_array( $class, $this->mapMethodNameToClasses[ $method ] ) ) {
				$this->mapMethodNameToClasses[ $method ][] = $class;
			}
		}
	}


	public function addClassLike( $className, $classInterfaceTrait, $extends = '' ) {
		$className                              = strtolower( $className );
		$this->addedClasses[ $className ]       = new ClassInfo();
		$this->addedClasses[ $className ]->type =
			[ T_CLASS => "class", T_INTERFACE => "interface", T_TRAIT => "trait" ][ $classInterfaceTrait ];

		if ( ! is_string( $extends ) ) {
			throw new \InvalidArgumentException( "extends must be string" );
		}
		$this->addedClasses[ $className ]->extends = $extends;
	}


	/** @var VariableInfo[] */
	public $globalVariables = [];

	private function registerGlobalVariable( $name, VariableInfo $info ) {
		if ( ! isset( $this->globalVariables[ $name ] ) ) {
			$this->globalVariables[ $name ] = clone $info;
		} else {
			$this->globalVariables[ $name ]->maybeUpgradeFrom( $info );
		}

		return $this->globalVariables[ $name ];
	}

	/**
	 * @param string $name
	 *
	 * @return VariableInfo
	 */
	public function requestGlobalVariable( $name ) {
		if ( ! isset( $this->globalVariables[ $name ] ) ) {
			$this->globalVariables[ $name ]           = new VariableInfo();
			$this->globalVariables[ $name ]->isGlobal = true;
		}

		return $this->globalVariables[ $name ];
	}

	/**
	 * @param $funcName
	 * @param VariableInfo[] $variables
	 * @param $returnType
	 */
	public function addFunctionDetails( $funcName, array &$variables, $returnType ) {
		$funcName = strtolower( $funcName );
		if ( ! isset( $this->addedFunctions[ $funcName ] ) ) {
			print_r( $this->addedFunctions );
			throw new \RuntimeException( "unknown func $funcName" );
		}

		if($returnType === null)
			$returnType = '';

		if(!is_string($returnType)) {
			var_dump($returnType);
			throw new \RuntimeException( "$funcName: returnType name must be string $returnType" );
		}

		foreach ( $variables as $name => $var ) {
			if ( $var->isGlobal ) {
				$variables[ $name ] = $this->registerGlobalVariable( $name, $variables[ $name ] );
			}
		}

		$this->addedFunctions[ $funcName ]->variables  += $variables;
		$this->addedFunctions[ $funcName ]->returnType =
			VariableInfo::strongerType( $this->addedFunctions[ $funcName ]->returnType, empty( $returnType ) ? 'void' : $returnType );
	}

	public $fileVariables = [];

	public function  addFileVariables($fileName, array &$variables)
	{
		$this->fileVariables[$fileName] = $variables;
	}


	/**
	 * @param string $className
	 * @param VariableInfo[] $properties
	 */
	public function addClassProperties( $className, array $properties ) {
		$className = strtolower( $className );
		if ( ! isset( $this->addedClasses[ $className ] ) ) {
			throw new \RuntimeException( "unknown class $className" );
		}
		$this->addedClasses[ $className ]->properties += $properties;

		foreach ( $properties as $name => $info ) {
			if ( ! isset( $this->mapPropertyNameToClasses[ $name ] ) ) {
				$this->mapPropertyNameToClasses[ $name ] = [];
			}
			$this->mapPropertyNameToClasses[ $name ][] = $className;
		}
	}

	public static function functionLikeHasCallbackParameter( Node\FunctionLike $func ) {
		foreach ( $func->getParams() as $i => $p ) {
			// dont guess if we have a type
			if ( empty( $p->type ) ? self::mightBeCallableParameterName( $p->name ) : ( $p->type === 'callable' ) ) {
				return $i;
			}
		}

		return false;
	}


	private function getExpressionType( Node\Expr $expr, Node\FunctionLike $context ) {
		if ( $expr instanceof Node\Expr\Variable ) {
			if ( ( $vars = $context->getAttribute( 'variables' ) ) ) {
				if ( isset( $vars[ $expr->name ] ) ) {
					return $vars[ $expr->name ]->type;
				}
			}

			print_r( $context );
			debug_print_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
			throw new Error( "can't resolve variable type", $expr, $context, $this->lastFile );

			print_r( $context );


		} elseif ( $expr instanceof Node\Expr\StaticCall ) {
			return $this->getReturnClass( $expr->class->toString() . '::' . $expr->name );
		} elseif ( $expr instanceof Node\Expr\Isset_ ) {
			return self::BOOL;
		} elseif ( $expr instanceof Node\Expr\Ternary ) {
			$types = [
				self::getExpressionType( $expr->if, $context ),
				self::getExpressionType( $expr->else, $context )
			];
			if ( $types[0] === $types[1] ) {
				return $types[0];
			}
			$types = array_filter( $types );

			return implode( '|', $types );
		} elseif ( $expr instanceof Node\Expr\ArrayDimFetch ) {

			// TODO find typed arrays
			if ( $expr->var->name === 'data' ) {
				return 'mixed'; // if the array name is $data
			}

			//return '';
		} elseif ( $expr instanceof Node\Expr\ConstFetch ) {
			if ( $expr->name->toString() === 'null' ) {
				return 'null';
			}
		} elseif ( $expr instanceof Node\Expr\Variable ) {
			print_r( $this->variables );
			exit;
		} else {
			print_r( $expr );
		}
		print_r( $expr );
		throw new Error( "unknown expression type", $expr, $context, $this->lastFile );
	}

	// TODO use DocComment
	public function functionLikeGetReturnType( Node\FunctionLike $func ) {
		if ( $func->getReturnType() ) {
			return $func->getReturnType();
		}

		// TODO find all return types and concat them with |
		$found = Traversing::find( $func->getStmts(), function ( $node ) use ( $func, &$type ) {
			if ( $node instanceof Node\Stmt\Return_ ) {
				if ( ! $node->expr ) {
					return 'void';
				}

				$type = $this->getExpressionType( $node->expr, $func );
				if ( $type ) {
					return $type;
				}
			}

			return false;
		} );

		if ( $found ) {
			return $type;
		}


		return false;
	}


	/**
	 * @param string $fullyQualifiedName
	 *
	 * @return string
	 */
	public function getReturnClass( $fullyQualifiedName ) {
		$fullyQualifiedName = strtolower( $fullyQualifiedName );

		if ( ! isset( $this->addedFunctions[ $fullyQualifiedName ] ) ) {
			//print_r($this->addedFunctions['\\bbpress']);
			throw new \RuntimeException( "unknown func $fullyQualifiedName" );
		}

		// resolve return type
		$fi = $this->addedFunctions[ $fullyQualifiedName ];
		if ( $fi->returnType === null ) {
			$fi->returnType = self::functionLikeGetReturnType( $fi->func );
		}

		return $fi->returnType;
	}


	public function getFunctionReturnType( $fullyQualifiedName ) {
		$fullyQualifiedName = strtolower( $fullyQualifiedName );

		if ( ! isset( $this->addedFunctions[ $fullyQualifiedName ] ) ) {
			//print_r($this->addedFunctions['\\bbpress']);
			throw new \RuntimeException( "unknown func $fullyQualifiedName" );
		}

		return $this->addedFunctions[ $fullyQualifiedName ]->returnType;
	}
}
