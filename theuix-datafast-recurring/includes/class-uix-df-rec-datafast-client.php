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
            return trim((string) ($this->settings['recurring_bearer_token'] ?? ''));
        }

        return trim((string) ($this->settings['initial_bearer_token'] ?? ''));
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

        $token = preg_replace('/^Bearer\s+/i', '', $token);
        return 'Bearer ' . trim((string) $token);
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

        UIX_DF_Rec_Logger::info('Datafast request', ['method' => $method, 'url' => $url, 'mode' => $mode, 'payload' => $payload]);

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            UIX_DF_Rec_Logger::error('Datafast wp_remote_request error', ['mode' => $mode, 'error' => $response->get_error_message()]);
            return ['ok' => false, 'error' => $response->get_error_message(), 'status' => 0, 'body' => null];
        }

        $rawBody = wp_remote_retrieve_body($response);
        $body = json_decode($rawBody, true);
        UIX_DF_Rec_Logger::info('Datafast response', ['mode' => $mode, 'status' => (int) wp_remote_retrieve_response_code($response), 'body' => is_array($body) ? $body : $rawBody]);
        return [
            'ok' => true,
            'status' => (int) wp_remote_retrieve_response_code($response),
            'body' => is_array($body) ? $body : [],
        ];
    }
}
