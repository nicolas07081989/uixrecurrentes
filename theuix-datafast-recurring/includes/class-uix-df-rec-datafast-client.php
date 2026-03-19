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
            return rtrim($this->settings['recurring_base_url'] ?? 'https://test.oppwa.com', '/');
        }

        return rtrim($this->settings['initial_base_url'] ?? 'https://eu-test.oppwa.com', '/');
    }

    private function token($mode)
    {
        $token = $mode === 'recurring'
            ? trim((string) ($this->settings['recurring_bearer_token'] ?? ''))
            : trim((string) ($this->settings['initial_bearer_token'] ?? ''));

        return preg_replace('/^Bearer\s+/i', '', $token);
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

    private function request($method, $url, array $payload, $mode)
    {
        $token = $this->token($mode);
        if ($token === '') {
            UIX_DF_Rec_Logger::info('Datafast auth error', ['mode' => $mode, 'url' => $url, 'reason' => 'empty_token']);
            return ['ok' => false, 'error' => 'Missing token', 'status' => 0, 'body' => null];
        }

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ];

        $finalUrl = $url;
        if ($method === 'GET') {
            $finalUrl = add_query_arg($payload, $url);
        } else {
            $args['body'] = http_build_query(array_filter($payload, static function ($value) {
                return $value !== '' && $value !== null;
            }));
        }

        $response = wp_remote_request($finalUrl, $args);
        if (is_wp_error($response)) {
            UIX_DF_Rec_Logger::info('Datafast transport error', [
                'mode' => $mode,
                'method' => $method,
                'url' => $finalUrl,
                'error' => $response->get_error_message(),
            ]);
            return ['ok' => false, 'error' => $response->get_error_message(), 'status' => 0, 'body' => null];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        $body = json_decode($rawBody, true);
        $parsedBody = is_array($body) ? $body : [];
        $ok = $status >= 200 && $status < 300;

        if (!$ok) {
            UIX_DF_Rec_Logger::info('Datafast auth/payload error', [
                'mode' => $mode,
                'method' => $method,
                'url' => $finalUrl,
                'status' => $status,
                'result_code' => $parsedBody['result']['code'] ?? null,
                'result_description' => $parsedBody['result']['description'] ?? null,
            ]);
        }

        return [
            'ok' => $ok,
            'status' => $status,
            'body' => $parsedBody,
            'error' => null,
        ];
    }
}
