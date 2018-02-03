<?php namespace BambooHR\Guardrail;

/**
 * Guardrail.  Copyright (c) 2016-2017, Jonathan Gardiner and BambooHR.
 * Apache 2.0 License
 */

use BambooHR\Guardrail\Abstractions\ClassMethod;
use PhpParser\Node;
use BambooHR\Guardrail\SymbolTable\SymbolTable;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\Clone_;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\ClassLike;

/**
 * Class TypeInferrer
 *
 * @package BambooHR\Guardrail
 */
class TypeInferrer {

	/** @var SymbolTable */
	private $index;

	/** @var TypeInferencePluginInterface[] */
	private $plugins;

	/**
	 * TypeInferrer constructor.
	 *
	 * @param SymbolTable $table Instance of SymbolTable
	 */
	public function __construct(SymbolTable $table, array $plugins = []) {
		$this->index = $table;
		$this->plugins = $plugins;
	}

	/**
	 * inferType
	 *
	 * Do some simplistic checks to see if we can figure out object type.  If we can, then we can check method calls
	 * using that variable for correctness.
	 *
	 * @param ClassLike|null $inside Instance of ClassLike
	 * @param Expr|null      $expr   Instance of Expr
	 * @param Scope          $scope  Instance of Scope
	 *
	 * @return array [0]=Type [1]=maybe null
	 * @todo This looks like a good place for a strategy pattern
	 */
	public function inferType(ClassLike $inside = null, Expr $expr = null, Scope $scope) {
		if ($expr == null) {
			return [Scope::MIXED_TYPE, Scope::NULL_UNKNOWN];
		}
		foreach ($this->plugins as $plugin) {
			$response = $plugin->inferNode( $expr, $inside, $scope, $this );
			if ($response) {
				return $response;
			}
		}

		if ($expr instanceof AssignOp) {
			return $this->inferType($inside, $expr->expr, $scope);
		} elseif ($expr instanceof Scalar) {
			return [Scope::SCALAR_TYPE, Scope::NULL_IMPOSSIBLE];
		} elseif ($expr instanceof New_) {
			if ($expr->class instanceof Name) {
				$className = strval($expr->class);
				if (strcasecmp($className, "self") == 0) {
					$className = $inside ? strval($inside->namespacedName) : Scope::MIXED_TYPE;
				} else {
					if (strcasecmp($className, "static") == 0) {
						// Todo: track static scope to figure out which child class to invoke.
						$className = $inside ? strval($inside->namespacedName) : Scope::MIXED_TYPE;
					}
				}
				return [$className, Scope::NULL_IMPOSSIBLE];
			}
		} elseif ($expr instanceof Node\Expr\Variable) {
			if (gettype($expr->name) == "string") {
				$varName = strval($expr->name);
				if ($varName == "this" && $inside) {
					return [strval($inside->namespacedName), false];
				}
				$scopeType = $scope->getVarType($varName);
				if ($scopeType != Scope::UNDEFINED) {
					return [$scopeType, $scope->getVarNullability($varName)];
				}
			}
		} elseif ($expr instanceof Closure) {
			return ["Closure", false];
		} elseif ($expr instanceof FuncCall) {
			if ($expr->name instanceof Name) {
				$funcName = strval($expr->name);
				$func = $this->index->getAbstractedFunction( $funcName );
				if ($func) {
					$type = Scope::constFromName($func->getReturnType());
					if ($type) {
						return [$type, Scope::NULL_IMPOSSIBLE];
					}
					if (Config::shouldUseDocBlockForReturnValues()) {
						$type = $func->getDocBlockReturnType();
						$type = Scope::constFromDocBlock(
							$type,
							Scope::UNDEFINED,
							Scope::UNDEFINED
						);
						if ($type) {
							return [$type, Scope::NULL_UNKNOWN];
						}
					}
				}
			}
		} elseif ($expr instanceof Node\Expr\MethodCall) {
			return $this->inferMethodCall($inside, $expr, $scope);
		} elseif ($expr instanceof PropertyFetch) {
			return $this->inferPropertyFetch($expr, $inside, $scope);
		} elseif ($expr instanceof ArrayDimFetch) {
			list($type) = $this->inferType($inside, $expr->var, $scope);
			if (substr($type, -2) == "[]") {
				return [substr($type, 0, -2), Scope::NULL_UNKNOWN];
			}
		} elseif ($expr instanceof Clone_) {
			// A cloned node will be the same type as whatever we're cloning.
			return $this->inferType($inside, $expr->expr, $scope);
		} elseif ($expr instanceof Expr\ConstFetch) {
			if (strcasecmp($expr->name, "null") == 0) {
				return [Scope::NULL_TYPE, Scope::NULL_POSSIBLE];
			} else {
				if (strcasecmp($expr->name, "false") == 0 || strcasecmp($expr->name, "true") == 0) {
					return [Scope::BOOL_TYPE, Scope::NULL_IMPOSSIBLE];
				} else {
					if ($this->index->isDefined($expr->name)) {
						return [Scope::MIXED_TYPE, Scope::NULL_UNKNOWN];
					}
				}
			}
		} elseif ($expr instanceof Expr\Ternary) {
			list($type1, $null1) = $this->inferType($inside, $expr->if, $scope);
			list($type2, $null2) = $this->inferType($inside, $expr->else, $scope);
			return [
				$type1 == $type2 ? $type1 : Scope::MIXED_TYPE,
				$null1 == Scope::NULL_POSSIBLE || $null2 == Scope::NULL_POSSIBLE ? Scope::NULL_POSSIBLE : Scope::NULL_UNKNOWN
			];
		} elseif ($expr instanceof Expr\BinaryOp\Spaceship) {
			return [Scope::INT_TYPE, Scope::NULL_IMPOSSIBLE];
		} elseif ($expr instanceof Expr\BinaryOp\Coalesce) {
			list($type1) = $this->inferType($inside, $expr->left, $scope);
			list($type2, $null2) = $this->inferType($inside, $expr->right, $scope);
			return [
				$type1 == $type2 ? $type1 : Scope::MIXED_TYPE,
				$null2
			];
		} elseif ($expr instanceof Expr\StaticCall) {
			return $this->inferStaticMethodCall($inside, $expr, $scope);
		}
		return [Scope::MIXED_TYPE, Scope::NULL_UNKNOWN];
	}

