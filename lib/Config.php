<?php

final class MomoBirdAI_Config
{
    private $baseUrl;
    private $collection;
    private $apiKey;
    private $timeout;

    private function __construct($baseUrl, $collection, $apiKey, $timeout)
    {
        $this->baseUrl = $baseUrl;
        $this->collection = $collection;
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
    }

    public static function fromArray(array $values)
    {
        $baseUrl = isset($values['base_url']) ? trim((string) $values['base_url']) : '';
        $collection = isset($values['collection']) ? trim((string) $values['collection']) : '';
        $apiKey = isset($values['api_key']) ? trim((string) $values['api_key']) : '';
        $timeout = isset($values['timeout']) ? (int) $values['timeout'] : 10;

        self::validateBaseUrl($baseUrl);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $collection)) {
            throw new InvalidArgumentException('Collection slug is invalid');
        }
        if ($apiKey === '') {
            throw new InvalidArgumentException('API key is required');
        }
        if ($timeout < 2 || $timeout > 30) {
            throw new InvalidArgumentException('Timeout must be between 2 and 30 seconds');
        }

        return new self(rtrim($baseUrl, '/'), $collection, $apiKey, $timeout);
    }

    public static function mergeForSave(array $stored, array $submitted)
    {
        if (!isset($submitted['api_key']) || trim((string) $submitted['api_key']) === '') {
            $submitted['api_key'] = isset($stored['api_key']) ? $stored['api_key'] : '';
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

    public function collection()
    {
        return $this->collection;
    }

    public function apiKey()
    {
        return $this->apiKey;
    }

    public function timeout()
    {
        return $this->timeout;
    }

    public function toArray($includeSecret = false)
    {
        $values = array(
            'base_url' => $this->baseUrl,
            'collection' => $this->collection,
            'timeout' => $this->timeout
        );
        if ($includeSecret) {
            $values['api_key'] = $this->apiKey;
        }
        return $values;
    }
}
