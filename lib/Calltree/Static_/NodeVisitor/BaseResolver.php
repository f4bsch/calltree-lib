<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 08.10.2017
 * Time: 18:18
 */

namespace Calltree\Static_\NodeVisitor;


use Calltree\Static_\FunctionCollector;
use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitor\NameResolver;


/**
 * This is the base class for all our resolvers
 * It comes with a namespace, class and function context
 * @package Calltree\Static_\NodeVisitor
 */
class BaseResolver extends NameResolver {
	/** @var  FunctionLike[] */
	private $functionStack = [];

	/** @var FunctionLike */
	protected $functionLike = null;

	/** @var ClassLike */
	protected $classLike = null;

	const CALLING_FROM_MAIN_CONTEXT = 'MAIN_CONTEXT';

	/**Error tracking
	 * @var string
	 */
	public $fileName = '';

	public function __construct(FunctionCollector $collector) {
		parent::__construct( null, [ 'preserveOriginalNames' => false ] );
		$this->functionCollector = $collector;
	}

	public function enterNode( Node $node ) {
		parent::enterNode( $node );

		if ( $node instanceof Node\FunctionLike ) {
			$this->functionStack[] = $node;
			$this->functionLike    = $node;
		} elseif ( $node instanceof Node\Stmt\ClassLike ) {
			$this->classLike = $node;
		}
	}


	public function leaveNode( Node $node ) {
		if ( $node === $this->functionLike ) {
			array_pop( $this->functionStack );
			$this->functionLike = end( $this->functionStack );
		} elseif ( $node === $this->classLike ) {
			$this->classLike = null; // no nested classes, phew
		}
		parent::leaveNode( $node );
	}

	protected function getCurrentClassLikeName() {
		if ( ! $this->classLike ) {
			throw new \LogicException( "not in class context" );
		}
		$nsPrefix = $this->namespace ? ( $this->namespace->toString() . '\\' ) : '';

		return $nsPrefix . $this->classLike->name;
	}


	/**
	 * @return string
	 */
	protected function getCurrentFunctionLikeName() {
		// TODO cache for current ?
		return $this->functionLike ?
			$this->fullFunctionName( $this->namespace, $this->classLike, $this->functionLike ) :
			self::CALLING_FROM_MAIN_CONTEXT;
	}


	/**
	 * @param Name|null $namespace
	 * @param ClassLike|null $class
	 * @param FunctionLike $functionLike
	 *
	 * @return string
	 */
	protected function fullFunctionName( $namespace, $class, FunctionLike $functionLike ) {
		if ( ! $functionLike ) {
			throw new Error( "fullFunctionName called without function" );
		}

		$nsPrefix = $namespace ? ( $namespace->toString() . '\\' ) : '';

		if ( $functionLike instanceof Closure ) {
			return "Closure@" . $functionLike->getLine();
		}

		// for functions declared in methods, dont use the class!
		if($functionLike instanceof  Function_) {
			$class = null;
		}

		/** @var Function_ $functionLike */
		if ( empty( $functionLike->name ) ) { // this should not happen
			throw new \RuntimeException( "function name empty", $functionLike );
		}

		return $nsPrefix . ( $class ? ( $class->name . '::' ) : '' ) . $functionLike->name;
	}


	/**
	 * @var FunctionCollector
	 */
	public $functionCollector;

	protected function nameToString( Name $name ) {

		$str = $name->toString();
		if ( $name->isFullyQualified() ) {
			return $str;
		}

		$str = strtolower( $str );

		if ( $str === 'self' ) {
			// TODO expect 'parent' 'static'
			return $this->getCurrentClassLikeName();
		}


		// TODO give type hint about name!

		// TODO ?!
		//$nsn       = $name->getAttribute( 'namespacedName' );
		//$fn        = $nsn ? $nsn->toString() : $bindExpr->name->toString();

		if ( ! $this->functionCollector->collected() ) {
			throw new \RuntimeException( "nameToString failed. function collector did not collect yet" );
		}

		// maybe add namespace
		if ( $this->namespace && ! $this->functionCollector->isKnownClass( $name ) ) {
			$name = $this->namespace->toString() . '\\' . $str;
		}

		return $name;
	}

	function error( $msg, $expr ) {
		$fn = is_file( $this->fileName ) ? realpath( $this->fileName ) : $this->fileName;

		return new Error( $msg . " (file $fn:{$expr->getLine()})", $expr );
	}
}