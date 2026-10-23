<?php

// Typecho records a plugin's hooks only when it is enabled. An owner who
// upgrades by copying new files over the old ones keeps the old hook list, so
// hooks added in a later version never run until the plugin is disabled and
// enabled again. This adds the missing ones the way enabling would: to the
// live handle table and to the plugin's own record, so disabling still removes
// them all.
final class MomoBirdAI_HookRepair
{
    private $registry;
    private $pluginName;

    // $registry wraps Typecho's plugin store: export(), register($handle,
    // $component, $callback), replace(array $plugins), persist(array $plugins).
    public function __construct($registry, $pluginName)
    {
        $this->registry = $registry;
        $this->pluginName = (string) $pluginName;
    }

    // $hooks lists array(handle, component, callback). Returns the
    // "handle:component" keys that were added.
    public function repair(array $hooks)
    {
        $plugins = $this->registry->export();
        if (!isset($plugins['activated'][$this->pluginName])) {
            return array();
        }
        $missing = array();
        foreach ($hooks as $hook) {
            if (!self::registered($plugins, $hook[2])) {
                $missing[] = $hook;
            }
        }
        if (!$missing) {
            return array();
        }
        foreach ($missing as $hook) {
            $this->registry->register($hook[0], $hook[1], $hook[2]);
        }
        $plugins = $this->registry->export();
        $repaired = array();
        foreach ($missing as $hook) {
            $key = self::keyOf($plugins, $hook[2]);
            if ($key === null) {
                continue;
            }
            $recorded = isset($plugins['activated'][$this->pluginName]['handles'][$key])
                ? $plugins['activated'][$this->pluginName]['handles'][$key]
                : array();
            if (!in_array($hook[2], $recorded, true)) {
                $recorded[] = $hook[2];
            }
            $plugins['activated'][$this->pluginName]['handles'][$key] = $recorded;
            $repaired[] = $key;
        }
        $this->registry->replace($plugins);
        $this->registry->persist($plugins);
        return $repaired;
    }

    private static function registered(array $plugins, $callback)
    {
        return self::keyOf($plugins, $callback) !== null;
    }

    private static function keyOf(array $plugins, $callback)
    {
        $handles = isset($plugins['handles']) && is_array($plugins['handles']) ? $plugins['handles'] : array();
        foreach ($handles as $key => $callbacks) {
            if (is_array($callbacks) && in_array($callback, $callbacks, true)) {
                return $key;
            }
        }
        return null;
    }
}

// The real store: Typecho's static plugin table and the options row it is saved in.
final class MomoBirdAI_TypechoHookRegistry
{
    public function export()
    {
        return Typecho_Plugin::export();
    }

    public function register($handle, $component, $callback)
    {
        Typecho_Plugin::factory($handle)->{$component} = $callback;
    }

    public function replace(array $plugins)
    {
        Typecho_Plugin::init($plugins);
    }

    public function persist(array $plugins)
    {
        $db = Typecho_Db::get();
        $db->query($db->update('table.options')->rows(array('value' => serialize($plugins)))->where('name = ?', 'plugins'));
    }
}
