<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
* payment gateway integration for WooCommerce
* @link https://docs.woothemes.com/document/payment-gateway-api/
*/
class EwayPaymentsWoo extends WC_Payment_Gateway_CC {

	protected $logger;

	/**
	* initialise gateway with custom settings
	*/
	public function __construct() {
		//~ parent::__construct();		// no parent constructor (yet!)

		$this->id						= 'eway_payments';
		$this->icon						= apply_filters('woocommerce_eway_icon', plugins_url('images/eway-tiny.png', EWAY_PAYMENTS_PLUGIN_FILE));
		$this->method_title				= _x('eWAY', 'WooCommerce payment method title', 'eway-payment-gateway');
		$this->admin_page_heading 		= _x('eWAY payment gateway', 'WooCommerce admin page heading', 'eway-payment-gateway');
		$this->admin_page_description 	= _x('Integration with the eWAY credit card payment gateway.', 'eway-payment-gateway');
		$this->has_fields = true;

		// load form fields.
		$this->initFormFields();

		// load settings (via WC_Settings_API)
		$this->init_settings();

		// define user set variables
		$this->enabled					= $this->settings['enabled'];
		$this->title					= $this->settings['title'];
		$this->description				= $this->settings['description'];
		$this->availability				= $this->settings['availability'];
		$this->countries				= $this->settings['countries'];
		$this->eway_api_key				= $this->settings['eway_api_key'];
		$this->eway_password			= $this->settings['eway_password'];
		$this->eway_ecrypt_key			= $this->settings['eway_ecrypt_key'];
		$this->eway_customerid			= $this->settings['eway_customerid'];
		$this->eway_sandbox				= $this->settings['eway_sandbox'];
		$this->eway_sandbox_api_key		= $this->settings['eway_sandbox_api_key'];
		$this->eway_sandbox_password	= $this->settings['eway_sandbox_password'];
		$this->eway_sandbox_ecrypt_key	= $this->settings['eway_sandbox_ecrypt_key'];
		$this->eway_stored				= $this->settings['eway_stored'];
		$this->eway_card_form			= $this->settings['eway_card_form'];
		$this->eway_card_msg			= $this->settings['eway_card_msg'];
		$this->eway_site_seal			= $this->settings['eway_site_seal'];
		$this->eway_site_seal_code		= $this->settings['eway_site_seal_code'];

		// handle support for standard WooCommerce credit card form instead of our custom template
		if ($this->eway_card_form === 'yes') {
			$this->supports[]			= 'default_credit_card_form';
			add_filter('woocommerce_credit_card_form_fields', array($this, 'wooCcFormFields'), 10, 2);
			add_action('woocommerce_credit_card_form_start', array($this, 'wooCcFormStart'));
			add_action('woocommerce_credit_card_form_end', array($this, 'wooCcFormEnd'));
		}

		// add email fields
		add_filter('woocommerce_email_order_meta_fields', array($this, 'wooEmailOrderMetaKeys'), 10, 3);

		// create a logger
		$this->logger = new EwayPaymentsLogging('woocommerce', empty($this->settings['eway_logging']) ? 'off' : $this->settings['eway_logging']);

		// save admin options, via WC_Settings_API
		// v1.6.6 and under:
		add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
		// v2.0+
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
	}

