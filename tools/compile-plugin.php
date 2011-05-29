<?php

/**
 * ApiGen - API Generator.
 *
 * Copyright (c) 2010 David Grudl (http://davidgrudl.com)
 * Copyright (c) 2011 Ondřej Nešpor (http://andrewsville.cz)
 * Copyright (c) 2011 Jaroslav Hanslík (http://kukulich.cz)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */

/**
 * Plugin compiler.
 *
 * Takes individual plugin files and stores them in a single PHAR archive.
 *
 * Using the -s console option you can specify files that should be included.
 * If you want to store files using relative filenames, use two directory
 * separators where the relative path should begin; e.g.: /path//dir/file.php
 * will be stored as dir/file.php.
 *
 * The -d option sets the output PHAR archive filename.
 *
 * @author Ondřej Nešpor
 */

try {
	if (!extension_loaded('Phar')) {
		throw new RuntimeException('The PHAR extension has to be loaded.');
	}

	$options = getopt('s:d:');

	if (empty($options['s'])) {
		throw new InvalidArgumentException('You have to specify at least one plugin file using the -s option.');
	}

	if (empty($options['d'])) {
		throw new InvalidArgumentException('You have to specify the output filename using the -d option.');
	}

	if (is_array($options['d'])) {
		throw new UnexpectedValueException('You can specify only one target PHAR filename.');
	}

	$package = new Phar($options['d']);
	foreach ((array) $options['s'] as $source) {
		if (strpos($source, DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR)) {
			$relativeName = substr(strstr($source, DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR), 2);
		} else {
			$relativeName = $source;
		}

		$package->addFile($source, $relativeName);
	}
	$package->setStub('<?php Phar::interceptFileFuncs(); __HALT_COMPILER(); ?>');

	echo "Done.\n";
} catch (Exception $e) {
	echo $e->getMessage() . "\n";
	exit(1);
}