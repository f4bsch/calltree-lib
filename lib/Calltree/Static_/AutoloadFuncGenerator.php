<?php
namespace Calltree\Static_;


use PhpParser\ParserFactory;

class AutoloadFuncGenerator {

	//public static function generate( $fileMapStats, $prefix = '') {
	//	return self::generateCode(self::generateMap($fileMapStats, $prefix));
	//}

	public static function generateMap( $fileMapStats, $prefix = '' ) {
		$uniqueSymbols = [];

		foreach ( $fileMapStats as $fileName => $stats ) {
			if ( $prefix && strncmp( $fileName, $prefix, strlen( $prefix ) ) !== 0 ) {
				continue;
			}

			if ( ! $stats->isAutoloadable ) {
				continue;
			}

			foreach ( $stats->symbols as $symbol ) {
				if ( $symbol === 'WP_Object_Cache' ) {
					continue;
				}

				if ( isset( $uniqueSymbols[ $symbol ] ) ) {
					throw new \RuntimeException( "duplicate symbol $symbol!" );
				}
				$uniqueSymbols[ $symbol ] = $fileName;
			}
		}

		return $uniqueSymbols;
	}

	public static function generateCode( $map, $pathPrefixLen, $pathPrefixConstantName ) {

		//$closures = new Closure( [ 'params' => [ new Param( 'symbol' ) ], 'stmts' => $autoloadStmts ] );
		//new FuncCall( new \PhpParser\Node\Name( 'spl_autoload_register' ), $closures );

		foreach ( $map as &$f ) {
			$f = substr( $f, $pathPrefixLen );
		}
		ob_start();
		echo "\n spl_autoload_register( function(\$symbol) { \n";
		echo "static \$map = " . var_export( $map, true ) . ";\n";
		echo '$symbol = strtolower($symbol); ';
		echo 'if(isset($map[$symbol])) require_once ' . $pathPrefixConstantName . '.$map[$symbol];' . "\n";
		echo "} );";
		$code = ob_get_clean();

		$parser = ( new ParserFactory )->create( ParserFactory::PREFER_PHP7 );

		return $parser->parse($code);
	}
}
