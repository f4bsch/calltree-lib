<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 12.10.2017
 * Time: 09:53
 */

namespace Calltree;

use Calltree\Static_\NodeVisitor\Manipulating\ProfilerInjector;
use Calltree\Static_\ProjectPHPFile;
use Calltree\Static_\ProjectProcessor;

/**
 * Inject profiler code
 * @package Calltree
 */
class ProjectProfilerInjector extends ProjectProcessor {

	public function injectToProject( $outputPath ) {
		$this->setOutputPath($outputPath);

		$slug = basename( $this->projectPath );

		$projectFileNames    = self::list_files( $this->projectPath );
		$projectPhpFileNames = self::phpFilesOnly( $projectFileNames );
		$projectNonPhpFileNames = array_diff($projectFileNames, $projectPhpFileNames);

		$this->copyFiles($projectNonPhpFileNames);

		$injectingVisitor = new ProfilerInjector();

		foreach ( $projectPhpFileNames as $phpFileName ) {
			$phpFile = new ProjectPHPFile( $phpFileName );
			$phpFile->traverseWith($injectingVisitor, true);
			$outFileName = self::mapOutPath($phpFileName);
			$this->mkdir(dirname($outFileName));
			$phpFile->generate()->write($outFileName);
		}
	}
}