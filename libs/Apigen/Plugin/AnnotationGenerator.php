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

/**
 * Annotation generator.
 *
 * Allows generating additional custom annotations.
 *
 * @author Ondřej Nešpor
 * @author Jaroslav Hanslík
 */
interface AnnotationGenerator extends Base
{
	/**
	 * Generates custom annotations.
	 *
	 * Please note that any generated annotation will be processed by appropriate plugins.
	 *
	 * @param \Apigen\Reflection|\TokenReflection\IReflection $element Reflection instance
	 * @return array
	 */
	public function getAnnotations($element);
}