	/**
	* initialise settings form fields
	*/
	public function initFormFields() {
		global $woocommerce;

		// get recorded settings, so we can determine sane defaults when upgrading
		$settings = get_option('woocommerce_eway_payments_settings');

		$this->form_fields = array(

			'enabled' => array(
							'title' 		=> translate('Enable/Disable', 'woocommerce'),
							'type' 			=> 'checkbox',
							'label' 		=> esc_html__('Enable eWAY credit card payment', 'eway-payment-gateway'),
							'default' 		=> 'no',
						),

			'title' => array(
							'title' 		=> translate('Method Title', 'woocommerce'),
							'type' 			=> 'text',
							'description' 	=> translate('This controls the title which the user sees during checkout.', 'woocommerce'),
							'desc_tip'		=> true,
							'default'		=> _x('Credit card', 'WooCommerce payment method title', 'eway-payment-gateway'),
						),

			'description' => array(
							'title' 		=> translate('Description', 'woocommerce'),
							'type' 			=> 'textarea',
							'description' 	=> translate('This controls the description which the user sees during checkout.', 'woocommerce'),
							'desc_tip'		=> true,
							'default'		=> _x('Pay with your credit card using eWAY secure checkout', 'WooCommerce payment method description', 'eway-payment-gateway'),
						),

			'availability' => array(
							'title' 		=> translate('Method availability', 'woocommerce'),
							'type' 			=> 'select',
							'default' 		=> 'all',
							'class'			=> 'availability',
							'options'		=> array(
								'all' 		=> translate('All allowed countries', 'woocommerce'),
								'specific' 	=> translate('Specific Countries', 'woocommerce'),
							),
						),

			'countries' => array(
							'title' 		=> translate('Specific Countries', 'woocommerce'),
							'type' 			=> 'multiselect',
							'class'			=> 'chosen_select',
							'css'			=> 'width: 450px;',
							'default' 		=> '',
							'options'		=> $woocommerce->countries->countries,
						),

			'eway_api_key' => array(
							'title' 		=> _x('API key', 'settings field', 'eway-payment-gateway'),
							'type' 			=> 'text',
							'css'			=> 'width: 100%',
							'custom_attributes'	=> array(
														'autocorrect'		=> 'off',
														'autocapitalize'	=> 'off',
														'spellcheck'		=> 'false',
													),
						),

			'eway_password' => array(
							'title' 		=> _x('API password', 'settings field', 'eway-payment-gateway'),
							'type' 			=> 'text',
							'custom_attributes'	=> array(
														'autocorrect'		=> 'off',
														'autocapitalize'	=> 'off',
														'spellcheck'		=> 'false',
													),
						),

			'eway_ecrypt_key' => array(
							'title' 		=> _x('Client Side Encryption key', 'settings field', 'eway-payment-gateway'),
							'type' 			=> 'textarea',
							'css'			=> 'height: 6em',
							'custom_attributes'	=> array(
														'autocorrect'		=> 'off',
														'autocapitalize'	=> 'off',
														'spellcheck'		=> 'false',
													),
						),

			'eway_customerid' => array(
							'title' 		=> _x('Customer ID', 'settings field', 'eway-payment-gateway'),
							'type' 			=> 'text',
							'description' 	=> esc_html__('Legacy connections only; please add your API key/password and Client Side Encryption key instead.', 'eway-payment-gateway'),
							'desc_tip'		=> true,
						),

			'eway_sandbox' => array(
							'title' 		=> _x('Sandbox mode', 'settings field', 'eway-payment-gateway'),
							'label' 		=> __('enable sandbox (testing) mode', 'eway-payment-gateway'),
							'type' 			=> 'checkbox',
							'description' 	=> esc_html__('Use the sandbox testing environment, no live payments are accepted; use test card number 4444333322221111', 'eway-payment-gateway'),
							'desc_tip'		=> true,
							'default' 		=> 'yes',
						),

			'eway_sandbox_api_key' => array(
							'title' 		=> _x('Sandbox API key', 'settings field', 'eway-payment-gateway'),
							'type' 			=> 'text',
							'css'			=> 'width: 100%',
							'custom_attributes'	=> array(
														'autocorrect'		=> 'off',
														'autocapitalize'	=> 'off',
														'spellcheck'		=> 'false',
													),
						),

			'eway_sandbox_password' => array(
							'title' 		=> _x('Sandbox API password', 'settings field', 'eway-payment-gateway'),
							'type' 			=> 'text',
							'custom_attributes'	=> array(
														'autocorrect'		=> 'off',
														'autocapitalize'	=> 'off',
														'spellcheck'		=> 'false',
													),
						),

			'eway_sandbox_ecrypt_key' => array(
							'title' 		=> _x('Sandbox Client Side Encryption key', 'settings field', 'eway-payment-gateway'),
							'type' 			=> 'textarea',
							'css'			=> 'height: 6em',
							'custom_attributes'	=> array(
														'autocorrect'		=> 'off',
														'autocapitalize'	=> 'off',
														'spellcheck'		=> 'false',
													),
						),

			'eway_stored' => array(
							'title' 		=> _x('Stored payments', 'settings field', 'eway-payment-gateway'),
							'label' 		=> esc_html__('enable stored payments', 'eway-payment-gateway'),
							'type' 			=> 'checkbox',
							'description' 	=> sprintf('%s <em id="woocommerce-eway-admin-stored-test" style="color:#c00"><br />%s</em>',
													esc_html__("Stored payments records payment details but doesn't bill immediately. Useful for drop-shipping merchants.", 'eway-payment-gateway'),
													esc_html__('NB: Stored Payments uses the Direct Payments sandbox; there is no Stored Payments sandbox.', 'eway-payment-gateway')),
							'default' 		=> 'no',
						),

			'eway_logging' => array(
							'title' 		=> _x('Logging', 'settings field', 'eway-payment-gateway'),
							'label' 		=> esc_html__('enable logging to assist trouble shooting', 'eway-payment-gateway'),
							'type' 			=> 'select',
							'description'	=>	sprintf('%s<br/>%s',
													esc_html__('the log file can be found in this folder:', 'eway-payment-gateway'),
													esc_html(EwayPaymentsLogging::getLogFolderRelative())),
							'default' 		=> 'off',
							'options'		=> array(
								'off' 		=> esc_html_x('Off', 'logging settings', 'eway-payment-gateway'),
								'info'	 	=> esc_html_x('All messages', 'logging settings', 'eway-payment-gateway'),
								'error' 	=> esc_html_x('Errors only', 'logging settings', 'eway-payment-gateway'),
							),
						),

			'eway_card_form' => array(
							'title' 		=> _x('Credit card fields', 'settings field', 'eway-payment-gateway'),
							'label' 		=> esc_html__('use WooCommerce standard credit card fields', 'eway-payment-gateway'),
							'type' 			=> 'checkbox',
							'description' 	=> esc_html__('Ticked, the standard WooCommerce credit card fields will be used. Unticked, a custom template will be used for the credit card fields.', 'eway-payment-gateway'),
							'desc_tip'		=> true,
							'default' 		=> (is_array($settings) ? 'no' : 'yes'),
						),

			'eway_card_msg' => array(
							'title' 		=> _x('Credit card message', 'settings field', 'eway-payment-gateway'),
							'type' 			=> 'text',
							'css'			=> 'width:100%',
							'description' 	=> esc_html__('Message to show above credit card fields, e.g. "Visa and Mastercard only"', 'eway-payment-gateway'),
							'desc_tip'		=> true,
							'default'		=> '',
						),

			'eway_site_seal' => array(
							'title' 		=> _x('Show eWAY Site Seal', 'settings field', 'eway-payment-gateway'),
							'label' 		=> esc_html__('show the eWAY site seal after the credit card fields', 'eway-payment-gateway'),
							'type' 			=> 'checkbox',
							'description' 	=> esc_html__('Add the verified eWAY Site Seal to your checkout', 'eway-payment-gateway'),
							'desc_tip'		=> true,
							'default' 		=> 'no',
						),

			'eway_site_seal_code' => array(
							'type' 			=> 'textarea',
							'description' 	=> sprintf('<a href="https://www.eway.com.au/features/tools-site-seal" target="_blank">%s</a>',
													esc_html__('generate your site seal on the eWAY website, and paste it here', 'eway-payment-gateway')),
							'default'		=> '',
							'css'			=> 'height:14em',
						),

			);
	}

