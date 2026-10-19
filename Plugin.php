<?php
/**
 * MomoBird AI knowledge-base synchronization for Typecho.
 *
 * @package MomoBirdAI
 * @author MomoBird Team
 * @version 0.1.0
 * @link https://momobird.atlaspaces.com
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/lib/Bootstrap.php';

class MomoBirdAI_Plugin implements Typecho_Plugin_Interface
{
    const PANEL_FILE = 'MomoBirdAI/panel.php';
    const ACTION_NAME = 'momobird-ai';
    const BACKUP_NAME = 'MomoBirdAIBackup';

    public static function activate()
    {
        try {
            self::validateRuntime(PHP_VERSION, function_exists('curl_init'), function_exists('mb_substr'));
        } catch (RuntimeException $error) {
            if (class_exists('Typecho\\Plugin\\Exception')) {
                throw new \Typecho\Plugin\Exception($error->getMessage());
            }
            throw $error;
        }
        MomoBirdAI_SyncRepository::install(Typecho_Db::get());
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array(__CLASS__, 'finishPublish');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishMark = array(__CLASS__, 'finishMark');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishDelete = array(__CLASS__, 'finishDelete');
        Helper::addPanel(3, self::PANEL_FILE, _t('MomoBird 同步'), _t('MomoBird 同步'), 'administrator');
        Helper::addAction(self::ACTION_NAME, 'MomoBirdAI_Action');
        return _t('MomoBird AI 已启用');
    }

    public static function validateRuntime($phpVersion, $hasCurl, $hasMbstring)
    {
        if (version_compare((string) $phpVersion, '7.2.0', '<')) {
            throw new RuntimeException('MomoBird AI requires PHP 7.2 or newer');
        }
        if (!$hasCurl) {
            throw new RuntimeException('MomoBird AI requires the PHP cURL extension');
        }
        if (!$hasMbstring) {
            throw new RuntimeException('MomoBird AI requires the PHP mbstring extension');
        }
    }

    public static function deactivate()
    {
        self::saveSettings(self::settings(), self::BACKUP_NAME);
        Helper::removePanel(3, self::PANEL_FILE);
        Helper::removeAction(self::ACTION_NAME);
        return _t('MomoBird AI 已停用；同步记录和配置已保留');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $enabled = new Typecho_Widget_Helper_Form_Element_Radio(
            'enabled',
            array('1' => _t('启用'), '0' => _t('停用')),
            '1',
            _t('自动同步'),
            _t('仅同步公开普通文章；草稿、私密文章和独立页面不会上传。')
        );
        $form->addInput($enabled);

        $baseUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'base_url',
            null,
            'https://api.valley.atlaspaces.com',
            _t('MomoBird Base URL'),
            _t('生产环境通常无需修改。仅本机地址允许使用 HTTP。')
        );
        $form->addInput($baseUrl);

        $connectionKey = new Typecho_Widget_Helper_Form_Element_Password(
            'connection_key_new',
            null,
            '',
            _t('连接密钥'),
            _t('在 MomoBird 控制台「知识 → 新增来源 → Typecho 博客」中生成，以 mbk_ 开头；留空保留已保存的密钥。')
        );
        $form->addInput($connectionKey);

        $timeout = new Typecho_Widget_Helper_Form_Element_Text(
            'timeout',
            null,
            '10',
            _t('请求超时（秒）'),
            _t('允许 2–30 秒，默认 10 秒。')
        );
        $form->addInput($timeout);

        $widget = new Typecho_Widget_Helper_Form_Element_Radio(
            'widget_enabled',
            array('1' => _t('显示'), '0' => _t('不显示')),
            '0',
            _t('在前台显示问答助手'),
            _t('读者可以在博客页面直接提问，答案来自已同步的文章。需要专业版或试用。')
        );
        $form->addInput($widget);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    public static function configHandle($settings, $isInit)
    {
        $stored = $isInit ? self::namedSettings(self::BACKUP_NAME) : self::settings();
        $source = $isInit && !empty($stored['site_id']) ? array_merge($settings, $stored) : $settings;
        $connectionKey = isset($settings['connection_key_new']) ? trim((string) $settings['connection_key_new']) : '';
        if ($connectionKey === '') {
            $connectionKey = isset($stored['connection_key']) ? $stored['connection_key'] : '';
        }
        $normalized = array(
            'enabled' => isset($source['enabled']) && (string) $source['enabled'] === '0' ? '0' : '1',
            'base_url' => isset($source['base_url']) ? trim((string) $source['base_url']) : 'https://api.valley.atlaspaces.com',
            'connection_key' => $connectionKey,
            'timeout' => isset($source['timeout']) ? (int) $source['timeout'] : 10,
            'widget_enabled' => isset($source['widget_enabled']) && (string) $source['widget_enabled'] === '1' ? '1' : '0',
            'site_id' => !empty($stored['site_id']) ? $stored['site_id'] : bin2hex(random_bytes(16))
        );

        if (!$isInit && $normalized['enabled'] === '1') {
            MomoBirdAI_Config::fromArray($normalized);
        }
        self::saveSettings($normalized);
        if ($isInit && !empty($stored['site_id'])) {
            self::saveSettings(array(), self::BACKUP_NAME);
        }
    }

    public static function finishPublish($contents, $widget)
    {
        $cid = isset($widget->cid) ? (int) $widget->cid : 0;
        self::syncPostId($cid, false);
    }

    public static function finishMark($status, $post, $widget)
    {
        self::syncPostId((int) $post, false);
    }

    public static function finishDelete($post, $widget)
    {
        self::syncPostId((int) $post, true);
    }

    public static function settings()
    {
        $defaults = array(
            'enabled' => '1',
            'base_url' => 'https://api.valley.atlaspaces.com',
            'connection_key' => '',
            'timeout' => 10,
            'widget_enabled' => '0',
            'site_id' => ''
        );
        return array_merge($defaults, self::namedSettings('MomoBirdAI'));
    }

    private static function namedSettings($name)
    {
        $values = array();
        if (!class_exists('Helper') || !method_exists('Helper', 'options')) {
            return $values;
        }
        try {
            $stored = Helper::options()->plugin($name);
            foreach (array('enabled', 'base_url', 'connection_key', 'timeout', 'widget_enabled', 'site_id') as $key) {
                if (isset($stored->{$key})) {
                    $values[$key] = $stored->{$key};
                }
            }
        } catch (Throwable $error) {
            return $values;
        }
        return $values;
    }

    private static function syncPostId($cid, $forceDelete)
    {
        if ($cid < 1) {
            return;
        }
        $settings = self::settings();
        if ((string) $settings['enabled'] !== '1') {
            return;
        }
        try {
            $config = MomoBirdAI_Config::fromArray($settings);
            $db = Typecho_Db::get();
            $options = Helper::options();
            $state = new MomoBirdAI_SyncRepository($db, $settings['site_id']);
            $service = new MomoBirdAI_SyncService(new MomoBirdAI_HttpClient($config), $state, self::siteOrigin($options));
            $posts = new MomoBirdAI_PostRepository($db, $options);
            $post = $forceDelete ? null : $posts->find($cid);
            $result = $post ? $service->syncPost($post) : $service->deletePost($cid);
            if ($result['ok']) {
                self::notice(_t('MomoBird 知识库同步成功'), 'success');
            } else {
                self::notice(_t('文章已保存，但 MomoBird 同步失败：%s', self::safeResultError($result)), 'error');
            }
        } catch (Throwable $error) {
            self::notice(_t('文章已保存，但 MomoBird 同步失败：%s', self::safeMessage($error)), 'error');
        }
    }

    public static function siteOrigin($options)
    {
        $siteUrl = isset($options->siteUrl) ? (string) $options->siteUrl : '';
        $parts = parse_url($siteUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        return strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private static function saveSettings(array $settings, $name = 'MomoBirdAI')
    {
        if (class_exists('Widget_Plugins_Edit')) {
            Widget_Plugins_Edit::configPlugin($name, $settings);
            return;
        }
        if (class_exists('Widget\\Plugins\\Edit')) {
            call_user_func(array('Widget\\Plugins\\Edit', 'configPlugin'), $name, $settings);
            return;
        }
        throw new RuntimeException('Typecho plugin configuration service is unavailable');
    }

    private static function notice($message, $type)
    {
        if (class_exists('Widget_Notice')) {
            Widget_Notice::alloc()->set($message, $type);
        } elseif (class_exists('Widget\\Notice')) {
            call_user_func(array('Widget\\Notice', 'alloc'))->set($message, $type);
        }
    }

    private static function safeResultError(array $result)
    {
        if (isset($result['error']['message'])) {
            return self::boundedMessage($result['error']['message']);
        }
        return _t('未知错误，请在同步面板重试');
    }

    private static function safeMessage($error)
    {
        return self::boundedMessage($error->getMessage());
    }

    private static function boundedMessage($message)
    {
        $message = strip_tags((string) $message);
        return function_exists('mb_substr') ? mb_substr($message, 0, 160, 'UTF-8') : substr($message, 0, 160);
    }
}
