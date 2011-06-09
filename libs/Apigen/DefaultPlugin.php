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

use Apigen\Plugin;

/**
 * Default Apigen plugin.
 *
 * Creates links to the highlighted source code and processes several docblock tags.
 *
 * This can serve as an example implementation of TR ApiGen plugins functionality.
 *
 * As you can see, a class can implement multiple plugin interfaces. This lets you
 * develop more complex plugins that affect the output in multiple ways.
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
	 * Returns the filename (relative to the destination directory)
	 * of a highlighted source code file.
	 *
	 * @param \Apigen\ReflectionBase $element Reflection element
	 * @return string|null
	 */
	public function getSourceFileName(ReflectionBase $element)
	{
		$fileName = '';

		if ($element instanceof ReflectionClass || $element instanceof ReflectionFunction || ($element instanceof ReflectionConstant && null === $element->getDeclaringClassName())) {
			$elementName = $element->getName();

			if ($element instanceof ReflectionFunction) {
				$fileName = 'function-';
			} elseif ($element instanceof ReflectionConstant) {
				$fileName = 'constant-';
			}
		} else {
			$elementName = $element->getDeclaringClassName();
		}

		$fileName = sprintf(
			$this->config->templates['main']['source']['filename'],
			$fileName . preg_replace('#[^a-z0-9_]#i', '.', $elementName)
		);
	}

	/**
	 * Returns URL (relative to the destination directory) of a highlighted
	 * source code file including line anchors.
	 *
	 * @param \Apigen\ReflectionBase $element Reflection element
	 * @return string
	 */
	public function getSourceUrl(ReflectionBase $element)
	{
		$line = $element->getStartLine();
		if ($doc = $element->getDocComment()) {
			$line -= substr_count($doc, "\n") + 1;
		}

		return $this->getSourceFileName($element) . '#' . $line;
	}

	/**
	 * Returns a list of tags being processed by this plugin.
	 *
	 * @return array
	 */
	public function getProcessedTags()
	{
		return array(
			'package' => self::TYPE_BLOCK,
			'subpackage' => self::TYPE_BLOCK,
			'see' => self::TYPE_BLOCK | self::TYPE_INLINE_SIMPLE,
			'uses' => self::TYPE_BLOCK | self::TYPE_INLINE_SIMPLE,
			'link' => self::TYPE_BLOCK | self::TYPE_INLINE_SIMPLE,
			'var' => self::TYPE_BLOCK,
			'param' => self::TYPE_BLOCK,
			'return' => self::TYPE_BLOCK,
			'throws' => self::TYPE_BLOCK,
			'throw' => self::TYPE_BLOCK,

			// ignored tags
			'property-read' => self::TYPE_BLOCK,
			'property-write' => self::TYPE_BLOCK,
			'method' => self::TYPE_BLOCK,
			'abstract' => self::TYPE_BLOCK,
			'access' => self::TYPE_BLOCK,
			'final' => self::TYPE_BLOCK,
			'filesource' => self::TYPE_BLOCK,
			'global' => self::TYPE_BLOCK,
			'name' => self::TYPE_BLOCK,
			'static' => self::TYPE_BLOCK,
			'staticvar' => self::TYPE_BLOCK,
		);
	}

	/**
	 * Processes a tag name.
	 *
	 * @param string $tag Tag name
	 * @param integer $type Tag type
	 * @param \ApiGen\ReflectionBase $element Documented reflection element
	 * @return string|null
	 */
	public function getTagName($tag, $type, ReflectionBase $element)
	{
		static $ignored = array(
			'property' => true,
			'property-read' => true,
			'property-write' => true,
			'method' => true,
			'abstract' => true,
			'access' => true,
			'final' => true,
			'filesource' => true,
			'global' => true,
			'name' => true,
			'static' => true,
			'staticvar' => true
		);

		if (isset($ignored[$tag])) {
			return '';
		}

		return $tag;
	}

	/**
	 * Processes a tag value.
	 *
	 * Retrieves the tag value (if any) and returns its processed form.
	 *
	 * @param string $tag Tag name
	 * @param integer $type Tag type
	 * @param string $value Tag value
	 * @param \ApiGen\ReflectionBase $element Documented reflection element
	 * @return string
	 */
	public function getTagValue($tag, $type, $value, ReflectionBase $element)
	{
		switch ($tag) {
			case 'package':
				list($packageName, $description) = $this->template->split($value);
				if ($this->template->packages) {
					return $this->template->link($this->template->getPackageUrl($packageName), $packageName) . ' ' . $this->template->doc($description, $element);
				}
				break;
			case 'subpackage':
				if ($element->hasAnnotation('package')) {
					list($packageName) = $this->template->split($element->annotations['package'][0]);
				} else {
					$packageName = '';
				}
				list($subpackageName, $description) = $this->template->split($value);

				if ($this->template->packages && $packageName) {
					return $this->template->link($this->template->getPackageUrl($packageName . '\\' . $subpackageName), $subpackageName) . ' ' . $this->template->doc($description, $element);
				}
				break;
			case 'param':
			case 'return':
			case 'throws':
			case 'throw':
			case 'var':
					$description = $this->template->description($value, $element);
					return sprintf('<code>%s</code>%s', $this->template->getTypeLinks($value, $element), $description ? '<br />' . $description : '');
				break;
			case 'internal':
				return $this->config->internal ? $this->template->escapeHtml($value) : '';
				break;
			case 'link':
			case 'see':
				if (false !== strpos($value, '://')) {
					return $this->template->link($this->template->escapeHtml($value), $value);
				} elseif (false !== strpos($value, '@')) {
					return $this->template->link('mailto:' . $this->template->escapeHtml($value), $value);
				}
				// Break missing on purpose
			case 'uses':
				list($link, $description) = $this->template->split($value);
				$separator = $element instanceof ReflectionClass || !$description ? ' ' : '<br />';
				if (null !== $this->template->resolveElement($link, $element)) {
					return sprintf('<code>%s</code>%s%s', $this->template->getTypeLinks($link, $element), $separator, $description);
				}

				return $this->template->escapeHtml($value);
			default:
				throw new Exception(sprintf('Unsupported tag: %s', $tag));
		}
	}
}