	/**
	* extend parent method for initialising settings, so that new settings can receive defaults
	*/
	public function init_settings() {
		parent::init_settings();

		if (method_exists($this, 'get_form_fields')) {
			$form_fields = $this->get_form_fields();
		}
		else {
			// WooCommerce 2.0.20 or earlier
			$form_fields = $this->form_fields;
		}

		if ($form_fields) {
			foreach ($form_fields as $key => $value) {
				if (!isset($this->settings[$key])) {
					$this->settings[$key] = isset($value['default']) ? $value['default'] : '';
				}
			}
		}
	}

	/**
	* show the admin panel for setting plugin options
	*/
	public function admin_options() {
		include EWAY_PAYMENTS_PLUGIN_ROOT . 'views/admin-woocommerce.php';

		add_action('admin_print_footer_scripts', array($this, 'adminSettingsScript'));
	}

	/**
	* add page script for admin options
	*/
	public function adminSettingsScript() {
		$min	= SCRIPT_DEBUG ? '' : '.min';

		echo '<script>';
		readfile(EWAY_PAYMENTS_PLUGIN_ROOT . "js/admin-woocommerce-settings$min.js");
		echo '</script>';
	}

	/**
	* add Name field to WooCommerce credit card form
	* @param array $fields
	* @param string $gateway
	* @return array
	*/
	public function wooCcFormFields($fields, $gateway) {
		if ($gateway === $this->id) {
			ob_start();
			require EWAY_PAYMENTS_PLUGIN_ROOT . 'views/woocommerce-ccfields-card-name.php';
			$card_name = ob_get_clean();

			$fields = array_merge(array('card-name-field' => $card_name), $fields);
		}

		return $fields;
	}

