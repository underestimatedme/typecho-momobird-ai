<?php

error_reporting(E_ALL);

$GLOBALS['mb_tests'] = array();

function mb_test($name, $callback)
{
    $GLOBALS['mb_tests'][] = array($name, $callback);
}

function mb_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mb_assert_same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

function mb_assert_throws($callback, $className)
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error instanceof $className) {
            return $error;
        }
        throw new RuntimeException('Expected ' . $className . ', got ' . get_class($error) . ': ' . $error->getMessage());
    }

    throw new RuntimeException('Expected ' . $className . ' to be thrown');
}

function mb_post($body, $cid = 1)
{
    return array(
        'cid' => $cid,
        'title' => 'Post ' . $cid,
        'text' => $body,
        'permalink' => 'https://example.com/' . $cid,
        'modified' => 1720000000,
        'tags' => array('docs'),
        'categories' => array('help')
    );
}

$bootstrap = dirname(__DIR__) . '/lib/Bootstrap.php';
if (is_file($bootstrap)) {
    require_once $bootstrap;
}
