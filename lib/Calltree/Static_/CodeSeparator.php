<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 10.10.2017
 * Time: 10:52
 */

namespace Calltree\Static_;


use Calltree\Static_\FunctionCollector;
use Calltree\Static_\NodeVisitor\SeparateDeclarationsVisitor;
use Calltree\Static_\Traversing;
use PhpParser\Builder\Class_;
use PhpParser\BuilderFactory;
use PhpParser\Comment;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\ParserFactory;


class CodeSeparator {
	/** @var ProjectPHPFile[] */
	public $newFiles = [];
	public $mapFunctions2Methods = [];

	public $mapClasses2Files = [];

	private $functionCollector;

	public $projectNamespace = '';

	public $autoloadable = [];
	public $nonAutoloadable = [];

	function __construct( $namespace, FunctionCollector $function_collector ) {
		$this->projectNamespace  = $namespace;
		$this->functionCollector = $function_collector;
	}

	private $functionGroups = [];

	/** @var Class_[] */
	private $functionGroupClassesBuilders = [];

	public function addFunctionGroup( $tag, $funcs ) {
		$this->functionGroups[ $tag ] = array_flip( array_values( $funcs ) );

		$factory                                   = new BuilderFactory;
		$this->functionGroupClassesBuilders[ $tag ] = $factory->class( self::fileNameToClassName( $tag ) );
	}

	private function isInGroup( $func ) {
		foreach ( $this->functionGroups as $name => $group ) {
			if ( isset( $group[ $func ] ) ) {
				return $name;
			}
		}

		return false;
	}

	public function generateFunctionGroupFiles( $outDir ) {
		foreach ( $this->functionGroupClassesBuilders as $name => $classBuilder ) {
			/** @var Class_ $classBuilder */
			$class                                  = $classBuilder->getNode();
			$fn                                     = ( "$name.class-{$class->name}.php" );
			$this->newFiles[]                       = ProjectPHPFile::fromStmts( [ $class ], $outDir . '/' . $fn );
			$this->mapClasses2Files[ $class->name ] = $fn;
		}

	}

	/**
	 * @param ProjectPHPFile $phpFile
	 * @param $outputPath
	 * @param $relFilePath
	 */
	public function separate( ProjectPHPFile $phpFile, $outputPath, $relFilePath ) {
		$outDir                   = dirname( $outputPath . $relFilePath );
		$basenameWithoutExtension = substr( basename( $relFilePath ), 0, - 4 );
		$newFilePath              = $outDir . "/$basenameWithoutExtension.php";

		$separator     = new SeparateDeclarationsVisitor( $this->functionCollector );
		$residingStmts = $phpFile->traverseWith( $separator );



		$needSeparation = $phpFile->needsSeparation( $cantAutoload );


		// classes
		foreach ( $separator->classLikes as $classLike ) {
			$stmts = $separator->uses;
			$stmts[] = $classLike;

			$fn    = $needSeparation
				? ( $outDir . "/$basenameWithoutExtension.class-{$classLike->name}.php" )
				: $newFilePath;

			$this->newFiles[] = ProjectPHPFile::fromStmts( $stmts, $fn );


			// TODO throw if existing
			$this->mapClasses2Files[ $classLike->name ] = substr( $fn, strlen( $outputPath ) + 1 );


			if ( $needSeparation ) {
				$nop = new Nop();
				$fn  = basename( $fn );
				$doc = new Comment\Doc( "\n/**\n * class {@see $classLike->name}\n moved to file $fn\n*/\n" );
				$nop->setDocComment( $doc );
				$residingStmts[] = $nop;
			}
		}

		$mapFunctionsToMethods = [];

		$maxStmtsPerClass = 100;

		// functions
		if ( ! empty( $separator->functions ) ) {
			$factory = new BuilderFactory;


			$stmtCounter = 0;
			$classIdx    = 0;

			$className = $this->fileNameToClassName( substr( $relFilePath, 0, - 4 ) ) . sprintf( '_LIB%02d', $classIdx );
			$newClass  = $factory->class( $className );

			foreach ( $separator->functions as $function ) {
				$newMethod = $factory->method( $function->name )->makeStatic()->makePublic()
				                     ->addParams( $function->getParams() )
				                     ->addStmts( $function->getStmts() );
				if ( ( $doc = $function->getDocComment() ) ) {
					$newMethod->setDocComment( $doc );
				}

				$group = self::isInGroup( $function->name );
				if ( $group ) {
					if ( count( $function->getStmts() ) > 0 ) {
						$mapFunctionsToMethods[ $function->name ] = self::fileNameToClassName( $group ) . "::$function->name";
						$this->functionGroupClassesBuilders[ $group ]->addStmt( $newMethod );
					} else {
						echo "empty function $function->name removed!\n";
					}
				} else {
					$stmtCounter                              += count( $function->getStmts() );
					$mapFunctionsToMethods[ $function->name ] = "$className::$function->name";
					$newClass->addStmt( $newMethod );


					if ( $stmtCounter > $maxStmtsPerClass ) {
						$stmts                                = array_merge( $separator->uses, [ $newClass->getNode() ] );
						$fn                                   = ( $outDir . "/$basenameWithoutExtension.class-{$className}.php" );
						$this->newFiles[]                     = ProjectPHPFile::fromStmts( $stmts, $fn );
						$this->mapClasses2Files[ $className ] = substr( $fn, strlen( $outputPath ) + 1 );


						$classIdx ++;
						$stmtCounter = 0;
						$className   = $this->fileNameToClassName( substr( $relFilePath, 0, - 4 ) ) . sprintf( '_LIB%02d', $classIdx );
						$newClass    = $factory->class( $className );
					}
				}
			}

			// always put static function class into separate file
			$stmts                                = array_merge( $separator->uses, [ $newClass->getNode() ] );
			$fn                                   = ( $outDir . "/$basenameWithoutExtension.class-{$className}.php" );
			$this->newFiles[]                     = ProjectPHPFile::fromStmts( $stmts, $fn );
			$this->mapClasses2Files[ $className ] = substr( $fn, strlen( $outputPath ) + 1 );


			$nop = new Nop();
			$fn  = basename( $fn );
			$doc = new Comment\Doc( "\n/**\n * functions moved to class {@see $className}\n * file $fn\n*/\n" );
			$nop->setDocComment( $doc );
			$residingStmts[] = $nop;
		}


		if ( empty( $separator->classLikes ) || $needSeparation ) {

			if ( Traversing::find( $residingStmts, function ( $node ) {
				return $node instanceof Function_;
			} )) {
				echo "$relFilePath still has functions, will NOT autoload!\n";
				$cantAutoload = true;
			}


			// residing code (original file name)
			$this->newFiles[] = ProjectPHPFile::fromStmts( $residingStmts, $newFilePath );
		}


		if ( $cantAutoload ) {
			$this->nonAutoloadable[] = trim( $relFilePath, '/' );
		} else {
			$this->autoloadable[] = trim( $relFilePath, '/' );
		}


		// TODO throw error on dupes
		$this->mapFunctions2Methods += $mapFunctionsToMethods;
	}