	/**
	* show message before fields in standard WooCommerce credit card form
	* @param string $gateway
	*/
	public function wooCcFormStart($gateway) {
		if ($gateway === $this->id) {
			if (!empty($this->settings['eway_card_msg'])) {
				printf('<span class="eway-credit-card-message">%s</span>', esc_html($this->settings['eway_card_msg']));
			}

			// maybe set up Client Side Encryption
			$creds = $this->getApiCredentials();
			if (!empty($creds['ecrypt_key'])) {
				wp_enqueue_script('eway-ecrypt');
				add_action('wp_print_footer_scripts', array($this, 'ecryptScript'));
			}
		}
	}

	/**
	* inline scripts for client-side encryption
	*/
	public function ecryptScript() {
		$creds	= $this->getApiCredentials();
		$min	= SCRIPT_DEBUG ? '' : '.min';

		$vars = array(
			'mode'		=> 'woocommerce',
			'key'		=> $creds['ecrypt_key'],
			'form'		=> 'form.checkout',
			'fields'	=> array(
							"#{$this->id}-card-number"	=> "cse:{$this->id}-card-number",
							"#{$this->id}-card-cvc"		=> "cse:{$this->id}-card-cvc",
							'#eway_card_number'			=> 'cse:eway_card_number',
							'#eway_cvn'					=> 'cse:eway_cvn',
						),
		);

		echo '<script>';
		echo 'var eway_ecrypt_vars = ', json_encode($vars), '; ';
		readfile(EWAY_PAYMENTS_PLUGIN_ROOT . "js/ecrypt$min.js");
		echo '</script>';
	}

	/**
	* show site seal after fields in standard WooCommerce credit card form, if entered
	* @param string $gateway
	*/
	public function wooCcFormEnd($gateway) {
		if ($gateway == $this->id) {
			if (!empty($this->settings['eway_site_seal']) && !empty($this->settings['eway_site_seal_code']) && $this->settings['eway_site_seal'] == 'yes') {
				echo $this->settings['eway_site_seal_code'];
			}
		}
	}

	/**
	* display payment form on checkout page
	*/
	public function payment_fields() {
		if ($this->eway_card_form == 'yes') {
			// use standard WooCommerce credit card form
			$this->form();
		}
		else {
			$optMonths = EwayPaymentsFormUtils::getMonthOptions();
			$optYears  = EwayPaymentsFormUtils::getYearOptions();

			// load payment fields template with passed values
			$settings = $this->settings;
			EwayPaymentsPlugin::loadTemplate('woocommerce-eway-fields.php', compact('optMonths', 'optYears', 'settings'));
		}
	}

	/**
	* get field values from credit card form -- either WooCommerce standard, or the old template
	* @return array
	*/
	protected function getCardFields() {
		$postdata = new EwayPaymentsFormPost();

		if ($this->eway_card_form == 'yes') {
			// split expiry field into month and year
			$expiry = $postdata->getValue('eway_payments-card-expiry');
			$expiry = array_map('trim', explode('/', $expiry, 2));
			if (count($expiry) === 2) {
				// prefix year with '20' if it's exactly two digits
				if (preg_match('/^[0-9]{2}$/', $expiry[1])) {
					$expiry[1] = '20' . $expiry[1];
				}
			}
			else {
				$expiry = array('', '');
			}

			$fields = array(
				'card_number'  => $postdata->cleanCardnumber($postdata->getValue('eway_payments-card-number')),
				'card_name'    => $postdata->getValue('eway_payments-card-name'),
				'expiry_month' => $expiry[0],
				'expiry_year'  => $expiry[1],
				'cvn'          => $postdata->getValue('eway_payments-card-cvc'),
			);
		}
		else {
			$fields = array(
				'card_number'  => $postdata->cleanCardnumber($postdata->getValue('eway_card_number')),
				'card_name'    => $postdata->getValue('eway_card_name'),
				'expiry_month' => $postdata->getValue('eway_expiry_month'),
				'expiry_year'  => $postdata->getValue('eway_expiry_year'),
				'cvn'          => $postdata->getValue('eway_cvn'),
			);
		}

		return $fields;
	}

