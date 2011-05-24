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

use Nette;
use Apigen\Reflection as ApiReflection, Apigen\Generator;
use TokenReflection, TokenReflection\Broker, TokenReflection\ReflectionBase, TokenReflection\ReflectionAnnotation;
use TokenReflection\IReflectionClass as ReflectionClass, TokenReflection\IReflectionProperty as ReflectionProperty, TokenReflection\IReflectionMethod as ReflectionMethod, TokenReflection\IReflectionConstant as ReflectionConstant, TokenReflection\IReflectionParameter as ReflectionParameter;

/**
 * Customized ApiGen template class.
 *
 * Adds ApiGen helpers to the Nette\Templating\FileTemplate parent class.
 *
 * @author Jaroslav Hanslík
 * @author Ondřej Nešpor
 */
class Template extends Nette\Templating\FileTemplate
{
	/**
	 * Regular expression for recursive extracting of inline tags.
	 *
	 * @var string
	 */
	const INLINE_TAGS_REGEX = '{@(\\w+)(?:(?:\\s++((?>(?R)|[^{}]+)*)})|})';

	/**
	 * Processing contexts stack.
	 *
	 * @var array
	 */
	public static $contexts = array();

	/**
	 * Config.
	 *
	 * @var \Apigen\Config
	 */
	private $config;

	/**
	 * List of classes.
	 *
	 * @var array
	 */
	private $classes;

	/**
	 * Highlighted source link plugin.
	 *
	 * @var \Apigen\Plugin\SourceLink
	 */
	private $sourceLinkPlugin;

	/**
	 * Annotation tag processing plugins.
	 *
	 * @var array of \Apigen\Plugin\AnnotationProcessor
	 */
	private $annotationPlugins = array();

