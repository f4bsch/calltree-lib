<?php
namespace Calltree\Static_;

class ProjectProcessor {

	protected $projectPath = '';
	protected $outputPath = '';


	public function __construct($projectPath) {
		$this->projectPath = $projectPath;

		if ( ! is_dir( $this->projectPath ) ) {
			throw new \RuntimeException( "can't access project dir $this->projectPath" );
		}

		$this->projectPath = realpath( $this->projectPath );

		ini_set( 'memory_limit', '1G' );
	}

	protected function setOutputPath($path) {
		$this->outputPath = $path;
		if ( empty( $this->outputPath ) ) {
			throw new \RuntimeException( 'not output path given' );
		}

		if ( ! ( is_dir( $this->outputPath ) || mkdir( $this->outputPath, 0777, true ) ) ) {
			throw new \RuntimeException( "can't create directory '$this->outputPath''" );
		}

		$this->outputPath = realpath( $this->outputPath );
	}

	protected function mapOutPath( $inPath ) {
		return $this->outputPath . '/' . substr( $inPath, strlen( $this->projectPath ) );
	}


	function logMsg( $msg ) {
		echo $msg . "\n";
	}

	static function phpFilesOnly( $fileNames ) {
		return array_filter( $fileNames, function ( $f ) {
			$ext = substr( $f, strrpos( $f, '.' ) + 1 );

			return ( $ext == "php" );
		} );
	}


	static function list_files( $folder = '', $levels = 100 ) {
		if ( empty( $folder ) ) {
			return false;
		}

		if ( ! $levels ) {
			return false;
		}

		$files = array();
		if ( $dir = @opendir( $folder ) ) {
			while ( ( $file = readdir( $dir ) ) !== false ) {
				if ( in_array( $file, array( '.', '..' ) ) ) {
					continue;
				}
				if ( is_dir( $folder . '/' . $file ) ) {
					$files2 = self::list_files( $folder . '/' . $file, $levels - 1 );
					if ( $files2 ) {
						$files = array_merge( $files, $files2 );
					} else {
						$files[] = $folder . '/' . $file . '/';
					}
				} else {
					$files[] = $folder . '/' . $file;
				}
			}
		}
		@closedir( $dir );

		return $files;
	}

	protected function mkdir($dir) {
		return is_dir($dir) || \mkdir($dir, 0777, true);
	}

	protected function copyFiles($fileNames) {
		foreach ($fileNames as $fn) {
			$fno = $this->mapOutPath($fn);
			$outDirName = dirname($fno);
			$this->mkdir($outDirName);
			copy($fn, $fno);
		}

	}

}