	/**
	* validate entered data for errors / omissions
	* @return bool
	*/
	public function validate_fields() {
		$postdata		= new EwayPaymentsFormPost();
		$fields			= $this->getCardFields();
		$errors			= $postdata->verifyCardDetails($fields);

		if (!empty($errors)) {
			foreach ($errors as $error) {
				wc_add_notice($error, 'error');
			}
		}

		return empty($errors);
	}

	/**
	* process the payment and return the result
	* @param int $order_id
	* @return array
	*/
	public function process_payment($order_id) {
		global $woocommerce;

		$order		= new WC_Order($order_id);
		$ccfields	= $this->getCardFields();

		$capture	= ($this->eway_stored  !== 'yes');
		$useSandbox	= ($this->eway_sandbox === 'yes');
		$creds		= apply_filters('woocommerce_eway_credentials', $this->getApiCredentials(), $useSandbox, $order);
		$eway		= EwayPaymentsFormUtils::getApiWrapper($creds, $capture, $useSandbox);

		if (!$eway) {
			$this->logger->log('error', 'credentials need to be defined before transactions can be processed.');
			wc_add_notice(esc_html__('eWAY payments is not configured for payments yet', 'eway-payment-gateway'), 'error');
			return array('result' => 'failure');
		}

		// allow plugins/themes to modify transaction ID; NB: must remain unique for eWAY account!
		$transactionID = apply_filters('woocommerce_eway_trans_number', $order_id);

		$eway->invoiceDescription		= get_bloginfo('name');
		$eway->invoiceReference			= $order->get_order_number();						// customer invoice reference
		$eway->transactionNumber		= $transactionID;
		$eway->cardHoldersName			= $ccfields['card_name'];
		$eway->cardNumber				= $ccfields['card_number'];
		$eway->cardExpiryMonth			= $ccfields['expiry_month'];
		$eway->cardExpiryYear			= $ccfields['expiry_year'];
		$eway->cardVerificationNumber	= $ccfields['cvn'];
		$eway->amount					= $order->order_total;
		$eway->currencyCode				= $order->order_currency;
		$eway->firstName				= $order->billing_first_name;
		$eway->lastName					= $order->billing_last_name;
		$eway->companyName				= $order->billing_company;
		$eway->emailAddress				= $order->billing_email;
		$eway->phone					= $order->billing_phone;
		$eway->address1					= $order->billing_address_1;
		$eway->address2					= $order->billing_address_2;
		$eway->suburb					= $order->billing_city;
		$eway->state					= $order->billing_state;
		$eway->postcode					= $order->billing_postcode;
		$eway->country					= $order->billing_country;
		$eway->countryName				= $order->billing_country;							// for eWAY legacy API
		$eway->comments					= $order->customer_note;

		// maybe send shipping details
		if ($order->shipping_address_1 || $order->shipping_address_2) {
			$eway->hasShipping			= true;
			$eway->shipFirstName		= $order->shipping_first_name;
			$eway->shipLastName			= $order->shipping_last_name;
			$eway->shipAddress1			= $order->shipping_address_1;
			$eway->shipAddress2			= $order->shipping_address_2;
			$eway->shipSuburb			= $order->shipping_city;
			$eway->shipState			= $order->shipping_state;
			$eway->shipCountry			= $order->shipping_country;
			$eway->shipPostcode			= $order->shipping_postcode;
		}

		// convert WooCommerce country code into country name (for eWAY legacy API)
		if (isset($woocommerce->countries->countries[$order->billing_country])) {
			$eway->countryName = $woocommerce->countries->countries[$order->billing_country];
		}

		// use cardholder name for last name if no customer name entered
		if (empty($eway->firstName) && empty($eway->lastName)) {
			$eway->lastName				= $eway->cardHoldersName;
		}

		// allow plugins/themes to modify invoice description and reference, and set option fields
		$eway->invoiceDescription		= apply_filters('woocommerce_eway_invoice_desc', $eway->invoiceDescription, $order_id);
		$eway->invoiceReference			= apply_filters('woocommerce_eway_invoice_ref', $eway->invoiceReference, $order_id);
		$eway->options					= array_filter(array(
												apply_filters('woocommerce_eway_option1', '', $order_id),
												apply_filters('woocommerce_eway_option2', '', $order_id),
												apply_filters('woocommerce_eway_option3', '', $order_id),
											), 'strlen');

		$this->logger->log('info', sprintf('%1$s gateway, invoice ref: %2$s, transaction: %3$s, amount: %4$s, cc: %5$s',
			$useSandbox ? 'test' : 'live', $eway->invoiceReference, $eway->transactionNumber, $eway->amount, $eway->cardNumber));

		try {
			$response = $eway->processPayment();

			if ($response->TransactionStatus) {
				// transaction was successful, so record details and complete payment
				update_post_meta($order_id, 'Transaction ID', $response->TransactionID);
				if (!empty($response->AuthorisationCode)) {
					update_post_meta($order_id, 'Authcode', $response->AuthorisationCode);
				}
				if ($response->BeagleScore >= 0) {
					update_post_meta($order_id, 'Beagle score', $response->BeagleScore);
				}

				if ($this->eway_stored === 'yes') {
					// payment hasn't happened yet, so record status as 'on-hold' and reduce stock in anticipation
					$order->reduce_order_stock();
					$order->update_status('on-hold', __('Awaiting stored payment', 'eway-payment-gateway'));
					unset($_SESSION['order_awaiting_payment']);
				}
				else {
					$order->payment_complete();
				}
				$woocommerce->cart->empty_cart();

				$result = array(
					'result'	=> 'success',
					'redirect'	=> $this->get_return_url($order),
				);

				$this->logger->log('info', sprintf('success, invoice ref: %1$s, transaction: %2$s, status = %3$s, amount = %4$s, authcode = %5$s, Beagle = %6$s',
					$eway->invoiceReference, $response->TransactionID, $this->eway_stored === 'yes' ? 'on-hold' : 'completed',
					$response->Payment->TotalAmount, $response->AuthorisationCode, $response->BeagleScore));
			}
			else {
				// transaction was unsuccessful, so record transaction number and the error
				$error_msg = $response->getErrorMessage(esc_html__('Transaction failed', 'eway-payment-gateway'));
				$order->update_status('failed', $error_msg);
				wc_add_notice($error_msg, 'error');
				$result = array('result' => 'failure');

				$this->logger->log('info', sprintf('failed; invoice ref: %1$s, error: %2$s', $eway->invoiceReference, $response->getErrorsForLog()));
				if ($response->BeagleScore > 0) {
					$this->logger->log('info', sprintf('BeagleScore = %s', $response->BeagleScore));
				}
			}
		}
		catch (EwayPaymentsException $e) {
			// an exception occured, so record the error
			$order->update_status('failed', nl2br(esc_html($e->getMessage())));
			wc_add_notice(nl2br(esc_html($e->getMessage())), 'error');
			$result = array('result' => 'failure');

			$this->logger->log('error', $e->getMessage());
		}

		return $result;
	}

