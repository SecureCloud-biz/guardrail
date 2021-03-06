<?php namespace BambooHR\Guardrail\NodeVisitors;

/**
 * Guardrail.  Copyright (c) 2016-2017, Jonathan Gardiner and BambooHR.
 * Apache 2.0 License
 */

use BambooHR\Guardrail\Abstractions\ClassMethod as AbstractClassMethod;
use BambooHR\Guardrail\Attributes;
use BambooHR\Guardrail\Checks\AccessingSuperGlobalsCheck;
use BambooHR\Guardrail\Checks\BackTickOperatorCheck;
use BambooHR\Guardrail\Checks\BreakCheck;
use BambooHR\Guardrail\Checks\CatchCheck;
use BambooHR\Guardrail\Checks\ClassConstantCheck;
use BambooHR\Guardrail\Checks\ClassMethodStringCheck;
use BambooHR\Guardrail\Checks\ClassStoredAsVariableCheck;
use BambooHR\Guardrail\Checks\ConditionalAssignmentCheck;
use BambooHR\Guardrail\Checks\ConstructorCheck;
use BambooHR\Guardrail\Checks\CyclomaticComplexityCheck;
use BambooHR\Guardrail\Checks\DefinedConstantCheck;
use BambooHR\Guardrail\Checks\DocBlockTypesCheck;
use BambooHR\Guardrail\Checks\FunctionCallCheck;
use BambooHR\Guardrail\Checks\GotoCheck;
use BambooHR\Guardrail\Checks\InstanceOfCheck;
use BambooHR\Guardrail\Checks\InstantiationCheck;
use BambooHR\Guardrail\Checks\InterfaceCheck;
use BambooHR\Guardrail\Checks\MethodCall;
use BambooHR\Guardrail\Checks\ParamTypesCheck;
use BambooHR\Guardrail\Checks\PropertyFetchCheck;
use BambooHR\Guardrail\Checks\PropertyStoreCheck;
use BambooHR\Guardrail\Checks\Psr4Check;
use BambooHR\Guardrail\Checks\ReturnCheck;
use BambooHR\Guardrail\Checks\SplatCheck;
use BambooHR\Guardrail\Checks\StaticCallCheck;
use BambooHR\Guardrail\Checks\StaticPropertyFetchCheck;
use BambooHR\Guardrail\Checks\SwitchCheck;
use BambooHR\Guardrail\Checks\UndefinedVariableCheck;
use BambooHR\Guardrail\Checks\UnreachableCodeCheck;
use BambooHR\Guardrail\Checks\UnusedPrivateMemberVariableCheck;
use BambooHR\Guardrail\Config;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use BambooHR\Guardrail\Abstractions\FunctionLikeParameter;
use BambooHR\Guardrail\Abstractions\ClassAbstraction as AbstractionClass;
use BambooHR\Guardrail\Abstractions\ReflectedClassMethod;
use BambooHR\Guardrail\Output\OutputInterface;
use BambooHR\Guardrail\Scope;
use BambooHR\Guardrail\SymbolTable\SymbolTable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Trait_;
use BambooHR\Guardrail\TypeInferrer;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use BambooHR\Guardrail\Checks\ErrorConstants;

/**
 * Class StaticAnalyzer
 *
 * @package BambooHR\Guardrail\NodeVisitors
 */
class StaticAnalyzer extends NodeVisitorAbstract {

	/** @var  SymbolTable */
	private $index;

	/** @var string */
	private $file;

	/** @var \BambooHR\Guardrail\Checks\BaseCheck[] */
	private $checks = [];

	/** @var Class_[] */
	private $classStack = [];
	/** @var Scope[] */
	private $scopeStack = [];

	/** @var TypeInferrer */
	private $typeInferrer;

	/** @var OutputInterface */
	private $output;

	/** @var array */
	private $timings = [];

	/** @var int[] */
	private $counts = [];



	/**
	 * @var \Closure[]
	 */
	private $enterHooks = [];

	/**
	 * @var \Closure[]
	 */
	private $exitHooks = [];

	/**
	 * @return array
	 */
	function getTimingsAndCounts() {
		return [$this->timings,$this->counts];
	}


