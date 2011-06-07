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

use Apigen\Plugin, TokenReflection, TokenReflection\Broker, Texy, ArrayAccess, ArrayObject;

/**
 * Plugins loader.
 *
 * @author Ondřej Nešpor
 */
class Plugins
{
	/**#@+
	 * SourceLink plugins identifier.
	 *
	 * @var string
	 */
	const PLUGIN_SOURCELINK = 'sourceLink';

	/**
	 * Annotation processors identifier.
	 */
	const PLUGIN_ANNOTATION_PROCESSOR = 'processor';
	/**#@-*/

	/**
	 * Regular expression for recursive extracting of inline tags.
	 *
	 * @var string
	 */
	const INLINE_TAGS_REGEX = '{@(\\w+)(?:(?:\\s++((?>(?R)|[^{}]+)*)})|})';

	/**
	 * Generator instance.
	 *
	 * @var \Apigen\Generator
	 */
	private $generator;

	/**
	 * Template instance.
	 *
	 * @var \Apigen\Template
	 */
	private $template;

	/**
	 * Configuration instance.
	 *
	 * @var \Apigen\Config
	 */
	private $config;

	/**
	 * Plugin container.
	 *
	 * @var \ArrayObject
	 */
	private $plugins;

	/**
	 * Constructor.
	 *
	 * Prepares plugin callbacks into given Template and Texy instances.
	 *
	 * @param \Apigen\Generator $generator Generator instance
	 * @param \Apigen\Template $template Template instance
	 * @param \Texy $texy Texy instance
	 */
	public function __construct(Generator $generator, Template $template, Texy $texy)
	{
		$this->generator = $generator;
		$this->config = $generator->getConfig();
		$this->template = $template;
		$this->plugins = new ArrayObject();

		// Register plugins defined in configuration
		$this->registerPlugins();

		// Inline tags (via plugins)
		$texy->registerLinePattern(
			array($this, 'processInlineTag'),
			sprintf('~%s~', self::INLINE_TAGS_REGEX),
			'inlineTag'
		);
	}

	/**
	 * Texy callback for plugin-based processing of line tags.
	 *
	 * @param \TexyParser $parser Texy parser instance
	 * @param array $matches Tag match definition
	 * @param string $name Block name
	 * @param integet $level Nesting level
	 * @return string
	 */
	public function processInlineTag(\TexyParser $parser, $matches, $name, $level = 1)
	{
		list($original, $tag, $value) = $matches;

		if (isset($this->plugins[self::PLUGIN_ANNOTATION_PROCESSOR][$tag][Plugin\AnnotationProcessor::TYPE_INLINE_SIMPLE])) {
			// Simple inline tag, no children
			$plugin = $this->plugins[self::PLUGIN_ANNOTATION_PROCESSOR][$tag][Plugin\AnnotationProcessor::TYPE_INLINE_SIMPLE];
			$type = Plugin\AnnotationProcessor::TYPE_INLINE_SIMPLE;
		} elseif (isset($this->plugins[self::PLUGIN_ANNOTATION_PROCESSOR][$tag][Plugin\AnnotationProcessor::TYPE_INLINE_WITH_CHILDREN])) {
			// Inline with possible children
			$plugin = $this->plugins[self::PLUGIN_ANNOTATION_PROCESSOR][$tag][Plugin\AnnotationProcessor::TYPE_INLINE_WITH_CHILDREN];
			$type = Plugin\AnnotationProcessor::TYPE_INLINE_WITH_CHILDREN;
		} else  {
			// No plugin found -> recursively process nested tags, but do not process the final value
			$plugin = null;
			$type = Plugin\AnnotationProcessor::TYPE_INLINE_WITH_CHILDREN;
		}

		if (null !== $plugin) {
			$tagName = $plugin->getTagName($tag, $type);
			if ('' === $tagName) {
				// Removing the tag
				return '';
			}
		}

		if (Plugin\AnnotationProcessor::TYPE_INLINE_WITH_CHILDREN === $type && preg_match_all(sprintf('~%s~', self::INLINE_TAGS_REGEX), $value, $matches, PREG_OFFSET_CAPTURE)) {
			// Recursively process subtags
			for ($index = count($matches[0]) - 1; $index >= 0 ; $index--) {
				// Walk backwards so that offset values match even after replacing with a value of different length
				$offset = $matches[0][$index][1];
				$length = strlen($matches[0][$index][0]);

				$value = sprintf(
					'%s%s%s',
					substr($value, 0, $offset),
					$this->processInlineTag(
						$parser,
						array($matches[0][$index][0], $matches[1][$index][0], isset($matches[2][$index][0]) ? $matches[2][$index][0] : null),
						$name,
						$level + 1
					),
					substr($value, $offset + $length)
				);
			}
		}

		if (null !== $plugin) {
			$value = $plugin->getTagValue($tag, $type, $value);
		} else {
			$value = sprintf('{@%s%s%s}', $tag, empty($value) ? '' : ' ', $value);
		}
		return 1 === $level ? $parser->getTexy()->protect($value, \Texy::CONTENT_MARKUP) : $value;
	}

