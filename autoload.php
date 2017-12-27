<?php

spl_autoload_register(function ($class) {
	$lib = __DIR__ . '/lib/';
	$file = $lib . $class . '.php';

	if (file_exists($file)) {
		include_once($file);
	}
});