<?php

if (!defined('ABSPATH')) {
    exit;
}

class UIX_DF_Rec_Subscription_Repo
{
    private $table;
    private $attempts;
    private $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'uix_subscriptions';
        $this->attempts = $wpdb->prefix . 'uix_charge_attempts';
    }

    public function create_pending(array $data)
    {
        $now = current_time('mysql');
        $this->wpdb->insert($this->table, [
            'uuid' => wp_generate_uuid4(),
            'customer_wp_user_id' => get_current_user_id() ?: null,
            'full_name' => $data['full_name'],
            'email' => $data['email'],
            'cedula_ruc' => $data['cedula_ruc'],
            'plan_slug' => $data['plan_slug'],
            'plan_title' => $data['plan_title'],
            'amount' => $data['amount'],
            'currency' => 'USD',
            'status' => 'pending',
            'max_retries' => (int) ($data['max_retries'] ?? 3),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->wpdb->insert_id;
    }

    public function find($id)
    {
        return $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d", $id), ARRAY_A);
    }

    public function update_checkout($id, $checkoutId, $baseUrl = '')
    {
        $data = [
            'checkout_id' => $checkoutId,
            'updated_at' => current_time('mysql'),
        ];

        if (trim((string) $baseUrl) !== '') {
            $data['checkout_resource_path'] = trim((string) $baseUrl);
        }

        $this->wpdb->update($this->table, $data, ['id' => $id]);
    }

    public function mark_from_result($id, array $body)
    {
        $code = $body['result']['code'] ?? null;
        $isSuccess = UIX_DF_Rec_Result_Codes::is_success($code);
        $next = gmdate('Y-m-d H:i:s', strtotime('+1 month'));

        $data = [
            'last_transaction_id' => $body['id'] ?? null,
            'last_result_code' => $code,
            'last_result_description' => $body['result']['description'] ?? null,
            'payment_brand' => $body['paymentBrand'] ?? null,
            'registration_id' => $body['registrationId'] ?? null,
            'updated_at' => current_time('mysql'),
        ];

        if ($isSuccess) {
            $data['status'] = 'active';
            if (!empty($body['registrationId'])) {
                $data['next_charge_at'] = $next;
            }
            $data['retry_count'] = 0;
        } else {
            $data['status'] = 'payment_failed';
        }

        $this->wpdb->update($this->table, $data, ['id' => $id]);
    }

    public function due_for_recurring($limit = 25)
    {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status IN ('active','past_due') AND registration_id IS NOT NULL AND next_charge_at IS NOT NULL AND next_charge_at <= %s LIMIT %d",
                current_time('mysql'),
                $limit
            ),
            ARRAY_A
        );
    }

    public function apply_recurring_result(array $subscription, array $body)
    {
        $code = $body['result']['code'] ?? null;
        $isSuccess = UIX_DF_Rec_Result_Codes::is_success($code);
        $isHard = UIX_DF_Rec_Result_Codes::is_hard_decline($code);

        $retryCount = (int) $subscription['retry_count'];
        $maxRetries = (int) $subscription['max_retries'];

        $data = [
            'last_transaction_id' => $body['id'] ?? null,
            'last_result_code' => $code,
            'last_result_description' => $body['result']['description'] ?? null,
            'last_charge_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        if ($isSuccess) {
            $data['status'] = 'active';
            $data['retry_count'] = 0;
            $data['next_charge_at'] = gmdate('Y-m-d H:i:s', strtotime('+1 month'));
        } else {
            $retryCount++;
            $data['retry_count'] = $retryCount;
            if ($isHard || $retryCount >= $maxRetries) {
                $data['status'] = 'suspended';
                $data['suspended_at'] = current_time('mysql');
            } else {
                $data['status'] = 'past_due';
                $data['next_charge_at'] = gmdate('Y-m-d H:i:s', strtotime('+1 day'));
            }
        }

        $this->wpdb->update($this->table, $data, ['id' => (int) $subscription['id']]);
    }

    public function add_attempt(array $attempt)
    {
        $this->wpdb->insert($this->attempts, [
            'subscription_id' => $attempt['subscription_id'],
            'idempotency_key' => $attempt['idempotency_key'],
            'kind' => $attempt['kind'],
            'requested_amount' => $attempt['requested_amount'],
            'currency' => 'USD',
            'request_payload_redacted' => wp_json_encode($attempt['request_payload_redacted']),
            'response_payload_redacted' => wp_json_encode($attempt['response_payload_redacted']),
            'http_status' => $attempt['http_status'],
            'result_code' => $attempt['result_code'],
            'result_description' => $attempt['result_description'],
            'transaction_id' => $attempt['transaction_id'],
            'decision' => $attempt['decision'],
            'created_at' => current_time('mysql'),
        ]);
    }

    public function all($limit = 100)
    {
        return $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
    }
}
