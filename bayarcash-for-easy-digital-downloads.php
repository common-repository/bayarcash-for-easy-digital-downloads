<?php
/**
 * Plugin Name: Bayarcash for Easy Digital Downloads
 * Plugin URI: https://bayarcash.com
 * Description: Integrate Bayarcash payment solutions with your Easy Digital Downloads store.
 * Version: 1.0.1
 * Author: Web Impian Sdn Bhd
 * Author URI: https://webimpian.com
 * License: GNU General Public License v3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain: bayarcash-for-easy-digital-downloads
 * Domain Path: /languages
 *
 * Requires PHP: 7.4
 * Requires at least: 5.0
 * Tested up to: 6.4
 */

// Exit if accessed directly
defined('ABSPATH') || exit;

if (!class_exists('Bayarcash_EDD_Integration')):

	class Bayarcash_EDD_Integration {
		private static ?Bayarcash_EDD_Integration $instance = null;
		public string $gateway_id = 'bayarcash';
		private ?bool $is_setup = null;

		const VERSION = '1.0.1';
		const REQUIRED_SETTINGS = ['bayarcash_token', 'bayarcash_secret_key', 'bayarcash_portal_key'];

		public static function instance(): Bayarcash_EDD_Integration {
			if (null === self::$instance) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			$this->define_constants();
			$this->includes();
			$this->init_hooks();
		}

		private function define_constants(): void {
			define('BAYARCASH_EDD_VERSION', self::VERSION);
			define('BAYARCASH_EDD_PLUGIN_DIR', plugin_dir_path(__FILE__));
			define('BAYARCASH_EDD_PLUGIN_URL', plugin_dir_url(__FILE__));
			define('BAYARCASH_EDD_PLUGIN_BASENAME', plugin_basename(__FILE__));
		}

		private function includes(): void {
			require_once BAYARCASH_EDD_PLUGIN_DIR . 'includes/class-bayarcash-edd-settings.php';
			require_once BAYARCASH_EDD_PLUGIN_DIR . 'includes/class-bayarcash-edd-payment-processor.php';
			require_once BAYARCASH_EDD_PLUGIN_DIR . 'includes/bayarcash-edd-sandbox-config.php';
			require_once BAYARCASH_EDD_PLUGIN_DIR . 'includes/bayarcash-transaction-requery.php';
			require_once BAYARCASH_EDD_PLUGIN_DIR . 'includes/bayarcash-edd-cron-requery.php';
			require_once BAYARCASH_EDD_PLUGIN_DIR . 'vendor/autoload.php';
		}

		private function init_hooks(): void {
			add_action('plugins_loaded', [$this, 'load_plugin_textdomain']);
			add_action('plugins_loaded', [$this, 'init_payment_processor']);
			add_filter('edd_payment_gateways', [$this, 'register_gateway']);
			add_filter('plugin_action_links_' . BAYARCASH_EDD_PLUGIN_BASENAME, [$this, 'plugin_action_links']);
			add_action('admin_notices', [$this, 'admin_notices']);
			add_filter("edd_is_gateway_setup_{$this->gateway_id}", [$this, 'gateway_setup']);
			add_action('edd_pre_process_purchase', [$this, 'check_config'], 1);

			// Icon management
			add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_styles']);

			// Admin scripts and AJAX
			add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
			add_action('wp_ajax_get_bayarcash_edd_settings', [$this, 'ajax_get_bayarcash_settings']);
		}

		public function init_payment_processor(): void {
			Bayarcash_EDD_Payment_Processor::getInstance();
		}

		public function load_plugin_textdomain(): void {
			load_plugin_textdomain('bayarcash-for-easy-digital-downloads', false, dirname(BAYARCASH_EDD_PLUGIN_BASENAME) . '/languages');
		}

		public function register_gateway(array $gateways): array {
			$gateways[$this->gateway_id] = [
				'admin_label' => __('Bayarcash', 'bayarcash-for-easy-digital-downloads'),
				'checkout_label' => __('Pay with Bayarcash', 'bayarcash-for-easy-digital-downloads'),
			];
			return $gateways;
		}

		public function enqueue_admin_scripts($hook): void {
			// Check if we're on the correct page
			if ($hook !== 'download_page_edd-settings') {
				return;
			}

			// Check if we're on the gateways tab and bayarcash section
			if (
				isset($_GET['post_type']) && $_GET['post_type'] === 'download' && // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				isset($_GET['page']) && $_GET['page'] === 'edd-settings' && // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				isset($_GET['tab']) && $_GET['tab'] === 'gateways' && // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				isset($_GET['section']) && $_GET['section'] === 'bayarcash' // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			) {
				$this->enqueue_admin_assets();
			} // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		private function enqueue_admin_assets(): void {
			$assets = [
				['vue', BAYARCASH_EDD_PLUGIN_URL . 'assets/js/vue.global.prod.min.js', [], '3.2.31'],
				['axios', BAYARCASH_EDD_PLUGIN_URL . 'assets/js/axios.min.js', [], '0.26.1'],
				['bayarcash-edd-admin-js', BAYARCASH_EDD_PLUGIN_URL . 'assets/js/bayarcash-edd.js', ['jquery', 'vue', 'axios'], self::VERSION],
			];

			foreach ($assets as $asset) {
				wp_enqueue_script(...$asset);
			}

			wp_localize_script('bayarcash-edd-admin-js', 'bayarcashEddAdminData', array(
				'ajaxurl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('bayarcash_edd_nonce')
			));

			wp_enqueue_script('wp-lodash');

			wp_enqueue_style('bayarcash-edd-admin-css', BAYARCASH_EDD_PLUGIN_URL . 'assets/css/bayarcash-edd-admin.css', [], self::VERSION);
		}

		public function enqueue_frontend_styles(): void {
			if (function_exists('edd_is_checkout') && edd_is_checkout()) {
				wp_enqueue_style('bayarcash-edd-frontend-css', BAYARCASH_EDD_PLUGIN_URL . 'assets/css/bayarcash-edd-frontend.css', [], self::VERSION);
			}
		}

		public function ajax_get_bayarcash_settings(): void {
			check_ajax_referer('bayarcash_edd_admin_nonce', 'nonce');

			$settings = [
				'bayarcash_token' => edd_get_option('bayarcash_token'),
				'bayarcash_portal_key' => edd_get_option('bayarcash_portal_key'),
			];

			wp_send_json($settings);
		}

		private function are_settings_configured(): bool {
			foreach (self::REQUIRED_SETTINGS as $setting) {
				if (empty(edd_get_option($setting))) {
					return false;
				}
			}
			return true;
		}

		public function admin_notices(): void {
			if (!$this->are_settings_configured() && edd_is_gateway_active($this->gateway_id)) {
				$message = __('Bayarcash gateway is active but not fully configured. Please configure the Bayarcash settings to enable the gateway.', 'bayarcash-for-easy-digital-downloads');
				echo "<div class='error'><p>" . esc_html($message) . "</p></div>";
			}
		}

		public function plugin_action_links(array $links): array {
			$plugin_links = [
				sprintf('<a href="%s">%s</a>',
					admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways&section=bayarcash'),
					__('Settings', 'bayarcash-for-easy-digital-downloads')
				),
				'<a href="https://docs.bayarcash.com" target="_blank">' . __('Documentation', 'bayarcash-for-easy-digital-downloads') . '</a>',
			];
			return array_merge($plugin_links, $links);
		}

		public function gateway_setup(bool $is_setup): bool {
			return $this->are_settings_configured();
		}

		public function check_config(): void {
			$is_enabled = edd_is_gateway_active($this->gateway_id);
			if ((!$is_enabled || !$this->is_setup()) && $this->gateway_id === edd_get_chosen_gateway()) {
				edd_set_error('bayarcash_gateway_not_configured', esc_html__('There is an error with the Bayarcash configuration.', 'bayarcash-for-easy-digital-downloads'));
			}
		}

		public function is_setup(): bool {
			if (null === $this->is_setup) {
				$this->is_setup = edd_is_gateway_setup($this->gateway_id);
			}
			return $this->is_setup;
		}
	}

endif;

function bayarcash_edd_init(): Bayarcash_EDD_Integration {
	return Bayarcash_EDD_Integration::instance();
}

bayarcash_edd_init();