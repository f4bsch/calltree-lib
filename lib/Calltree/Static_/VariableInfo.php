<?php
/**
 * Created by PhpStorm.
 * User: Fabian
 * Date: 09.10.2017
 * Time: 09:38
 */

namespace Calltree\Static_;


class VariableInfo {
	/**
	 * function(Type $param) {}
	 */
	const STRENGTH_TYPED_FUNCTION_PARAMETER = 999;

	/** @var string */
	private $type = '';

	/** @var int */
	private $typeStrength = - 1;

	/** @var mixed */
	public $value = NAN; // map NAN to net set
	public $possibleValues = [];

	public $isGlobal = false;

	public function __construct( $type = '', $value = NAN, $typeStrength = - 1 ) {

		if ( ! is_string( $type ) ) {
			throw new \LogicException( "type must be a string" );
		}

		$this->type  = $type;
		$this->value = $value;

		if ( $typeStrength === - 1 ) {
			if ( $this->type ) {
				$typeStrength = self::getTypeStrength( $type );
			}
		}

		$this->typeStrength = $typeStrength;
	}

	/** @return bool */
	public function isset_() {
		return ! is_float( $this->value ) || ! is_nan( $this->value );
	}

	/**
	 * @return string
	 */
	public function getType() {
		return $this->type;
	}

	public function isClassType() {
		return $this->typeStrength > 55;
	}

	/**
	 * @param string $type
	 * @param mixed $value
	 * @param int $strength
	 */
	public function maybeUpgrade( $type, $value = NAN, $strength = - 1 ) {
		$type = strtolower($type);

		if(strpos($type,'|') !== false) {
			$type = self::strongestType($type);
		}
		if ( $strength === - 1 ) {
			$strength = self::getTypeStrength( $type );
		}

		if(is_object($value))
			$value = '{'.get_class($value).'}';

		if ( $strength > $this->typeStrength ) {
			$this->type         = $type;
			$this->typeStrength = $strength;
			if ( ! is_float( $value ) || ! is_nan( $value ) ) {
				$this->value = $value;
			}

		} elseif ( $type === $this->type ) {
			// only upgrade value if types are equal
			if ( ! is_float( $value ) || ! is_nan( $value ) ) {
				$this->value = $value;
			}
		}

		//if($value && !is_object($value))
		//	$this->possibleValues[] = $value;
	}


	/**
	 * @param VariableInfo $other
	 */
	public function maybeUpgradeFrom( $other ) {
		$this->maybeUpgrade( $other->type, $other->value, $other->typeStrength );
	}

	/**
	 * @param string $type
	 *
	 * @return int
	 */
	public static function getTypeStrength( $type ) {
		if ( empty( $type ) ) {
			return 0;
		}

		// typeRef
		if ( $type{0} === '*' ) {
			return 60; // better than stdclass, worse than a specific className, but better than WP_Error
		}


		switch ( strtolower( $type ) ) {
			case 'void':
				return 10; // better than nothing
			case 'mixed':
				return 20; // better than void

			// scalars
			case 'null':
				return 30;

			case 'bool':
			case 'boolean':
			case 'false':
				return 40;

			case 'int':
			case 'integer':
				return 41;

			case 'string':
				return 42;

			case 'array':
				return 43;

			case 'object':
				return 50;

			case 'stdclass':
				return 51; // stdclass is better than object

			case 'wp_error':
				return 59;

		}

		// TODO 'true'?

		return 70; // any className
	}

	public static function strongerType( $t1, $t2 ) {
		return ( self::getTypeStrength( $t2 ) > self::getTypeStrength( $t1 ) ) ? $t2 : $t1;
	}

	public static function strongestType($types, &$strength = null) {
		if(is_string($types))
			$types = explode('|', $types);
		$types = array_map('trim', $types);
		$st = '';
		$strength = -1;
		foreach($types as $type) {
			$s = self::getTypeStrength($type);
			if($s > $strength) {
				$strength = $s;
				$st = $type;
			}
		}
		return $st;
	}
}