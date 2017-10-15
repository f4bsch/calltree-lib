<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 07.10.2017
 * Time: 18:32
 */

namespace Calltree\Static_;


use Calltree\Cache;
use Calltree\Static_\FunctionCollector;
use Calltree\Static_\NodeVisitor\BaseResolver;
use Calltree\Static_\NodeVisitor\CallbackReplacer;
use Calltree\Static_\NodeVisitor\CallbackReplacerReplacer;
use Calltree\Static_\NodeVisitor\Cleaner;
use Calltree\Static_\NodeVisitor\CollectClassLikesAndFunctions;
use Calltree\Static_\NodeVisitor\FindHookDependencies;
use Calltree\Static_\NodeVisitor\FunctionReplacer;
use Calltree\Static_\NodeVisitor\IncludesReplacer;
use Calltree\Static_\NodeVisitor\SeparateDeclarationsVisitor;
use Calltree\Static_\NodeVisitor\TypeResolver1;
use PhpParser\BuilderFactory;
use PhpParser\Node\Stmt\Nop;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Console\Exception\LogicException;
use PhpParser\PrettyPrinter;

class ProjectPHPFile {

	private $fileName;
	private $code = null;
	private $stmts;

	/**
	 * @var CollectClassLikesAndFunctions
	 */
	private $collected = null;

	/**
	 * @var TypeResolver1
	 */
	private $resolved1 = null;

	/**
	 * @var \PhpParser\Parser
	 */
	public static $parser;


	public function __construct( $fileName ) {
		$this->fileName = $fileName;
	}

	/**
	 * Collects ClassLikes, Functions and code in the outer file scope.
	 * It populates the given FunctionCollector with the methods and function declaration in this file
	 * This is the first thing you should do.
	 *
	 * @param FunctionCollector $functionCollector
	 *
	 * @return array
	 */
	public function collect( $functionCollector ) {
		$functionCollector->lastFile = $this->fileName; // error tracking
		$this->collected             = new CollectClassLikesAndFunctions( $functionCollector );
		//
		$this->traverseWith( $this->collected );

		return $this->collected->serialize();
	}

	public function resolveTypes( $functionCollector ) {
		$functionCollector->lastFile = $this->fileName; // error tracking
		$this->resolved1             = new TypeResolver1( $functionCollector );
		$this->traverseWith( $this->resolved1 );

		//return $this->resolved1->serialize();
	}


	/**
	 * Finds coupling between functions/methods, and the WordPress Hook API Bus.
	 * It tries to find all dependencies of registered hooks and functions.
	 *
	 * Note that you have to collect() first!
	 *
	 * @param FunctionCollector $functionCollector
	 *
	 * @return array
	 */
	public function findDependencies( $functionCollector ) {
		$depFinder = new FindHookDependencies( $functionCollector );
		$this->traverseWith( $depFinder );

		return $depFinder->serialize();
	}

	/**
	 * A file needs separation if more than one of these 4 conditions is true:
	 *  - has code in global context
	 *  - has ClassLike declaration (class, interface, trait)
	 *  - has another ClassLike declaration
	 *  - has Function declaration (except Closures)
	 *
	 * A file cannot autoload if the first condition is true (global code).
	 * In this case we can still separate ClassLikes and Functions from the global code and make the
	 * separated declarations to autoload.
	 *
	 * @param bool $cannotAutoload
	 *
	 * @return bool
	 */
	public function needsSeparation( &$cannotAutoload = null ) {

		if ( ! $this->collected ) {
			throw new \LogicException( "need to collect() first" );
		}

		$hasMiscCode   = count( $this->collected->miscCode ) > 0;
		$numClassLikes = $this->collected->numClassLikes;
		$hasFunctions  = $this->collected->numNamedFunctions > 0;

		$cannotAutoload = $hasMiscCode;

		return ( $hasMiscCode + $numClassLikes + $hasFunctions ) > 1;
	}


	/**
	 * @param $stmts
	 *
	 * @return ProjectPHPFile
	 */
	public static function fromStmts( $stmts, $fileName ) {
		$pf        = new ProjectPHPFile( $fileName );
		$pf->stmts = $stmts;

		// TODO need to write out?
		$prettyPrinter = new \PhpParser\PrettyPrinter\Standard();
		$pf->code      = $prettyPrinter->prettyPrintFile( $stmts );

		//file_put_contents($fileName, $pf->code);

		return $pf;
	}

