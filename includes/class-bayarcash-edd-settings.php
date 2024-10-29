<?php

defined('ABSPATH') || exit; // Exit if accessed directly

class Bayarcash_EDD_Settings {
	private static $instance = null;
	public const GATEWAY_ID = 'bayarcash';

	public static function get_instance(): self {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	private function init_hooks(): void {
		add_filter('edd_settings_sections_gateways', [$this, 'register_gateway_section'], 1);
		add_filter('edd_settings_gateways', [$this, 'register_gateway_settings'], 1);
		add_action('edd_' . self::GATEWAY_ID . '_cc_form', [$this, 'display_payment_options']);
		add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
	}

	public function enqueue_scripts() {
        if (!is_admin() && function_exists('edd_is_checkout') && edd_is_checkout()) {
	        wp_enqueue_script(
		        'bayarcash-edd-payment-options',
		        BAYARCASH_EDD_PLUGIN_URL . 'assets/js/payment-options.js',
		        array( 'jquery' ),
		        BAYARCASH_EDD_VERSION,
		        true
	        );
        }
	}

	public function register_gateway_section(array $gateway_sections): array {
		$gateway_sections[self::GATEWAY_ID] = esc_html__('Bayarcash', 'bayarcash-edd');
		return $gateway_sections;
	}

	public function register_gateway_settings(array $gateway_settings): array {
		$bayarcash_settings = [
			'bayarcash_settings' => [
				'id'   => 'bayarcash_settings',
				'name' => '<h3>' . esc_html__('Bayarcash Settings', 'bayarcash-edd') . '</h3>',
				'type' => 'header',
			],
			'bayarcash_token' => [
				'id'   => 'bayarcash_token',
				'name' => esc_html__('Token', 'bayarcash-edd'),
				'desc' => esc_html__('Enter your Bayarcash token', 'bayarcash-edd'),
				'type' => 'textarea',
				'size' => 'regular',
			],
			'bayarcash_secret_key' => [
				'id'   => 'bayarcash_secret_key',
				'name' => esc_html__('Secret Key', 'bayarcash-edd'),
				'desc' => esc_html__('Secret key can be obtained from Bayarcash Dashboard >> API Keys', 'bayarcash-edd'),
				'type' => 'text',
				'size' => 'regular',
			],
			'bayarcash_portal_key' => [
				'id'   => 'bayarcash_portal_key',
				'name' => esc_html__('Portal Key', 'bayarcash-edd'),
				'desc' => esc_html__('Enter your Bayarcash portal key', 'bayarcash-edd'),
				'type' => 'select',
				'size' => 'regular',
			],
			'bayarcash_payment_methods' => [
				'id'   => 'bayarcash_payment_methods',
				'name' => esc_html__('Payment Methods', 'bayarcash-edd'),
				'desc' => esc_html__('Select available payment methods for your customers', 'bayarcash-edd'),
				'type' => 'multicheck',
				'options' => $this->get_payment_method_options(),
			],
			'bayarcash_debug' => [
				'id'   => 'bayarcash_debug',
				'name' => esc_html__('Debug Mode', 'bayarcash-edd'),
				'desc' => esc_html__('Enable debug logging for troubleshooting', 'bayarcash-edd'),
				'type' => 'checkbox',
			],
		];

		$gateway_settings[self::GATEWAY_ID] = apply_filters('edd_bayarcash_settings', $bayarcash_settings);
		return $gateway_settings;
	}

	private function get_payment_method_options(): array {
		return [
			'1' => esc_html__('Online Banking', 'bayarcash-edd'),
			'5' => esc_html__('Online Banking & Wallets', 'bayarcash-edd'),
			'4' => esc_html__('Credit Card', 'bayarcash-edd'),
			// Add more payment methods as needed
		];
	}

	public function display_payment_options(): void {
		$selected_methods = edd_get_option('bayarcash_payment_methods', []);
		$all_methods = $this->get_payment_method_options();

		if (empty($selected_methods) || !is_array($selected_methods)) {
			echo '<p>' . esc_html__('No payment methods available.', 'bayarcash-edd') . '</p>';
			return;
		}

		echo '<div class="bayarcash-payment-box">';

		$first_option = true;
		foreach ($all_methods as $method_id => $method_name) {
			if (isset($selected_methods[$method_id]) && $selected_methods[$method_id] === $method_name) {
				$this->display_payment_option($method_id, $method_name, $first_option);
				$first_option = false;
			}
		}

		echo '</div>';
	}

	private function display_payment_option(string $method_id, string $method_name, bool $is_first): void {
		$image_url = $this->get_payment_method_image($method_id);
		$description = $this->get_payment_method_description($method_id);

		?>
		<div class="bayarcash-payment-option">
			<input
				type="radio"
				id="bayarcash_method_<?php echo esc_attr($method_id); ?>"
				name="bayarcash_payment_method"
				value="<?php echo esc_attr($method_id); ?>"
				required
				<?php checked($is_first); ?>
			>
			<div class="bayarcash-payment-option-wrapper">
				<label for="bayarcash_method_<?php echo esc_attr($method_id); ?>">
					<?php echo esc_html($method_name); ?>
				</label>
				<img
					class="bayarcash-payment-option-image"
					src="<?php echo esc_url($image_url); ?>"
					alt="<?php echo esc_attr($method_name); ?>"
				>
			</div>
			<div class="bayarcash-payment-details">
				<p><?php echo esc_html($description); ?></p>
			</div>
		</div>
		<?php
	}

	private function get_payment_method_description(string $method_id): string {
		$descriptions = [
			'1' => esc_html__('Pay with online banking Maybank2u, CIMB Clicks, Bank Islam GO and more banks from Malaysia via FPX.', 'bayarcash-edd'),
			'5' => esc_html__('Pay with online banking Maybank2u, CIMB Clicks, Bank Islam GO and more banks from Malaysia via DuitNow.', 'bayarcash-edd'),
			'4' => esc_html__('Pay with Visa/Mastercard credit card account issued by Malaysia local banks.', 'bayarcash-edd'),
			// Add more descriptions for other payment methods as needed
		];

		return $descriptions[$method_id] ?? esc_html__('Select this payment method to proceed with your purchase.', 'bayarcash-edd');
	}

	private function get_payment_method_image(string $method_id): string {
		$plugin_url = BAYARCASH_EDD_PLUGIN_URL;
		$image_urls = [
			'1' => $plugin_url . 'assets/img/fpx-online-banking.png',
			'5' => $plugin_url . 'assets/img/duitnow-online-banking-wallets.png',
			'4' => $plugin_url . 'assets/img/visa-mastercard.png',
		];

		return $image_urls[$method_id];
	}
}

Bayarcash_EDD_Settings::get_instance();