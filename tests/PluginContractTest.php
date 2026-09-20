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

class MomoBirdAI_TestOptions
{
    public $plugins = array();

    public function plugin($name)
    {
        return (object) (isset($this->plugins[$name]) ? $this->plugins[$name] : array());
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
    mb_assert(isset(Helper::$actions['momobird-ai']), 'admin action missing');
    mb_assert(isset(Helper::$panels['MomoBirdAI/panel.php']), 'admin panel missing');

    MomoBirdAI_Plugin::deactivate();
    mb_assert(!isset(Helper::$actions['momobird-ai']), 'admin action was not removed');
    mb_assert(!isset(Helper::$panels['MomoBirdAI/panel.php']), 'admin panel was not removed');
});

mb_test('saving blank API key preserves the secret and stable site identity', function () {
    require_once dirname(__DIR__) . '/Plugin.php';
    Helper::$options = new MomoBirdAI_TestOptions();
    Helper::$options->plugins['MomoBirdAI'] = array(
        'enabled' => '1',
        'base_url' => 'https://api.valley.atlaspaces.com',
        'collection' => 'blog-kb',
        'api_key' => 'stored-secret',
        'timeout' => 10,
        'site_id' => 'stable-site-id'
    );

    MomoBirdAI_Plugin::configHandle(array(
        'enabled' => '1',
        'base_url' => 'https://api.valley.atlaspaces.com',
        'collection' => 'blog-kb',
        'api_key_new' => '',
        'timeout' => '12'
    ), false);

    $saved = Helper::$options->plugins['MomoBirdAI'];
    mb_assert_same('stored-secret', $saved['api_key'], 'stored API key was lost');
    mb_assert_same('stable-site-id', $saved['site_id'], 'site identity changed');
    mb_assert_same(12, $saved['timeout'], 'timeout was not normalized');
    mb_assert(!isset($saved['api_key_new']), 'temporary password field was persisted');
});

mb_test('deactivation backup restores secret and site identity on reactivation', function () {
    require_once dirname(__DIR__) . '/Plugin.php';
    Helper::$options = new MomoBirdAI_TestOptions();
    Helper::$options->plugins['MomoBirdAI'] = array(
        'enabled' => '1',
        'base_url' => 'https://api.valley.atlaspaces.com',
        'collection' => 'blog-kb',
        'api_key' => 'surviving-secret',
        'timeout' => 9,
        'site_id' => 'surviving-site-id'
    );

    MomoBirdAI_Plugin::deactivate();
    unset(Helper::$options->plugins['MomoBirdAI']); // Typecho core does this after deactivate().
    MomoBirdAI_Plugin::configHandle(array(
        'enabled' => '1',
        'base_url' => 'https://api.valley.atlaspaces.com',
        'collection' => '',
        'api_key_new' => '',
        'timeout' => 10
    ), true);

    $restored = Helper::$options->plugins['MomoBirdAI'];
    mb_assert_same('surviving-secret', $restored['api_key'], 'deactivation lost the API key');
    mb_assert_same('surviving-site-id', $restored['site_id'], 'deactivation changed the site identity');
    mb_assert_same('blog-kb', $restored['collection'], 'deactivation lost the collection');
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