	public static function fromCode( $code, $fileName ) {
		$pf       = new ProjectPHPFile( $fileName );
		$pf->code = $code;

		return $pf;
	}

	public function write( $fileName = null ) {
		if ( $fileName === null ) {
			$fileName = $this->fileName;
		}
		if ( empty( $fileName ) ) {
			throw new \LogicException( "no file name" );
		}
		if ( empty( $this->code ) ) {
			throw new \LogicException( "no code in $this->fileName" );
		}

		//
		file_put_contents($fileName, $this->code );
		$this->code  = null;
		$this->stmts = null;
	}

	public function setCode( $code ) {
		$this->code  = $code;
		$this->stmts = null;
	}


	/**
	 * @param NodeVisitorAbstract $visitor
	 *
	 * @param bool $storeStmts
	 *
	 * @return \PhpParser\Node[]
	 */
	public function traverseWith( NodeVisitorAbstract $visitor, $storeStmts = false ) {
		$traverser = new NodeTraverser;
		if ( $visitor instanceof BaseResolver ) {
			//$visitor->functionCollector->lastFile = $this->fileName; // error tracking
			$visitor->fileName = $this->fileName;
		}
		$traverser->addVisitor( $visitor );
		$stmts = $this->parse();

		$stmts = $traverser->traverse( $stmts );

		if ( $storeStmts ) {
			$this->stmts = $stmts;
		}

		return $stmts;
	}

	private function cacheKey( $k = '' ) {
		if ( ! is_file( $this->fileName ) ) {
			return false;
		}

		return __CLASS__ . "|$k|" . $this->fileName . '|' . filemtime( $this->fileName );
	}

	public function parse( $force = false ) {
		if ( ! $force && $this->stmts ) {
			return $this->stmts;
		}

		if ( ! self::$parser ) {
			self::$parser = ( new ParserFactory )->create( ParserFactory::PREFER_PHP7 );
		}

		if ( ! $force && $this->code !== null ) {
			return self::$parser->parse( $this->getCode() );
		}

		return Cache::get()->auto( $key = $this->cacheKey( 'parse7' ), function () use ( $key ) {
			if ( ! self::$parser ) {
				self::$parser = ( new ParserFactory )->create( ParserFactory::PREFER_PHP7 );
			}

			if ( $key ) {
				echo( "parsing! $this->fileName ($key)\n" );
			}

			return self::$parser->parse( $this->getCode() );
		} );
	}

	public function getCode() {
		if ( $this->code === null ) {
			$this->code = \file_get_contents( $this->fileName );
			if ( ! \is_string( $this->code ) ) {
				throw new \RuntimeException( "cannot read $this->fileName" );
			}
		}

		return $this->code;
	}

	public function generate() {
		if ( ! is_array( $this->stmts ) ) {
			throw new \LogicException( "no stmts to generate code" );
		}
		$prettyPrinter = new PrettyPrinter\Standard();
		$this->code    = $prettyPrinter->prettyPrintFile( $this->stmts );
		$this->stmts   = null; // prevent OOM

		return $this;
	}

	public function replaceFunctions( $map, $functionCollector ) {
		$callbackReplacer = new CallbackReplacer( $map, $functionCollector );
		$callReplacer     = new FunctionReplacer( $map, $functionCollector );
		$this->traverseWith( $callbackReplacer, true );// first callbacks (strings)
		$this->traverseWith( $callReplacer, true ); // then function names


		$this->traverseWith( new Cleaner(), true );
	}


	public function replaceIncludes( $funcName, $ignoreExprs ) {
		$this->traverseWith( new IncludesReplacer( $funcName, $ignoreExprs ) );
	}

	public function insertTop( $stmts ) {
		$this->stmts = $this->parse();

		for ( $i = 0; $i < count( $stmts ); ++ $i ) {
			if ( ! ( $stmts[ $i ] instanceof Nop ) ) {
				$i ++;
				break;
			}
		}


		//array_unshift($stmts,$stmt);
		array_splice( $this->stmts, $i, 0, $stmts );
		$this->generate();

		return $this;
	}
}