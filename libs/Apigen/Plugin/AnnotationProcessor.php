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
 * Annotation processor.
 *
 * Allows custom processing of particular annotation tags.
 *
 * @author Ondřej Nešpor
 * @author Jaroslav Hanslík
 */
interface SourceLink extends Apigen\Plugin
{
	/**#@+
	 * Inline tag with no children, {@link} for example.
	 *
	 * @var integer
	 */
	const TYPE_INLINE_SIMPLE = 0x1;

	/**
	 * Inline tag that can contain another inline tags, {@internal} for example.
	 */
	const TYPE_INLINE_WITH_CHILDREN = 0x2;

	/**
	 * Block tag, @copyright for example.
	 */
	const TYPE_BLOCK = 0x4;

	/**
	 * Output will be escaped.
	 */
	const PROCESS_ESCAPE = 0x10;

	/**
	 * Output will be processed with Texy.
	 */
	const PROCESS_TEXY = 0x20;

	/**
	 * Output will be processed as a single line with Texy.
	 */
	const PROCESS_TEXY_LINE = 0x40;

	/**
	 * Output will be left as is.
	 */
	const PROCESS_RAW = 0x80;
	/**#@-*/

	/**
	 * Returns a list of tags being processed by this plugin.
	 *
	 * Please note that there can be only one plugin for each tag and defining
	 * multiple plugins for a single tag will result in the latest one being used.
	 *
	 * The function has to return an array where keys are tag names and values
	 * are definitions (tag type and postprocessing type) or-ed together.
	 *
	 * This way you can specify multiple tag types (to process a block and inline
	 * tag of the same name). You cannot however specify different postprocessing
	 * options for such tags. They will be postprocessed the same way.
	 *
	 * @return array
	 */
	public function getProcessedTags();

	/**
	 * Processes a tag definition.
	 *
	 * If the function returns null, the original tag definition will stay unchanged.
	 * If the function returns false, the original tag definition will be removed
	 * (replaced with an empty string).
	 *
	 * @param string $tag Tag name
	 * @param string $description Additional description if present
	 * @return string|false|null
	 */
	public function processTag($tag, $description = null);
}
