<?php

final class MomoBirdAI_Config
{
    const KEY_PATTERN = '/^mbk_[A-Za-z0-9_-]{43}$/';

    private $baseUrl;
    private $connectionKey;
    private $timeout;
    private $autoSync;
    private $widgetEnabled;

    private function __construct($baseUrl, $connectionKey, $timeout, $autoSync, $widgetEnabled)
    {
        $this->baseUrl = $baseUrl;
        $this->connectionKey = $connectionKey;
        $this->timeout = $timeout;
        $this->autoSync = $autoSync;
        $this->widgetEnabled = $widgetEnabled;
    }

    public static function fromArray(array $values)
    {
        $baseUrl = isset($values['base_url']) ? trim((string) $values['base_url']) : '';
        $connectionKey = isset($values['connection_key']) ? trim((string) $values['connection_key']) : '';
        $timeout = isset($values['timeout']) ? (int) $values['timeout'] : 10;
        $autoSync = !isset($values['enabled']) || (string) $values['enabled'] !== '0';
        $widgetEnabled = isset($values['widget_enabled']) && (string) $values['widget_enabled'] === '1';

        self::validateBaseUrl($baseUrl);
        if (!preg_match(self::KEY_PATTERN, $connectionKey)) {
            throw new InvalidArgumentException('Connection key is invalid; copy it again from the MomoBird console');
        }
        if ($timeout < 2 || $timeout > 30) {
            throw new InvalidArgumentException('Timeout must be between 2 and 30 seconds');
        }

        return new self(rtrim($baseUrl, '/'), $connectionKey, $timeout, $autoSync, $widgetEnabled);
    }

    public static function mergeForSave(array $stored, array $submitted)
    {
        if (!isset($submitted['connection_key']) || trim((string) $submitted['connection_key']) === '') {
            $submitted['connection_key'] = isset($stored['connection_key']) ? $stored['connection_key'] : '';
        }
        return self::fromArray($submitted);
    }

    private static function validateBaseUrl($baseUrl)
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('Base URL is invalid');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Base URL must not contain credentials, query, or fragment');
        }
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            throw new InvalidArgumentException('Base URL must not contain a path');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $localHosts = array('localhost', '127.0.0.1', '::1');
        if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, $localHosts, true))) {
            throw new InvalidArgumentException('Base URL must use HTTPS');
        }
    }

    public function baseUrl()
    {
        return $this->baseUrl;
    }

    public function connectionKey()
    {
        return $this->connectionKey;
    }

    public function timeout()
    {
        return $this->timeout;
    }

    public function autoSync()
    {
        return $this->autoSync;
    }

    public function widgetEnabled()
    {
        return $this->widgetEnabled;
    }

    public function toArray($includeSecret = false)
    {
        return array(
            'base_url' => $this->baseUrl,
            'connection_key' => $includeSecret ? $this->connectionKey : '',
            'timeout' => $this->timeout,
            'enabled' => $this->autoSync ? '1' : '0',
            'widget_enabled' => $this->widgetEnabled ? '1' : '0'
        );
    }
}