	public function inferStaticMethodCall(ClassLike $inside = null, Node\Expr\StaticCall $expr, Scope $scope) {
		if (gettype($expr->name) == "string") {
			if ($expr->class instanceof Expr) {
				list($class) = $this->inferType($inside, $expr->class, $scope);
			} else {
				$class = strval($expr->class);
			}
			if (!empty($class) && $class[0] != "!") {
				return $this->inferMethodCallInternal($class, $expr->name);
			}
		}
		return [Scope::MIXED_TYPE, Scope::NULL_UNKNOWN];
	}

	// @codingStandardsIgnoreStart

	/**
	 * inferPropertyFetch
	 *
	 * @param PropertyFetch $expr   Instance of PropertyFetch
	 * @param ClassLike     $inside Method inside the class
	 * @param Scope         $scope  The scope
	 *
	 * @return array
	 */
	public function inferPropertyFetch(PropertyFetch $expr, $inside, $scope) {
		if (!Config::shouldUseDocBlockForProperties()) {
			return [Scope::MIXED_TYPE, Scope::NULL_UNKNOWN];
		}
		list($class) = $this->inferType($inside, $expr->var, $scope);
		if (!empty($class) && $class[0] != "!") {
			if (gettype($expr->name) == 'string') {
				$propName = $expr->name;
				if ($propName != "") {
					list($prop,$hostingClass) = Util::findAbstractedProperty($class, $propName, $this->index);
					if ($prop) {
						$type = $prop->getType();
						if (!empty($type)) {
							if ($type[0] == '\\') {
								$type = substr($type, 1);
							}

							$type2 = Scope::constFromDocBlock($type, $hostingClass, $class);
							return [$type2, Scope::NULL_UNKNOWN];
						}
					} else {
						//	echo "Unable to find prop $propName\n";
					}
				} else {
					//echo "Unable to infer property type: $propName\n";
				}
			}
		} else {
			//echo "Unable to infer left side of prop fetch ".get_class($expr->var)." ".$expr->getLine()." inside ".($inside?$inside->name:"")."\n";
		}
		return [Scope::MIXED_TYPE, Scope::NULL_UNKNOWN];
	}

	// @codingStandardsIgnoreStop

	/**
	 * @param ClassLike $inside -
	 * @param Expr      $expr   -
	 * @param Scope     $scope  -
	 * @return array
	 */
	protected function inferMethodCall(ClassLike $inside = null, Node\Expr\MethodCall $expr, Scope $scope) {
		if (gettype($expr->name) == "string") {
			list($class) = $this->inferType($inside, $expr->var, $scope);
			if (!empty($class) && $class[0] != "!") {

				return $this->inferMethodCallInternal($class, strval($expr->name));
			}
		}
		return [Scope::MIXED_TYPE, Scope::NULL_UNKNOWN];
	}



	/**
	 *
	 * @param string $class
	 * @param string $name

	 */
	protected function inferMethodCallInternal($class, $name) {
		/** @var ClassMethod $method */
		/** @var string $hostingClass */
		list($method, $hostingClass) = Util::findAbstractedMethodAndHostingClass($class,$name, $this->index);

		if ($method) {
			$type = Scope::constFromName($method->getReturnType());

			if ($type) {
				return [$type, Scope::NULL_IMPOSSIBLE];
			}

			if (Config::shouldUseDocBlockForReturnValues()) {
				$type = $method->getDocBlockReturnType();
				$type = Scope::constFromDocBlock(
					$type,
					$hostingClass,
					$class
				);
				if ($type) {
					return [$type, Scope::NULL_UNKNOWN];
				}
			}
		}
		return [Scope::MIXED_TYPE, Scope::NULL_UNKNOWN];
	}
}