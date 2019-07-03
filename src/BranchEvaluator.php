<?php

namespace BambooHR\Guardrail;


use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Switch_;

class BranchEvaluator {
	private $errors = [];

	/**
	 * allBranchesExit
	 *
	 * @param array $stmts List of statements
	 *
	 * @return bool
	 */
	public function statementsAlwaysExit(array $stmts) {
		$stmtCount = count($stmts);
		for($i = 0; $i < $stmtCount; ++$i) {
			if ($stmts[$i] instanceof Nop) {
				continue;
			}
			if ($this->statementAlwaysExits($stmts[$i])) {
				while ($i < $stmtCount && $stmts[$i] instanceof Nop) {
					++$i;
				}
				if ($i < $stmtCount) {
					$this->errors[] = $stmts[$i];
					return true;
				}
			}
		}
		return false;
	}

	/**
	 *
	 */
	public function reset() {
		$this->errors = [];
	}

	/**
	 * @return array
	 */
	public function getErrors() {
		return $this->errors;
	}

	/**
	 * allIfBranchesExit
	 *
	 * @param If_ $lastStatement Instance of If_
	 *
	 * @return bool
	 */
	private function allIfBranchesExit(If_ $lastStatement) {
		if (!$lastStatement->else && !$lastStatement->elseifs) {
			return false;
		}
		$trueCond = $this->statementsAlwaysExit($lastStatement->stmts);
		if (!$trueCond) {
			return false;
		}
		if ($lastStatement->else && !$this->statementsAlwaysExit($lastStatement->else->stmts)) {
			return false;
		}
		if ($lastStatement->elseifs) {
			foreach ($lastStatement->elseifs as $elseIf) {
				if (!$this->statementsAlwaysExit($elseIf->stmts)) {
					return false;
				}
			}
			// If an elseif doesn't also have trailing else,
			if (!$lastStatement->else || $this->statementsAlwaysExit($lastStatement->else->stmts)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * allSwitchCasesExit
	 *
	 * @param Switch_ $lastStatement Instance of Switch_
	 *
	 * @return bool
	 */
	private function allSwitchCasesExit(Switch_ $lastStatement) {
		$hasDefault = false;
		foreach ($lastStatement->cases as $case) {
			if (!$case->cond) {
				$hasDefault = true;
			}
			$stmts = $case->stmts;
			// Remove the trailing break (if found) and just look for a return the statement prior
			while ( ($last = end($stmts)) instanceof Break_ || $last instanceof Nop) {
				$stmts = array_slice($stmts, 0, -1);
			}
			if ($stmts && !$this->statementsAlwaysExit($stmts)) {
				return false;
			}
		}
		return $hasDefault;
	}

	/**
	 * @param $lastStatement
	 * @return bool
	 */
	private function statementAlwaysExits($lastStatement): bool {
		if (!$lastStatement) {
			return false;
		} else if ($lastStatement instanceof Exit_ || $lastStatement instanceof Return_) {
			return true;
		} else if ($lastStatement instanceof If_) {
			return $this->allIfBranchesExit($lastStatement);
		} else if ($lastStatement instanceof Switch_) {
			return $this->allSwitchCasesExit($lastStatement);
		} else {
			return false;
		}
	}
}