	/**
	 * StaticAnalyzer constructor.
	 *
	 * @param string          $basePath The base path
	 * @param SymbolTable     $index    The index
	 * @param OutputInterface $output   Instance if OutputInterface
	 * @param Config          $config   The config
	 */
	function __construct($basePath, $index, OutputInterface $output, $config) {
		$this->index = $index;
		$this->scopeStack = [new Scope(true, true)];
		$this->typeInferrer = new TypeInferrer($index);
		$this->output = $output;

		/** @var \BambooHR\Guardrail\Checks\BaseCheck[] $checkers */
		$checkers = [
			new DocBlockTypesCheck($this->index, $output),
			new UndefinedVariableCheck($this->index, $output),
			new DefinedConstantCheck($this->index, $output),
			new BackTickOperatorCheck($this->index, $output),
			new PropertyFetchCheck($this->index, $output),
			new InterfaceCheck($this->index, $output),
			new ParamTypesCheck($this->index, $output),
			new StaticCallCheck($this->index, $output),
			new InstantiationCheck($this->index, $output),
			new InstanceOfCheck($this->index, $output),
			new CatchCheck($this->index, $output),
			new ClassConstantCheck($this->index, $output),
			new FunctionCallCheck($this->index, $output),
			new MethodCall($this->index, $output),
			new SwitchCheck($this->index, $output),
			new BreakCheck($this->index, $output),
			new ConstructorCheck($this->index, $output),
			new GotoCheck($this->index, $output),
			new ReturnCheck($this->index, $output),
			new StaticPropertyFetchCheck($this->index, $output),
			new AccessingSuperGlobalsCheck($this->index, $output),
			new UnreachableCodeCheck($this->index, $output),
			new Psr4Check($this->index, $output),
			new CyclomaticComplexityCheck($this->index, $output),
			new ConditionalAssignmentCheck($this->index, $output),
			new ClassMethodStringCheck($this->index, $output),
			new UnusedPrivateMemberVariableCheck($this->index, $output),
			new SplatCheck($this->index, $output),
			new PropertyStoreCheck($this->index, $output),
			//new ClassStoredAsVariableCheck($this->index, $output)
		];

		$this->enterHooks = $this->buildClosures();
		$this->exitHooks = $this->buildLeaveClosures();

		$checkers = array_merge($checkers, $config->getPlugins($this->index, $output));

		foreach ($checkers as $checker) {
			foreach ($checker->getCheckNodeTypes() as $nodeType) {
				if (!isset($this->checks[$nodeType])) {
					$this->checks[$nodeType] = [$checker];
				} else {
					$this->checks[$nodeType][] = $checker;
				}
			}
		}
	}

	/**
	 * setFile
	 *
	 * @param string $name The name
	 *
	 * @return void
	 */
	public function setFile($name) {
		$this->file = $name;
		$this->scopeStack = [new Scope(true, true)];
	}

	//@codingStandardsIgnoreStart

	/**
	 * @return Closure[]
	 */
	private function buildLeaveClosures() {
		$func = [];
		$func[Node\Expr\Variable::class] = function (Node\Expr\Variable $node) {
			if (is_string($node->name) && !$node->hasAttribute('assignment')) {
				$this->setScopeUsed($node->name);
			}
		};

		$func[Node\Expr\ClosureUse::class] = function (Node\Expr\ClosureUse $node) {
			if (is_string($node->var)) {
				$this->setScopeUsed($node->var);
			}
		};

		$func[Node\Expr\FuncCall::class] = function (Node\Expr\FuncCall $node) {
			if (
				$node->name instanceof Node\Name &&
				strcasecmp(strval($node->name), "get_defined_vars()") == 0
			) {
				$this->setAllScopeUsed();
			}
		};

		$func[Node\Expr\Assign::class] = function (Node\Expr\Assign $node) {
			$var = $node->var;
			if ($var instanceof Node\Expr\Variable) {
				if (is_string($var->name)) {
					$this->setScopeWritten($var->name, $node->getLine());
				}
			}
		};

		$func[Node\Expr\AssignRef::class] = function (Node\Expr\AssignRef $node) {
			$var = $node->var;
			if ($var instanceof Node\Expr\Variable) {
				if (is_string($var->name)) {
					$this->setScopeWritten($var->name, $node->getLine());
				}
			}
		};

		$func[Node\Stmt\Class_::class] = function (Node\Stmt\Class_ $node) {
			array_pop($this->classStack);
		};

		$func[ClassMethod::class] = function (ClassMethod $node) {
			if (count($this->classStack) > 0) {
				$this->handleUnusedVars($node);
			}
		};

		$func[Function_::class] = function (Function_ $node) {
			$this->handleUnusedVars($node);
		};

		$func[Closure::class] = function (Closure $node) {
			$this->handleUnusedVars($node);

		};

		$func[Node\Expr\ArrowFunction::class] = function(Node\Expr\ArrowFunction $node) {
			array_pop($this->scopeStack);
			//TODO: distinguish unused locally declared variables vs variables wrapped by the closure scope.
		};

		$func[ElseIf_::class] = function (ElseIf_ $node) {
			$last = end($this->scopeStack);
			$last->mergePrevious();
		};

		$func[Node\Stmt\Else_::class] = function (Node\Stmt\Else_ $node) {
			$last = end($this->scopeStack);
			$last->mergePrevious();
		};

		$func[If_::class] = function (If_ $node) {
			$last = array_pop($this->scopeStack);
			$next = end($this->scopeStack);
			$next->merge($last);
		};

		$func[Node\Expr\BinaryOp\BooleanOr::class] = function($node) {
			$last = array_pop($this->scopeStack);
			$next=end($this->scopeStack);
			$next->merge($last);
		};

		$func[Node\Expr\BinaryOp\BooleanAnd::class] = function($node) {
			$last = array_pop($this->scopeStack);
			$next=end($this->scopeStack);
			$next->merge($last);
		};
		return $func;
	}