	/**
	 * Processes block tags from elements documentation.
	 *
	 * @param \Apigen\Reflection|\TokenReflection\ReflectionBase $element Reflection instance
	 * @param array $ignore Array of ignored annotations
	 * @return array
	 */
	public function processBlockTags($element, $ignore = array())
	{
		// Get raw annotations
		$annotations = $this->getAnnotations($element);

		if (!empty($ignore)) {
			// Ignore given tags
			$annotations = array_diff_key($annotations, array_flip($ignore));
		}

		// Remove descriptions
		unset($annotations[TokenReflection\ReflectionAnnotation::LONG_DESCRIPTION], $annotations[TokenReflection\ReflectionAnnotation::SHORT_DESCRIPTION]);

		// Show/hide todo
		if (!$this->config->todo) {
			unset($annotations['todo']);
		}

		// Put tags into a consistent order
		uksort($annotations, function($a, $b) {
			static $order = array(
				'deprecated' => 0, 'category' => 1, 'package' => 2, 'subpackage' => 3, 'copyright' => 4,
				'license' => 5, 'author' => 6, 'version' => 7, 'since' => 8, 'see' => 9, 'uses' => 10,
				'link' => 11, 'example' => 12, 'tutorial' => 13, 'todo' => 14
			);

			$orderA = isset($order[$a]) ? $order[$a] : 99;
			$orderB = isset($order[$b]) ? $order[$b] : 99;
			return $orderA - $orderB;
		});

		// Process each annotation tag
		foreach ($annotations as $name => $values) {
			$searchName = strtolower(preg_replace('~^([\\w-]+).*~i', '\\1', $name));

			// Find the appropriate plugin
			if (isset($this->plugins[self::PLUGIN_ANNOTATION_PROCESSOR][$searchName][Plugin\AnnotationProcessor::TYPE_BLOCK])) {
				$plugin = $this->plugins[self::PLUGIN_ANNOTATION_PROCESSOR][$searchName][Plugin\AnnotationProcessor::TYPE_BLOCK];
				$tagName = $plugin->getTagName($name, Plugin\AnnotationProcessor::TYPE_BLOCK);

				// Remove the particular annotation
				if ('' === $tagName) {
					unset($annotations[$name]);
				} else {
					$tagValues = array();
					foreach ($values as $index => $value) {
						// Set the processed values
						$tagValues[$index] = $plugin->getTagValue($name, Plugin\AnnotationProcessor::TYPE_BLOCK, $value);
					}


					if ($name !== $tagName) {
						// Tag name altered
						unset($annotations[$name]);
						$name = $tagName;
					}

					$annotations[$name] = $tagValues;
				}
			} else {
				// No plugin found, just escape the value
				$annotations[$name] = array_map(array($this->template, 'escapeHtml'), $values);
			}
		}

		return $annotations;
	}

