<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 08.10.2017
 * Time: 08:31
 */

namespace Calltree\Static_\NodeVisitor;


use phpDocumentor\Reflection\DocBlockFactory;
use PhpParser\Node;
use Calltree\VariableInfo;
use Symfony\Component\Console\Exception\LogicException;


/**
 * Restores collected variables
 * @package Calltree\Static_\NodeVisitor
 */
trait VariableDispenser {


	/** Function variables (including parameters). Only valid inside a FunctionLike
	 * @var VariableInfo[]
	 */
	protected $variables = [];

	/** Class Properties. Only valid inside a ClassLike
	 * @var VariableInfo[]
	 */
	protected $properties = [];


	protected $fileVariables = [];


	protected function beforeTraverseVariableDispenser(
		/** @noinspection PhpUnusedParameterInspection */
		array $nodes
	) {
		if(empty($this->fileName))
			throw new LogicException("VariableDispenser without fileName context!");

		$this->fileVariables = isset( $this->functionCollector->fileVariables[ $this->fileName ] )
			? $this->functionCollector->fileVariables[ $this->fileName ] : [];

		$this->variables  = $this->fileVariables;
		$this->properties = [];




	}

	/**
	 * @param Node $node
	 */
	protected function enterNodeVariableDispenser( $node ) {
		if ( $node instanceof Node\FunctionLike ) {
			$fn = strtolower( $this->getCurrentFunctionLikeName() );
			if ( ! isset( $this->functionCollector->addedFunctions[ $fn ] ) ) {
				throw new \LogicException( "cant dispense variables of function $fn, not yet collected?" );
			}
			$this->variables = $this->functionCollector->addedFunctions[ $fn ]->variables;
		} elseif ( $node instanceof Node\Stmt\ClassLike ) {
			$cn = strtolower( $this->getCurrentClassLikeName() );
			if ( ! isset( $this->functionCollector->addedClasses[ $cn ] ) ) {
				throw new \LogicException( "cant dispense properties of class $cn, not yet collected?" );
			}
			$this->properties = $this->functionCollector->addedClasses[ $cn ]->properties;
		}
	}

	/**
	 * @param Node $node
	 */
	protected function leaveNodeVariableDispenser( $node ) {
		if ( $node instanceof Node\FunctionLike ) {
			$this->variables = ($node instanceof Node\Stmt\Function_ && !$this->classLike) ? $this->fileVariables : [];
		} elseif ( $node instanceof Node\Stmt\ClassLike ) {
			$this->properties = [];
			$this->variables = $this->fileVariables;
		}
	}
}