	/**
	 * @return Closure[]
	 */
	private function buildClosures() {
		$func = [];

		$func[Class_::class] = function (Class_ $node) {
			array_push($this->classStack, $node);
		};

		$func[Trait_::class] = function (Trait_ $node) {
			array_push($this->classStack, $node);
		};

		$func[ClassMethod::class] = function (ClassMethod $node) {
			if (count($this->classStack) > 0) {
				$this->pushFunctionScope($node);
			}
		};

		$func[Function_::class] = function (Function_ $node) {
			$this->pushFunctionScope($node);
		};

		$func[Node\Expr\ArrowFunction::class] = function(Node\Expr\ArrowFunction $node) {
			$this->pushFunctionScope($node);
		};

		$func[Closure::class] = function (Closure $node) {
			$this->pushFunctionScope($node);
		};

		$func[Node\Expr\AssignRef::class] = function (Node\Expr\AssignRef $node) {
			$this->handleAssignment($node);
		};

		$func[Node\Expr\Assign::class] = function (Node\Expr\Assign $node) {
			$this->handleAssignment($node);
		};

		$func[Node\Stmt\StaticVar::class] = function (Node\Stmt\StaticVar $node) {
			$this->setScopeExpression($node->var->name, $node->default, $node->getLine());
		};

		$func[Node\Stmt\Catch_::class] = function (Node\Stmt\Catch_ $node) {
			$this->setScopeType(strval($node->var->name), count($node->types)==1 ? strval($node->types[0]) : Scope::MIXED_TYPE, $node->getLine());
			$this->setScopeUsed(strval($node->var->name));
		};

		$func[Node\Stmt\Global_::class] = function (Node\Stmt\Global_ $node) {
			foreach ($node->vars as $var) {
				if ($var instanceof Variable) {
					if (gettype($var->name) == "string") {
						$this->setScopeType(strval($var->name), Scope::MIXED_TYPE, $var->getLine());
					}
				}
			}
		};

		$func[Node\Expr\MethodCall::class] = function (Node\Expr\MethodCall $node) {
			if ($node->name instanceof Node\Identifier) {
				list($type) = $this->typeInferrer->inferType(end($this->classStack) ?: null, $node->var, end($this->scopeStack));
				if ($type && $type[0] != "!") {
					$method = $this->index->getAbstractedMethod($type, $node->name->name);
					if ($method) {
						$this->processMethodCall($node, $method);
					}
				}
			}
		};

		$func[Node\Expr\StaticCall::class] = function (Node\Expr\StaticCall $node) {
			if ($node->class instanceof Node\Name && gettype($node->name) == "string") {
				$method = $this->index->getAbstractedMethod(strval($node->class), strval($node->name));
				if ($method) {
					$this->processStaticCall($node, $method);
				}
			}
		};

		$func[Node\Expr\FuncCall::class] = function (Node\Expr\FuncCall $node) {
			if ($node->name instanceof Node\Name) {
				if (strcasecmp($node->name, "assert") == 0 &&
					count($node->args) == 1
				) {
					$var = $node->args[0]->value;
					if ($var instanceof Instanceof_) {
						$expr = $var->expr;
						if ($expr instanceof Variable) {
							$class = $var->class;
							if ($class instanceof Node\Name) {
								if (gettype($expr->name) == "string") {
									end($this->scopeStack)->setVarType($expr->name, strval($class), $var->getLine());
									end($this->scopeStack)->setVarNull($expr->name, false);
								}
							}
						}
					}
				}

				$function = $this->index->getAbstractedFunction(strval($node->name));
				if ($function) {
					$this->addReferenceParametersToLocalScope($node->args, $function->getParameters());
				}
			}
		};

		$func[Node\Stmt\Foreach_::class] = function (Node\Stmt\Foreach_ $node) {
			$valueVar = $node->valueVar;
			$keyVar = $node->keyVar;
			if ($keyVar instanceof Variable) {
				if (gettype($keyVar->name) == "string") {
					$this->setScopeType(strval($keyVar->name), Scope::MIXED_TYPE, $keyVar->getLine());
				}
			}
			if ($valueVar instanceof Variable) {
				if (gettype($valueVar->name) == "string") {
					list($type) = $this->typeInferrer->inferType(end($this->classStack) ?: null, $node->expr, end($this->scopeStack));
					if (substr($type, -2) == "[]") {
						$type = substr($type, 0, -2);
					} else {
						$type = Scope::MIXED_TYPE;
					}
					$this->setScopeType(strval($valueVar->name), $type, $valueVar->getLine());
				}
			} else {
				if ($valueVar instanceof List_) {
					// Deal with traditional list($a,b,$c) style list.
					foreach ($valueVar->items as $var) {
						if ($var->key == NULL && $var->value instanceof Variable) {
							if (gettype($var->value->name) == "string") {
								$this->setScopeType(strval($var->value->name), Scope::MIXED_TYPE, $var->getLine());
							}
						}
					}
				}
			}
		};

		$func[ElseIf_::class] = function (ElseIf_ $node) {
			$last = array_pop($this->scopeStack);
			$this->pushIfScope($node, $last);
		};

		$func[Node\Expr\Ternary::class] =  function(Node\Expr\Ternary $node) {

			if($node->if) {
				// Push any scope we'll need to restore at the end of the "true" branch.
				array_push($this->scopeStack, end($this->scopeStack)->getScopeClone());
				$node->if->setAttribute('merge-scope-on-leave',true);
			}

			// Then push the scope that will be true if the cond was true and prepare to merge it with
			// either the condition (if there is no true clause) or the true clause.
			$this->pushIfScope($node);
			$node->else->setAttribute('merge-scope-on-leave',true);

		};

		$func[If_::class] = function (If_ $node) {
			$this->pushIfScope($node);
		};

		$func[Node\Expr\BinaryOp\BooleanOr::class] = function(Node\Expr\BinaryOp\BooleanOr $node) {
			$this->pushShortCircuitOrScope($node);
		};

		$func[Node\Expr\BinaryOp\BooleanAnd::class] = function(Node\Expr\BinaryOp\BooleanAnd $node) {
			$this->pushShortCircuitAndScope($node);
		};

		$func[Node\Stmt\Else_::class] = function (Node\Stmt\Else_ $node) {
			$last = array_pop($this->scopeStack); // Save the old scope so we can merge it later.
			$this->pushIfScope($node, $last);
		};

		return $func;
	}

