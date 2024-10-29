<?php

use Webimpian\BayarcashSdk\Bayarcash;
use Webimpian\BayarcashSdk\Exceptions\ValidationException;

defined('ABSPATH') || exit;

class Bayarcash_EDD_Payment_Processor {
	private const GATEWAY_ID = 'bayarcash';
	/** @var Bayarcash */
	private Bayarcash $bayarcashSdk;
	private static ?Bayarcash_EDD_Payment_Processor $instance = null;

	/**
	 * @return self
	 */
	public static function getInstance(): self {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->initHooks();
	}

	/**
	 * @return void
	 */
	private function initHooks(): void
	{
		add_action('edd_gateway_' . self::GATEWAY_ID, [$this, 'processPayment']);
		add_action('init', [$this, 'verifyPayment']);
	}

	/**
	 * @param array $purchaseData
	 *
	 * @return void
	 */
	public function processPayment(array $purchaseData): void
	{
		if (empty($purchaseData['gateway']) || self::GATEWAY_ID !== $purchaseData['gateway']) {
			return;
		}

		$this->log('Starting payment process for purchase: ' . wp_json_encode($purchaseData));

		$paymentMethod = $this->getPaymentMethod();
		if (empty($paymentMethod)) {
			$this->handleError('Please select a payment method.', 'bayarcash_error');
			return;
		}

		$paymentData = $this->preparePaymentData($purchaseData);
		$payment = $this->insertPayment($paymentData);

		if (false === $payment) {
			$this->handleError('Payment creation failed before sending buyer to Bayarcash.', 'payment_error');
			return;
		}

		$this->savePaymentMethod($payment, $paymentMethod);

		$this->initializeBayarcashSdk();

		try {
			$paymentIntent = $this->createPaymentIntent($payment, $purchaseData, $paymentMethod);
			$this->log('Payment intent created. Redirecting to Bayarcash URL: ' . $paymentIntent->url);
			edd_empty_cart();
			wp_redirect($paymentIntent->url);
			exit;
		} catch (ValidationException $e) {
			$this->handleValidationError($e);
		} catch ( Exception $e) {
			$this->handleGeneralError($e);
		}
	}

	/**
	 * @return void
	 */
	public function verifyPayment(): void
	{
		if (!isset($_GET['verify_bayarcash_payment']) ||
		    !isset($_POST['bayarcash_nonce']) ||
		    !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bayarcash_nonce'])), 'verify_bayarcash_payment')) {
			return;
		}

		if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
			return;
		}

		$this->log('Starting payment verification. POST data: ' . wp_json_encode($_POST));

		$paymentId = isset($_POST['order_number']) ? absint(wp_unslash($_POST['order_number'])) : 0;
		$recordType = isset($_POST['record_type']) ? sanitize_text_field(wp_unslash($_POST['record_type'])) : '';

		if (!$paymentId) {
			$this->handleVerificationError('Invalid payment verification data.');
			return;
		}

		$this->log("Verifying payment. Payment ID: $paymentId");
		$this->initializeBayarcashSdk();