	/**
	 * Creates a template.
	 *
	 * @param \Apigen\Generator $generator
	 */
	public function __construct(Generator $generator)
	{
		$this->config = $generator->getConfig();
		$this->classes = $generator->getClasses();
		$this->preparePlugins($generator);

		$that = $this;

		$latte = new Nette\Latte\Engine;
		$latte->parser->macros['try'] = '<?php try { ?>';
		$latte->parser->macros['/try'] = '<?php } catch (\Exception $e) {} ?>';
		$latte->parser->macros['foreach'] = '<?php foreach (%:macroForeach%): if (!$iterator->isFirst()) $template->popContext(); $template->context = $template->pushContext($iterator->current()) ?>';
		$latte->parser->macros['/foreach'] = '<?php endforeach; $template->context = $template->popContext(); array_pop($_l->its); $iterator = end($_l->its); ?>';
		$this->registerFilter($latte);

		// common operations
		$this->registerHelperLoader('Nette\Templating\DefaultHelpers::loader');
		$this->registerHelper('ucfirst', 'ucfirst');
		$this->registerHelper('values', 'array_values');
		$this->registerHelper('map', function($arr, $callback) {
			return array_map(create_function('$value', $callback), $arr);
		});
		$this->registerHelper('replaceRE', 'Nette\Utils\Strings::replace');

		// PHP source highlight
		$fshl = new \fshlParser('HTML_UTF8');
		$this->registerHelper('highlightPHP', function($source) use ($fshl) {
			return $fshl->highlightString('PHP', (string) $source);
		});
		$this->registerHelper('highlightValue', function($definition) use ($that) {
			return $that->highlightPHP(preg_replace('#^(?:[ ]{4}|\t)#m', '', $definition));
		});

		// links
		$this->registerHelper('packageLink', new Nette\Callback($this, 'getPackageLink'));
		$this->registerHelper('namespaceLink', new Nette\Callback($this, 'getNamespaceLink'));
		$this->registerHelper('classLink', new Nette\Callback($this, 'getClassLink'));
		$this->registerHelper('methodLink', new Nette\Callback($this, 'getMethodLink'));
		$this->registerHelper('propertyLink', new Nette\Callback($this, 'getPropertyLink'));
		$this->registerHelper('constantLink', new Nette\Callback($this, 'getConstantLink'));
		$this->registerHelper('sourceLink', new Nette\Callback($this, 'getSourceLink'));
		$this->registerHelper('manualLink', new Nette\Callback($this, 'getManualLink'));

		// types
		$this->registerHelper('getTypes', new Nette\Callback($this, 'getTypes'));
		$this->registerHelper('resolveType', function($variable) {
			return is_object($variable) ? get_class($variable) : gettype($variable);
		});
		$this->registerHelper('resolveClass', new Nette\Callback($this, 'resolveClass'));
		$this->registerHelper('resolveConstant', new Nette\Callback($this, 'resolveConstant'));

		// docblock
		$texy = new \Texy;
		$linkModule = new \TexyLinkModule($texy);
		$linkModule->shorten = FALSE;
		$texy->linkModule = $linkModule;
		$texy->mergeLines = FALSE;
		$texy->allowedTags = array_flip($this->config->allowedHtml);
		$texy->allowed['list/definition'] = FALSE;
		$texy->allowed['phrase/em-alt'] = FALSE;
		$texy->allowed['longwords'] = FALSE;
		// highlighting <code>, <pre>
		$texy->registerBlockPattern(
			function($parser, $matches, $name) use ($fshl) {
				$content = $matches[1] === 'code' ? $fshl->highlightString('PHP', $matches[2]) : htmlSpecialChars($matches[2]);
				$content = $parser->getTexy()->protect($content, \Texy::CONTENT_BLOCK);
				return \TexyHtml::el('pre', $content);
			},
			'#<(code|pre)>(.+?)</\1>#s',
			'codeBlockSyntax'
		);

		// Documentation formatting
		$this->registerHelper('docline', function($text) use ($texy) {
			return $texy->processLine($text);
		});
		$this->registerHelper('docblock', function($text) use ($texy) {
			return $texy->process($text);
		});
		$this->registerHelper('doclabel', function($doc, $namespace, ReflectionParameter $parameter = null) use ($that) {
			@list($names, $label) = preg_split('#\s+#', $doc, 2);
			$res = '';
			foreach (explode('|', $names) as $name) {
				if (null !== $parameter && $name === $parameter->getOriginalClassName()) {
					$name = $parameter->getClassName();
				}

				$class = $that->resolveClass($name, $namespace);
				$res .= $class !== null ? sprintf('<a href="%s">%s</a>', $that->classLink($class), $that->escapeHtml($class)) : $that->escapeHtml($that->resolveName($name));
				$res .= '|';
			}

			if (null !== $parameter) {
				$label = preg_replace('~^(\\$?' . $parameter->getName() . ')(\s+|$)~i', '\\2', $label, 1);
			}

			return rtrim($res, '|') . (!empty($label) ? '<br />' . $that->escapeHtml($label) : '');
		});

		// Docblock descriptions
		$this->registerHelper('longDescription', function($element, $shortIfNone = false) {
			$short = $element->getAnnotation(ReflectionAnnotation::SHORT_DESCRIPTION);
			$long = $element->getAnnotation(ReflectionAnnotation::LONG_DESCRIPTION);

			if ($long) {
				$short .= "\n\n" . $long;
			}

			return $short;
		});
		$this->registerHelper('shortDescription', function($element) {
			return $element->getAnnotation(ReflectionAnnotation::SHORT_DESCRIPTION);
		});

		// static files versioning
		$destination = $this->config->destination;
		$this->registerHelper('staticFile', function($name, $line = null) use ($destination) {
			static $versions = array();

			$filename = $destination . '/' . $name;
			if (!isset($versions[$filename]) && file_exists($filename)) {
				$versions[$filename] = sprintf('%u', crc32(file_get_contents($filename)));
			}
			if (isset($versions[$filename])) {
				$name .= '?' . $versions[$filename];
			}
			return $name;
		});

		// Inline tags (via plugins)
		$texy->registerLinePattern(
			array($this, 'processInlineTag'),
			sprintf('~%s~', self::INLINE_TAGS_REGEX),
			'inlineTag'
		);
	}

	/**
	 * Adds a context value to the stack.
	 *
	 * @param mixed $context Context value
	 * @return \Apigen\Reflection|\TokenReflection\IReflection|null
	 */
	public function pushContext($context)
	{
		array_unshift(self::$contexts, ($context instanceof ApiReflection || $context instanceof TokenReflection\IReflection) ? $context : null);
		return $this->getContext();
	}

	/**
	 * Removes a context from the stack.
	 *
	 * @return \Apigen\Reflection|\TokenReflection\IReflection|null
	 */
	public function popContext()
	{
		array_shift(self::$contexts);
		return $this->getContext();
	}

	/**
	 * Returns the current context.
	 *
	 * @return \Apigen\Reflection|\TokenReflection\IReflection|null
	 */
	public function getContext()
	{
		foreach (self::$contexts as $context) {
			if ($context instanceof ApiReflection || $context instanceof TokenReflection\IReflection) {
				return $context;
			}
		}

		return null;
	}

