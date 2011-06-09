<?php
/**
 * ApiGen - API Generator.
 *
 * Copyright (c) 2010 David Grudl (http://davidgrudl.com)
 * Copyright (c) 2011 Ondřej Nešpor (http://andrewsville.cz)
 * Copyright (c) 2011 Jaroslav Hanslík (http://kukulich.cz)
 *
 * This source file is subject to the "Nette license", and/or
 * GPL license. For more information please see http://nette.org
 */

namespace Apigen\Plugin;

use Apigen;

/**
 * Source link plugin interface.
 *
 * This interface allows replacing the default link to the highlighted
 * documentation with a custom URL.
 *
 * @author Ondřej Nešpor
 * @author Jaroslav Hanslík
 */
interface SourceLink extends Base
{
	/**
	 * Returns the filename (relative to the destination directory)
	 * of a highlighted source code file.
	 *
	 * If the method returns null, no such file will be generated.
	 *
	 * @param \Apigen\ReflectionBase $element Reflection element
	 * @return string|null
	 */
	public function getSourceFileName(Apigen\ReflectionBase $element);

	/**
	 * Returns URL (relative to the destination directory) of a highlighted
	 * source code file including line anchors.
	 *
	 * @param \Apigen\ReflectionBase $element Reflection element
	 * @return string
	 */
	public function getSourceUrl(Apigen\ReflectionBase $element);
}
