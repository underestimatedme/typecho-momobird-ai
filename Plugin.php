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
        MomoBirdAI_SyncRepository::install(Typecho_Db::get());
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array(__CLASS__, 'finishPublish');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishMark = array(__CLASS__, 'finishMark');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishDelete = array(__CLASS__, 'finishDelete');
        Helper::addPanel(3, self::PANEL_FILE, _t('MomoBird 同步'), _t('MomoBird 同步'), 'administrator');
        Helper::addAction(self::ACTION_NAME, 'MomoBirdAI_Action');
        return _t('MomoBird AI 已启用');
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

        $collection = new Typecho_Widget_Helper_Form_Element_Text(
            'collection',
            null,
            '',
            _t('Collection Slug'),
            _t('在 MomoBird/Forge 中创建的知识集合标识。')
        );
        $form->addInput($collection);

        $apiKey = new Typecho_Widget_Helper_Form_Element_Password(
            'api_key_new',
            null,
            '',
            _t('API Key'),
            _t('填写 collection 级读写密钥；留空保留已保存的密钥。')
        );
        $form->addInput($apiKey);

        $timeout = new Typecho_Widget_Helper_Form_Element_Text(
            'timeout',
            null,
            '10',
            _t('请求超时（秒）'),
            _t('允许 2–30 秒，默认 10 秒。')
        );
        $form->addInput($timeout);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    public static function configHandle($settings, $isInit)
    {
        $stored = $isInit ? self::namedSettings(self::BACKUP_NAME) : self::settings();
        $source = $isInit && !empty($stored['site_id']) ? array_merge($settings, $stored) : $settings;
        $apiKey = isset($settings['api_key_new']) ? trim((string) $settings['api_key_new']) : '';
        if ($apiKey === '') {
            $apiKey = isset($stored['api_key']) ? $stored['api_key'] : '';
        }
        $normalized = array(
            'enabled' => isset($source['enabled']) && (string) $source['enabled'] === '0' ? '0' : '1',
            'base_url' => isset($source['base_url']) ? trim((string) $source['base_url']) : 'https://api.valley.atlaspaces.com',
            'collection' => isset($source['collection']) ? trim((string) $source['collection']) : '',
            'api_key' => $apiKey,
            'timeout' => isset($source['timeout']) ? (int) $source['timeout'] : 10,
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
            'collection' => '',
            'api_key' => '',
            'timeout' => 10,
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
            foreach (array('enabled', 'base_url', 'collection', 'api_key', 'timeout', 'site_id') as $key) {
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
            $service = new MomoBirdAI_SyncService(new MomoBirdAI_HttpClient($config), $state, $settings['site_id']);
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
            return mb_substr(strip_tags((string) $result['error']['message']), 0, 160, 'UTF-8');
        }
        return _t('未知错误，请在同步面板重试');
    }

    private static function safeMessage($error)
    {
        return mb_substr(strip_tags((string) $error->getMessage()), 0, 160, 'UTF-8');
    }
}
