<?php

class ArgumentParser {
	protected $command;
	protected $flags = [];

	public function __construct($command, $flags) {
		$this->command = $command;
		$this->flags = $flags;
	}

	private static function parseArgs($args, $toggle_flags=array()) {
		$flags = array();
		$current_arg = null;

		foreach($args as $arg) {
			if (substr($arg, 0, 2) == '--') {
				$current_arg = substr($arg, 2);
				$flags[$current_arg] = true;
			} else if (substr($arg, 0, 1) == '-') {
				$current_arg = substr($arg, 1);
				$flags[$current_arg] = true;
			} else {
				if (null === $current_arg || in_array($current_arg, $toggle_flags)) {
					$flags['__value'] = $arg;
				} else {
					$flags[$current_arg] = $arg;
				}
				$current_arg = null;
			}
		}

		return $flags;
	}

	public function getFlag($name, $default=null) {
		return $this->isFlagSet($name) ? $this->flags[$name] : $default;
	}

	public function isFlagSet($name) {
		return isset($this->flags[$name]);
	}

	public function getValue() {
		return $this->getFlag('__value');
	}

	/**
	 * @param array $toggle_flags
	 * @return ArgumentParser
	 */
	public static function create($toggle_flags = array()) {
		global $argv;

		$args = $argv;
		$command = array_shift($args);
		$flags = static::parseArgs($args, $toggle_flags);

		return new ArgumentParser($command, $flags);
	}
}