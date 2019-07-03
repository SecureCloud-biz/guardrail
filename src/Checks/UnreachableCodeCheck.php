<?php namespace BambooHR\Guardrail\Checks;

use BambooHR\Guardrail\BranchEvaluator;
use BambooHR\Guardrail\Scope;
use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Class UnreachableCodeCheck
 *
 * @package BambooHR\Guardrail\Checks
 */
class UnreachableCodeCheck extends BaseCheck {

	/**
	 * getCheckNodeTypes
	 *
	 * @return string[]
	 */
	function getCheckNodeTypes() {
		return [ Function_::class, ClassMethod::class ];
	}

	/**
	 * run
	 *
	 * @param string         $fileName The name of the file we are parsing
	 * @param Node           $node     Instance of the Node
	 * @param ClassLike|null $inside   Instance of the ClassLike (the class we are parsing) [optional]
	 * @param Scope|null     $scope    Instance of the Scope (all variables in the current state) [optional]
	 *
	 * @return void
	 */
	public function run($fileName, Node $node, ClassLike $inside = null, Scope $scope = null) {
		if ($node instanceof Function_ || $node instanceof ClassMethod) {
			$statements = [];
			if ($node instanceof FunctionLike) {
				$statements = $node->getStmts();
				if (!is_array($statements)) {
					$statements = [$statements];
				}
			}
			if (count($statements) >= 2) {
				$evaluator = new BranchEvaluator();
				$evaluator->statementsAlwaysExit($statements);
				foreach ($evaluator->getErrors() as $unreachableStatement) {
					$this->emitError($fileName, $unreachableStatement, ErrorConstants::TYPE_UNREACHABLE_CODE, "Unreachable code was found.");
				}
			}
		}
	}
}