	// @codingStandardsIgnoreEnd

	/**
	 * enterNode
	 *
	 * @param Node $node Instance of the node
	 * @guardrail-ignore Standard.VariableFunctionCall
	 *
	 * @return null
	 */
	public function enterNode(Node $node) {
		$class = get_class($node);
		if ($node instanceof Trait_) {
			return NodeTraverser::DONT_TRAVERSE_CHILDREN;
		}
		if (Config::shouldUseDocBlockForInlineVars() && !($node instanceof FunctionLike)) {
			$vars = $node->getAttribute("namespacedInlineVar");
			if (is_array($vars)) {
				foreach ($vars as $varName => $varType) {
					$type = Scope::constFromDocBlock($varType);
					end($this->scopeStack)->setVarType($varName, $type, $node->getLine());
				}
			}
		}

		if (isset($this->enterHooks[$class])) {
			$this->enterHooks[$class]($node);
		}

		if (isset($this->checks[$class])) {
			$last = microtime(true);
			foreach ($this->checks[$class] as $check) {
				$start = $last;
				$check->run($this->file, $node, end($this->classStack) ?: null, end($this->scopeStack) ?: null);
				$last = microtime(true);
				$name = get_class($check);
				$this->timings[$name] = (isset($this->timings[$name]) ? $this->timings[$name] : 0) + ($last - $start);
				$this->counts[$name] = (isset($this->counts[$name]) ? $this->counts[$name] : 0) + 1;

			}
		}
		return null;
	}

