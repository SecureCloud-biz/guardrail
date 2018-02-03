<?php

/**
 * Guardrail.  Copyright (c) 2018, Jonathan Gardiner and BambooHR.
 * Apache 2.0 License
 */

namespace BambooHR\Guardrail;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\ClassLike;

interface TypeInferencePluginInterface {
	function inferNode(Expr $node, ClassLike $inside = null, Scope $scope,  TypeInferrer $inferrer);
}