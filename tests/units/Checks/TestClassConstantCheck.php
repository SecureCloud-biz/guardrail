<?php namespace BambooHR\Guardrail\Tests\Checks;

use BambooHR\Guardrail\Checks\ErrorConstants;
use BambooHR\Guardrail\Tests\TestSuiteSetup;

/**
 * Class TestClassConstantCheck
 *
 * @package BambooHR\Guardrail\Tests\Checks
 */
class TestClassConstantCheck extends TestSuiteSetup {

	/**
	 * testCantUseParentWithNoParent
	 *
	 * @return void
	 * @rapid-unit Checks:ClassConstantCheck:Cannot access parent constant inside a class that has no parent
	 */
	public function testCantUseParentWithNoParent() {
		$this->assertEquals(1, $this->runAnalyzerOnFile('.1.inc', ErrorConstants::TYPE_SCOPE_ERROR));
	}

	/**
	 * testAccessParentOutsideOfClass
	 *
	 * @return void
	 * @rapid-unit Checks:ClassConstantCheck:Cannot access parent constant outside of class
	 */
	public function testAccessParentOutsideOfClass() {
		$this->assertEquals(1, $this->runAnalyzerOnFile('.2.inc', ErrorConstants::TYPE_SCOPE_ERROR));
	}

	/**
	 * testAccessSelfOutsideOfClass
	 *
	 * @return void
	 * @rapid-unit Checks:ClassConstantCheck:Cannot access constant using self:: outside of class
	 */
	public function testAccessSelfOutsideOfClass() {
		$this->assertEquals(1, $this->runAnalyzerOnFile('.3.inc', ErrorConstants::TYPE_SCOPE_ERROR));
	}

	/**
	 * testAccessStaticOutsideOfClass
	 *
	 * @return void
	 * @rapid-unit Checks:ClassConstantCheck:Cannot access constant using static:: outside of class
	 */
	public function testAccessStaticOutsideOfClass() {
		$this->assertEquals(1, $this->runAnalyzerOnFile('.4.inc', ErrorConstants::TYPE_SCOPE_ERROR));
	}

	/**
	 * testAccessingUnknownClassConstant
	 *
	 * @return void
	 * @rapid-unit Checks:ClassConstantCheck:Catches trying to access unknown constant inside class
	 */
	public function testAccessingUnknownClassConstant() {
		$this->assertEquals(1, $this->runAnalyzerOnFile('.5.inc', ErrorConstants::TYPE_UNKNOWN_CLASS_CONSTANT));
	}

	/**
	 * testAccessingUnknownClass
	 *
	 * @return void
	 * @rapid-unit Checks:ClassConstantCheck:Cacthes trying to access constant in unknown class
	 */
	public function testAccessingUnknownClass() {
		$this->assertEquals(1, $this->runAnalyzerOnFile('.6.inc', ErrorConstants::TYPE_UNKNOWN_CLASS));
	}
}
