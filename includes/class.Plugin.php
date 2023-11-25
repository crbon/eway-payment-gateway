<?php
namespace webaware\eway_payment_gateway;

use EwayPaymentGatewayRequires as Requires;

use EM_Options;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * plugin controller class
 */
final class Plugin {

	/**
	 * static method for getting the instance of this singleton object
	 */
	public static function getInstance() : self {
		static $instance = null;

		if (is_null($instance)) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * hide constructor
	 */
	private function __construct() {}

	/**
	 * initialise plugin, hooked on plugins_loaded at priority 0
	 */
	public function pluginStart() : void {
		add_action('init', 'eway_payment_gateway_load_text_domain');
		add_filter('plugin_row_meta', [$this, 'addPluginDetailsLinks'], 10, 2);

		if (!$this->checkPrerequisites()) {
			return;
		}

		add_action('wp_enqueue_scripts', [$this, 'registerScripts']);

		// register integrations
		add_filter('wpsc_merchants_modules', [$this, 'registerWPeCommerce']);
		add_action('AHEE__EE_System__load_espresso_addons', [$this, 'registerEventEspresso']);
		add_action('plugins_loaded', [$this, 'maybeRegisterWooCommerce']);
		add_action('plugins_loaded', [$this, 'maybeRegisterAWPCP']);
		add_action('em_gateways_init', [$this, 'maybeRegisterEventsManager']);
	}

	/**
	 * check for required PHP extensions, tell admin if any are missing
	 */
	private function checkPrerequisites() : bool {
		// need these PHP extensions
		$missing = array_filter(['json', 'pcre'], static function($ext) {
			return !extension_loaded($ext);
		});

		if (!empty($missing)) {
			$requires = new Requires();
			ob_start();
			include EWAY_PAYMENTS_PLUGIN_ROOT . 'views/requires-extensions.php';
			$requires->addNotice(ob_get_clean());

			return false;
		}

		return true;
	}

	/**
	 * register required scripts
	 */
	public function registerScripts() : void {
		$min = SCRIPT_DEBUG ? '' : '.min';
		$ver = get_cache_buster();

		wp_register_script('eway-ecrypt', "https://secure.ewaypayments.com/scripts/eCrypt$min.js", [], null, true);
		wp_register_script('eway-payment-gateway-ecrypt', plugins_url("static/js/ecrypt$min.js", EWAY_PAYMENTS_PLUGIN_FILE), ['jquery','eway-ecrypt'], $ver, true);
		wp_localize_script('eway-payment-gateway-ecrypt', 'eway_ecrypt_msg', [
			'ecrypt_mask'			=> _x('•', 'encrypted field mask character', 'eway-payment-gateway'),
			'card_number_invalid'	=> __('Card number is invalid', 'eway-payment-gateway'),
		]);
	}

	/**
	 * register new WP eCommerce payment gateway
	 */
	public function registerWPeCommerce(array $gateways) : array {
		require_once EWAY_PAYMENTS_PLUGIN_ROOT . 'includes/integrations/class.WPeCommerce.php';
		return MethodWPeCommerce::register_eway($gateways);
	}

	/**
	 * register with Event Espresso
	 */
	public function registerEventEspresso() : void {
		remove_action('AHEE__EE_System__load_espresso_addons', [$this, __FUNCTION__]);
		require EWAY_PAYMENTS_PLUGIN_ROOT . 'includes/integrations/class.EventEspresso.php';
		MethodEventEspresso::register_eway();
	}

	/**
	 * maybe load WooCommerce payment gateway
	 */
	public function maybeRegisterWooCommerce() : void {
		if (!function_exists('WC')) {
			return;
		}

		if (version_compare(WC()->version, MIN_VERSION_WOOCOMMERCE, '<')) {
			$requires = new Requires();
			$requires->addNotice(
				/* translators: %1$s: minimum required version number, %2$s: installed version number */
				sprintf(esc_html__('Requires WooCommerce version %1$s or higher; your website has WooCommerce version %2$s', 'eway-payment-gateway'),
				esc_html(MIN_VERSION_WOOCOMMERCE), esc_html(WC()->version))
			);
			return;
		}

		require EWAY_PAYMENTS_PLUGIN_ROOT . 'includes/integrations/class.WooCommerce.php';
		MethodWooCommerce::register_eway();
	}

	/**
	 * maybe register with Events Manager
	 */
	public function maybeRegisterEventsManager() : void {
		if (!defined('EMP_VERSION')) {
			return;
		}

		if (version_compare(EMP_VERSION, MIN_VERSION_EVENTS_MANAGER, '<')) {
			$requires = new Requires();
			$requires->addNotice(
				/* translators: %1$s: minimum required version number, %2$s: installed version number */
				sprintf(esc_html__('Requires Events Manager Pro version %1$s or higher; your website has Events Manager Pro version %2$s', 'eway-payment-gateway'),
				esc_html(MIN_VERSION_EVENTS_MANAGER), esc_html(EMP_VERSION))
			);
			return;
		}

		// don't proceed if EM is configured for legacy gateways only
		if (EM_Options::site_get('legacy-gateways', false) || em_constant('EMP_GATEWAY_LEGACY')) {
			$requires = new Requires();
			$requires->addNotice(esc_html__('Does not support Events Manager Pro legacy gateways mode', 'eway-payment-gateway'));
			return;
		}

		require EWAY_PAYMENTS_PLUGIN_ROOT . 'includes/integrations/class.EventsManager.php';
		MethodEventsManager::init();
	}

	/**
	 * maybe register with Another WordPress Classifieds Plugin (AWPCP)
	 */
	public function maybeRegisterAWPCP() : void {
		if (function_exists('awpcp')) {
			require EWAY_PAYMENTS_PLUGIN_ROOT . 'includes/integrations/class.AWPCP.php';
			MethodAWPCP::register_eway();
		}
	}

	/**
	 * action hook for adding plugin details links
	 */
	public function addPluginDetailsLinks(array $links, string $file) : array {
		if ($file === EWAY_PAYMENTS_PLUGIN_NAME) {
			$links[] = sprintf('<a href="https://wordpress.org/support/plugin/eway-payment-gateway" rel="noopener" target="_blank">%s</a>', _x('Get help', 'plugin details links', 'eway-payment-gateway'));
			$links[] = sprintf('<a href="https://wordpress.org/plugins/eway-payment-gateway/" rel="noopener" target="_blank">%s</a>', _x('Rating', 'plugin details links', 'eway-payment-gateway'));
			$links[] = sprintf('<a href="https://translate.wordpress.org/projects/wp-plugins/eway-payment-gateway" rel="noopener" target="_blank">%s</a>', _x('Translate', 'plugin details links', 'eway-payment-gateway'));
			$links[] = sprintf('<a href="https://shop.webaware.com.au/donations/?donation_for=Eway+Payment+Gateway" rel="noopener" target="_blank">%s</a>', _x('Donate', 'plugin details links', 'eway-payment-gateway'));
		}

		return $links;
	}

}
