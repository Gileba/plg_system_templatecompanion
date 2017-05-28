<?php
/**
 * @package		System Plugin - Less Template Companion, an automatic Less compiler for developers and users
 * @version		0.1.0-alpha.4
 * @author		Gijs Lamon
 * @copyright	(C) 2017 Gijs Lamon
 * @license		GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Based on the works of Andreas Tasch (https://github.com/ndeet/plg_system_less) and Thomas Hunziker (https://github.com/Bakual/Allrounder)
 */

// no direct access
defined('_JEXEC') or die();

/**
 * Plugin checks and compiles updated .less files on page load and on template style save.
 * Give your users the ability to set variables as template parameter and removing the need to manually compile .less files ever again.
 *
 * JLess compiler uses lessphp; see http://leafo.net/lessphp/
 *
 * @since  1.0
 */
class plgSystemLessTemplateCompanion extends JPlugin
{
	/**
	 * @var $app
	 */
	protected $app;

	/**
	 * override constructor to load classes as soon as possible
	 * @param $subject
	 * @param $config
	 */
	public function __construct(&$subject, $config)
	{
		// trigger parent constructor first so params get set
		parent::__construct($subject, $config);
		// set app
		$this->app = JFactory::getApplication();
		// check if lessc already exists but bypass autoloader
		if (class_exists('lessc', false))
		{
			// the lessc class already exists, so we cannot load our own version
			JDEBUG ? $this->app->enqueueMessage('[DEBUG] class "lessc" already exists, using version ' . lessc::$VERSION) : null;
		}
		// load the appropriate class
		else
		{
			// determine the name of the file to load based on application
			$name = false;
			if ($this->app->isSite())
			{
				$name = $this->params->get('sitelessc', 'lessc-0.3.9');
			}
			else if ($this->app->isAdmin())
			{
				$name = $this->params->get('adminlessc', 'lessc-0.3.9');
			}
			$name && JDEBUG ? $this->app->enqueueMessage("[DEBUG] loading $name") : null;
			// confirm that the named file exists
			if ($name && file_exists($file = dirname(__FILE__) . '/lessc/' . $name . '.php'))
			{
				require_once $file;
			}
		}
		// trigger autoload in case the file wasn't found while checking for debug
		class_exists('lessc') && JDEBUG ? $this->app->enqueueMessage("[DEBUG] lessc " . lessc::$VERSION) : null;
	}