		try {
			if ($recordType === 'pre_transaction') {
				$this->handlePreTransaction($paymentId);
			} elseif ($recordType === 'transaction_receipt') {
				$this->handleTransactionReceipt($paymentId);
			} else {
				$this->handleVerificationError('Unknown record type received.');
			}
		} catch (Exception $e) {
			$this->handleVerificationError('Error verifying payment: ' . $e->getMessage());
		}
	}

	/**
	 * @param int $paymentId
	 *
	 * @return void
	 */
	private function handlePreTransaction(int $paymentId): void
	{
		if (!isset($_POST['bayarcash_nonce']) ||
		    !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bayarcash_nonce'])), 'verify_bayarcash_payment')) {
			wp_die('Security check failed');
		}

		$transactionId = isset($_POST['transaction_id']) ? sanitize_text_field(wp_unslash($_POST['transaction_id'])) : '';
		if ($transactionId) {
			edd_update_payment_meta($paymentId, 'bayarcash_transaction_id', $transactionId);
			$this->log("Saved transaction ID for payment ID $paymentId: $transactionId");
			/* translators: %s: Transaction ID */
			edd_insert_payment_note($paymentId, sprintf(__('Bayarcash pre-transaction received. Transaction ID: %s', 'bayarcash-for-easy-digital-downloads'), $transactionId));
		} else {
			$this->log("Missing transaction ID for pre-transaction. Payment ID: $paymentId", 'error');
		}
		wp_die('OK');
	}

	/**
	 * @param int $paymentId
	 *
	 * @return void
	 */
	private function handleTransactionReceipt(int $paymentId): void
	{
		if (!isset($_POST['bayarcash_nonce']) ||
		    !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bayarcash_nonce'])), 'verify_bayarcash_payment')) {
			wp_die('Security check failed');
		}
		$transactionId = isset($_POST['transaction_id']) ? sanitize_text_field(wp_unslash($_POST['transaction_id'])) : '';

		if (!$transactionId) {
			$this->handleVerificationError('Transaction ID not found for this payment.');
			return;
		}

		$this->log("Attempting to requery transaction. Payment ID: $paymentId, Transaction ID: $transactionId");

		$requeryClass = Bayarcash_Transaction_Requery::getInstance();
		$transactionData = $requeryClass->requeryTransaction($transactionId);

		if (!$transactionData) {
			$this->log("Failed to retrieve transaction data from Bayarcash API. Payment ID: $paymentId, Transaction ID: $transactionId", 'error');
			$this->handleVerificationError('Failed to retrieve transaction data from Bayarcash API.');
			return;
		}

		$this->processValidTransaction($paymentId, $transactionData);
	}

	/**
	 * @param int $paymentId
	 * @param array $transactionData
	 *
	 * @return void
	 */
	private function processValidTransaction(int $paymentId, array $transactionData): void
	{
		$status = $transactionData['status'];
		$noteDetails = $this->prepareTransactionNoteDetails($transactionData);

		$currentStatus = edd_get_payment_status($paymentId);

		if (3 === $status) {
			$this->completePayment($paymentId, $currentStatus, $noteDetails);
		} else {
			$this->failPayment($paymentId, $currentStatus, $noteDetails, $status, $transactionData['status_description']);
		}

		wp_redirect(edd_get_receipt_page_uri($paymentId));
		exit;
	}

	/**
	 * @param array $transactionData
	 *
	 * @return string
	 */
	private function prepareTransactionNoteDetails(array $transactionData): string
	{
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

	/**
	 * @param int $paymentId
	 * @param string $currentStatus
	 * @param string $noteDetails
	 *
	 * @return void
	 */
	private function completePayment(int $paymentId, string $currentStatus, string $noteDetails): void
	{
		$newStatus = 'complete';
		if ($this->addNoteIfStatusChanged($paymentId, $currentStatus, $newStatus, __('Payment completed successfully via Bayarcash. Details:', 'bayarcash-for-easy-digital-downloads') . "\n" . $noteDetails)) {
			edd_update_payment_status($paymentId, $newStatus);
			$this->log("Payment completed successfully. Payment ID: $paymentId");
		} else {
			$this->log("Payment already marked as complete. Skipping note addition. Payment ID: $paymentId");
		}
	}

	/**
	 * @param int $paymentId
	 * @param string $currentStatus
	 * @param string $noteDetails
	 * @param int $status
	 * @param string $statusDescription
	 *
	 * @return void
	 */
	private function failPayment(int $paymentId, string $currentStatus, string $noteDetails, int $status, string $statusDescription): void
	{
		$newStatus = 'failed';
		$errorMessage = sprintf( /* translators: 1: Status, 2: Description */
			__('Payment failed. Status: %1$s, Description: %2$s', 'bayarcash-for-easy-digital-downloads'),
			$status,
			$statusDescription
		);

		if ($this->addNoteIfStatusChanged($paymentId, $currentStatus, $newStatus, __('Payment failed via Bayarcash. Details:', 'bayarcash-for-easy-digital-downloads') . "\n" . $noteDetails . "\n" . __('Error Description: ', 'bayarcash-for-easy-digital-downloads') . $statusDescription)) {
			edd_update_payment_status($paymentId, $newStatus);
			edd_set_error('payment_failed', $errorMessage);
			$this->log("$errorMessage Payment ID: $paymentId", 'error');
		} else {
			$this->log("Payment already marked as failed. Skipping note addition. Payment ID: $paymentId");
		}
	}

	/**
	 * @return string
	 */
	private function getPaymentMethod(): string
	{
		if (!isset($_POST['bayarcash_nonce']) ||
		    !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bayarcash_nonce'])), 'bayarcash_payment_method')) {
			return '';
		}

		return isset($_POST['bayarcash_payment_method'])
			? sanitize_text_field(wp_unslash($_POST['bayarcash_payment_method']))
			: '';
	}

	/**
	 * @param array $purchaseData
	 *
	 * @return array
	 */
	private function preparePaymentData(array $purchaseData): array
	{
		$paymentData = [
			'price'        => $purchaseData['price'],
			'date'         => $purchaseData['date'],
			'user_email'   => $purchaseData['user_email'],
			'purchase_key' => $purchaseData['purchase_key'],
			'currency'     => edd_get_currency(),
			'downloads'    => $purchaseData['downloads'],
			'cart_details' => $purchaseData['cart_details'],
			'user_info'    => $purchaseData['user_info'],
			'status'       => 'pending',
			'gateway'      => self::GATEWAY_ID,
		];

		$this->log('Prepared payment data: ' . wp_json_encode($paymentData));
		return $paymentData;
	}

	/**
	 * @param array $paymentData
	 *
	 * @return bool|int
	 */
	private function insertPayment(array $paymentData)
	{
		$payment = edd_insert_payment($paymentData);

		if ($payment) {
			$this->log("Payment inserted successfully. Payment ID: $payment");
		} else {
			$this->log('Failed to insert payment. Payment data: ' . wp_json_encode($paymentData), 'error');
		}

		return $payment;
	}

	/**
	 * @return void
	 */
	private function initializeBayarcashSdk(): void
	{
		$token = edd_is_test_mode()
			? BAYARCASH_EDD_SANDBOX_TOKEN
			: edd_get_option('bayarcash_token');

		$this->bayarcashSdk = new Bayarcash($token);

		if (edd_is_test_mode()) {
			$this->bayarcashSdk->useSandbox();
			$this->log('Sandbox mode enabled');
		}
	}

	/**
	 * @param int $paymentId
	 * @param array $purchaseData
	 * @param string $paymentMethod
	 *
	 * @return object
	 */
	private function createPaymentIntent(int $paymentId, array $purchaseData, string $paymentMethod): object
	{
		$portalKey = edd_is_test_mode() ? BAYARCASH_EDD_SANDBOX_PORTAL_KEY : edd_get_option('bayarcash_portal_key');
		$secretKey = edd_is_test_mode() ? BAYARCASH_EDD_SANDBOX_SECRET_KEY : edd_get_option('bayarcash_secret_key');

		$data = [
			'portal_key'             => $portalKey,
			'payment_channel'        => $paymentMethod,
			'order_number'           => $paymentId,
			'amount'                 => $purchaseData['price'],
			'payer_name'             => $purchaseData['user_info']['first_name'] . ' ' . $purchaseData['user_info']['last_name'],
			'payer_email'            => $purchaseData['user_email'],
			//'payer_telephone_number' => $purchaseData['user_info']['phone'],
			'description'            => edd_get_purchase_summary($purchaseData, false),
			'return_url'             => add_query_arg('verify_bayarcash_payment', '1', get_permalink(edd_get_option('success_page'))),
		];

		$data['checksum'] = $this->bayarcashSdk->createPaymentIntenChecksumValue($secretKey, $data);

		$this->log('Creating payment intent with data: ' . wp_json_encode($data));

		return $this->bayarcashSdk->createPaymentIntent($data);
	}

	/**
	 * @param int $paymentId
	 * @param string $paymentMethod
	 *
	 * @return void
	 */
	private function savePaymentMethod(int $paymentId, string $paymentMethod): void
	{
		edd_update_payment_meta($paymentId, 'bayarcash_payment_method', $paymentMethod);
		$this->log("Saved payment method for payment ID $paymentId: $paymentMethod");
	}

	/**
	 * @param string $message
	 * @param string $errorCode
	 * @return void
	 */
	private function handleError(string $message, string $errorCode): void
	{
		$this->log($message, 'error');
		edd_set_error($errorCode, esc_html($message));
		edd_send_back_to_checkout(['payment-mode' => self::GATEWAY_ID]);
	}

	/**
	 * @param ValidationException $e
	 * @return void
	 */
	private function handleValidationError(ValidationException $e): void
	{
		$this->log('Validation error: ' . $e->getMessage() . ' Errors: ' . print_r($e->errors(), true), 'error');
		$this->handleError('Payment error: ' . $e->getMessage(), 'bayarcash_error');
	}

	/**
	 * @param Exception $e
	 *
	 * @return void
	 */
	private function handleGeneralError( Exception $e): void
	{
		$this->log('Payment error: ' . $e->getMessage(), 'error');
		$this->handleError('Payment error: ' . $e->getMessage(), 'bayarcash_error');
	}

	/**
	 * @param string $message
	 * @return void
	 */
	private function handleVerificationError(string $message): void
	{
		$this->log($message, 'error');
		wp_die(esc_html($message));
	}

	/**
	 * @param int $paymentId
	 * @param string $currentStatus
	 * @param string $newStatus
	 * @param string $noteContent
	 *
	 * @return bool
	 */
	private function addNoteIfStatusChanged(int $paymentId, string $currentStatus, string $newStatus, string $noteContent): bool
	{
		if ($newStatus !== $currentStatus) {
			edd_insert_payment_note($paymentId, $noteContent);
			return true;
		}
		return false;
	}

	/**
	 * @param string $message
	 * @param string $type
	 *
	 * @return void
	 */
	private function log(string $message, string $type = 'info'): void
	{
		if (edd_get_option('bayarcash_debug') === '1' && function_exists('edd_debug_log')) {
			edd_debug_log(sprintf('[Bayarcash] %s: %s', ucfirst($type), $message));
		}
	}
}

// Initialize the PaymentProcessor
Bayarcash_EDD_Payment_Processor::getInstance();