	/**
	 * Returns an array of annotations from the given reflection instance.
	 *
	 * Adds custom annotations created by registered generator plugins.
	 *
	 * @param \Apigen\Reflection|\TokenReflection\IReflection $element Reflection instance
	 * @return array
	 */
	public function getAnnotations($element)
	{
		return $element->getAnnotations();

	}

	/**
	 * Registers custom plugins.
	 *
	 * @return array
	 * @throws \Apigen\Exception When no sourceLink plugin is registered
	 */
	private function registerPlugins()
	{
		$this->plugins = new \ArrayObject();

		// Load plugin files and find plugins
		$pluginBroker = new Broker(new Broker\Backend\Memory(), false);
		$pluginBroker->processDirectory(__DIR__ . '/Plugin');
		$pluginBroker->processFile(__DIR__ . '/DefaultPlugin.php');

		// Process plugin files and directories
		foreach ($this->config->plugin as $path) {
			if (is_dir($path)) {
				$pluginBroker->processDirectory($path);
			} else {
				$pluginBroker->processFile($path);
			}
		}

		// Process found classes and detect plugin types
		$plugins = $pluginBroker->getClasses(Backend::TOKENIZED_CLASSES);
		foreach ($plugins as $plugin) {
			$this->registerPlugin($plugin);
		}

		if (empty($this->plugins[self::PLUGIN_SOURCELINK])) {
			throw new Exception('No sourceLink plugin was registered');
		}

		$tmp = $this->plugins->getArrayCopy();
		array_walk_recursive($tmp, function(Plugin\Base $plugin) use(&$pluginNames) {
			$pluginNames[get_class($plugin)] = true;
		});
		$this->generator->output(sprintf("Using plugins\n %s\n", implode("\n ", array_keys($pluginNames))));

		return $plugins;
	}

	/**
	 * Registers a particular custom plugin.
	 *
	 * @param \TokenReflection\ReflectionClass $plugin Plugin class reflection
	 * @return boolean
	 */
	private function registerPlugin(TokenReflection\ReflectionClass $class)
	{
		$result = false;

		if ($class->isInterface() || !$class->implementsInterface('Apigen\\Plugin\\Base')) {
			// Class is an interface or does not implement the plugin interface
			return $result;
		}

		if (!include_once($class->getFileName())) {
			// Cannot include the plugin file
			throw new Exception(sprintf('Could not include plugin file "%s".', $class->getFileName()));
		}

		// Create a plugin instance
		$plugin = $class->newInstance($this->generator, $this->template, $this->config);

		// Plugin is a sourceLink
		if ($class->implementsInterface('Apigen\\Plugin\\SourceLink')) {
			$this->plugins[self::PLUGIN_SOURCELINK] = $plugin;
			$result = true;
		}

		// Plugin is an annotationProcessor
		if ($class->implementsInterface('Apigen\\Plugin\\AnnotationProcessor')) {
			static $types = array(
				Plugin\AnnotationProcessor::TYPE_BLOCK,
				Plugin\AnnotationProcessor::TYPE_INLINE_SIMPLE,
				Plugin\AnnotationProcessor::TYPE_INLINE_WITH_CHILDREN
			);

			foreach ($plugin->getProcessedTags() as $tag => $options) {
				$tag = strtolower($tag);
				foreach ($types as $type) {
					if ($options & $type) {
						// Register for a particular tag and type
						$this->plugins[self::PLUGIN_ANNOTATION_PROCESSOR][$tag][$type] = $plugin;
						$result = true;
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Returns a link to a element source code page.
	 *
	 * @param \Apigen\Reflection|\TokenReflection\IReflectionMethod|\TokenReflection\IReflectionProperty|\TokenReflection\IReflectionConstant|\TokenReflection\IReflectionFunction $element Element reflection
	 * @param boolean $filesystemName Determines if a physical filename is requested
	 * @return string
	 */
	public function getSourceUrl($element, $filesystemName = false)
	{
		return $this->plugins[self::PLUGIN_SOURCELINK]->getSourceLink($element, $filesystemName);
	}
}
