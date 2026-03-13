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
        if ($mode === 'recurring') {
            return rtrim($this->settings['recurring_base_url'] ?? 'https://eu-test.oppwa.com', '/');
        }

        return rtrim($this->settings['initial_base_url'] ?? 'https://eu-test.oppwa.com', '/');
    }

    private function token($mode)
    {
        if ($mode === 'recurring') {
            return $this->sanitize_token((string) ($this->settings['recurring_bearer_token'] ?? ''));
        }

        return $this->sanitize_token((string) ($this->settings['initial_bearer_token'] ?? ''));
    }

    private function sanitize_token($token)
    {
        $token = preg_replace('/\s+/', '', (string) $token);
        $token = preg_replace('/^Bearer/i', '', (string) $token);

        return trim((string) $token);
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

    public function recurring_payment($registrationId, array $payload)
    {
        $url = $this->base_url('recurring') . '/v1/registrations/' . rawurlencode($registrationId) . '/payments';
        return $this->request('POST', $url, $payload, 'recurring');
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
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $rawBody = (string) wp_remote_retrieve_body($response);
        $parsedBody = json_decode($rawBody, true);

        UIX_DF_Rec_Logger::info('Datafast response', [
            'mode' => $mode,
            'status' => $status,
            'parsed_body' => is_array($parsedBody) ? $parsedBody : null,
            'raw_body' => $rawBody,
        ]);

        return [
            'ok' => true,
            'status' => $status,
            'body' => is_array($parsedBody) ? $parsedBody : [],
            'parsed_body' => is_array($parsedBody) ? $parsedBody : [],
            'raw_body' => $rawBody,
        ];
    }
}