	/**
	* add the successful transaction ID to WooCommerce order emails
	* @param array $keys
	* @param bool $sent_to_admin
	* @param mixed $order
	* @return array
	*/
	public function wooEmailOrderMetaKeys($keys, $sent_to_admin, $order) {
		if (apply_filters('woocommerce_eway_email_show_trans_number', true, $order)) {
			$key = 'Transaction ID';
			$keys[$key] = array(
				'label'		=> wptexturize($key),
				'value'		=> wptexturize(get_post_meta($order->id, $key, true)),
			);
		}

		return $keys;
	}

	/**
	* get API credentials based on settings
	* @return array
	*/
	protected function getApiCredentials() {
		$useSandbox	= ($this->eway_sandbox === 'yes');

		if (!$useSandbox) {
			$creds = array(
				'api_key'		=> $this->eway_api_key,
				'password'		=> $this->eway_password,
				'ecrypt_key'	=> $this->eway_ecrypt_key,
				'customerid'	=> $this->eway_customerid,
			);
		}
		else {
			$creds = array(
				'api_key'		=> $this->eway_sandbox_api_key,
				'password'		=> $this->eway_sandbox_password,
				'ecrypt_key'	=> $this->eway_sandbox_ecrypt_key,
				'customerid'	=> EWAY_PAYMENTS_TEST_CUSTOMER,
			);
		}

		return $creds;
	}

	/**
	* register new payment gateway
	* @param array $gateways array of registered gateways
	* @return array
	*/
	public static function register($gateways) {
		$gateways[] = __CLASS__;
		return $gateways;
	}

}