	/**
	 * @param Node\Expr $node -
	 * @return string
	 */
	public static function checksForNonNullVariable(Node\Expr $node) {
		if ($node instanceof Node\Expr\BinaryOp\BooleanOr) {
			$var = self::checksForNonNullVariable($node->left);
			return $var;
		}
		if ($node instanceof Node\Expr\BinaryOp\BooleanAnd) {
			$var = self::checksForNonNullVariable($node->left);
			if ($var) {
				return $var;
			}
			$var = self::checksForNonNullVariable($node->right);
			if ($var) {
				return $var;
			}
		}

		// $var!==NULL
		// $var!=NULL
		if ($node instanceof Node\Expr\BinaryOp\NotEqual || $node instanceof Node\Expr\BinaryOp\NotIdentical) {
			// NULL!=$var
			if ( ($node->left instanceof Node\Expr\ConstFetch && strcasecmp($node->left->name, 'null') == 0) &&
				$node->right instanceof Variable &&
				is_string($node->right->name)
			) {
				return $node->right->name;
			}

			// $var!=NULL
			if ( ($node->right instanceof Node\Expr\ConstFetch && strcasecmp($node->right->name, 'null') == 0) &&
				$node->left instanceof Variable &&
				is_string($node->left->name)
			) {
				return $node->left->name;
			}
		}

		// $var instancof Foo
		if (
			$node instanceof  Instanceof_ &&
			$node->expr instanceof Variable &&
			is_string($node->expr->name)
		) {
			return $node->expr->name;
		}

		// $var
		if ($node instanceof Variable && is_string($node->name)) {
			return $node->name;
		}

		// isset($var)
		if (
			$node instanceof Node\Expr\Isset_ &&
			count($node->vars) == 1 &&
			$node->vars[0] instanceof Variable &&
			is_string($node->vars[0]->name)
		) {
			return $node->vars[0]->name;
		}

		// !is_null($var)
		if (
			$node instanceof Node\Expr\BooleanNot &&
			$node->expr instanceof Node\Expr\FuncCall &&
			$node->expr->name instanceof Node\Name &&
			strval($node->expr->name) == "is_null" &&
			count($node->expr->args) == 1 &&
			$node->expr->args[0]->value instanceof Variable &&
			is_string($node->expr->args[0]->value->name)
		) {
			return $node->expr->args[0]->value->name;
		}

		// !empty($var)
		if (
			$node instanceof Node\Expr\BooleanNot &&
			$node->expr instanceof Node\Expr\Empty_ &&
			$node->expr->expr instanceof Variable &&
			is_string($node->expr->expr->name)
		) {
			return $node->expr->expr->name;
		}
		return "";
	}

	/**
	 * pushIfScope
	 *
	 * @param Node  $node     Instance of Node
	 * @param Scope $previous The previous scope
	 *
	 * @return void
	 */
	function pushIfScope($node, Scope $previous = null) {
		/** @var Scope $scope */
		$scope = end($this->scopeStack);
		$newScope = $scope->getScopeClone($previous);
		$cond = self::getIfCond($node);
		if ($cond) {
			if ($cond instanceof Instanceof_) {
				$this->addCastedType($cond, $newScope);
			}
			$var = self::checksForNonNullVariable($cond);

			if ($var) {
				$newScope->setVarNull($var, false);
				if ($newScope->getVarType($var) == Scope::NULL_TYPE) {
					// Change the type in our scope, without setting the line as written.
					$newScope->getVarObject($var)->type = Scope::MIXED_TYPE;
				}
			}
		}
		array_push($this->scopeStack, $newScope);
//		echo "  New scope created, depth=".count($this->scopeStack)."\n";
	}

	/**
	 * @param Node\Expr\BinaryOp\LogicalAnd|Node\Expr\BinaryOp\LogicalOr $node     -
	 * @param Scope|null                                                 $previous -
	 * @return void
	 */
	function pushShortCircuitAndScope($node, Scope $previous=null) {
		/** @var Scope $scope */
		$scope = end($this->scopeStack);
		$newScope = $scope->getScopeClone($previous);
		$cond = $node->left;
		if ($cond instanceof Instanceof_) {
			$this->addCastedType($cond, $newScope);
		}
		$var = self::checksForNonNullVariable($cond);
		if ($var) {
			$newScope->setVarNull($var, false);
		}
		array_push($this->scopeStack, $newScope);
	}

