<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));
}

if (!interface_exists('Widget_Interface_Do')) {
    interface Widget_Interface_Do
    {
    }
}

class MomoBirdAI_ActionTestStop extends Exception
{
}

class MomoBirdAI_ActionTestUser
{
    public $passed = array();

    public function pass($group)
    {
        $this->passed[] = $group;
    }
}

class MomoBirdAI_ActionTestSecurity
{
    public $protected = 0;

    public function protect()
    {
        $this->protected++;
        throw new MomoBirdAI_ActionTestStop();
    }
}

if (!class_exists('Typecho_Widget')) {
    // Mirrors Typecho 1.2: a plain widget has no user or security property,
    // so unknown names read as null. Only Widget\Base initialises them.
    class Typecho_Widget
    {
        public static $widgets = array();

        public function __get($name)
        {
            return null;
        }

        public static function widget($name)
        {
            return self::$widgets[$name];
        }
    }
}

mb_test('action checks the administrator and the request token before doing anything', function () {
    // Loaded here, after PluginContractTest has declared the Typecho plugin stubs.
    require_once dirname(__DIR__) . '/Action.php';
    $user = new MomoBirdAI_ActionTestUser();
    $security = new MomoBirdAI_ActionTestSecurity();
    Typecho_Widget::$widgets = array('Widget_User' => $user, 'Widget_Security' => $security);
    $action = new MomoBirdAI_Action();

    mb_assert_throws(function () use ($action) {
        $action->action();
    }, 'MomoBirdAI_ActionTestStop');

    mb_assert_same(array('administrator'), $user->passed, 'administrator check');
    mb_assert_same(1, $security->protected, 'token check');
});
