<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 07.10.2017
 * Time: 18:06
 */

namespace Calltree;

/*
 *
 * todo: need dynamic transition data
 * make method static?
 */

use Calltree\Static_\CodeSeparator;
use Calltree\Static_\FunctionCollector;
use Calltree\Static_\NodeVisitor\CollectingUtils;
use Calltree\Static_\NodeVisitor\Error;
use Calltree\Static_\ProjectPHPFile;
use Calltree\Static_\ProjectProcessor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;

class ProjectFixer extends ProjectProcessor {

	/**
	 * The main file that always loads first (relative)
	 * For WordPress it should be wp-load.php, for plugins the main file
	 * @var string
	 */
	private $mainFileName = '';

	/**
	 * paths inside the project to ignore
	 * @var array
	 */
	private $excludePaths = [];

	/**
	 * external deps
	 * @var array
	 */
	private $includePaths = [];

	/**
	 * Don't wrap these functions with static classes
	 * e.g. wp_cache_*
	 * @var array
	 */
	private $keepFunctions = [];

	public function __construct( $projectPath, $mainFile ) {
		parent::__construct($projectPath);
		$this->mainFileName = $mainFile;
	}

	public function addIncludePath( $path ) {
		$this->includePaths[ $path ] = $path;
	}



	public function fixProject( $outputPath ) {


		$this->outputPath = $outputPath;

		if ( empty( $this->outputPath ) ) {
			throw new \RuntimeException( 'not output path given' );
		}

		if ( ! ( is_dir( $this->outputPath ) || mkdir( $this->outputPath, 0777, true ) ) ) {
			throw new \RuntimeException( "can't create directory '$this->outputPath''" );
		}


		// normalize paths
		$projPath = $this->projectPath;
		$outPath  = $this->outputPath = realpath( $this->outputPath );


		$slug = basename( $projPath );

		// check main file
		$mainFilePath    = "$projPath/$this->mainFileName";
		$mainFileOutPath = $this->mapOutPath( $mainFilePath );
		if ( ! is_file( $mainFilePath ) ) {
			throw new \RuntimeException( "can't find $mainFilePath!" );
		}


		// delete main file, just in case
		file_exists( $mainFileOutPath ) && unlink( $mainFileOutPath );



		// get project files
		$projectFileNames    = self::list_files( $projPath );
		$projectPhpFileNames = self::phpFilesOnly( $projectFileNames );
		$numProjectPhpFiles  = count( $projectPhpFileNames );


		// get inc files
		$incPhpFileNames = [];
		foreach ( $this->includePaths as $incPath ) {
			if(!is_dir($incPath))
				throw new \RuntimeException("include path $incPath not  found");
			$incPath = realpath($incPath);
			if(strncmp($incPath, $projPath, strlen($projPath)) === 0) {
				throw new \LogicException("include path $incPath within $projPath");
			}
			$this->logMsg( "incPath '$incPath'" );
			$incFiles = self::phpFilesOnly( self::list_files( $incPath ) );
			if(empty($incFiles)) {
				throw new \RuntimeException("not files in include path $incPath");
			}
			$incPhpFileNames = array_merge( $incPhpFileNames, $incFiles );
		}
		$incPhpFileNames = array_unique( $incPhpFileNames );
		$numIncFiles     = count( $incPhpFileNames );

		$allPhpFileNames = array_unique( array_merge( $projectPhpFileNames, $incPhpFileNames ) );


		// TODO
		// create a file where we put EverCallees
		// an EverCallee is a function that is always called


		/** @var ProjectPHPFile[] $allPhpFiles */
		$allPhpFiles = [];


		$nonAutoloadables = [];

		$functionCollector = new FunctionCollector();


		// 1.1. pass: collect
		$this->logMsg( "collecting in $numProjectPhpFiles project + $numIncFiles include files ..." );
		try {
			foreach ( $allPhpFileNames as $phpFileName ) {
				$phpFile = new ProjectPHPFile( $phpFileName );
				$phpFile->collect( $functionCollector );
				$allPhpFiles[ $phpFileName ] = $phpFile;
			}
			$functionCollector->collected( true );
		} catch ( Error $e ) {
			$this->logMsg( "ERROR: " . strtok( $e->getErrorMessage(), "\n" ) );

			return;
		}
		file_put_contents( $outPath . '/method2Classes.json', json_encode( $functionCollector->mapPropertyNameToClasses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );


		// 1.2. pass resolve types
		$this->logMsg( "resolving types ..." );
		try {
			foreach ( $allPhpFiles as $phpFile ) {
				$phpFile->resolveTypes( $functionCollector );
			}
			file_put_contents( $outPath . '/classes.json', json_encode( $functionCollector->addedClasses, JSON_PRETTY_PRINT ) );
			file_put_contents( $outPath . '/classes.printr', print_r( $functionCollector->addedClasses, true ) );
			file_put_contents( $outPath . '/functions_preRef.json', json_encode( $functionCollector->serialize(), JSON_PRETTY_PRINT ) );
			file_put_contents( $outPath . '/functions_preRef.printr', print_r(  $functionCollector->serialize(), true ) );
		} catch ( Error $e ) {
			$e->getErrorMessage();
			$this->logMsg( "ERROR while resolving types: " . strtok( $e->getErrorMessage(), "\n" ) );

			return;
		}


		// 1.3. pass resolve type refs
		$this->logMsg( "resolving type refs ..." );
		try {
			$functionCollector->resolveTypeRefs();
			$this->logMsg( $outPath . '/functions.json' );
			file_put_contents( $outPath . '/functions.json', json_encode( $functionCollector->serialize(), JSON_PRETTY_PRINT ) );
			file_put_contents( $outPath . '/functions.printr', print_r(  $functionCollector->serialize(), true ) );
		} catch ( Error $e ) {
			$this->logMsg( "ERROR: " . strtok( $e->getErrorMessage(), "\n" ) );

			return;
		}

		file_put_contents( $outPath . '/fileVariables.printr', print_r( $functionCollector->fileVariables, true ) );



		// 1.4. finding dependencies
		$this->logMsg( "finding dependencies ..." );
		$allDeps = [];

		foreach ( $projectPhpFileNames as $phpFileName ) {
			$phpFile = $allPhpFiles[ $phpFileName ];
			try {
				$deps = $phpFile->findDependencies( $functionCollector );


				//$allDeps = array_replace_recursive( $allDeps, $deps );
				foreach($deps as $group => $triggers) {
					if(!isset($allDeps[$group]))
						$allDeps[$group] = [];
					foreach($triggers as $trigger => $triggerings) {
						if(!isset($allDeps[$group][$trigger]))
							$allDeps[$group][$trigger] = [];
						$allDeps[$group][$trigger] += $triggerings;
					}
				}

			} catch ( Error $e ) {
				$e->getErrorMessage();
				throw $e;
				//$this->logMsg( "WARNING: " . strtok( $e->getErrorMessage(), "\n" ) );
			}
		}

		file_put_contents( $outPath . '/deps.json', json_encode( $allDeps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );


		$this->logMsg( "... done" );
		//exit;

		$alwaysFires = [
			'common'   => [
				'plugins_loaded',
				'init',
				'wp_roles_init',
				'set_current_user',
				'map_meta_cap',
				'setup_theme',
				'after_setup_theme',
				'template_redirect',
				'template_include',
				'body_class'
			],
			'admin'    => [
				"admin_menu",
				"admin_init",
				"admin_head",
				"admin_notices",
				"custom_menu_order",
				"menu_order"
			],
			'frontend' => [
				'parse_query',
				'widgets_init',
				'wp_enqueue_scripts',
				'wp_head',
				'wp_footer',
				'pre_get_posts',
				'wp_title'
			]
		];


		// map_meta_cap redirect_canonical the_title posts_request

		$everCallees = [ 'common' => [], 'admin' => [], 'frontend' => [], 'rest' => [], 'ajax' => [] ];
		$everFires = $everCallees;

		$everCalleesAll = [];

		foreach ( $alwaysFires as $groupName => $af ) {

			// add ever callees (functions registered to hooks that always fire)
			// hooks -> func
			foreach ( $af as $hookTag ) {
				$everFires[$groupName][$hookTag] = $hookTag;
				if ( ! empty( $allDeps['hookFunc'][ $hookTag ] ) ) {
					foreach ( $allDeps['hookFunc'][ $hookTag ] as $func ) {
						$everCallees[$groupName][ $func ] = $func;
						$everCalleesAll[$func] = $func;
					}
				}
			}

			//only do func -> func on first level (because the transition probabilty is not 100%)
			// func -> func
			foreach ($everCallees[$groupName] as $func) {
				if ( ! empty( $allDeps['funcFunc'][ $func ] ) ) {
					foreach ( $allDeps['funcFunc'][ $func ] as $func2 ) {
						$everCallees[ $groupName ][ $func2 ] = $func2;
					}
				}
			}

			// func -> hook
			foreach ($everCallees[$groupName] as $func) {
				if ( ! empty( $allDeps['funcHook'][ $func ] ) ) {
					foreach ( $allDeps['funcHook'][ $func ] as $hookTag ) {
						$everFires[$groupName][ $hookTag ] = $hookTag;
					}
				}
			}

/*
			// hook -> func
			foreach ($everFires[$groupName] as $hookTag) {
				if ( ! empty( $allDeps['hookFunc'][ $hookTag ] ) ) {
					foreach ( $allDeps['hookFunc'][ $hookTag ] as $func ) {
						$everCallees[$groupName][ $func ] = $func;
						$everCalleesAll[$func] = $func;
					}
				}
			}
*/


/*
			// func -> func
			foreach ($everCallees[$groupName] as $func) {
				if ( ! empty( $allDeps['funcFunc'][ $func ] ) ) {
					foreach ( $allDeps['funcFunc'][ $func ] as $func2 ) {
						$everCallees[ $groupName ][ $func2 ] = $func2;
					}
				}
			}
*/

		}

		echo count($everCalleesAll)." ever callees:\n";
		//print_r($everCallees);
		//print_r($everFires);

		// bbp_admin_init, bbp_init bbp_admin_menu must appear here! TODO


		// 2.0. pass: find EverCallees
		// TODO

		$codeSeparator = new CodeSeparator( $slug, $functionCollector );

		$codeSeparator->addFunctionGroup('ever-common', $everCallees['common']);
		$codeSeparator->addFunctionGroup('ever-admin',$everCallees['admin']);
		$codeSeparator->addFunctionGroup('ever-frontend',$everCallees['frontend']);

		$fixedFiles = [];

		// 2.1. pass: do code separation
		foreach ( $projectPhpFileNames as $phpFileName ) {
			$phpFile     = $allPhpFiles[ $phpFileName ];
			$relFilePath = substr( $phpFileName, strlen( $projPath ) );

			$outDir = dirname( $outputPath . $relFilePath );
			is_dir( $outDir ) || mkdir( $outDir, 0777, true );

			if ( $phpFile->needsSeparation( $cannotAutoload ) ) {
				if ( $cannotAutoload ) {
					$nonAutoloadables[ $phpFileName ] = $phpFileName;
				}

				//$this->logMsg( "$relFilePath needs sparation, hasResidingCode = $cannotAutoload" );
			}
			$codeSeparator->separate( $phpFile, $outputPath, $relFilePath );
		}

		$codeSeparator->generateFunctionGroupFiles($outputPath);

		file_put_contents( $outPath . '/funcMap.json', json_encode( $codeSeparator->mapFunctions2Methods, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		file_put_contents( $outPath . '/class2FileMap.json', json_encode( $codeSeparator->mapClasses2Files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		/** @var ProjectPHPFile[] $fixedFiles */
		$fixedFiles = array_merge( $fixedFiles, $codeSeparator->newFiles );


		$ignoreIncludes = $codeSeparator->genIncludeReplaceExcludes(basename($mainFileOutPath));

		// function_exists() does not work with methods!
		$codeSeparator->mapFunctions2Methods['function_exists'] = 'is_callable';

		// 2.2 replace functions
		foreach ( $fixedFiles as $file ) {
			$file->replaceFunctions( $codeSeparator->mapFunctions2Methods, $functionCollector );

			$file->replaceIncludes( $slug . '__include', $ignoreIncludes );

			$file->generate()->write();
		}


		// 2.3 generate autoloader

		$mainFileFixed = new ProjectPHPFile( $mainFileOutPath );
		$autoloader    = $codeSeparator->generateAutoloadStmt();

		$smartInclude = $codeSeparator->generateSmartIncludeFunction();


		echo count( $codeSeparator->autoloadable ) . ' vs ' . count( $codeSeparator->nonAutoloadable );


		$mainFileFixed->insertTop( $autoloader )
		              ->insertTop( $smartInclude )
		              ->write();


		// 2.4 replace includes


	}
}