	/**
	 * @param Node\Expr\BinaryOp\LogicalAnd|Node\Expr\BinaryOp\LogicalOr $node     -
	 * @param Scope|null                                                 $previous -
	 * @return void
	 */
	function pushShortCircuitOrScope($node, Scope $previous=null) {
		/** @var Scope $scope */
		$scope = end($this->scopeStack);
		$newScope = $scope->getScopeClone($previous);
		array_push($this->scopeStack, $newScope);
	}

	/**
	 * addCastedType
	 *
	 * When a node is of the form "if ($var instanceof ClassName)" (with no else clauses) then we can
	 * relax the scoping rules inside the if statement to allow a different set of methods that might not
	 * be normally visible.  This is primarily used for downcasting.
	 *
	 * "ClassName" inside the true clause.
	 *
	 * @param Instanceof_ $cond     -
	 * @param Scope       $newScope Instance of Scope
	 * @guardrail-ignore Standard.Unknown.Property
	 *
	 * @return void
	 */
	public function addCastedType(Instanceof_ $cond, Scope $newScope) {
		if ($cond->expr instanceof Variable && gettype($cond->expr->name) == "string" && $cond->class instanceof Node\Name) {
			$newScope->setVarType($cond->expr->name, strval($cond->class), $cond->expr->getLine());
		}
	}

	/**
	 * updateFunctionEmit
	 *
	 * @param FunctionLike $func      Instance of FunctionLike
	 * @param string       $pushOrPop Push | Pop
	 *
	 * @return void
	 */
	public function updateFunctionEmit(FunctionLike $func, $pushOrPop) {

		$docBlock = trim($func->getDocComment());
		$ignoreList = [];

		if (preg_match_all("/@guardrail-ignore ([A-Za-z. ,]*)/", $docBlock, $ignoreList)) {
			foreach ($ignoreList[1] as $ignoreListEntry) {
				$toIgnore = explode(",", $ignoreListEntry);
				foreach ($toIgnore as $type) {
					$type = trim($type);
					if (!empty($type)) {
						if ($pushOrPop == "push") {
							$this->output->silenceType($type);
						} else {
							$this->output->resumeType($type);
						}
					}
				}
			}
		}
	}

	/**
	 * pushFunctionScope
	 *
	 * @param FunctionLike $func Instance of FunctionLike
	 *
	 * @return void
	 */
	public function pushFunctionScope(FunctionLike $func) {
		$isStatic = true;
		if ($func instanceof ClassMethod) {
			$isStatic = $func->isStatic();
		} else if ($func instanceof Closure) {
			$isStatic = $func->static;
		} else if ($func instanceof Node\Expr\ArrowFunction) {
			$isStatic = $func->static;
		}


		//echo "Function: ".$func->name." depth=".(count($this->scopeStack)+1)."\n";
		$scope = new Scope($isStatic, false, $func);

		foreach ($func->getParams() as $param) {
			//echo "  Param ".$param->var->name." ". $param->type. " ". ($param->default==NULL ? "Not null" : "default"). " ".($param->variadic ? "variadic" : "")."\n";
			if ($param->variadic) {
				if ($param->type) {
					$type = $param->type;
					if ($type instanceof Node\NullableType) {
						$type = $type->type;
					}
					$type = Scope::constFromName(strval($type));
					$scope->setVarType(strval($param->var->name), $type . "[]", $param->getLine());
				} else {
					$scope->setVarType(strval($param->var->name), Scope::ARRAY_TYPE, $param->getLine());
				}
				$scope->setVarNull(strval($param->var->name), false); // Variadic parameter will never be null.
			} else {
				$paramType = $param->type instanceof Node\NullableType ? strval($param->type->type) : strval($param->type);
				$scope->setVarType(strval($param->var->name), Scope::constFromName($paramType), $param->getLine());
				$scope->setVarAttributes(strval($param->var->name), Attributes::TOUCHED_FUNCTION_PARAM);
				if ($param->type != null && $param->default == null) {
					$scope->setVarNull(strval($param->var->name), false);
				}
			}
			$scope->setVarUsed(strval($param->var->name)); // It's ok to leave a parameter unused, so we just mark it used.
		}
		if ($func instanceof Node\Expr\ArrowFunction) {
			// Arrow functions automatically inherit all local scope.
			$scope->merge( end($this->scopeStack) );
		}
		if ($func instanceof Closure) {
			$oldScope = end($this->scopeStack);
			foreach ($func->uses as $variable) {
				$type = $oldScope->getVarType($variable->var->name);
				// We don't track variables in global scope, so we'll have to assume those are ok.
				if ($type == Scope::UNDEFINED && !$oldScope->isGlobal()) {
					$this->output->emitError(__CLASS__, $this->file, $variable->getLine(), ErrorConstants::TYPE_UNKNOWN_VARIABLE, "Attempt to use unknown variable \$" . $variable->var->name . " in uses() clause");
				} else {
					if ($variable->byRef) {
						// This is kind of fun, it's passed by reference so we literally reference the exact same
						// scope variable object in the new scope.
						$scope->setVarReference($variable->var->name, $oldScope->getVarObject($variable->var->name));
					} else {
						$oldScope->setVarUsed($variable->var->name);
						if ($type == Scope::UNDEFINED) {
							$type = Scope::MIXED_TYPE;
						}
						$scope->setVarType($variable->var->name, $type, $variable->getLine());
					}
				}
			}
		}


		if ($func instanceof ClassMethod || $func instanceof Function_) {
			$this->updateFunctionEmit($func, "push");
		}
		array_push($this->scopeStack, $scope);
	}

