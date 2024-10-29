<?php

defined('ABSPATH') || exit;

class Bayarcash_EDD_Cron_Requery {
	private static $instance = null;
	private const CRON_HOOK = 'bayarcash_edd_requery_payments';

	// Bayarcash status constants
	private const STATUS_NEW = 0;
	private const STATUS_PENDING = 1;
	private const STATUS_FAILED = 2;
	private const STATUS_SUCCESS = 3;
	private const STATUS_CANCELLED = 4;

	public static function getInstance(): self {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	private function init_hooks(): void {
		add_action(self::CRON_HOOK, [$this, 'requery_payments']);

		// Add custom cron schedule
		add_filter('cron_schedules', [$this, 'add_cron_interval']);

		// Schedule the cron job if it's not already scheduled
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time(), 'every_minute', self::CRON_HOOK);
		}
	}

	public function add_cron_interval($schedules): array {
		$schedules['every_minute'] = array(
			'interval' => 60,
			'display'  => esc_html__('Every Minute', 'bayarcash-for-easy-digital-downloads'),
		);
		return $schedules;
	}

	public function requery_payments(): void {
		$payments = $this->get_payments_to_requery();

		if (empty($payments)) {
			$this->log('No payments found for requery.');
			return;
		}

		$requery_class = Bayarcash_Transaction_Requery::getInstance();

		foreach ($payments as $payment) {
			$transaction_id = edd_get_payment_meta($payment->ID, 'bayarcash_transaction_id', true);

			if (empty($transaction_id)) {
				continue;
			}

			$this->log("Requerying payment ID: {$payment->ID}, Transaction ID: {$transaction_id}");

			$transaction_data = $requery_class->requeryTransaction($transaction_id);

			if (!$transaction_data) {
				$this->log("Failed to retrieve transaction data for Payment ID: {$payment->ID}", 'error');
				continue;
			}

			$this->process_requery_result($payment->ID, $transaction_data);
		}
	}

	private function get_payments_to_requery(): array {
		$args = [
			'number'     => -1,
			'status'     => ['pending', 'failed'],
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => 'bayarcash_transaction_id',
					'compare' => 'EXISTS',
				],
			],
		];

		return edd_get_payments($args);
	}

	private function process_requery_result($payment_id, $transaction_data): void {
		$status = $transaction_data['status'];
		$current_status = edd_get_payment_status($payment_id);

		switch ($status) {
			case self::STATUS_SUCCESS:
				$this->update_payment($payment_id, 'complete', $transaction_data, $current_status);
				break;
			case self::STATUS_PENDING:
				$this->update_payment($payment_id, 'pending', $transaction_data, $current_status);
				break;
			case self::STATUS_FAILED:
			case self::STATUS_CANCELLED:
				$this->update_payment($payment_id, 'failed', $transaction_data, $current_status);
				break;
			case self::STATUS_NEW:
				$this->log("Payment is new. No action taken. Payment ID: {$payment_id}");
				break;
			default:
				$this->log("Unknown status received. Payment ID: {$payment_id}, Status: {$status}", 'error');
				break;
		}
	}

	private function update_payment($payment_id, $new_status, $transaction_data, $current_status): void {
		if ($this->has_status_changed($current_status, $new_status)) {
			edd_update_payment_status($payment_id, $new_status);
			$note = $this->get_status_update_note($new_status, $transaction_data);
			edd_insert_payment_note($payment_id, $note);
			$this->log("Payment updated. Payment ID: {$payment_id}, New Status: {$new_status}");
		} else {
			$this->log("No status change. Payment ID: {$payment_id}, Status: {$new_status}");
		}
	}

	private function has_status_changed($current_status, $new_status): bool {
		return $current_status !== $new_status;
	}

	private function get_status_update_note($status, $transaction_data): string {
		$status_messages = [
			'complete' => __('Payment completed via Bayarcash requery.', 'bayarcash-for-easy-digital-downloads'),
			'pending' => __('Payment still pending via Bayarcash requery.', 'bayarcash-for-easy-digital-downloads'),
			'failed' => __('Payment failed via Bayarcash requery.', 'bayarcash-for-easy-digital-downloads'),
		];

		$message = $status_messages[$status] ?? __('Payment status updated via Bayarcash requery.', 'bayarcash-for-easy-digital-downloads');

		return sprintf(
			"%s Details:\n%s",
			$message,
			$this->prepareTransactionNoteDetails($transaction_data)
		);
	}

	private function prepareTransactionNoteDetails($transactionData): string {
		$fields = [
			'status', 'status_description', 'id', 'exchange_reference_number', 'exchange_transaction_id',
			'currency', 'amount', 'payer_name', 'payer_email', 'payer_bank_name', 'datetime', 'updated_at'
		];
		$details = [];
		foreach ($fields as $field) {
			$value = $transactionData[$field] ?? 'N/A';
			$details[] = ucfirst(str_replace('_', ' ', $field)) . ': ' . $value;
		}
		// Add payment gateway information
		$details[] = 'Payment Gateway: ' . ($transactionData['payment_gateway']['name'] ?? 'N/A');
		return implode("\n", $details);
	}

	private function log($message, $type = 'info'): void {
		if (edd_get_option('bayarcash_debug') === '1' && function_exists('edd_debug_log')) {
			edd_debug_log('[Bayarcash Cron Requery] ' . ucfirst($type) . ': ' . $message);
		}
	}
}

// Initialize the Cron Requery class
Bayarcash_EDD_Cron_Requery::getInstance();