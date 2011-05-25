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
use Apigen\Generator, Apigen\Reflection as ReflectionClass;
use TokenReflection\IReflectionProperty as ReflectionProperty, TokenReflection\IReflectionMethod as ReflectionMethod, TokenReflection\IReflectionConstant as ReflectionConstant, TokenReflection\IReflectionFunction as ReflectionFunction, TokenReflection\IReflectionParameter as ReflectionParameter;
use TokenReflection, TokenReflection\Broker, TokenReflection\ReflectionBase;
use TokenReflection\IReflectionExtension as ReflectionExtension, TokenReflection\ReflectionAnnotation;

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
	/**#@+
	 * SourceLink plugins identifier.
	 *
	 * @var string
	 */
	const PLUGIN_SOURCELINK = 'sourceLink';

	/**
	 * Annotation generators identifier.
	 */
	const PLUGIN_ANNOTATION_GENERATOR = 'generator';

	/**
	 * Annotation processors identifier.
	 */
	const PLUGIN_ANNOTATION_PROCESSOR = 'processor';

	/**
	 * Page generators identifier.
	 */
	const PLUGIN_PAGE = 'page';
	/**#@-*/

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
	 * @var \ArrayObject
	 */
	private $classes;

	/**
	 * List of constants.
	 *
	 * @var \ArrayObject
	 */
	private $constants;

	/**
	 * List of functions.
	 *
	 * @var \ArrayObject
	 */
	private $functions;

	/**
	 * Plugin container.
	 *
	 * @var \ArrayObject
	 */
	private $plugins;

	/**
	 * Creates a template.
	 *
	 * @param \Apigen\Generator $generator
	 */
	public function __construct(Generator $generator)
	{
		$this->config = $generator->getConfig();
		$this->classes = $generator->getClasses();
		$this->constants = $generator->getConstants();
		$this->functions = $generator->getFunctions();

		$this->registerPlugins($generator);

		$that = $this;

		$latte = new Nette\Latte\Engine;
		$latte->parser->macros['try'] = '<?php try { ?>';
		$latte->parser->macros['/try'] = '<?php } catch (\Exception $e) {} ?>';
		$latte->parser->macros['foreach'] = '<?php foreach (%:macroForeach%): if (!$iterator->isFirst()) $template->popContext(); $template->context = $template->pushContext($iterator->current()) ?>';
		$latte->parser->macros['/foreach'] = '<?php endforeach; $template->context = $template->popContext(); array_pop($_l->its); $iterator = end($_l->its); ?>';
		$this->registerFilter($latte);

		// Common operations
		$this->registerHelperLoader('Nette\Templating\DefaultHelpers::loader');
		$this->registerHelper('ucfirst', 'ucfirst');
		$this->registerHelper('replaceRE', 'Nette\Utils\Strings::replace');

		// PHP source highlight
		$fshl = new \fshlParser('HTML_UTF8');
		$this->registerHelper('highlightPHP', function($source) use ($fshl) {
			return $fshl->highlightString('PHP', (string) $source);
		});
		$this->registerHelper('highlightValue', function($definition) use ($that) {
			return $that->highlightPHP(preg_replace('#^(?:[ ]{4}|\t)#m', '', $definition));
		});

		// Url
		$this->registerHelper('packageUrl', new Nette\Callback($this, 'getPackageUrl'));
		$this->registerHelper('namespaceUrl', new Nette\Callback($this, 'getNamespaceUrl'));
		$this->registerHelper('classUrl', new Nette\Callback($this, 'getClassUrl'));
		$this->registerHelper('methodUrl', new Nette\Callback($this, 'getMethodUrl'));
		$this->registerHelper('propertyUrl', new Nette\Callback($this, 'getPropertyUrl'));
		$this->registerHelper('constantUrl', new Nette\Callback($this, 'getConstantUrl'));
		$this->registerHelper('functionUrl', new Nette\Callback($this, 'getFunctionUrl'));
		$this->registerHelper('sourceUrl', new Nette\Callback($this, 'getSourceUrl'));
		$this->registerHelper('manualUrl', new Nette\Callback($this, 'getManualUrl'));

		$this->registerHelper('namespaceLinks', new Nette\Callback($this, 'getNamespaceLinks'));

		// Types
		$this->registerHelper('getTypes', new Nette\Callback($this, 'getTypes'));
		$this->registerHelper('resolveType', function($variable) {
			return is_object($variable) ? get_class($variable) : gettype($variable);
		});
		$this->registerHelper('resolveClass', new Nette\Callback($this, 'resolveClass'));

		// Texy
		$texy = new \Texy;
		$linkModule = new \TexyLinkModule($texy);
		$linkModule->shorten = FALSE;
		$texy->linkModule = $linkModule;
		$texy->mergeLines = FALSE;
		$texy->allowedTags = array_flip($this->config->allowedHtml);
		$texy->allowed['list/definition'] = FALSE;
		$texy->allowed['phrase/em-alt'] = FALSE;
		$texy->allowed['longwords'] = FALSE;
		// Highlighting <code>, <pre>
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
				$res .= $class !== null ? sprintf('<a href="%s">%s</a>', $that->classUrl($class), $that->escapeHtml($class)) : $that->escapeHtml($that->resolveName($name));
				$res .= '|';
			}

			if (null !== $parameter) {
				$label = preg_replace('~^(\\$?' . $parameter->getName() . ')(\s+|$)~i', '\\2', $label, 1);
			}

			return rtrim($res, '|') . (!empty($label) ? '<br />' . $that->escapeHtml($label) : '');
		});
		$this->registerHelper('docdescription', function($doc, $no) {
			$parts = preg_split('#\s+#', $doc, $no);
			return isset($parts[$no - 1]) ? $parts[$no - 1] : '';
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

		// Namespaces
		$this->registerHelper('subnamespaceName', function($namespaceName) {
			if ($pos = strrpos($namespaceName, '\\')) {
				return substr($namespaceName, $pos + 1);
			}
			return $namespaceName;
		});

		// Packages
		$this->registerHelper('packageName', function($packageName) {
			if ($pos = strpos($packageName, '\\')) {
				return substr($packageName, 0, $pos);
			}
			return $packageName;
		});
		$this->registerHelper('subpackageName', function($packageName) {
			if ($pos = strpos($packageName, '\\')) {
				return substr($packageName, $pos + 1);
			}
			return '';
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
	 * Returns links for namespace and its parent namespaces.
	 *
	 * @param string $namespace
	 * @param boolean $last
	 * @return string
	 */
	public function getNamespaceLinks($namespace, $last = true)
	{
		$links = array();

		$parent = '';
		foreach (explode('\\', $namespace) as $part) {
			$parent = ltrim($parent . '\\' . $part, '\\');
			$links[] = $last || $parent !== $namespace
				? '<a href="' . $this->getNamespaceUrl($parent) . '">' . $this->escapeHtml($part) . '</a>'
				: $this->escapeHtml($part);
		}

		return implode('\\', $links);
	}

	/**
	 * Returns a link to a namespace summary file.
	 *
	 * @param string $namespaceName Namespace name
	 * @return string
	 */
	public function getNamespaceUrl($namespaceName)
	{
		return sprintf($this->config->templates['main']['namespace']['filename'], preg_replace('#[^a-z0-9_]#i', '.', $namespaceName));
	}

	/**
	 * Returns a link to a package summary file.
	 *
	 * @param string $packageName Package name
	 * @return string
	 */
	public function getPackageUrl($packageName)
	{
		return sprintf($this->config->templates['main']['package']['filename'], preg_replace('#[^a-z0-9_]#i', '.', $packageName));
	}

	/**
	 * Returns a link to class summary file.
	 *
	 * @param string|\Apigen\Reflection $class Class reflection or name
	 * @return string
	 */
	public function getClassUrl($class)
	{
		if ($class instanceof ReflectionClass) {
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
	public function getMethodUrl(ReflectionMethod $method)
	{
		return $this->getClassUrl($method->getDeclaringClassName()) . '#_' . $method->getName();
	}

	/**
	 * Returns a link to property in class summary file.
	 *
	 * @param \TokenReflection\IReflectionProperty $property Property reflection
	 * @return string
	 */
	public function getPropertyUrl(ReflectionProperty $property)
	{
		return $this->getClassUrl($property->getDeclaringClassName()) . '#$' . $property->getName();
	}

	/**
	 * Returns a link to constant in class summary file or to constant summary file.
	 *
	 * @param \TokenReflection\IReflectionConstant $constant Constant reflection
	 * @return string
	 */
	public function getConstantUrl(ReflectionConstant $constant)
	{
		// Class constant
		if ($className = $constant->getDeclaringClassName()) {
			return $this->getClassUrl($constant->getDeclaringClassName()) . '#' . $constant->getName();
		}
		// Constant in namespace or global space
		return sprintf($this->config->templates['main']['constant']['filename'], preg_replace('#[^a-z0-9_]#i', '.', $constant->getName()));
	}

	/**
	 * Returns a link to function summary file.
	 *
	 * @param \TokenReflection\IReflectionMethod $method Function reflection
	 * @return string
	 */
	public function getFunctionUrl(ReflectionFunction $function)
	{
		return sprintf($this->config->templates['main']['function']['filename'], preg_replace('#[^a-z0-9_]#i', '.', $function->getName()));
	}

	/**
	 * Returns a link to a element source code.
	 *
	 * @param \Apigen\Reflection|\TokenReflection\IReflectionMethod|\TokenReflection\IReflectionProperty|\TokenReflection\IReflectionConstant|\TokenReflection\IReflectionFunction $element Element reflection
	 * @param boolean $filesystemName Determines if a physical filename is requested
	 * @return string
	 */
	public function getSourceUrl($element, $filesystemName = false)
	{
		return $this->plugins[self::PLUGIN_SOURCELINK]->getSourceLink($element, $filesystemName);
	}

	/**
	 * Returns a link to a element documentation at php.net.
	 *
	 * @param \Apigen\Reflection|ReflectionMethod|ReflectionProperty|ReflectionConstant $element Element reflection
	 * @return string
	 */
	public function getManualUrl($element)
	{
		static $manual = 'http://php.net/manual';
		static $reservedClasses = array('stdClass', 'Closure', 'Directory');

		// Extension
		if ($element instanceof ReflectionExtension) {
			$extensionName = strtolower($element->getName());
			if ('core' === $extensionName) {
				return $manual;
			}

			if ('date' === $extensionName) {
				$extensionName = 'datetime';
			}

			return sprintf('%s/book.%s.php', $manual, $extensionName);
		}

		// Class and its members
		$class = $element instanceof ReflectionClass ? $element : $element->getDeclaringClass();

		if (in_array($class->getName(), $reservedClasses)) {
			return $manual . '/reserved.classes.php';
		}

		$className = strtolower($class->getName());
		$classUrl = sprintf('%s/class.%s.php', $manual, $className);
		$elementName = strtolower(strtr(ltrim($element->getName(), '_'), '_', '-'));

		if ($element instanceof ReflectionClass) {
			return $classUrl;
		} elseif ($element instanceof ReflectionMethod) {
			return sprintf('%s/%s.%s.php', $manual, $className, $elementName);
		} elseif ($element instanceof ReflectionProperty) {
			return sprintf('%s#%s.props.%s', $classUrl, $className, $elementName);
		} elseif ($element instanceof ReflectionConstant) {
			return sprintf('%s#%s.constants.%s', $classUrl, $className, $elementName);
		}
	}

	/**
	 * Returns a list of resolved types from @param or @return tags.
	 *
	 * @param \TokenReflection\ReflectionMethod|\TokenReflection\ReflectionProperty|\TokenReflection\ReflectionFunction $element Element reflection
	 * @param integer $position Parameter position
	 * @return array
	 */
	public function getTypes($element, $position = NULL, $annotationName = '')
	{
		$annotation = array();
		if ($element instanceof ReflectionProperty || $element instanceof ReflectionConstant) {
			$annotation = $element->getAnnotation('var');
			if (null === $annotation && !$element->isTokenized()) {
				$value = $element->getDefaultValue();
				if (null !== $value) {
					$annotation = gettype($value);
				}
			}
		} elseif ($element instanceof ReflectionFunction && !empty($annotationName)) {
			$annotation = $element->getAnnotation($annotationName);
		} elseif ($element instanceof ReflectionMethod || $element instanceof ReflectionFunction) {
			$annotation = $position === null ? $element->getAnnotation('return') : @$element->annotations['param'][$position];
		}

		if ($element instanceof ReflectionFunction) {
			$namespace = $element->getNamespaceName();
		} elseif ($element instanceof ReflectionParameter) {
			$namespace = $element->getDeclaringFunction()->getNamespaceName();
		} else {
			$namespace = $element->getDeclaringClass()->getNamespaceName();
		}

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
	 * Tries to resolve type as function name.
	 *
	 * @param string $functionName Function name
	 * @param string $namespace Namespace name
	 * @return string
	 */
	public function resolveFunction($functionName, $namespace = NULL)
	{
		if (substr($functionName, 0, 1) === '\\') {
			$namespace = '';
			$functionName = substr($functionName, 1);
		}

		if (isset($this->functions[$namespace . '\\' . $functionName])) {
			return $namespace . '\\' . $functionName;
		}

		if (isset($this->functions[$functionName])) {
			return $functionName;
		}

		return null;
	}

	/**
	 * Tries to resolve type as constant name.
	 *
	 * @param string $constantName Constant name
	 * @param string $namespace Namespace name
	 * @return string
	 */
	public function resolveConstant($constantName, $namespace = NULL)
	{
		if (substr($constantName, 0, 1) === '\\') {
			$namespace = '';
			$constantName = substr($constantName, 1);
		}

		if (isset($this->constants[$namespace . '\\' . $constantName])) {
			return $namespace . '\\' . $constantName;
		}

		if (isset($this->constants[$constantName])) {
			return $constantName;
		}

		return null;
	}

	/**
	 * Resolves links in documentation.
	 *
	 * @param string $text Processed documentation text
	 * @param \Apigen\Reflection|\TokenReflection\IReflection $element Reflection object
	 * @return string
	 */
	public function resolveLinks($text, $element)
	{
		$that = $this;
		return preg_replace_callback('~{@link\\s+([^}]+)}~', function ($matches) use ($element, $that) {
			return $that->resolveClassLink($matches[1], $element) ?: $matches[0];
		}, $text);
	}

	/**
	 * Tries to parse a link to a class/method/property and returns the appropriate link if successful.
	 *
	 * @param string $link Link definition
	 * @param \Apigen\Reflection|\TokenReflection\IReflection $context Link context
	 * @return string|null
	 */
	public function resolveClassLink($link, $context)
	{
		if (!$context instanceof ReflectionClass && !$context instanceof ReflectionConstant && !$context instanceof ReflectionFunction) {
			$context = $this->classes[$context->getDeclaringClassName()];
		}

		if (($pos = strpos($link, '::')) || ($pos = strpos($link, '->'))) {
			// Class::something or Class->something
			$className = $this->resolveClass(substr($link, 0, $pos), $context->getNamespaceName());

			if (null === $className) {
				$className = $this->resolveClass(ReflectionBase::resolveClassFQN(substr($link, 0, $pos), $context->getNamespaceAliases(), $context->getNamespaceName()));
			}

			// No class
			if (null === $className) {
				return null;
			}

			$context = $this->classes[$className];

			$link = substr($link, $pos + 2);
		} elseif ((null !== ($className = $this->resolveClass(ReflectionBase::resolveClassFQN($link, $context->getNamespaceAliases(), $context->getNamespaceName()), $context->getNamespaceName())))
			|| null !== ($className = $this->resolveClass($link, $context->getNamespaceName()))) {
			// Class
			$context = $this->classes[$className];

			// No "documented" class
			if (!$context->isDocumented()) {
				return null;
			}

			return '<a href="' . $this->classUrl($context) . '">' . $this->escapeHtml($className) . '</a>';
		} elseif ($functionName = $this->resolveFunction($link, $context->getNamespaceName())) {
			// Function
			$context = $this->functions[$functionName];

			return '<a href="' . $this->functionUrl($context) . '">' . $this->escapeHtml($functionName) . '</a>';
		} elseif ($constantName = $this->resolveConstant($link, $context->getNamespaceName())) {
			// Constant
			$context = $this->constants[$constantName];

			return '<a href="' . $this->constantUrl($context) . '">' . $this->escapeHtml($constantName) . '</a>';
		}

		// No "documented" class
		if ($context instanceof ReflectionClass && !$context->isDocumented()) {
			return null;
		}

		// No context
		if ($context instanceof ReflectionConstant || $context instanceof ReflectionFunction) {
			return null;
		}

		if ($context->hasProperty($link)) {
			// Class property
			$reflection = $context->getProperty($link);
		} elseif ('$' === $link{0} && $context->hasProperty(substr($link, 1))) {
			// Class $property
			$reflection = $context->getProperty(substr($link, 1));
		} elseif ($context->hasMethod($link)) {
			// Class method
			$reflection = $context->getMethod($link);
		} elseif ('()' === substr($link, -2) && $context->hasMethod(substr($link, 0, -2))) {
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
			$link = $this->propertyUrl($reflection);
			$value .= '::$' . $reflection->getName();
		} elseif ($reflection instanceof ReflectionMethod) {
			$link = $this->methodUrl($reflection);
			$value .= '::' . $reflection->getName() . '()';
		} elseif ($reflection instanceof ReflectionConstant) {
			$link = $this->constantUrl($reflection);
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
				$annotations[$name] = array_map(array($this, 'escapeHtml'), $values);
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
		$annotations = $element->getAnnotations();
		if (!empty($this->plugins[self::PLUGIN_ANNOTATION_GENERATOR])) {
			foreach ($this->plugins[self::PLUGIN_ANNOTATION_GENERATOR] as $plugin) {
				$customAnnotations = $plugin->getAnnotations($element);

				// Descriptions cannot be merged; they are appended instead
				if (isset($customAnnotations[ReflectionAnnotation::SHORT_DESCRIPTION])) {
					if (isset($annotations[ReflectionAnnotation::SHORT_DESCRIPTION])) {
						$annotations[ReflectionAnnotation::SHORT_DESCRIPTION] .= "\n\n" . $customAnnotations[ReflectionAnnotation::SHORT_DESCRIPTION];
					} else {
						$annotations[ReflectionAnnotation::SHORT_DESCRIPTION] = $customAnnotations[ReflectionAnnotation::SHORT_DESCRIPTION];
					}
					unset($customAnnotations[ReflectionAnnotation::SHORT_DESCRIPTION]);
				}
				if (isset($customAnnotations[ReflectionAnnotation::LONG_DESCRIPTION])) {
					if (isset($annotations[ReflectionAnnotation::LONG_DESCRIPTION])) {
						$annotations[ReflectionAnnotation::LONG_DESCRIPTION] .= "\n\n" . $customAnnotations[ReflectionAnnotation::LONG_DESCRIPTION];
					} else {
						$annotations[ReflectionAnnotation::LONG_DESCRIPTION] = $customAnnotations[ReflectionAnnotation::LONG_DESCRIPTION];
					}
					unset($customAnnotations[ReflectionAnnotation::LONG_DESCRIPTION]);
				}

				if (!empty($customAnnotations)) {
					$annotations = array_merge_recursive($annotations, $customAnnotations);
				}
			}
		}

		return $annotations;
	}

	/**
	 * Calls page plugins to render custom pages.
	 */
	public function renderCustomPages()
	{
		if (!empty($this->plugins[self::PLUGIN_PAGE])) {
			foreach ($this->plugins[self::PLUGIN_PAGE] as $plugin) {
				$plugin->renderPages();
			}
		}
	}

	/**
	 * Registers custom plugins.
	 *
	 * @param \Apigen\Generator $generator
	 * @return array
	 * @throws \Apigen\Exception When no sourceLink plugin is registered
	 */
	private function registerPlugins(Generator $generator)
	{
		$this->plugins = new \ArrayObject();

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

		if (empty($this->plugins[self::PLUGIN_SOURCELINK])) {
			throw new Exception('No sourceLink plugin was registered');
		}

		$tmp = $this->plugins->getArrayCopy();
		array_walk_recursive($tmp, function(Plugin $plugin) use(&$pluginNames) {
			$pluginNames[get_class($plugin)] = true;
		});
		$generator->output(sprintf("Using plugins\n %s\n", implode("\n ", array_keys($pluginNames))));

		return $plugins;
	}

	/**
	 * Registers a particular custom plugin.
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

		// Plugin is an annotationGenerator
		if ($class->implementsInterface('Apigen\\Plugin\\AnnotationGenerator')) {
			$this->plugins[self::PLUGIN_ANNOTATION_GENERATOR][] = $plugin;
			$result = true;
		}

		// Plugin is a page
		if ($class->implementsInterface('Apigen\\Plugin\\Page')) {
			$this->plugins[self::PLUGIN_PAGE][] = $plugin;
			$result = true;
		}

		return $result;
	}
}