	/**
	 * Returns a link to a namespace summary file.
	 *
	 * @param string|\Apigen\Reflection $class Class reflection or namespace name
	 * @return string
	 */
	public function getNamespaceLink($class)
	{
		$namespace = ($class instanceof ApiReflection) ? $class->getNamespaceName() : $class;
		return sprintf($this->config->templates['main']['namespace']['filename'], $namespace ? preg_replace('#[^a-z0-9_]#i', '.', $namespace) : 'None');
	}

	/**
	 * Returns a link to a package summary file.
	 *
	 * @param string|\Apigen\Reflection $class Class reflection or package name
	 * @return string
	 */
	public function getPackageLink($class)
	{
		$package = ($class instanceof ApiReflection) ? $class->getPackageName() : $class;
		return sprintf($this->config->templates['main']['package']['filename'], $package ? preg_replace('#[^a-z0-9_]#i', '.', $package) : 'None');
	}

	/**
	 * Returns a link to class summary file.
	 *
	 * @param string|\Apigen\Reflection $class Class reflection or name
	 * @return string
	 */
	public function getClassLink($class)
	{
		if ($class instanceof ApiReflection) {
			$class = $class->getName();
		}

		return sprintf($this->config->templates['main']['class']['filename'], preg_replace('#[^a-z0-9_]#i', '.', $class));
	}

	/**
	 * Returns a link to method in class summary file.
	 *
	 * @param \TokenReflection\IReflectionMethod $method Method reflection
	 * @return string
	 */
	public function getMethodLink(ReflectionMethod $method)
	{
		return $this->getClassLink($method->getDeclaringClassName()) . '#_' . $method->getName();
	}

	/**
	 * Returns a link to property in class summary file.
	 *
	 * @param \TokenReflection\IReflectionProperty $property Property reflection
	 * @return string
	 */
	public function getPropertyLink(ReflectionProperty $property)
	{
		return $this->getClassLink($property->getDeclaringClassName()) . '#$' . $property->getName();
	}

	/**
	 * Returns a link to constant in class summary file.
	 *
	 * @param \TokenReflection\IReflectionConstant $constant Constant reflection
	 * @return string
	 */
	public function getConstantLink(ReflectionConstant $constant)
	{
		return $this->getClassLink($constant->getDeclaringClassName()) . '#' . $constant->getName();
	}

	/**
	 * Returns a link to a element source code.
	 *
	 * @param \Apigen\Reflection|\TokenReflection\IReflectionMethod|\TokenReflection\IReflectionProperty|\TokenReflection\IReflectionConstant $element Element reflection
	 * @param boolean $filesystemName Determines if a physical filename is requested
	 * @return string
	 */
	public function getSourceLink($element, $filesystemName = false)
	{
		return $this->sourceLinkPlugin->getSourceLink($element, $filesystemName);
	}

	/**
	 * Returns a link to a element documentation at php.net.
	 *
	 * @param \Apigen\Reflection|ReflectionMethod|ReflectionProperty|ReflectionConstant $element Element reflection
	 * @return string
	 */
	public function getManualLink($element)
	{
		static $manual = 'http://php.net/manual';
		static $reservedClasses = array('stdClass', 'Closure', 'Directory');

		$class = $element instanceof ApiReflection ? $element : $element->getDeclaringClass();

		if (in_array($class->getName(), $reservedClasses)) {
			return $manual . '/reserved.classes.php';
		}

		$className = strtolower($class->getName());
		$classLink = sprintf('%s/class.%s.php', $manual, $className);
		$elementName = strtolower(strtr(ltrim($element->getName(), '_'), '_', '-'));

		if ($element instanceof ApiReflection) {
			return $classLink;
		} elseif ($element instanceof ReflectionMethod) {
			return sprintf('%s/%s.%s.php', $manual, $className, $elementName);
		} elseif ($element instanceof ReflectionProperty) {
			return sprintf('%s#%s.props.%s', $classLink, $className, $elementName);
		} elseif ($element instanceof ReflectionConstant) {
			return sprintf('%s#%s.constants.%s', $classLink, $className, $elementName);
		}
	}

