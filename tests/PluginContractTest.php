<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));
}

if (!interface_exists('Typecho_Plugin_Interface')) {
    interface Typecho_Plugin_Interface
    {
    }
}

if (!function_exists('_t')) {
    function _t($text)
    {
        return $text;
    }
}

class MomoBirdAI_TestPluginFactory
{
    public $finishPublish;
    public $finishMark;
    public $finishDelete;
    public $footer;
}

if (!class_exists('Typecho_Plugin')) {
    class Typecho_Plugin
    {
        public static $factories = array();

        public static function factory($name)
        {
            if (!isset(self::$factories[$name])) {
                self::$factories[$name] = new MomoBirdAI_TestPluginFactory();
            }
            return self::$factories[$name];
        }
    }
}

if (!class_exists('Helper')) {
    class Helper
    {
        public static $panels = array();
        public static $actions = array();
        public static $options;

        public static function addPanel($index, $file, $title, $subTitle, $access)
        {
            self::$panels[$file] = compact('index', 'title', 'subTitle', 'access');
        }

        public static function removePanel($index, $file)
        {
            unset(self::$panels[$file]);
        }

        public static function addAction($name, $class)
        {
            self::$actions[$name] = $class;
        }

        public static function removeAction($name)
        {
            unset(self::$actions[$name]);
        }

        public static function options()
        {
            return self::$options;
        }
    }
}

// Mirrors Typecho\Config: values live in private storage, exposed by magic access and iteration.
class MomoBirdAI_TestConfig implements Iterator
{
    private $currentConfig;

    public function __construct(array $values)
    {
        $this->currentConfig = $values;
    }

    public function __get($name)
    {
        return isset($this->currentConfig[$name]) ? $this->currentConfig[$name] : null;
    }

    public function __isset($name)
    {
        return isset($this->currentConfig[$name]);
    }

    #[\ReturnTypeWillChange]
    public function current() { return current($this->currentConfig); }
    #[\ReturnTypeWillChange]
    public function key() { return key($this->currentConfig); }
    #[\ReturnTypeWillChange]
    public function next() { next($this->currentConfig); }
    #[\ReturnTypeWillChange]
    public function rewind() { reset($this->currentConfig); }
    #[\ReturnTypeWillChange]
    public function valid() { return key($this->currentConfig) !== null; }
}

class MomoBirdAI_TestOptions
{
    public $plugins = array();
    public $siteUrl = 'https://example.com/';

    // Like Widget\Options::plugin(): unknown plugin names throw.
    public function plugin($name)
    {
        if (!isset($this->plugins[$name])) {
            throw new RuntimeException('plugin config not found: ' . $name);
        }
        return new MomoBirdAI_TestConfig($this->plugins[$name]);
    }
}

if (!class_exists('Typecho_Widget_Helper_Form')) {
    class Typecho_Widget_Helper_Form
    {
        public $inputs = array();

        public function addInput($input)
        {
            $this->inputs[$input->name] = $input;
        }
    }
}

foreach (array('Text', 'Password', 'Radio', 'Hidden') as $kind) {
    $class = 'Typecho_Widget_Helper_Form_Element_' . $kind;
    if (!class_exists($class)) {
        eval('class ' . $class . ' { public $name; public function __construct($name) { $this->name = $name; } }');
    }
}

if (!class_exists('Widget_Plugins_Edit')) {
    class Widget_Plugins_Edit
    {
        public static function configPlugin($name, array $settings)
        {
            Helper::$options->plugins[$name] = $settings;
        }
    }
}

class MomoBirdAI_TestInstallDb
{
    public $queries = array();

    public function getAdapterName()
    {
        return 'Pdo_Mysql';
    }

    public function getPrefix()
    {
        return 'typecho_';
    }

    public function query($sql)
    {
        $this->queries[] = $sql;
    }
}

if (!class_exists('Typecho_Db')) {
    class Typecho_Db
    {
        const WRITE = 2;
        public static $instance;

        public static function get()
        {
            return self::$instance;
        }
    }
}

mb_test('activation registers only post lifecycle hooks and symmetric admin resources', function () {
    $pluginFile = dirname(__DIR__) . '/Plugin.php';
    mb_assert(is_file($pluginFile), 'Plugin.php does not exist');
    require_once $pluginFile;

    Typecho_Db::$instance = new MomoBirdAI_TestInstallDb();
    Typecho_Plugin::$factories = array();
    Helper::$panels = array();
    Helper::$actions = array();
    Helper::$options = new MomoBirdAI_TestOptions();

    MomoBirdAI_Plugin::activate();

    mb_assert(isset(Typecho_Plugin::$factories['Widget_Contents_Post_Edit']->finishPublish), 'publish hook missing');
    mb_assert(isset(Typecho_Plugin::$factories['Widget_Contents_Post_Edit']->finishMark), 'mark hook missing');
    mb_assert(isset(Typecho_Plugin::$factories['Widget_Contents_Post_Edit']->finishDelete), 'delete hook missing');
    mb_assert(!isset(Typecho_Plugin::$factories['Widget_Contents_Page_Edit']), 'page hooks must not be registered');
    mb_assert(isset(Typecho_Plugin::$factories['Widget_Archive']->footer), 'front-end footer hook missing');
    mb_assert(isset(Helper::$actions['momobird-ai']), 'admin action missing');
    mb_assert(isset(Helper::$panels['MomoBirdAI/panel.php']), 'admin panel missing');

    MomoBirdAI_Plugin::deactivate();
    mb_assert(!isset(Helper::$actions['momobird-ai']), 'admin action was not removed');
    mb_assert(!isset(Helper::$panels['MomoBirdAI/panel.php']), 'admin panel was not removed');
});

