<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 10.10.2017
 * Time: 17:50
 */

namespace Calltree\Static_;

use PhpParser\Node;
use \PhpParser\PrettyPrinter;

class toStr {
	/**
	 * @param Node|Node\Exp|Node[] $expr
	 *
	 * @return string
	 */
	static function expr($expr) {
		if(!$expr) return '';
		if(is_string($expr)) return $expr;
		if(is_numeric($expr)) return "$expr";

		$prettyPrinter = new PrettyPrinter\Standard;
		$l = is_array($expr) ? reset($expr)->getLine() : $expr->getLine();
		return (($expr instanceof Node\Expr ) ? $prettyPrinter->prettyPrintExpr($expr) : $prettyPrinter->prettyPrint( is_array($expr) ? $expr : [$expr])) . " :$l";
	}
}