<?php

class MomoBirdAI_HttpException extends RuntimeException
{
    private $status;
    private $apiCode;

    public function __construct($message, $status = 0, $apiCode = 'upstream_error')
    {
        parent::__construct($message);
        $this->status = (int) $status;
        $this->apiCode = (string) $apiCode;
    }

    public function status()
    {
        return $this->status;
    }

    public function apiCode()
    {
        return $this->apiCode;
    }
}

class MomoBirdAI_TransportException extends MomoBirdAI_HttpException
{
}

final class MomoBirdAI_HttpClient
{
    const MAX_RESPONSE_BYTES = 1048576;

    private $config;
    private $transport;

    public function __construct(MomoBirdAI_Config $config, $transport = null)
    {
        if ($transport !== null && !is_callable($transport)) {
            throw new InvalidArgumentException('HTTP transport must be callable');
        }
        $this->config = $config;
        $this->transport = $transport;
    }

    public function import(array $entries)
    {
        if (count($entries) < 1 || count($entries) > 100) {
            throw new InvalidArgumentException('Import batch must contain 1 to 100 entries');
        }
        $result = $this->request(
            'POST',
            '/collections/' . rawurlencode($this->config->collection()) . '/entries/batch',
            array('entries' => $entries),
            true
        );
        foreach (array('created', 'updated', 'unchanged', 'results') as $field) {
            if (!array_key_exists($field, $result)) {
                throw new MomoBirdAI_HttpException('MomoBird returned an incomplete import response');
            }
        }
        foreach (array('created', 'updated', 'unchanged') as $field) {
            if (!is_int($result[$field]) || $result[$field] < 0) {
                throw new MomoBirdAI_HttpException('MomoBird returned invalid import counters');
            }
        }
        if (!is_array($result['results'])) {
            throw new MomoBirdAI_HttpException('MomoBird returned invalid import results');
        }
        foreach ($result['results'] as $item) {
            if (!is_array($item)
                || empty($item['external_id'])
                || !self::isUuid(isset($item['entry_id']) ? $item['entry_id'] : '')
                || !in_array(isset($item['status']) ? $item['status'] : '', array('created', 'updated', 'unchanged'), true)
                || !isset($item['version'])) {
                throw new MomoBirdAI_HttpException('MomoBird returned an invalid import item');
            }
        }
        return $result;
    }

    public function listEntries($cursor = null, $tag = null, $status = 'active')
    {
        $query = array('limit' => 200, 'status' => (string) $status);
        if ($cursor !== null && $cursor !== '') {
            if (!self::isUuid($cursor)) {
                throw new InvalidArgumentException('Entry cursor must be a UUID');
            }
            $query['cursor'] = $cursor;
        }
        if ($tag !== null && trim((string) $tag) !== '') {
            $query['tag'] = trim((string) $tag);
        }
        $result = $this->request(
            'GET',
            '/collections/' . rawurlencode($this->config->collection()) . '/entries?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            null,
            false
        );
        if (!isset($result['items']) || !is_array($result['items'])) {
            throw new MomoBirdAI_HttpException('MomoBird returned an invalid entry list');
        }
        return $result;
    }

    public function deleteEntry($uuid)
    {
        if (!self::isUuid($uuid)) {
            throw new InvalidArgumentException('Entry ID must be a UUID');
        }
        $this->request(
            'DELETE',
            '/collections/' . rawurlencode($this->config->collection()) . '/entries/' . rawurlencode($uuid),
            null,
            true,
            array(204)
        );
    }

    public function testConnection()
    {
        return $this->request(
            'GET',
            '/collections/' . rawurlencode($this->config->collection()),
            null,
            false
        );
    }

    private function request($method, $path, $body, $retryable, array $successStatuses = array(200))
    {
        $url = $this->config->baseUrl() . '/momobird/api/v1' . $path;
        $headers = array(
            'Accept: application/json',
            'Authorization: Bearer ' . $this->config->apiKey()
        );
        $encodedBody = null;
        if ($body !== null) {
            $encodedBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encodedBody === false) {
                throw new InvalidArgumentException('Request body is not valid JSON data');
            }
            $headers[] = 'Content-Type: application/json';
        }

        $attempts = $retryable ? 2 : 1;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->send($method, $url, $headers, $encodedBody);
            } catch (MomoBirdAI_TransportException $error) {
                if ($attempt < $attempts) {
                    usleep(50000);
                    continue;
                }
                throw $error;
            }

            $status = isset($response['status']) ? (int) $response['status'] : 0;
            $raw = isset($response['body']) ? (string) $response['body'] : '';
            if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
                throw new MomoBirdAI_HttpException('MomoBird response exceeded the safety limit', $status, 'response_too_large');
            }
            if (in_array($status, $successStatuses, true)) {
                if ($status === 204) {
                    return array();
                }
                return $this->decodeJson($raw, $status);
            }
            if ($attempt < $attempts && ($status === 502 || $status === 504)) {
                usleep(50000);
                continue;
            }
            throw $this->apiError($status, $raw);
        }

        throw new MomoBirdAI_HttpException('MomoBird request failed');
    }

    private function send($method, $url, array $headers, $body)
    {
        if ($this->transport !== null) {
            $response = call_user_func($this->transport, $method, $url, $headers, $body, $this->config->timeout());
            if (!is_array($response)) {
                throw new MomoBirdAI_TransportException('MomoBird transport returned an invalid response');
            }
            return $response;
        }
        return $this->curlTransport($method, $url, $headers, $body);
    }

    private function curlTransport($method, $url, array $headers, $body)
    {
        if (!function_exists('curl_init')) {
            throw new MomoBirdAI_TransportException('PHP cURL extension is required');
        }
        $handle = curl_init($url);
        $responseBody = '';
        $tooLarge = false;
        curl_setopt_array($handle, array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->config->timeout()),
            CURLOPT_TIMEOUT => $this->config->timeout(),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($curl, $data) use (&$responseBody, &$tooLarge) {
                if (strlen($responseBody) + strlen($data) > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $data;
                return strlen($data);
            }
        ));
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $errorNumber = curl_errno($handle);
        curl_close($handle);

        if ($tooLarge) {
            throw new MomoBirdAI_HttpException('MomoBird response exceeded the safety limit', $status, 'response_too_large');
        }
        if ($ok === false) {
            throw new MomoBirdAI_TransportException('MomoBird network request failed', 0, 'transport_error_' . $errorNumber);
        }
        return array('status' => $status, 'body' => $responseBody);
    }

    private function decodeJson($raw, $status)
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new MomoBirdAI_HttpException('MomoBird returned malformed JSON', $status, 'malformed_response');
        }
        return $decoded;
    }

    private function apiError($status, $raw)
    {
        $decoded = json_decode($raw, true);
        $code = 'http_' . $status;
        $message = 'MomoBird request failed with HTTP ' . $status;
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            if (!empty($decoded['error']['code'])) {
                $code = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) $decoded['error']['code']);
            }
            if (!empty($decoded['error']['message'])) {
                $message = mb_substr(strip_tags((string) $decoded['error']['message']), 0, 200, 'UTF-8');
            }
        }
        $message = str_replace($this->config->apiKey(), '[redacted]', $message);
        return new MomoBirdAI_HttpException($message, $status, $code);
    }

    private static function isUuid($value)
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
