<?php

defined('ABSPATH') || exit;

// Text Domain: bayarcash-for-easy-digital-downloads

class Bayarcash_Transaction_Requery {
	private static $instance = null;

	public static function getInstance(): self {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Initialize any necessary hooks or actions here
	}

	public function requeryTransaction($transactionId): ?array {
		$apiUrl = $this->getApiUrl() . $transactionId;
		$token = $this->getToken();

		$args = [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Accept' => 'application/json',
			],
		];

		$response = wp_remote_get($apiUrl, $args);

		if (is_wp_error($response)) {
			$this->log('Error connecting to Bayarcash API: ' . $response->get_error_message(), 'error');
			return null;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (!$data || !isset($data['status'])) {
			$this->log('Invalid response from Bayarcash API.', 'error');
			return null;
		}

		return $data;
	}

	private function getApiUrl(): string {
		return edd_is_test_mode()
			? 'https://console.bayarcash-sandbox.com/api/v2/transactions/'
			: 'https://console.bayar.cash/api/v2/transactions/';
	}

	private function getToken(): string {
		return edd_is_test_mode()
			? BAYARCASH_EDD_SANDBOX_TOKEN
			: edd_get_option('bayarcash_token');
	}

	private function log($message, $type = 'info'): void {
		if (edd_get_option('bayarcash_debug') === '1' && function_exists('edd_debug_log')) {
			edd_debug_log('[Bayarcash Requery] ' . ucfirst($type) . ': ' . $message);
		}
	}
}

// Initialize the Requery class
Bayarcash_Transaction_Requery::getInstance();