	/**
	 * isCastableIf
	 *
	 * An if is castable if there are no elseifs and the expr is a simple "InstanceOf" expression.
	 *
	 * @param Node $node Instance of Node
	 *
	 * @return bool
	 */
	static public function getIfCond(Node $node) {
		if ($node instanceof Node\Expr\Ternary) {
			return $node->cond;
		}
		if ($node instanceof If_) {
			return $node->cond;
		}
		if ($node instanceof ElseIf_) {
			return $node->cond;
		}
		return null;
	}

	/**
	 * setScopeExpression
	 *
	 * @param string    $varName Variable name
	 * @param Node\Expr $expr    Expression name
	 * @param int       $line    line number
	 *
	 * @return void
	 */
	private function setScopeExpression($varName, $expr, $line) {
		$scope = end($this->scopeStack);
		$class = end($this->classStack) ?: null;
		list($newType, $attributes) = $this->typeInferrer->inferType($class, $expr, $scope);
		$this->setScopeType($varName, $newType, $line, $attributes);
		if ($attributes & Attributes::NULL_POSSIBLE) {
			$scope->setVarNull($varName, true);
		}
	}


	/**
	 * setScopeType
	 *
	 * @param string $varName    Variable name
	 * @param string $newType    The new type
	 * @param int    $line       The line number
	 * @param int    $attributes Any attributes to set
	 *
	 * @return void
	 */
	private function setScopeType($varName, $newType, $line, $attributes = 0) {
		$scope = end($this->scopeStack);
		$oldType = $scope->getVarType($varName);
		if ($oldType != $newType) {
			if ($oldType == Scope::UNDEFINED) {
				$scope->setVarType($varName, $newType, $line);
			} elseif ($newType == Scope::NULL_TYPE) {
				$scope->setVarNull($varName, true);
			} else {
				// The variable has been used with 2 different types.  Update it in the scope as a mixed type.
				$scope->setVarType($varName, Scope::MIXED_TYPE, $line);
			}
		}
		$scope->setVarAttributeS($varName, $attributes);
	}

	/**
	 * setAllScopeUsed
	 *
	 * @return void
	 */
	private function setAllScopeUsed() {
		$scope = end($this->scopeStack);
		$scope->markAllVarsUsed();
	}

	/**
	 * setScopeUsed
	 *
	 * @param string $varName The name of the variable
	 *
	 * @return void
	 */
	private function setScopeUsed($varName) {
		$scope = end($this->scopeStack);
		$scope->setVarUsed($varName);
	}

	/**
	 * setScopeWritten
	 *
	 * @param string $varName    The name of the variable
	 * @param int    $lineNumber The line number
	 *
	 * @return void
	 */
	private function setScopeWritten($varName, $lineNumber) {
		$scope = end($this->scopeStack);
		$scope->setVarWritten($varName, $lineNumber);
	}


