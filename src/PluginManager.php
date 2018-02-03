<?php

/**
 * Guardrail.  Copyright (c) 2018, Jonathan Gardiner and BambooHR.
 * Apache 2.0 License
 */

namespace BambooHR\Guardrail;


use BambooHR\Guardrail\Checks\BaseCheck;
use BambooHR\Guardrail\Output\OutputInterface;
use BambooHR\Guardrail\SymbolTable\SymbolTable;

class PluginManager {
	private $checkingPlugins = [];
	private $typeInferrerPlugins = [];

	private $config;

	/**
	 * PluginManager constructor.
	 * @param Config $config
	 */
	function __construct(Config $config) {
		$this->config = $config;
	}

	/**
	 * @return BaseCheck[]
	 */
	function getTypeInferrerPlugins() {
		return $this->typeInferrerPlugins;
	}

	/**
	 * @return TypeInferencePluginInterface[]
	 */
	function getCheckingPlugins() {
		return $this->checkingPlugins;
	}

	/**
	 * @param                 $plugins
	 * @param SymbolTable     $index
	 * @param OutputInterface $output
	 */
	function initPlugins($plugins, SymbolTable $index, OutputInterface $output) {
		foreach ($plugins as $fileName) {
			$fullPath = Util::fullDirectoryPath($this->config->getBasePath(), $fileName);
			$function = require $fullPath;
			try {
				$method = new \ReflectionFunction($function);
				if ($method->getNumberOfParameters() == 0) {
					$class = call_user_func($function);
				} else {
					$class = call_user_func($function, $index, $output);
				}

				if ($class instanceof TypeInferencePluginInterface) {
					$this->typeInferrerPlugins[] = $class;
				} else if ($class instanceof BaseCheck) {
					$this->checkingPlugins[] = $class;
				} else {
					echo "Unknown plugin type... What do I do with a " . get_class($class)."\n";
					exit(1);
				}
			}
			catch(\ReflectionException $exc) {
				echo "Unable to initialize plugin: $fullPath\n";
				exit(1);
			}
		}
	}
}