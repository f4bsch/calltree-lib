<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 08.10.2017
 * Time: 08:31
 */

namespace Calltree\Static_\NodeVisitor;


use Calltree\Static_\VariableInfo;
use phpDocumentor\Reflection\DocBlockFactory;
use PhpParser\Node;


/**
 * Collects class variables (properties) and local variables/parameters in functions
 * // TODO global vars!
 * @package Calltree\Static_\NodeVisitor
 */
trait VariableCollector {

	/** Functions can be nested
	 * @var VariableInfo[][]
	 */
	private $variablesStack = [];

	/** Function variables (including parameters). Only valid inside a FunctionLike
	 * @var VariableInfo[]
	 */
	protected $variables = [];

	/** Class Properties. Only valid inside a ClassLike
	 * @var VariableInfo[]
	 */
	protected $properties = [];


	protected function beforeTraverseVariableCollector(
		/** @noinspection PhpUnusedParameterInspection */
		array $nodes
	) {
		$this->variablesStack = [];
		$this->variables      = [];

		$this->properties = [];


	}

	/**
	 * @param Node $node
	 */
	protected function enterNodeVariableCollector( $node ) {
		if ( $node instanceof Node\FunctionLike ) {
			$this->variablesStack[] = $this->variables;
			$this->variables        = [];

			$docText = $node->getDocComment() ? $node->getDocComment()->getText() : '';


			// already add function parameters
			// and use their default values
			if ( $params = $node->getParams() ) {
				foreach ( $params as $param ) {
					/** @var Node\Param $param */


					// TODO use doc comment!
					$valDefault = NAN;
					$type       = $param->default ? $this->resolveExpressionTypeVar( $param->default, $valDefault ) : '';
					$strength   = - 1;

					if ( $param->type && ! is_numeric( $param->type ) ) {
						$type = $param->type;
					}


					if ( $type instanceof Node\Name\FullyQualified ) {
						// yeey, we found a typed parameter!
						$type     = $this->nameToString( $type );
						$strength = VariableInfo::STRENGTH_TYPED_FUNCTION_PARAMETER; // this is super strong
					} elseif ( $docText
					           && preg_match( '/@param(\s+[a-z0-9_\\\\|]+)?\s+\$' . $param->name . '(\s[a-z0-9_\\\\|]+\s)?/i', $docText, $m )
					) {
						if ( ! empty( $m[1] ) ) {
							$type = trim( $m[1] );
						} elseif ( ! empty( $m[2] ) ) {
							$type = trim( $m[2] );
						}


						if ( strpos( $type, '|' ) ) {
							//echo $this->getCurrentFunctionLikeName();
							$type = VariableInfo::strongestType( $type, $strength );
							-- $strength;
							//die( " $type" );
						} else {
							$strength = VariableInfo::getTypeStrength( $type ) - 1;
						}

					}

					$this->variables        [ $param->name ] = new VariableInfo( $type, $valDefault, $strength );
				}
				//print_r($this->variables);
				//exit;
			}
		} elseif ( $node instanceof Node\Stmt\ClassLike ) {
			$this->properties = [];
		} elseif($node instanceof  Node\Stmt\Property) {
			$prop = $node->props[0];


			$valDefault = NAN; // get this in resolveExpressionTypeVar()
			$type       = $prop->default ? $this->resolveExpressionTypeVar( $prop->default, $valDefault ) : '';

			if(count($node->props) === 1 && $node->getDocComment()
			   && preg_match( '/@var(\s+[a-z0-9_\\\\|]+)/i', $node->getDocComment()->getText(), $m )) {
				$type = VariableInfo::strongerType($type, trim($m[1]));
			}

			// TODO add  more strength to defaults?!

			if ( isset( $this->properties        [ $prop->name ] ) ) {
				$this->properties[ $prop->name ]->maybeUpgrade( $type, $valDefault );
			} else {
				$this->properties        [ $prop->name ] = new VariableInfo( $type, $valDefault );
			}

		}
	}

	/**
	 * @param Node $node
	 */
	protected function leaveNodeVariableCollector( $node ) {
		if ( $node instanceof Node\FunctionLike ) {
			$node->setAttribute( 'variables', $this->variables );
			$this->variables = array_pop( $this->variablesStack );
		}

		if ( $node instanceof Node\Stmt\ClassLike ) {
			$node->setAttribute( 'properties', $this->properties );
			$this->properties = [];
		}
	}
}