<?php
/**
 * MomoBird AI knowledge-base synchronization for Typecho.
 *
 * @package MomoBirdAI
 * @author MomoBird Team
 * @version 0.3.3
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
    const WIDGET_STATE_NAME = 'MomoBirdAIWidget';
    // Typecho renders every key of plugin:MomoBirdAI into the settings form, so values
    // without a form input (the secret key and the site identity) live under their own option.
    const SECRET_NAME = 'MomoBirdAISecret';
    const PODCAST_STATE_NAME = 'MomoBirdAIPodcast';

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
        Typecho_Plugin::factory('Widget_Archive')->footer = array(__CLASS__, 'footer');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array(__CLASS__, 'podcastContent');
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

        // The key is never echoed back, so say which one is saved; otherwise the empty field
        // reads as "not configured" and owners paste the key again on every save.
        $savedKey = (string) self::settings()['connection_key'];
        $keyHelp = $savedKey === ''
            ? _t('在 MomoBird 控制台「知识 → 新增来源 → Typecho 博客」中生成，以 mbk_ 开头。')
            : _t('已保存 %s…，留空即继续使用，升级插件也不用重新填写；只有换新密钥时才需要填。', substr($savedKey, 0, 12));
        $connectionKey = new Typecho_Widget_Helper_Form_Element_Password(
            'connection_key_new',
            null,
            '',
            _t('连接密钥'),
            $keyHelp
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

        $podcast = new Typecho_Widget_Helper_Form_Element_Radio(
            'podcast_enabled',
            array('1' => _t('显示'), '0' => _t('不显示')),
            '1',
            _t('在文章页显示播客播放器'),
            _t('在 MomoBird 控制台为文章生成播客后，文章正文上方会出现播放器；没有播客的文章不显示任何内容。')
        );
        $form->addInput($podcast);
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
            'podcast_enabled' => isset($source['podcast_enabled']) && (string) $source['podcast_enabled'] === '0' ? '0' : '1',
            'site_id' => !empty($stored['site_id']) ? $stored['site_id'] : bin2hex(random_bytes(16))
        );

        if (!$isInit && $normalized['enabled'] === '1') {
            MomoBirdAI_Config::fromArray($normalized);
        }
        self::saveSettings(array(
            'enabled' => $normalized['enabled'],
            'base_url' => $normalized['base_url'],
            'timeout' => $normalized['timeout'],
            'widget_enabled' => $normalized['widget_enabled'],
            'podcast_enabled' => $normalized['podcast_enabled']
        ));
        self::saveSettings(array(
            'connection_key' => $normalized['connection_key'],
            'site_id' => $normalized['site_id']
        ), self::SECRET_NAME);
        if ($isInit && !empty($stored['site_id'])) {
            self::saveSettings(array(), self::BACKUP_NAME);
        }
        if (!$isInit) {
            self::applyWidgetSetting($normalized);
        }
    }

    public static function footer()
    {
        try {
            $state = self::widgetState();
            echo $state->snippet(time());
            $player = self::podcastPlayer();
            echo $player->script((string) self::settings()['podcast_enabled'] === '1');
            if (self::canFinishResponseEarly() && $player->claimRefresh(time())) {
                register_shutdown_function(array(__CLASS__, 'refreshPodcastAfterResponse'));
            }
            // Only refresh where the response can be finished first, and only one view at a time.
            if (self::canFinishResponseEarly() && $state->claimRefresh(time())) {
                register_shutdown_function(array(__CLASS__, 'refreshWidgetAfterResponse'));
            }
        } catch (Throwable $error) {
            // The blog page must never break because of the assistant.
        }
    }

    // Runs after the page is sent (PHP-FPM) so a slow MomoBird never delays readers.
    private static function canFinishResponseEarly()
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    public static function refreshWidgetAfterResponse()
    {
        try {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            } else {
                return;
            }
            $settings = self::settings();
            if ((string) $settings['widget_enabled'] !== '1') {
                return;
            }
            $config = MomoBirdAI_Config::fromArray(array_merge($settings, array('timeout' => 3)));
            $client = new MomoBirdAI_HttpClient($config);
            self::widgetState()->ensureEnabled($client->withoutRetry(), time());
        } catch (Throwable $error) {
            // Best effort; the claimed timestamp spaces out the next attempt.
        }
    }

    public static function podcastPlayer()
    {
        return new MomoBirdAI_PodcastPlayer(
            function () {
                return MomoBirdAI_Plugin::optionArray(MomoBirdAI_Plugin::PODCAST_STATE_NAME);
            },
            function (array $values) {
                MomoBirdAI_Plugin::saveSettings($values, MomoBirdAI_Plugin::PODCAST_STATE_NAME);
            }
        );
    }

    // contentEx filter: $lastResult carries what earlier plugins made of the content.
    public static function podcastContent($content, $widget, $lastResult = null)
    {
        $content = $lastResult === null || $lastResult === '' ? $content : $lastResult;
        try {
            if (!is_object($widget) || !method_exists($widget, 'is') || !$widget->is('post')) {
                return $content;
            }
            $settings = self::settings();
            $public = isset($widget->status) && (string) $widget->status === 'publish' && empty($widget->password);
            $placeholder = self::podcastPlayer()->placeholder(
                isset($widget->cid) ? (int) $widget->cid : 0,
                true,
                $public,
                (string) $settings['podcast_enabled'] === '1',
                (string) $settings['base_url']
            );
            return $placeholder . $content;
        } catch (Throwable $error) {
            // The article must render even when the player cannot be placed.
            return $content;
        }
    }

    public static function refreshPodcastAfterResponse()
    {
        try {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            } else {
                return;
            }
            $settings = self::settings();
            if (trim((string) $settings['connection_key']) === '') {
                return;
            }
            $config = MomoBirdAI_Config::fromArray(array_merge($settings, array('timeout' => 3)));
            $client = new MomoBirdAI_HttpClient($config);
            self::podcastPlayer()->rememberStatus($client->withoutRetry()->status(), time());
        } catch (Throwable $error) {
            // Best effort; the claimed timestamp spaces out the next attempt.
        }
    }

    public static function widgetState()
    {
        return new MomoBirdAI_WidgetState(
            function () {
                return MomoBirdAI_Plugin::optionArray(MomoBirdAI_Plugin::WIDGET_STATE_NAME);
            },
            function (array $values) {
                MomoBirdAI_Plugin::saveSettings($values, MomoBirdAI_Plugin::WIDGET_STATE_NAME);
            }
        );
    }

    private static function applyWidgetSetting(array $settings)
    {
        $state = self::widgetState();
        if ((string) $settings['widget_enabled'] !== '1') {
            $state->clear();
            return;
        }
        try {
            $result = $state->enable(new MomoBirdAI_HttpClient(MomoBirdAI_Config::fromArray($settings)), time());
        } catch (Throwable $error) {
            self::notice(_t('前台问答助手开启失败：%s', self::safeMessage($error)), 'error');
            return;
        }
        if (isset($result['error_code']) && $result['error_code'] === 'WIDGET_NOT_READY') {
            self::notice(_t('请先完成一次全量同步，等文章索引完成后再打开前台助手'), 'notice');
        } elseif (empty($result['available'])) {
            self::notice(_t('前台问答助手暂不可用：%s', $result['reason']), 'notice');
        } else {
            self::notice(_t('前台问答助手已开启'), 'success');
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
            'podcast_enabled' => '1',
            'site_id' => ''
        );
        $form = array_intersect_key(self::optionArray('MomoBirdAI'), array_flip(array('enabled', 'base_url', 'timeout', 'widget_enabled', 'podcast_enabled')));
        $secret = array_intersect_key(self::optionArray(self::SECRET_NAME), array_flip(array('connection_key', 'site_id')));
        return array_merge($defaults, $form, $secret);
    }

    private static function namedSettings($name)
    {
        return self::optionArray($name);
    }

    // Helper::options()->plugin() returns a Typecho\Config (private storage, iterable) and
    // throws when the name has never been saved.
    public static function optionArray($name)
    {
        if (!class_exists('Helper') || !method_exists('Helper', 'options')) {
            return array();
        }
        try {
            $config = Helper::options()->plugin($name);
        } catch (Throwable $error) {
            return array();
        }
        if ($config instanceof Traversable) {
            return iterator_to_array($config);
        }
        if (is_object($config) && method_exists($config, 'toArray')) {
            return (array) $config->toArray();
        }
        return is_array($config) ? $config : array();
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
            $widget = self::widgetState();
            if ($config->widgetEnabled() && $widget->needsRefresh(time())) {
                $widget->ensureEnabled(new MomoBirdAI_HttpClient($config), time());
            }
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

    public static function saveSettings(array $settings, $name = 'MomoBirdAI')
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
