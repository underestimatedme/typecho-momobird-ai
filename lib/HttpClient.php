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

class MomoBirdAI_PlanRequiredException extends MomoBirdAI_HttpException
{
}

final class MomoBirdAI_HttpClient
{
    const MAX_RESPONSE_BYTES = 1048576;
    const API_PREFIX = '/momobird/api/public/connect/v1';
    const MAX_UPSERT_POSTS = 20;
    const MAX_DELETE_IDS = 100;
    const LIST_LIMIT = 500;

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

    public function status()
    {
        return $this->request('GET', '/status', null);
    }

    public function upsertPosts(array $posts)
    {
        if (count($posts) < 1 || count($posts) > self::MAX_UPSERT_POSTS) {
            throw new InvalidArgumentException('Upsert batch must contain 1 to 20 posts');
        }
        $data = $this->request('PUT', '/posts', array('posts' => array_values($posts)));
        return self::results($data, array('created', 'updated', 'unchanged', 'rejected'));
    }

    public function deletePosts(array $externalIds)
    {
        if (count($externalIds) < 1 || count($externalIds) > self::MAX_DELETE_IDS) {
            throw new InvalidArgumentException('Delete batch must contain 1 to 100 ids');
        }
        $ids = array();
        foreach ($externalIds as $id) {
            $ids[] = (string) $id;
        }
        $data = $this->request('POST', '/posts/delete', array('external_ids' => $ids));
        return self::results($data, array('deleted', 'not_found'));
    }

    public function listPosts($cursor = null, $limit = self::LIST_LIMIT)
    {
        $query = array('limit' => (int) $limit);
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = (string) $cursor;
        }
        $data = $this->request('GET', '/posts?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), null);
        if (!isset($data['items']) || !is_array($data['items'])) {
            throw new MomoBirdAI_HttpException('MomoBird returned an invalid post list');
        }
        foreach ($data['items'] as $item) {
            if (!is_array($item) || !isset($item['external_id']) || !is_string($item['external_id'])) {
                throw new MomoBirdAI_HttpException('MomoBird returned an invalid post list item');
            }
        }
        return array(
            'items' => $data['items'],
            'next_cursor' => isset($data['next_cursor']) && is_string($data['next_cursor']) && $data['next_cursor'] !== '' ? $data['next_cursor'] : null
        );
    }

    public function enableWidget()
    {
        return $this->request('POST', '/widget/enable', new stdClass());
    }

    private static function results($data, array $statuses)
    {
        if (!isset($data['results']) || !is_array($data['results'])) {
            throw new MomoBirdAI_HttpException('MomoBird returned invalid results');
        }
        foreach ($data['results'] as $item) {
            if (!is_array($item)
                || !isset($item['external_id']) || !is_string($item['external_id'])
                || !in_array(isset($item['status']) ? $item['status'] : '', $statuses, true)) {
                throw new MomoBirdAI_HttpException('MomoBird returned an invalid result item');
            }
        }
        return $data['results'];
    }

    private function request($method, $path, $body)
    {
        $url = $this->config->baseUrl() . self::API_PREFIX . $path;
        $headers = array(
            'Accept: application/json',
            'Authorization: Bearer ' . $this->config->connectionKey()
        );
        $encodedBody = null;
        if ($body !== null) {
            $encodedBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encodedBody === false) {
                throw new InvalidArgumentException('Request body is not valid JSON data');
            }
            $headers[] = 'Content-Type: application/json';
        }

        // Every connect endpoint is idempotent, so one retry on a transient failure is safe.
        $attempts = 2;
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
            if ($status === 200) {
                return $this->unwrap($raw, $status);
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

    private function unwrap($raw, $status)
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new MomoBirdAI_HttpException('MomoBird returned malformed JSON', $status, 'malformed_response');
        }
        if (!isset($decoded['code']) || $decoded['code'] !== 'OK' || !array_key_exists('data', $decoded) || !is_array($decoded['data'])) {
            throw new MomoBirdAI_HttpException('MomoBird returned an unexpected response envelope', $status, 'malformed_response');
        }
        return $decoded['data'];
    }

    private function apiError($status, $raw)
    {
        $decoded = json_decode($raw, true);
        $code = 'http_' . $status;
        $message = 'MomoBird request failed with HTTP ' . $status;
        if (is_array($decoded)) {
            $source = isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : $decoded;
            if (!empty($source['code']) && is_string($source['code'])) {
                $code = preg_replace('/[^a-zA-Z0-9_.-]/', '', $source['code']);
            }
            if (!empty($source['message']) && is_string($source['message'])) {
                $message = mb_substr(strip_tags($source['message']), 0, 200, 'UTF-8');
            }
        }
        $message = str_replace($this->config->connectionKey(), '[redacted]', $message);
        if ($status === 402 && $code === 'PLAN_REQUIRED') {
            return new MomoBirdAI_PlanRequiredException($message, $status, $code);
        }
        return new MomoBirdAI_HttpException($message, $status, $code);
    }
}
