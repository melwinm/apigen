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

namespace Apigen;
use Apigen\Plugin, Apigen\Reflection as ApiReflection;

/**
 * Default Apigen plugin.
 *
 * Creates links to the highlighted source code and processes several docblock tags.
 *
 * This can serve as an example implementation of TR ApiGen plugins functionality.
 * Implementing two plugin interfaces makes this a "double plugin" actually :)
 *
 * @author Ondřej Nešpor
 * @author Jaroslav Hanslík
 */
class DefaultPlugin implements Plugin\SourceLink, Plugin\AnnotationProcessor
{
	/**
	 * Template instance.
	 *
	 * @var \Apigen\Template
	 */
	protected $template;

	/**
	 * Config instance.
	 *
	 * @var \Apigen\Config
	 */
	protected $config;

	/**
	 * Plugin constructor.
	 *
	 * @param \Apigen\Template $template Template instance
	 * @param \Apigen\Config $config Configuration
	 * @param \Apigen\Config $config Configuration
	 */
	public function __construct(Generator $generator, Template $template, Config $config)
	{
		$this->template = $template;
		$this->config = $config;
	}

	/**
	 * Returns an URL of a highlighted class source code.
	 *
	 * @param \Apigen\Reflection|\TokenReflection\IReflection $element Reflection instance
	 * @param boolean $filesystemName Determines if a physical filename is requested
	 * @return string
	 */
	public function getSourceLink($element, $filesystemName)
	{
		$class = $element instanceof ApiReflection ? $element : $element->getDeclaringClass();

		$filename = sprintf(
			$this->config->templates['main']['source']['filename'],
			preg_replace('#[^a-z0-9_]#i', '.', str_replace('\\', '/', $class->getName()))
		);

		if ($filesystemName) {
			return $filename;
		}

		$line = $element->getStartLine();
		if ($doc = $element->getDocComment()) {
			$line -= substr_count($doc, "\n") + 1;
		}

		return $filename . '#' . $line;
	}

	/**
	 * Returns a list of tags being processed by this plugin.
	 *
	 * @return array
	 */
	public function getProcessedTags()
	{
		return array(
			'package' => self::TYPE_BLOCK | self::TYPE_INLINE_SIMPLE,
			'see' => self::TYPE_BLOCK | self::TYPE_INLINE_SIMPLE,
			'uses' => self::TYPE_BLOCK | self::TYPE_INLINE_SIMPLE,
			'link' => self::TYPE_BLOCK | self::TYPE_INLINE_SIMPLE,

			// ignored tags
			'property*' => self::TYPE_BLOCK,
			'method' => self::TYPE_BLOCK,
			'abstract' => self::TYPE_BLOCK,
			'access' => self::TYPE_BLOCK,
			'final' => self::TYPE_BLOCK,
			'filesource' => self::TYPE_BLOCK,
			'global' => self::TYPE_BLOCK,
			'name' => self::TYPE_BLOCK,
			'static*' => self::TYPE_BLOCK,
		);
	}

	/**
	 * Processes a tag name.
	 *
	 * @param string $tag Tag name
	 * @param integer $type Tag type
	 * @return string
	 */
	public function getTagName($tag, $type)
	{
		static $ignored = array('property', 'property-read', 'property-write', 'method', 'abstract', 'access', 'final', 'filesource', 'global', 'name', 'static', 'staticvar');
		return in_array($tag, $ignored) ? '' : $tag;
	}

	/**
	 * Processes a tag value.
	 *
	 * @param string $tag Tag name
	 * @param integer $type Tag type
	 * @param string $value Tag value
	 * @return string
	 */
	public function getTagValue($tag, $type, $value)
	{
		switch ($tag) {
			case 'package':
				return sprintf('<a href="%s">%s</a>', $this->template->packageLink($value), $this->template->escapeHtml($value));
				break;
			case 'link':
				if (false !== strpos($value, '://')) {
					return sprintf('<a href="%1$s">%1$s</a>', $this->template->escapeHtml($value));
				} elseif (false !== strpos($value, '@')) {
					return sprintf('<a href="mailto:%1$s">%1$s</a>', $this->template->escapeHtml($value));
				}
				// Break missing on purpose
			case 'see':
			case 'uses':
				if (!empty($this->template->class)) {
					$context = $this->template->class;
				} else {
					$context = $this->template->getContext();
				}

				return $this->template->resolveClassLink($value, $context) ?: $this->template->escapeHtml($value);
				break;
			default:
				return $this->template->escapeHtml($value);
				break;
		}
	}
}
