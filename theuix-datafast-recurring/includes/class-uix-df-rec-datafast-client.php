<?php

if (!defined('ABSPATH')) {
    exit;
}

class UIX_DF_Rec_Datafast_Client
{
    private $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    private function base_url($mode)
    {
        return rtrim($this->settings['initial_base_url'] ?? 'https://eu-test.oppwa.com', '/');
    }

    private function token($mode)
    {
        return $this->sanitize_token((string) ($this->settings['initial_bearer_token'] ?? ''));
    }

    private function sanitize_token($token)
    {
        $token = (string) $token;
        $token = preg_replace('/\x{FEFF}|\x{200B}|\x{200C}|\x{200D}|\x{00A0}/u', '', $token);
        $token = str_replace(["\r", "\n", "\t"], '', $token);
        $token = preg_replace('/^Bearer\s+/i', '', $token);
        $token = trim($token);

        return $token;
    }

    private function token_tail_masked($mode)
    {
        $token = $this->token($mode);
        if ($token === '') {
            return '';
        }

        $tail = substr($token, -12);
        if ($tail === false) {
            $tail = '';
        }
        $len = strlen($tail);

        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4) . substr($tail, -4);
    }

    private function token_signature($mode)
    {
        $token = $this->token($mode);
        if ($token === '') {
            return ['length' => 0, 'hash8' => ''];
        }

        return [
            'length' => strlen($token),
            'hash8' => substr(hash('sha256', $token), 0, 8),
        ];
    }

    public function create_checkout(array $payload)
    {
        return $this->request('POST', $this->base_url('initial') . '/v1/checkouts', $payload, 'initial');
    }

    public function verify_payment($resourcePath, $entityId)
    {
        $url = $this->base_url('initial') . $resourcePath;
        return $this->request('GET', $url, ['entityId' => $entityId], 'initial');
    }

    public function verify_payment_with_base_url($baseUrl, $resourcePath, $entityId)
    {
        $url = rtrim((string) $baseUrl, '/') . $resourcePath;
        return $this->request('GET', $url, ['entityId' => $entityId], 'initial');
    }

    private function normalized_auth_header($mode)
    {
        $token = $this->token($mode);
        if ($token === '') {
            return '';
        }

        return 'Bearer ' . $token;
    }

    private function request($method, $url, array $payload, $mode)
    {
        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => $this->normalized_auth_header($mode),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ];

        if ($method === 'GET') {
            $url = add_query_arg($payload, $url);
        } else {
            $args['body'] = http_build_query($payload);
        }

        $tokenSignature = $this->token_signature($mode);
        UIX_DF_Rec_Logger::info('Datafast request', [
            'method' => $method,
            'url' => $url,
            'base_url' => $this->base_url($mode),
            'mode' => $mode,
            'entity_id' => isset($payload['entityId']) ? trim((string) $payload['entityId']) : null,
            'token_length' => $tokenSignature['length'],
            'token_hash8' => $tokenSignature['hash8'],
            'token_tail_masked' => $this->token_tail_masked($mode),
            'token_sent_length' => strlen((string) $this->normalized_auth_header($mode)),
            'payload' => $payload,
        ]);

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            UIX_DF_Rec_Logger::error('Datafast wp_remote_request error', [
                'mode' => $mode,
                'error' => $response->get_error_message(),
            ]);

            return [
                'ok' => false,
                'error' => $response->get_error_message(),
                'status' => 0,
                'body' => null,
                'parsed_body' => null,
                'raw_body' => null,
                'headers' => [],
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $rawBody = (string) wp_remote_retrieve_body($response);
        $parsedBody = json_decode($rawBody, true);
        $headers = wp_remote_retrieve_headers($response);
        $headersArray = is_array($headers) ? $headers : (method_exists($headers, 'getAll') ? $headers->getAll() : []);

        UIX_DF_Rec_Logger::info('Datafast response', [
            'mode' => $mode,
            'status' => $status,
            'parsed_body' => is_array($parsedBody) ? $parsedBody : null,
            'raw_body' => $rawBody,
        ]);

        $isJson = is_array($parsedBody);
        $isHttpOk = $status >= 200 && $status < 300;

        return [
            'ok' => $isHttpOk && $isJson,
            'status' => $status,
            'body' => $isJson ? $parsedBody : [],
            'parsed_body' => $isJson ? $parsedBody : [],
            'raw_body' => $rawBody,
            'headers' => $headersArray,
            'final_url' => $url,
            'host_used' => (string) parse_url($url, PHP_URL_HOST),
        ];
    }
}