	private function fileNameToClassName( $fileName ) {
		$fileName = trim( str_replace( '\\', '/', $fileName ), '/' );

		$fileName = str_replace( '/', '_', $fileName );
		$fileName = str_replace( '.', '', $fileName );

		$className = substr( $fileName, 0 ); // rm ext?

		$className = $this->projectNamespace . "_" . $className;

		// dash -> camelCase
		$className = str_replace( ' ', '', ucwords( str_replace( '-', ' ', $className ) ) );

		return $className;
	}


	public function generateAutoloadStmt() {

		$pathPrefixLen = 0;
		//$closures = new Closure( [ 'params' => [ new Param( 'symbol' ) ], 'stmts' => $autoloadStmts ] );
		//new FuncCall( new \PhpParser\Node\Name( 'spl_autoload_register' ), $closures );

		$map = $this->mapClasses2Files;

		foreach ( $map as &$f ) {
			$f = substr( $f, $pathPrefixLen );
		}
		$map = array_combine( array_map( 'strtolower', array_keys( $map ) ), array_values( $map ) );

		ob_start();
		echo "<?php spl_autoload_register( function(\$symbol) {";
		echo "static \$map = " . var_export( $map, true ) . ";";
		echo '$symbol = strtolower($symbol); ';
		echo 'if(isset($map[$symbol])) require_once ' . $this->projectNamespace . '_rootPath.$map[$symbol];';
		echo "});";
		$code = ob_get_clean();


		$parser = ( new ParserFactory )->create( ParserFactory::PREFER_PHP7 );

		$stmts = $parser->parse( $code );

		return ( $stmts );
	}

	public function generateSmartIncludeFunction() {


		$slug         = $this->projectNamespace;
		$willAutoload = var_export( array_flip( array_values( $this->autoloadable ) ), true );

		$inc     = Include_::TYPE_INCLUDE;
		$incOnce = Include_::TYPE_INCLUDE_ONCE;
		$req     = Include_::TYPE_REQUIRE;
		$reqOnce = Include_::TYPE_REQUIRE_ONCE;

		$code =
			<<<CODE
<?php
				define('{$slug}_rootPath', dirname(__FILE__).'/');
				define('{$slug}_rootPathLen', strlen({$slug}_rootPath));
				function {$slug}__include(\$__file, \$__incType) {
					static \$willAutoload = $willAutoload;
					if(isset(\$willAutoload[substr(\$__file,{$slug}_rootPathLen)])) return;
					error_log("{$slug}__include barrier break with ".substr(\$__file,{$slug}_rootPathLen));
					switch(\$__incType) {
						case {$inc}: include_once(\$__file); break;
						case {$incOnce}: include_once(\$__file); break;
						case {$req}: require(\$__file); break;
						case {$reqOnce}: require_once(\$__file); break;
					}
					// TODO need to export locals to global scope! (see stats->implicitGlobals)
				}	
CODE;


		$parser = ( new ParserFactory )->create( ParserFactory::PREFER_PHP7 );

		$stmts = $parser->parse( $code );

		return ( $stmts );
	}


	public function genIncludeReplaceExcludes( $mf ) {
		$expressions = [ "dirname(dirname(__FILE__)) . '/$mf", "dirname(__FILE__) . '/$mf" ];

		$code = '<?php';
		foreach ( $expressions as $expr ) {
			$code .= "\n$expr;";
		}

		$parser = ( new ParserFactory )->create( ParserFactory::PREFER_PHP7 );

		return $parser->parse( $code );
	}
}