mb_test('saving a blank connection key preserves the secret and stable site identity', function () {
    require_once dirname(__DIR__) . '/Plugin.php';
    Helper::$options = new MomoBirdAI_TestOptions();
    Helper::$options->plugins['MomoBirdAI'] = array(
        'enabled' => '1',
        'base_url' => 'https://api.valley.atlaspaces.com',
        'timeout' => 10
    );
    Helper::$options->plugins['MomoBirdAISecret'] = array('connection_key' => mb_key('s'), 'site_id' => 'stable-site-id');

    MomoBirdAI_Plugin::configHandle(array(
        'enabled' => '1',
        'base_url' => 'https://api.valley.atlaspaces.com',
        'connection_key_new' => '',
        'timeout' => '12'
    ), false);

    $saved = MomoBirdAI_Plugin::settings();
    mb_assert_same(mb_key('s'), $saved['connection_key'], 'stored connection key was lost');
    mb_assert_same('stable-site-id', $saved['site_id'], 'site identity changed');
    mb_assert_same(12, $saved['timeout'], 'timeout was not normalized');
    mb_assert(!isset($saved['connection_key_new']), 'temporary password field was persisted');
});

mb_test('deactivation backup restores secret and site identity on reactivation', function () {
    require_once dirname(__DIR__) . '/Plugin.php';
    Helper::$options = new MomoBirdAI_TestOptions();
    Helper::$options->plugins['MomoBirdAI'] = array(
        'enabled' => '1',
        'base_url' => 'https://api.valley.atlaspaces.com',
        'timeout' => 9
    );
    Helper::$options->plugins['MomoBirdAISecret'] = array('connection_key' => mb_key('v'), 'site_id' => 'surviving-site-id');

    MomoBirdAI_Plugin::deactivate();
    unset(Helper::$options->plugins['MomoBirdAI']); // Typecho core does this after deactivate().
    unset(Helper::$options->plugins['MomoBirdAISecret']); // prove the restore comes from the backup
    MomoBirdAI_Plugin::configHandle(array(
        'enabled' => '1',
        'base_url' => 'https://api.valley.atlaspaces.com',
        'connection_key_new' => '',
        'timeout' => 10
    ), true);

    $restored = MomoBirdAI_Plugin::settings();
    mb_assert_same(mb_key('v'), $restored['connection_key'], 'deactivation lost the connection key');
    mb_assert_same('surviving-site-id', $restored['site_id'], 'deactivation changed the site identity');
});

mb_test('activation requirements fail clearly when a required runtime capability is absent', function () {
    mb_assert_throws(function () {
        MomoBirdAI_Plugin::validateRuntime('7.1.0', true, true);
    }, 'RuntimeException');
    mb_assert_throws(function () {
        MomoBirdAI_Plugin::validateRuntime('7.2.0', false, true);
    }, 'RuntimeException');
    mb_assert_throws(function () {
        MomoBirdAI_Plugin::validateRuntime('7.2.0', true, false);
    }, 'RuntimeException');
    MomoBirdAI_Plugin::validateRuntime('7.2.0', true, true);
    mb_assert(true, 'valid runtime was rejected');
});

mb_test('site origin comes from the Typecho site url', function () {
    require_once dirname(__DIR__) . '/Plugin.php';
    $options = new stdClass();
    $options->siteUrl = 'https://underestimated.cn/blog/';
    mb_assert_same('https://underestimated.cn', MomoBirdAI_Plugin::siteOrigin($options), 'site origin not derived');
    $options->siteUrl = 'HTTP://Localhost:8080';
    mb_assert_same('http://Localhost:8080', MomoBirdAI_Plugin::siteOrigin($options), 'port or scheme lost');
    $options->siteUrl = 'not a url';
    mb_assert_same('', MomoBirdAI_Plugin::siteOrigin($options), 'invalid site url accepted');
});


mb_test('stored plugin settings only contain keys the settings form renders', function () {
    require_once dirname(__DIR__) . '/Plugin.php';
    Helper::$options = new MomoBirdAI_TestOptions();
    MomoBirdAI_Plugin::configHandle(array(
        'enabled' => '1',
        'base_url' => 'https://api.valley.atlaspaces.com',
        'connection_key_new' => mb_key('f'),
        'timeout' => '10',
        'widget_enabled' => '0'
    ), false);
    $form = new Typecho_Widget_Helper_Form();
    MomoBirdAI_Plugin::config($form);
    // Typecho's config page calls $form->getInput($key)->value() for every stored key.
    foreach (array_keys(Helper::$options->plugins['MomoBirdAI']) as $key) {
        mb_assert(isset($form->inputs[$key]), 'stored setting has no form input: ' . $key);
    }
    mb_assert_same(mb_key('f'), MomoBirdAI_Plugin::settings()['connection_key'], 'connection key not kept outside the form settings');
    mb_assert(strpos(serialize(Helper::$options->plugins['MomoBirdAI']), 'mbk_') === false, 'connection key stored with form-rendered settings');
});

mb_test('widget state reads Typecho config objects', function () {
    require_once dirname(__DIR__) . '/Plugin.php';
    Helper::$options = new MomoBirdAI_TestOptions();
    $now = time();
    Helper::$options->plugins['MomoBirdAIWidget'] = array('public_id' => 'pub_9', 'available' => true, 'reason' => 'ok', 'checked_at' => $now);
    $state = MomoBirdAI_Plugin::widgetState();
    mb_assert(strpos($state->snippet($now), 'pub_9') !== false, 'widget state was not read from the Typecho config object');
    mb_assert(!$state->needsRefresh($now), 'fresh state reported stale');
    unset(Helper::$options->plugins['MomoBirdAIWidget']);
    mb_assert_same('', MomoBirdAI_Plugin::widgetState()->snippet($now), 'missing widget config should render nothing');
});
