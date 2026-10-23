<?php

// Mirrors how Typecho 1.2 keeps hooks: options.plugins holds 'handles' keyed by
// "Handle:component" and, per activated plugin, the callbacks it registered.
class MomoBirdAI_TestHookRegistry
{
    public $plugins;
    public $persisted = array();

    public function __construct(array $plugins)
    {
        $this->plugins = $plugins;
    }

    public function export()
    {
        return $this->plugins;
    }

    public function register($handle, $component, $callback)
    {
        $key = $handle . ':' . $component;
        if (!isset($this->plugins['handles'][$key])) {
            $this->plugins['handles'][$key] = array();
        }
        $weight = 0;
        while (isset($this->plugins['handles'][$key][strval($weight)])) {
            $weight += 0.001;
        }
        $this->plugins['handles'][$key][strval($weight)] = $callback;
    }

    public function replace(array $plugins)
    {
        $this->plugins = $plugins;
    }

    public function persist(array $plugins)
    {
        $this->persisted[] = $plugins;
    }

    // Typecho\Plugin::deactivate: drop every callback the plugin recorded.
    public function deactivate($name)
    {
        foreach ($this->plugins['activated'][$name]['handles'] as $key => $callbacks) {
            foreach ($callbacks as $callback) {
                while (false !== ($index = array_search($callback, $this->plugins['handles'][$key], true))) {
                    unset($this->plugins['handles'][$key][$index]);
                }
            }
            if (empty($this->plugins['handles'][$key])) {
                unset($this->plugins['handles'][$key]);
            }
        }
        unset($this->plugins['activated'][$name]);
    }
}

function mb_hook_list()
{
    return array(
        array('Widget_Archive', 'footer', array('MomoBirdAI_Plugin', 'footer')),
        array('Widget_Abstract_Contents', 'contentEx', array('MomoBirdAI_Plugin', 'podcastContent')),
    );
}

// An install activated as 0.2.x: only the footer hook was ever recorded.
function mb_old_install()
{
    $footer = array('MomoBirdAI_Plugin', 'footer');
    return array(
        'activated' => array(
            'MomoBirdAI' => array('handles' => array('Widget_Archive:footer' => array($footer))),
            'Other' => array('handles' => array('Widget_Abstract_Contents:contentEx' => array(array('Other_Plugin', 'parse')))),
        ),
        'handles' => array(
            'Widget_Archive:footer' => array('0' => $footer),
            'Widget_Abstract_Contents:contentEx' => array('0' => array('Other_Plugin', 'parse')),
        ),
    );
}

mb_test('hook repair registers a hook added after the plugin was activated', function () {
    $registry = new MomoBirdAI_TestHookRegistry(mb_old_install());
    $repaired = (new MomoBirdAI_HookRepair($registry, 'MomoBirdAI'))->repair(mb_hook_list());
    mb_assert_same(array('Widget_Abstract_Contents:contentEx'), $repaired, 'repaired hooks');
    mb_assert_same(1, count($registry->persisted), 'not persisted exactly once');
    $saved = $registry->persisted[0];
    mb_assert(in_array(array('MomoBirdAI_Plugin', 'podcastContent'), $saved['handles']['Widget_Abstract_Contents:contentEx'], true), 'contentEx not registered');
    mb_assert(in_array(array('Other_Plugin', 'parse'), $saved['handles']['Widget_Abstract_Contents:contentEx'], true), 'another plugin lost its hook');
    mb_assert_same(
        array(array('MomoBirdAI_Plugin', 'podcastContent')),
        $saved['activated']['MomoBirdAI']['handles']['Widget_Abstract_Contents:contentEx'],
        'repaired hook not recorded for deactivation'
    );
    mb_assert_same($saved, $registry->plugins, 'current request does not see the repaired hooks');
});

mb_test('hook repair leaves an up-to-date install alone', function () {
    $registry = new MomoBirdAI_TestHookRegistry(mb_old_install());
    $repair = new MomoBirdAI_HookRepair($registry, 'MomoBirdAI');
    $repair->repair(mb_hook_list());
    $registry->persisted = array();
    mb_assert_same(array(), $repair->repair(mb_hook_list()), 'second repair changed something');
    mb_assert_same(array(), $registry->persisted, 'second repair wrote the options');
});

mb_test('hook repair does nothing while the plugin is not active', function () {
    $plugins = mb_old_install();
    unset($plugins['activated']['MomoBirdAI']);
    $registry = new MomoBirdAI_TestHookRegistry($plugins);
    mb_assert_same(array(), (new MomoBirdAI_HookRepair($registry, 'MomoBirdAI'))->repair(mb_hook_list()), 'repaired an inactive plugin');
    mb_assert_same(array(), $registry->persisted, 'wrote options for an inactive plugin');
});

mb_test('repaired hooks are removed again when the plugin is disabled', function () {
    $registry = new MomoBirdAI_TestHookRegistry(mb_old_install());
    (new MomoBirdAI_HookRepair($registry, 'MomoBirdAI'))->repair(mb_hook_list());
    $registry->deactivate('MomoBirdAI');
    $left = json_encode($registry->plugins['handles']);
    mb_assert(strpos($left, 'MomoBirdAI_Plugin') === false, 'MomoBird hooks left behind: ' . $left);
    mb_assert(strpos($left, 'Other_Plugin') !== false, 'another plugin hook removed');
});

mb_test('activation registers every hook in the shared list', function () {
    require_once dirname(__DIR__) . '/Plugin.php';
    $components = array();
    foreach (MomoBirdAI_Plugin::hooks() as $hook) {
        $components[] = $hook[0] . ':' . $hook[1];
    }
    sort($components);
    mb_assert_same(array(
        'Widget_Abstract_Contents:contentEx',
        'Widget_Archive:footer',
        'Widget_Contents_Post_Edit:finishDelete',
        'Widget_Contents_Post_Edit:finishMark',
        'Widget_Contents_Post_Edit:finishPublish',
    ), $components, 'hook list');
});
