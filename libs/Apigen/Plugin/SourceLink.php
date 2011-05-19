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
interface SourceLink extends Apigen\Plugin
{
	/**
	 * Returns an URL of a highlighted class source code.
	 *
	 * @param \Apigen\Reflection|\TokenReflection\IReflection $element Reflection instance
	 * @param boolean $filesystemName Determines if a physical filename is requested
	 * @return string
	 */
	public function getSourceLink($element, $filesystemName);
}