	/**
	 * Returns a list of resolved types from @param or @return tags.
	 *
	 * @param \TokenReflection\ReflectionMethod|\TokenReflection\ReflectionProperty $element Element reflection
	 * @param integer $position Parameter position
	 * @return array
	 */
	public function getTypes($element, $position = NULL)
	{
		$annotation = array();
		if ($element instanceof ReflectionProperty) {
			$annotation = $element->getAnnotation('var');
			if (null === $annotation && !$element->isTokenized()) {
				$value = $element->getDefaultValue();
				if (null !== $value) {
					$annotation = gettype($value);
				}
			}
		} elseif ($element instanceof ReflectionMethod) {
			$annotation = $position === null ? $element->getAnnotation('return') : @$element->annotations['param'][$position];
		}

		$namespace = $element->getDeclaringClass()->getNamespaceName();
		$types = array();
		foreach (preg_replace('#\s.*#', '', (array) $annotation) as $s) {
			foreach (explode('|', $s) as $name) {
				$class = $this->resolveClass($name, $namespace);
				$types[] = (object) array('name' => $class ?: $this->resolveName($name), 'class' => $class);
			}
		}
		return $types;
	}

	/**
	 * Resolves a parameter value definition (class name or parameter data type).
	 *
	 * @param string $name Parameter definition
	 * @return string
	 */
	public function resolveName($name)
	{
		static $names = array(
			'int' => 'integer',
			'bool' => 'boolean',
			'double' => 'float',
			'void' => '',
			'FALSE' => 'false',
			'TRUE' => 'true',
			'NULL' => 'null'
		);

		$name = ltrim($name, '\\');
		if (isset($names[$name])) {
			return $names[$name];
		}
		return $name;
	}

	/**
	 * Tries to resolve type as class or interface name.
	 *
	 * @param string $className Class name description
	 * @param string $namespace Namespace name
	 * @return string
	 */
	public function resolveClass($className, $namespace = NULL)
	{
		if (substr($className, 0, 1) === '\\') {
			$namespace = '';
			$className = substr($className, 1);
		}

		$name = isset($this->classes["$namespace\\$className"]) ? "$namespace\\$className" : (isset($this->classes[$className]) ? $className : null);
		if (null !== $name && !$this->classes[$name]->isDocumented()) {
			$name = null;
		}
		return $name;
	}

