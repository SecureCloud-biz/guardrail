<?php

abstract class MyTestClass {
	public function checkStoredClass() {

	}
}

class MyOtherClass {

	public function checkStoredClass() {
		$classVar = 'MyTestClass';
		$object = new $classVar;
	}
}