	/**
	 * Compile .less files on change
	 */
	function onBeforeRender()
	{
		//path to less file
		$lessFile = '';

		// 0 = frontend only
		// 1 = backend only
		// 2 = front + backend
		$mode = $this->params->get('mode', 0);

		//only execute frontend
		if ($this->app->isSite() && ($mode == 0 || $mode == 2))
		{
			$templatePath = JPATH_BASE . DIRECTORY_SEPARATOR . 'templates/' . $this->app->getTemplate() . DIRECTORY_SEPARATOR;

			//entrypoint for main .less file, default is less/template.less
			$lessFile = $templatePath . $this->params->get('lessfile', 'less/template.less');

			//destination .css file, default css/template.css
			$cssFile = $templatePath . $this->params->get('cssfile', 'css/template.css');

		}

		//execute backend
		if ($this->app->isAdmin() && ($mode == 1 || $mode == 2))
		{
			$templatePath = JPATH_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'templates/' . $this->app->getTemplate() . DIRECTORY_SEPARATOR;

			//entrypoint for main .less file, default is less/template.less
			$lessFile = $templatePath . $this->params->get('admin_lessfile', 'less/template.less');

			//destination .css file, default css/template.css
			$cssFile = $templatePath . $this->params->get('admin_cssfile', 'css/template.css');

		}

		//check if .less file exists and is readable
		if (is_readable($lessFile))
		{
			if ((bool) $this->params->get('clientside_enable', 0))
			{
				$this->clientsideLess();
				return false;
			}
	
			//initialise less compiler
			try
			{
				$this->autoCompileLess($lessFile, $cssFile);
			}
			catch (Exception $e)
			{
				$app->enqueueMessage(JText::_($e->getMessage(), 'error');
			}
		}

		return false;
	}

	/**
	 * Checks if .less file has been updated and stores it in cache for quick comparison.
	 *
	 * This function is taken and modified from documentation of lessphp
	 *
	 * @param String $inputFile
	 * @param String $outputFile
	 */
	function autoCompileLess($inputFile, $outputFile)
	{
		// load config file
		$config = JFactory::getConfig();
		//path to temp folder
		$tmpPath = $config->get('tmp_path');

		//load chached file
		$cacheFile = $tmpPath . DIRECTORY_SEPARATOR . $this->app->getTemplate() . "_" . basename($inputFile) . ".cache";

		if (file_exists($cacheFile))
		{
			$tmpCache = unserialize(file_get_contents($cacheFile));
			if ($tmpCache['root'] === $inputFile)
			{
				$cache = $tmpCache;
			}
			else
			{
				$cache = $inputFile;
				unlink($cacheFile);
			}
		}
		else
		{
			$cache = $inputFile;
		}

		//instantiate less compiler
		$less = new lessc;

		//set less options
		//option: force recompilation regardless of change
		$force = (boolean) $this->params->get('less_force', 0);

		//option: preserve comments
		if ($this->params->get('less_comments', 0))
		{
			$less->setPreserveComments(true);
		}

		//option: compression
		if ($this->params->get('less_compress', 0))
		{
			$less->setFormatter("compressed");
		}
		else
		{
			$less->setFormatter("classic");
		}

		//compile cache file
		$newCache = $less->cachedCompile($cache, $force);

		if (!is_array($cache) || $newCache["updated"] > $cache["updated"])
		{
			file_put_contents($cacheFile, serialize($newCache));
			file_put_contents($outputFile, $newCache['compiled']);
		}
	}

	/**
	 * Configure and add Client-side Less library
	 * @author   piotr-cz
	 * @return   void
	 *
	 * @see      LESS: Ussage  http://lesscss.org/#usage
	 */
	function clientsideLess()
	{
		// Initialise variables
		$doc = JFactory::getDocument();


		// Early exit
		if ($doc->getType() !== 'html')
		{
			return;
		}

		// Get asset paths
		$templateRel = 'templates/' . $doc->template . '/';


		// Determine which param to use (admin/ site)
		$mode = $this->params->get('mode', 0);
		$lessKey = 'lessfile';
		$cssKey = 'cssfile';

		if ($this->app->isAdmin() && ($mode == 1 || $mode == 2))
		{
			$lessKey = 'admin_' . $lessKey;
			$cssKey = 'admin_' . $cssKey;
		}


		// Get template css filenames
		$lessUri = $templateRel . $this->params->get($lessKey, 'less/template.less');
		$cssUri = $templateRel . $this->params->get($cssKey, 'css/template.css');


		// Add less file to document
		$doc->addHeadLink($lessUri, 'stylesheet/less', 'rel', array('type' => 'text/css'));

		/*
		 * Configure Less options
		 *  async			: false,
		 *  fileAsync		: false,
		 *  poll			: 1500,
		 *  relativeUrls	: false,
		 *  rootpath		: $templateUrl
		 */
		$options = array(
			'env' => 'development',
			'dumpLineNumbers' => 'mediaquery', // default: 'comments'
		);

		$doc->addScriptDeclaration('
				// Less options
				var less = ' . json_encode($options, JSON_FORCE_OBJECT | (defined('JSON_PRETTY_PRINT') ? JSON_PRETTY_PRINT : false)) . ';
		');


		// Load less.js (pick latest version in media folder)
		// Joomla adds JS code after libraries in head. We need it other way around
		$mediaJsDestination = '/media/plg_less/js/';
		$mediaPath = JPATH_SITE . $mediaJsDestination;
		$mediaUri = JUri::root(true) . $mediaJsDestination;

		$lessVersions = glob($mediaPath . 'less-*.js');

		if (!empty($lessVersions))
		{
			rsort($lessVersions);

			// Load at the end of head
			$doc->addCustomTag('<script src="' . $mediaUri . basename($lessVersions[0]) . '" type="text/javascript"></script>');

			// Load after options (experimental, cannot use in XHTML documents)
			/*
				$doc->addScriptDeclaration('
						// Less library
						document.write( unescape( \'%3Cscript src="' . $mediaUri . basename($lessVersions[0]) . '" type="text/javascript"%3E%3C/script%3E\' ) );
				');
			*/
		}
		// Cannot find client-side parser
		else
		{
			return;
		}


		/*
		 * Remove template.css from document head
		 *
		 * Note:  Css file must be added either using `JFactory::getDocument->addStylesheet($cssFile)` or `JHtml::_('stylesheet', $cssFile)`
		 * Note:  Cannot rely on removing stylesheet using JDocumentHTML methods.
		 * Note:  Passes ignore cache trick (template.css?1234567890123)
		 * Note:  Template.css may be added to $doc['stylesheets'] using following keys:
		 *	- relative						: `templates/...`
		 *	- semi		JUri::base(true)	: `/[path-to-root]/templates/...`
		 * 	- absolute 	JUri::base()		: `http://[host]/[path-to-root]/templates/...`
		 *	- or outside $doc->_styleSheets
		 */
		$lookups = array($cssUri, JUri::base(true) . '/' . $cssUri, JUri::base() . $cssUri);

		// Loop trough all registered document stylesheets...
		foreach ($doc->_styleSheets as $stylesSheetUri)
		{
			// ...and compare to every lookup...
			foreach ($lookups as $lookup)
			{
				// ...that starts like a lookup
				if (strpos($stylesSheetUri, $lookup) === 0)
				{
					unset($doc->_styleSheets[$stylesSheetUri]);
					return;
				}
			}
		}

		// Didn't find a css file in JDocument instance, register event to remove in from rendered html body.
		$this->app->registerEvent('onAfterRender', array($this, 'removeCss'));

		return;
	}

	/**
	 * Remove template.css from document html
	 * Stylesheet href may include query string, ie template.css?1234567890123
	 * @author   piotr-cz
	 *
	 * @return   void
	 */
	public function removeCss()
	{
		// Initialise variables
		$doc = JFactory::getDocument();
		$body = JResponse::getBody();

		// Get Uri to template stylesheet file
		$templateUri = JUri::base(true) . '/templates/' . $doc->template . '/';
		$cssUri = $templateUri . $this->params->get('cssfile', 'css/template.css');

		// Replace line with link element and path to stylesheet file
		$replaced = preg_replace( '~(\s*?<link.* href=".*?' . preg_quote($cssUri) . '(?:\?.*)?".*/>)~', '', $body, -1, $count);

		if ($count)
		{
			JResponse::setBody($replaced);
		}

		return;
	}
	
	/**
	 * Compile .less files on template style change
	 *
	 * @param   string  $context  Context of the data
	 * @param   object  $table    Table object
	 * @param   bool    $isNew    New entry or edit
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	public function onExtensionAfterSave($context, $table, $isNew)
	{
		if ($context != 'com_templates.style' && $context != 'com_advancedtemplates.style')
		{
			return;
		}

		// Convert the params to an object.
		if (is_string($table->params))
		{
			$registry = new \Joomla\Registry\Registry;
			$registry->loadString($table->params);
			$table->params = $registry;
		}

		// Check if parameter "useLESS" is set
		if (!$table->params->get('useLESS'))
		{
			return;
		}

		// Path to less file
		$client       = ($table->client_id) ? JPATH_ADMINISTRATOR : JPATH_SITE;
		$templatePath = $client . '/templates/' . $table->template;
		$lessFile     = $templatePath . '/less/template.less';
		$cssFile      = $templatePath . '/css/template' . $table->id . '.css';

		// Check if .less file exists and is readable
		if (is_readable($lessFile))
		{
			$this->compileLess($table, $client, $templatePath, $lessFile, $cssFile)
		}
	}
	
	public function compileLess($table, $client, $templatePath, $lessFile, $cssFile)
	{
		$less = new JLess;

		if ($table->params->get('cssCompress', 0))
		{
			$less->setFormatter('compressed');
		}
		else
		{
			// Joomla way
			$formatter = new JLessFormatterJoomla;
			$less->setFormatter($formatter);
		}

		$params_array = $table->params->toArray();

		// Unset the some parameter as it breaks the compiler if it starts with a dot (.) or hash (#).
		$unsets = array(
					'customCssCode',
					'textLogo',
					'slogan',
					'copyText',
				);

		foreach ($unsets as $unset)
		{
			if (array_key_exists($unset, $params_array))
			{
				unset($params_array[$unset]);
			}
		}

		// Sanitising params for LESS
		foreach ($params_array as &$value)
		{
			// Trim whitespaces
			$value = trim($value);

			// Adding quotes around variable so it's threaten as string if a slash is in it.
			if (strpos($value, '/') !== false)
			{
				$value = '"' . $value . '"';
			}

			// Quoting empty values as they break the compiler
			if ($value == '')
			{
				$value = '""';
			}
		}

		// Adding template path to params
		$basePath                 = ($table->client_id) ? JURI::base(true) : JURI::root(true);
		$params_array['basePath'] = '"' . $basePath . '/"';

		$less->setVariables($params_array);

		$less->setImportDir(array($templatePath . '/less/'));
		$lessString = file_get_contents($lessFile);

		// Check for custom files
		if (is_readable($templatePath . '/less/custom.less'))
		{
			$lessString .= file_get_contents($templatePath . '/less/custom.less');
		}

		if (is_readable($templatePath . '/css/custom.css'))
		{
			$lessString .= file_get_contents($templatePath . '/css/custom.css');
		}

		try
		{
			$cssString = $less->compile($lessString);
		}
		catch (Exception $e)
		{
			$this->app->enqueueMessage('lessphp error: ' . $e->getMessage(), 'warning');
		}

		JFile::write($cssFile, $cssString);

		$this->loadLanguage();
		$this->app->enqueueMessage(JText::sprintf('PLG_SYSTEM_LESSALLROUNDER_SUCCESS', $cssFile), 'message');
	}
}