	/**
	 * Tries to resolve a constant using its name.
	 *
	 * @param string $definition Constant name (NAME or Class::NAME)
	 * @return \TokenReflection\ReflectionConstant|null
	 */
	public function resolveConstant($definition)
	{
		if (false === strpos($definition, '::')) {
			return null;
		}
		list($className, $constantName) = explode('::', $definition);
		$className = $this->resolveClass($className);
		if (null === $className) {
			return null;
		}

		try {
			return $this->classes[$className]->getConstantReflection($constantName);
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Tries to parse a link to a class/method/property and returns the appropriate link if successful.
	 *
	 * @param string $link Link definition
	 * @param \Apigen\Reflection $context Link context
	 * @return \Apigen\Reflection|null
	 */
	public function resolveClassLink($link, ApiReflection $context = null)
	{
		if (($pos = strpos($link, '::')) || ($pos = strpos($link, '->'))) {
			// Class::something or Class->something
			$className = $this->resolveClass(substr($link, 0, $pos), null !== $context ? $context->getNamespaceName() : null);

			if (null === $className) {
				$className = $this->resolveClass(ReflectionBase::resolveClassFQN(substr($link, 0, $pos), $context->getNamespaceAliases(), $context->getNamespaceName()));
			}

			if (null === $className) {
				return null;
			} else {
				$context = $this->classes[$className];
			}

			$link = substr($link, $pos + 2);
		} elseif ((null !== $context && null !== ($className = $this->resolveClass(ReflectionBase::resolveClassFQN($link, $context->getNamespaceAliases(), $context->getNamespaceName()), $context->getNamespaceName())))
			|| null !== ($className = $this->resolveClass($link, null !== $context ? $context->getNamespaceName() : null))) {
			// Class
			$context = $this->classes[$className];
			return !$context->isDocumented() ? null : '<a href="' . $this->classLink($context) . '">' . $this->escapeHtml($className) . '</a>';
		}

		if (null === $context || !$context->isDocumented()) {
			return null;
		} elseif ($context->hasProperty($link)) {
			// Class property
			$reflection = $context->getProperty($link);
		} elseif ('$' === $link{0} && $context->hasProperty(substr($link, 1))) {
			// Class $property
			$reflection = $context->getProperty(substr($link, 1));
		} elseif ($context->hasMethod($link)) {
			// Class method
			$reflection = $context->getMethod($link);
		} elseif (('()' === substr($link, -2) && $context->hasMethod(substr($link, 0, -2)))) {
			// Class method()
			$reflection = $context->getMethod(substr($link, 0, -2));
		} elseif ($context->hasConstant($link)) {
			// Class constant
			$reflection = $context->getConstantReflection($link);
		} else {
			return null;
		}

		$value = $reflection->getDeclaringClassName();
		if ($reflection instanceof ReflectionProperty) {
			$link = $this->propertyLink($reflection);
			$value .= '::$' . $reflection->getName();
		} elseif ($reflection instanceof ReflectionMethod) {
			$link = $this->methodLink($reflection);
			$value .= '::' . $reflection->getName() . '()';
		} elseif ($reflection instanceof ReflectionConstant) {
			$link = $this->constantLink($reflection);
			$value .= '::' . $reflection->getName();
		}

		return '<a href="' . $link . '">' . $this->escapeHtml($value) . '</a>';
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

		if (isset($this->annotationPlugins[$tag][Plugin\AnnotationProcessor::TYPE_INLINE_SIMPLE])) {
			// Simple inline tag, no children
			$plugin = $this->annotationPlugins[$tag][Plugin\AnnotationProcessor::TYPE_INLINE_SIMPLE];
			$type = Plugin\AnnotationProcessor::TYPE_INLINE_SIMPLE;
		} elseif (isset($this->annotationPlugins[$tag][Plugin\AnnotationProcessor::TYPE_INLINE_WITH_CHILDREN])) {
			// Inline with possible children
			$plugin = $this->annotationPlugins[$tag][Plugin\AnnotationProcessor::TYPE_INLINE_WITH_CHILDREN];
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
		if (empty($ignore)) {
			$annotations = $element->getAnnotations();
		} else {
			// Ignore given tags
			$annotations = array_diff_key($element->getAnnotations(), array_flip($ignore));
		}

		// Remove descriptions
		unset($annotations[ReflectionAnnotation::LONG_DESCRIPTION], $annotations[ReflectionAnnotation::SHORT_DESCRIPTION]);

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
			// Find the appropriate plugin
			if (isset($this->annotationPlugins[$name][Plugin\AnnotationProcessor::TYPE_BLOCK])) {
				$plugin = $this->annotationPlugins[$name][Plugin\AnnotationProcessor::TYPE_BLOCK];
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
				$annotations[$name] = array_map(array($this, 'escapeHtml'), $values);
			}
		}

		return $annotations;
	}

	/**
	 * Prepares custom plugins.
	 *
	 * @param \Apigen\Generator $generator
	 * @return array
	 */
	private function preparePlugins(Generator $generator)
	{
		// Load plugin files and find plugins
		$pluginBroker = new Broker(new Broker\Backend\Memory(), false);
		$pluginBroker->processFile(__DIR__ . '/Plugin.php');
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
			$this->registerPlugin($plugin, $generator);
		}

		$pluginNames = array(get_class($this->sourceLinkPlugin) => true);
		array_walk_recursive($this->annotationPlugins, function(Plugin $plugin) use(&$pluginNames) {
			$pluginNames[get_class($plugin)] = true;
		});
		$generator->output(sprintf("Using plugins\n %s\n", implode("\n ", array_keys($pluginNames))));

		return $plugins;
	}

	/**
	 * Registers a plugin.
	 *
	 * @param \TokenReflection\ReflectionClass $plugin Plugin class reflection
	 * @param \TokenReflection\Generator $generator Generator instance
	 * @return boolean
	 */
	private function registerPlugin(TokenReflection\ReflectionClass $class, Generator $generator)
	{
		$result = false;

		if ($class->isInterface() || !$class->implementsInterface('Apigen\\Plugin')) {
			// Class is an interface or does not implement the plugin interface
			return $result;
		}

		if (!include_once($class->getFileName())) {
			// Cannot include the plugin file
			throw new Exception(sprintf('Could not include plugin file "%s".', $class->getFileName()));
		}

		// Create a plugin instance
		$plugin = $class->newInstance($generator, $this, $this->config);

		// Plugin is a sourceLink
		if ($class->implementsInterface('Apigen\\Plugin\\SourceLink')) {
			$this->sourceLinkPlugin = $plugin;
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
				foreach ($types as $type) {
					if ($options & $type) {
						// Register for a particular tag and type
						$this->annotationPlugins[$tag][$type] = $plugin;
						$result = true;
					}
				}
			}
		}

		return $result;
	}
}
