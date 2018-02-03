<?php

namespace BambooHR\Guardrail\Checks;


use BambooHR\Guardrail\Output\OutputInterface;
use BambooHR\Guardrail\SymbolTable\SymbolTable;
use BambooHR\Guardrail\TypeInferrer;

abstract class TypeInferringBaseCheck extends BaseCheck {

	/** @var TypeInferrer */
	protected $typeInferrer;

	/**
	 * TypeInferringBaseCheck constructor.
	 * @param SymbolTable     $symbolTable
	 * @param OutputInterface $doc
	 * @param TypeInferrer    $inferrer
	 */
	public function __construct(SymbolTable $symbolTable, OutputInterface $doc, TypeInferrer $inferrer) {
		parent::__construct($symbolTable, $doc);
		$this->typeInferrer = $inferrer;
	}
}