	/**
	 * handleAssignment
	 *
	 * Assignment can cause a new variable to come into scope.  We infer the type of the expression (if possible) and
	 * add an entry to the variable table for this scope.
	 *
	 * @param Node\Expr\Assign|Node\Expr\AssignRef $op Variable instances of different things
	 *
	 * @return void
	 */
	private function handleAssignment($op) {

		if ($op->var instanceof Node\Expr\Variable && gettype($op->var->name) == "string") {
			$overrides = Config::shouldUseDocBlockForInlineVars() ? $op->getAttribute('namespacedInlineVar') : [];

			$op->var->setAttribute('assignment', true);

			$varName = strval($op->var->name);

			// If it's in overrides, then it was already set by a DocBlock @var
			if (!isset($overrides[$varName])) {
				$this->setScopeExpression($varName, $op->expr, $op->expr->getLine());
			}
		} else if ($op->var instanceof List_ || $op->var instanceof Node\Expr\Array_) {
			// We're not going to examine a potentially complex right side of the assignment, so just set all vars to mixed.
			foreach ($op->var->items as $var) {
				if ($var) {
					/** @var Node\Expr\ArrayItem $var */
					$value = $var->value;
					if ($var->key == null && $value && $value instanceof Variable && gettype($value->name) == "string") {
						$this->setScopeType(strval($value->name), Scope::MIXED_TYPE, $var->getLine());
					}
				}
			}
		} else if ($op->var instanceof ArrayDimFetch) {
			$var = $op->var;
			while ($var instanceof ArrayDimFetch) {
				$var = $var->var;
			}
			if ($var instanceof Variable && gettype($var->name) == "string") {
				$varName = strval($var->name);
				$currentType = end($this->scopeStack)->getVarType($varName);
				if ($currentType == Scope::UNDEFINED) {
					// Implicitly create an array on the first assignment to an undefined array.
					$this->setScopeType($varName, Scope::ARRAY_TYPE, $var->getLine());
				}
			}
		}
	}

	/**
	 * leaveNode
	 *
	 * @param Node $node Instance of node
	 * @guardrail-ignore Standard.VariableFunctionCall
	 * @return null
	 */
	public function leaveNode(Node $node) {
		$class = get_class($node);

		if ($node instanceof Node\Expr && $node->hasAttribute('merge-scope-on-leave')) {
			$top = array_pop($this->scopeStack);
			end($this->scopeStack)->merge($top);

		}
		if (isset($this->exitHooks[$class])) {
			$this->exitHooks[$class]($node);
		}
		return null;
	}

	/**
	 * @param Node $node -
	 * @return void
	 */
	private function handleUnusedVars(Node $node) {
		$scope = array_pop($this->scopeStack);

		$unusedVars = $scope->getUnusedVars();

		if (count($unusedVars) > 0) {
			foreach ($unusedVars as $varName => $lineNumber) {
				$this->output->emitError(__CLASS__, $this->file, $lineNumber, ErrorConstants::TYPE_UNUSED_VARIABLE, '$' . $varName . " is assigned but never referenced");
			}
		}

		if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
			assert($node instanceof FunctionLike);
			$this->updateFunctionEmit($node, "pop");
		}
	}

	/**
	 * processMethodCall
	 *
	 * @param Node\Expr\MethodCall                                                  $node   Instance of Node
	 * @param AbstractionClass|AbstractClassMethod|ReflectedClassMethod|null|string $method Instance of the Method (optional)
	 *
	 * @return void
	 */
	private function processMethodCall(Node\Expr\MethodCall $node, $method) {
		$this->addReferenceParametersToLocalScope($node->args, $method->getParameters());
	}

	/**
	 * processStaticCall
	 *
	 * @param Node\Expr\StaticCall                                                  $node   Instance of Node
	 * @param AbstractionClass|AbstractClassMethod|ReflectedClassMethod|null|string $method Instance of the Method (optional)
	 *
	 * @return void
	 */
	private function processStaticCall(Node\Expr\StaticCall $node, $method) {
		$this->addReferenceParametersToLocalScope($node->args, $method->getParameters());
	}

	/**
	 * @param Node\Arg[]   $args
	 * @param Node\Param[] $params
	 */
	private function addReferenceParametersToLocalScope(array $args, array $params) {
		$paramCount = count($params);
		foreach ($args as $index => $arg) {
			if (
				(isset($params[$index]) && $params[$index]->isReference()) ||
				($index >= $paramCount && $paramCount > 0 && $params[$paramCount - 1]->isReference())
			) {
				$value = $arg->value;
				if ($value instanceof Variable) {
					if (gettype($value->name) == "string" || $value instanceof Node\Identifier) {
						$this->setScopeType(strval($value->name), Scope::MIXED_TYPE, $value->getLine());
					}
				}
			}
		}
	}
}
