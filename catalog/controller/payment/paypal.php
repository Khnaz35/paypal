<?php
namespace Opencart\Catalog\Controller\Extension\PayPal\Payment;
class PayPal extends \Opencart\System\Engine\Controller {
	private $error = [];
	private $separator = '';
		
	public function __construct($registry) {
		parent::__construct($registry);
		
		if (version_compare(VERSION, '4.0.2.0', '>=')) {
			$this->separator = '.';
		} else {
			$this->separator = '|';
		}

		if (version_compare(phpversion(), '7.1', '>=')) {
			ini_set('precision', 14);
			ini_set('serialize_precision', 14);
		}
		
		if (empty($this->config->get('paypal_version')) || (!empty($this->config->get('paypal_version')) && (version_compare($this->config->get('paypal_version'), '3.1.4', '<')))) {
			$this->update();
		}
	}
	
	public function index(): string {
		$this->load->model('extension/paypal/payment/paypal');
		
		$agree_status = $this->model_extension_paypal_payment_paypal->getAgreeStatus();
		
		if ($this->config->get('payment_paypal_status') && $this->config->get('payment_paypal_client_id') && $this->config->get('payment_paypal_secret') && !$this->callback() && !$this->webhook() && !$this->cron() && $agree_status) {
			if (version_compare(VERSION, '4.0.2.0', '>=')) {
				if (!empty($this->session->data['payment_method']['code'])) {
					if ($this->session->data['payment_method']['code'] == 'paypal.paylater') {
						return $this->load->controller('extension/paypal/payment/paypal_paylater');
					}
					
					if ($this->session->data['payment_method']['code'] == 'paypal.googlepay') {
						return $this->load->controller('extension/paypal/payment/paypal_googlepay');
					}
					
					if ($this->session->data['payment_method']['code'] == 'paypal.applepay') {
						return $this->load->controller('extension/paypal/payment/paypal_applepay');
					}
					
					if ($this->session->data['payment_method']['code'] == 'paypal.fastlane') {
						return $this->load->controller('extension/paypal/payment/paypal_fastlane');
					}
				}
			}
		
			$this->load->language('extension/paypal/payment/paypal');
			
			$_config = new \Opencart\System\Engine\Config();
			$_config->addPath(DIR_EXTENSION . 'paypal/system/config/');
			$_config->load('paypal');
			
			$config_setting = $_config->get('paypal_setting');
		
			$setting = array_replace_recursive((array)$config_setting, (array)$this->config->get('payment_paypal_setting'));
			
			$data['client_id'] = $this->config->get('payment_paypal_client_id');
			$data['secret'] = $this->config->get('payment_paypal_secret');
			$data['merchant_id'] = $this->config->get('payment_paypal_merchant_id');
			$data['environment'] = $this->config->get('payment_paypal_environment');
			$data['partner_id'] = $setting['partner'][$data['environment']]['partner_id'];
			$data['partner_attribution_id'] = $setting['partner'][$data['environment']]['partner_attribution_id'];
			$data['vault_status'] = $setting['general']['vault_status'];
			$data['checkout_mode'] = $setting['general']['checkout_mode'];
			$data['transaction_method'] = $setting['general']['transaction_method'];
		
			$data['button_status'] = $setting['button']['checkout']['status'];
			$data['googlepay_button_status'] = $setting['googlepay_button']['checkout']['status'];
			$data['card_status'] = $setting['card']['status'];
			$data['message_status'] = $setting['message']['checkout']['status'];
			
			if ($setting['applepay_button']['checkout']['status'] && !empty($this->session->data['paypal']['applepay'])) {
				$data['applepay_button_status'] = $setting['applepay_button']['checkout']['status'];
			} else {
				$data['applepay_button_status'] = false;
			}
			
			$data['logged'] = $this->customer->isLogged();
						
			require_once DIR_EXTENSION . 'paypal/system/library/paypal.php';

			$paypal_info = [
				'partner_id' => $data['partner_id'],
				'client_id' => $data['client_id'],
				'secret' => $data['secret'],
				'environment' => $data['environment'],
				'partner_attribution_id' => $data['partner_attribution_id']
			];
			
			if (isset($this->session->data['paypal_client_metadata_id'])) {
				$paypal_info['client_metadata_id'] = $this->session->data['paypal_client_metadata_id'];
			}
		
			$paypal = new \Opencart\System\Library\PayPal($paypal_info);
		
			$token_info = [
				'grant_type' => 'client_credentials'
			];	
				
			$paypal->setAccessToken($token_info);
								
			if ($paypal->hasErrors()) {
				$error_messages = [];
				
				$errors = $paypal->getErrors();
								
				foreach ($errors as $error) {
					if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
						$error['message'] = $this->language->get('error_timeout');
					}
				
					if (isset($error['details'][0]['description'])) {
						$error_messages[] = $error['details'][0]['description'];
					} elseif (isset($error['message'])) {
						$error_messages[] = $error['message'];
					}
									
					$this->model_extension_paypal_payment_paypal->log($error, $error['message']);
				}
				
				$this->error['warning'] = implode(' ', $error_messages);
			}

			if (!empty($this->error['warning'])) {
				$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
			}
			
			$data['separator'] = $this->separator;
						
			$data['language'] = $this->config->get('config_language');		

			$data['error'] = $this->error;	

			return $this->load->view('extension/paypal/payment/paypal', $data);
		}
		
		return '';
	}
	
	public function modal(): void {
		$this->load->language('extension/paypal/payment/paypal');
			
		$_config = new \Opencart\System\Engine\Config();
		$_config->addPath(DIR_EXTENSION . 'paypal/system/config/');
		$_config->load('paypal');
			
		$config_setting = $_config->get('paypal_setting');
		
		$setting = array_replace_recursive((array)$config_setting, (array)$this->config->get('payment_paypal_setting'));
			
		$data['client_id'] = $this->config->get('payment_paypal_client_id');
		$data['secret'] = $this->config->get('payment_paypal_secret');
		$data['merchant_id'] = $this->config->get('payment_paypal_merchant_id');
		$data['environment'] = $this->config->get('payment_paypal_environment');
		$data['partner_id'] = $setting['partner'][$data['environment']]['partner_id'];
		$data['partner_attribution_id'] = $setting['partner'][$data['environment']]['partner_attribution_id'];
		$data['vault_status'] = $setting['general']['vault_status'];
		$data['transaction_method'] = $setting['general']['transaction_method'];
		
		$data['button_status'] = $setting['button']['checkout']['status'];
		$data['googlepay_button_status'] = $setting['googlepay_button']['checkout']['status'];
		$data['card_status'] = $setting['card']['status'];
		$data['message_status'] = $setting['message']['checkout']['status'];
		
		if ($setting['applepay_button']['checkout']['status'] && !empty($this->session->data['paypal']['applepay'])) {
			$data['applepay_button_status'] = $setting['applepay_button']['checkout']['status'];
		} else {
			$data['applepay_button_status'] = false;
		}
		
		$data['logged'] = $this->customer->isLogged();
			
		require_once DIR_EXTENSION . 'paypal/system/library/paypal.php';

		$paypal_info = [
			'partner_id' => $data['partner_id'],
			'client_id' => $data['client_id'],
			'secret' => $data['secret'],
			'environment' => $data['environment'],
			'partner_attribution_id' => $data['partner_attribution_id']
		];
		
		if (isset($this->session->data['paypal_client_metadata_id'])) {
			$paypal_info['client_metadata_id'] = $this->session->data['paypal_client_metadata_id'];
		}
		
		$paypal = new \Opencart\System\Library\PayPal($paypal_info);
		
		$token_info = [
			'grant_type' => 'client_credentials'
		];	
				
		$paypal->setAccessToken($token_info);
								
		if ($paypal->hasErrors()) {
			$error_messages = [];
				
			$errors = $paypal->getErrors();
								
			foreach ($errors as $error) {
				if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
					$error['message'] = $this->language->get('error_timeout');
				}
				
				if (isset($error['details'][0]['description'])) {
					$error_messages[] = $error['details'][0]['description'];
				} elseif (isset($error['message'])) {
					$error_messages[] = $error['message'];
				}
									
				$this->model_extension_paypal_payment_paypal->log($error, $error['message']);
			}
				
			$this->error['warning'] = implode(' ', $error_messages);
		}

		if (!empty($this->error['warning'])) {
			$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
		}

		$data['language'] = $this->config->get('config_language');		

		$data['error'] = $this->error;

		$this->response->setOutput($this->load->view('extension/paypal/payment/paypal_modal', $data));
	}
	
	public function getData(): void {
		$this->load->model('extension/paypal/payment/paypal');
		
		$agree_status = $this->model_extension_paypal_payment_paypal->getAgreeStatus();
		
		if ($this->config->get('payment_paypal_status') && $this->config->get('payment_paypal_client_id') && $this->config->get('payment_paypal_secret') && $agree_status && !empty($this->request->post['page_code'])) {	
			$this->load->language('extension/paypal/payment/paypal');
		
			$this->load->model('localisation/country');
			$this->load->model('checkout/order');
		
			$_config = new \Opencart\System\Engine\Config();
			$_config->addPath(DIR_EXTENSION . 'paypal/system/config/');
			$_config->load('paypal');
			
			$config_setting = $_config->get('paypal_setting');
		
			$setting = array_replace_recursive((array)$config_setting, (array)$this->config->get('payment_paypal_setting'));
			
			$secret = $this->config->get('payment_paypal_secret');
			
			$data['page_code'] = $this->request->post['page_code'];
			$data['client_id'] = $this->config->get('payment_paypal_client_id');
			$data['merchant_id'] = $this->config->get('payment_paypal_merchant_id');
			$data['environment'] = $this->config->get('payment_paypal_environment');
			$data['googlepay_environment'] = (($data['environment'] == 'production') ? 'PRODUCTION' : 'TEST');
			$data['partner_id'] = $setting['partner'][$data['environment']]['partner_id'];
			$data['partner_attribution_id'] = $setting['partner'][$data['environment']]['partner_attribution_id'];
			$data['vault_status'] = $setting['general']['vault_status'];
			$data['transaction_method'] = $setting['general']['transaction_method'];
			
			$country = $this->model_extension_paypal_payment_paypal->getCountryByCode($setting['general']['country_code']);
			
			$data['locale'] = preg_replace('/-(.+?)+/', '', $this->config->get('config_language')) . '_' . $country['iso_code_2'];
				
			$data['currency_code'] = $this->session->data['currency'];
			$data['currency_value'] = $this->currency->getValue($this->session->data['currency']);
		
			if (empty($setting['currency'][$data['currency_code']]['status'])) {
				$data['currency_code'] = $setting['general']['currency_code'];
				$data['currency_value'] = $setting['general']['currency_value'];
			}
		
			$data['decimal_place'] = $setting['currency'][$data['currency_code']]['decimal_place'];
			
			if (!empty($this->request->post['applepay'])) {
				$this->session->data['paypal']['applepay'] = true;
			}
			
			$data['components'] = [];
			
			if ($this->request->post['page_code'] == 'home') {				
				if ($setting['message']['home']['status'] && !empty($setting['paylater_country'][$setting['general']['country_code']]) && ($data['currency_code'] == $setting['general']['currency_code'])) {
					$data['components'][] = 'messages';
					
					$data['message_status'] = $setting['message']['home']['status'];
					$data['message_insert_tag'] = html_entity_decode($setting['message']['home']['insert_tag']);
					$data['message_insert_type'] = $setting['message']['home']['insert_type'];
					$data['message_layout'] = $setting['message']['home']['layout'];
					$data['message_logo_type'] = $setting['message']['home']['logo_type'];
					$data['message_logo_position'] = $setting['message']['home']['logo_position'];
					$data['message_text_color'] = $setting['message']['home']['text_color'];
					$data['message_text_size'] = $setting['message']['home']['text_size'];
					$data['message_flex_color'] = $setting['message']['home']['flex_color'];
					$data['message_flex_ratio'] = $setting['message']['home']['flex_ratio'];
									
					$item_total = 0;
								
					foreach ($this->cart->getProducts() as $product) {
						$product_price = $this->tax->calculate($product['price'], $product['tax_class_id'], true);
									
						$item_total += $product_price * $product['quantity'];
					}
					
					if (!empty($this->session->data['vouchers'])) {
						foreach ($this->session->data['vouchers'] as $voucher) {
							$item_total += $voucher['amount'];
						}
					}
			
					$data['message_amount'] = number_format($item_total * $data['currency_value'], $data['decimal_place'], '.', '');
				}
			}
			
			if (!empty($this->request->post['product'])) {
				$this->request->post['product'] = $this->unserialize($this->request->post['product']);
			}
			
			if (($this->request->post['page_code'] == 'product') && !empty($this->request->post['product']['product_id'])) {
				$product = $this->request->post['product'];
				$product_id = (int)$this->request->post['product']['product_id'];
				$product_price = 0;
				
				if (isset($product['quantity'])) {
					$quantity = (int)$product['quantity'];
				} else {
					$quantity = 1;
				}

				if (isset($product['option'])) {
					$option = array_filter($product['option']);
				} else {
					$option = array();
				}
				
				$this->load->model('catalog/product');

				$product_info = $this->model_catalog_product->getProduct($product_id);

				if ($product_info && ($this->customer->isLogged() || !$this->config->get('config_customer_price'))) {				
					$option_price = 0;

					$product_options = $this->model_catalog_product->getOptions($product_id);
						
					foreach ($product_options as $product_option) {
						if (isset($option[$product_option['product_option_id']])) {
							if (($product_option['type'] == 'select') || ($product_option['type'] == 'radio')) {
								foreach ($product_option['product_option_value'] as $product_option_value) {
									if (!$product_option_value['subtract'] || ($product_option_value['quantity'] > 0)) {
										if ((float)$product_option_value['price']) {
											if ($option[$product_option['product_option_id']] == $product_option_value['product_option_value_id']) {
												if ($product_option_value['price_prefix'] == '+') {
													$option_price += $product_option_value['price'];
												} elseif ($product_option_value['price_prefix'] == '-') {
													$option_price -= $product_option_value['price'];
												}
											}
										}
									}	
								}
							} elseif (($product_option['type'] == 'checkbox') && is_array($option[$product_option['product_option_id']])) {
								foreach ($product_option['product_option_value'] as $product_option_value) {
									if (!$product_option_value['subtract'] || ($product_option_value['quantity'] > 0)) {
										if ((float)$product_option_value['price']) {
											if (in_array($product_option_value['product_option_value_id'], $option[$product_option['product_option_id']])) {
												if ($product_option_value['price_prefix'] == '+') {
													$option_price += $product_option_value['price'];
												} elseif ($product_option_value['price_prefix'] == '-') {
													$option_price -= $product_option_value['price'];
												}
											}
										}
									}	
								}
							}
						}
					}
					
					if ((float)$product_info['special']) {
						$product_price = $this->tax->calculate(($product_info['special'] + $option_price) * $quantity, $product_info['tax_class_id'], true);
					} else {
						$product_price = $this->tax->calculate(($product_info['price'] + $option_price) * $quantity, $product_info['tax_class_id'], true);
					}
				}
				
				if ($setting['button']['product']['status']) {
					$data['components'][] = 'buttons';
					
					$data['button_status'] = $setting['button']['product']['status'];
					$data['button_insert_tag'] = html_entity_decode($setting['button']['product']['insert_tag']);
					$data['button_insert_type'] = $setting['button']['product']['insert_type'];
					$data['button_align'] = $setting['button']['product']['align'];
					$data['button_size'] = $setting['button']['product']['size'];
					$data['button_width'] = $setting['button_width'][$data['button_size']];
					$data['button_color'] = $setting['button']['product']['color'];
					$data['button_shape'] = $setting['button']['product']['shape'];
					$data['button_label'] = $setting['button']['product']['label'];
					
					$data['button_enable_funding'] = [];
					$data['button_disable_funding'] = [];
				
					foreach ($setting['button_funding'] as $button_funding) {
						if ($setting['button']['product']['funding'][$button_funding['code']] == 1) {
							$data['button_enable_funding'][] = $button_funding['code'];
						} 
				
						if ($setting['button']['product']['funding'][$button_funding['code']] == 2) {
							$data['button_disable_funding'][] = $button_funding['code'];
						}
					}
				}
				
				if ($setting['googlepay_button']['product']['status']) {
					$data['components'][] = 'googlepay';
					
					$data['googlepay_button_status'] = $setting['googlepay_button']['product']['status'];
					$data['googlepay_button_insert_tag'] = html_entity_decode($setting['googlepay_button']['product']['insert_tag']);
					$data['googlepay_button_insert_type'] = $setting['googlepay_button']['product']['insert_type'];
					$data['googlepay_button_align'] = $setting['googlepay_button']['product']['align'];
					$data['googlepay_button_size'] = $setting['googlepay_button']['product']['size'];
					$data['googlepay_button_width'] = $setting['googlepay_button_width'][$data['googlepay_button_size']];
					$data['googlepay_button_color'] = $setting['googlepay_button']['product']['color'];
					$data['googlepay_button_shape'] = $setting['googlepay_button']['product']['shape'];
					$data['googlepay_button_type'] = $setting['googlepay_button']['product']['type'];
					
					if ($product_price) {
						$data['googlepay_amount'] = number_format($product_price * $data['currency_value'], $data['decimal_place'], '.', ''); 			
					}
				}
				
				if ($setting['applepay_button']['product']['status'] && !empty($this->session->data['paypal']['applepay'])) {
					$data['components'][] = 'applepay';
					
					$data['applepay_button_status'] = $setting['applepay_button']['product']['status'];
					$data['applepay_button_insert_tag'] = html_entity_decode($setting['applepay_button']['product']['insert_tag']);
					$data['applepay_button_insert_type'] = $setting['applepay_button']['product']['insert_type'];
					$data['applepay_button_align'] = $setting['applepay_button']['product']['align'];
					$data['applepay_button_size'] = $setting['applepay_button']['product']['size'];
					$data['applepay_button_width'] = $setting['applepay_button_width'][$data['applepay_button_size']];
					$data['applepay_button_color'] = $setting['applepay_button']['product']['color'];
					$data['applepay_button_shape'] = $setting['applepay_button']['product']['shape'];
					$data['applepay_button_type'] = $setting['applepay_button']['product']['type'];
					
					if ($product_price) {
						$data['applepay_amount'] = number_format($product_price * $data['currency_value'], $data['decimal_place'], '.', ''); 			
					}
				}
				
				if ($setting['message']['product']['status'] && !empty($setting['paylater_country'][$setting['general']['country_code']]) && ($data['currency_code'] == $setting['general']['currency_code'])) {
					$data['components'][] = 'messages';
					
					$data['message_status'] = $setting['message']['product']['status'];
					$data['message_insert_tag'] = html_entity_decode($setting['message']['product']['insert_tag']);
					$data['message_insert_type'] = $setting['message']['product']['insert_type'];
					$data['message_layout'] = $setting['message']['product']['layout'];
					$data['message_logo_type'] = $setting['message']['product']['logo_type'];
					$data['message_logo_position'] = $setting['message']['product']['logo_position'];
					$data['message_text_color'] = $setting['message']['product']['text_color'];
					$data['message_text_size'] = $setting['message']['product']['text_size'];
					$data['message_flex_color'] = $setting['message']['product']['flex_color'];
					$data['message_flex_ratio'] = $setting['message']['product']['flex_ratio'];
									
					if ($product_price) {
						$data['message_amount'] = number_format($product_price * $data['currency_value'], $data['decimal_place'], '.', ''); 			
					}
				}
			}
			
			if (($this->request->post['page_code'] == 'cart') && ($this->cart->hasProducts() || !empty($this->session->data['vouchers']))) {
				if ($setting['button']['cart']['status']) {
					$data['components'][] = 'buttons';
					
					$data['button_status'] = $setting['button']['cart']['status'];
					$data['button_insert_tag'] = html_entity_decode($setting['button']['cart']['insert_tag']);
					$data['button_insert_type'] = $setting['button']['cart']['insert_type'];
					$data['button_align'] = $setting['button']['cart']['align'];
					$data['button_size'] = $setting['button']['cart']['size'];
					$data['button_width'] = $setting['button_width'][$data['button_size']];
					$data['button_color'] = $setting['button']['cart']['color'];
					$data['button_shape'] = $setting['button']['cart']['shape'];
					$data['button_label'] = $setting['button']['cart']['label'];
					
					$data['button_enable_funding'] = [];
					$data['button_disable_funding'] = [];
				
					foreach ($setting['button_funding'] as $button_funding) {
						if ($setting['button']['cart']['funding'][$button_funding['code']] == 1) {
							$data['button_enable_funding'][] = $button_funding['code'];
						} 
				
						if ($setting['button']['cart']['funding'][$button_funding['code']] == 2) {
							$data['button_disable_funding'][] = $button_funding['code'];
						}
					}
				}
				
				if ($setting['googlepay_button']['cart']['status']) {
					$data['components'][] = 'googlepay';
					
					$data['googlepay_button_status'] = $setting['googlepay_button']['cart']['status'];
					$data['googlepay_button_insert_tag'] = html_entity_decode($setting['googlepay_button']['cart']['insert_tag']);
					$data['googlepay_button_insert_type'] = $setting['googlepay_button']['cart']['insert_type'];
					$data['googlepay_button_align'] = $setting['googlepay_button']['cart']['align'];
					$data['googlepay_button_size'] = $setting['googlepay_button']['cart']['size'];
					$data['googlepay_button_width'] = $setting['googlepay_button_width'][$data['googlepay_button_size']];
					$data['googlepay_button_color'] = $setting['googlepay_button']['cart']['color'];
					$data['googlepay_button_shape'] = $setting['googlepay_button']['cart']['shape'];
					$data['googlepay_button_type'] = $setting['googlepay_button']['cart']['type'];
					
					$item_total = 0;
								
					foreach ($this->cart->getProducts() as $product) {
						$product_price = $this->tax->calculate($product['price'], $product['tax_class_id'], true);
									
						$item_total += $product_price * $product['quantity'];
					}
						
					if (!empty($this->session->data['vouchers'])) {
						foreach ($this->session->data['vouchers'] as $voucher) {
							$item_total += $voucher['amount'];
						}
					}
			
					$data['googlepay_amount'] = number_format($item_total * $data['currency_value'], $data['decimal_place'], '.', '');
				}
				
				if ($setting['applepay_button']['cart']['status'] && !empty($this->session->data['paypal']['applepay'])) {
					$data['components'][] = 'applepay';
					
					$data['applepay_button_status'] = $setting['applepay_button']['cart']['status'];
					$data['applepay_button_insert_tag'] = html_entity_decode($setting['applepay_button']['cart']['insert_tag']);
					$data['applepay_button_insert_type'] = $setting['applepay_button']['cart']['insert_type'];
					$data['applepay_button_align'] = $setting['applepay_button']['cart']['align'];
					$data['applepay_button_size'] = $setting['applepay_button']['cart']['size'];
					$data['applepay_button_width'] = $setting['applepay_button_width'][$data['applepay_button_size']];
					$data['applepay_button_color'] = $setting['applepay_button']['cart']['color'];
					$data['applepay_button_shape'] = $setting['applepay_button']['cart']['shape'];
					$data['applepay_button_type'] = $setting['applepay_button']['cart']['type'];
					
					$item_total = 0;
								
					foreach ($this->cart->getProducts() as $product) {
						$product_price = $this->tax->calculate($product['price'], $product['tax_class_id'], true);
								
						$item_total += $product_price * $product['quantity'];
					}
						
					if (!empty($this->session->data['vouchers'])) {
						foreach ($this->session->data['vouchers'] as $voucher) {
							$item_total += $voucher['amount'];
						}
					}
			
					$data['applepay_amount'] = number_format($item_total * $data['currency_value'], $data['decimal_place'], '.', '');
				}

				if ($setting['message']['cart']['status'] && !empty($setting['paylater_country'][$setting['general']['country_code']]) && ($data['currency_code'] == $setting['general']['currency_code'])) {
					$data['components'][] = 'messages';
					
					$data['message_status'] = $setting['message']['cart']['status'];
					$data['message_insert_tag'] = html_entity_decode($setting['message']['cart']['insert_tag']);
					$data['message_insert_type'] = $setting['message']['cart']['insert_type'];
					$data['message_layout'] = $setting['message']['cart']['layout'];
					$data['message_logo_type'] = $setting['message']['cart']['logo_type'];
					$data['message_logo_position'] = $setting['message']['cart']['logo_position'];
					$data['message_text_color'] = $setting['message']['cart']['text_color'];
					$data['message_text_size'] = $setting['message']['cart']['text_size'];
					$data['message_flex_color'] = $setting['message']['cart']['flex_color'];
					$data['message_flex_ratio'] = $setting['message']['cart']['flex_ratio'];
									
					$item_total = 0;
								
					foreach ($this->cart->getProducts() as $product) {
						$product_price = $this->tax->calculate($product['price'], $product['tax_class_id'], true);
									
						$item_total += $product_price * $product['quantity'];
					}
					
					if (!empty($this->session->data['vouchers'])) {
						foreach ($this->session->data['vouchers'] as $voucher) {
							$item_total += $voucher['amount'];
						}
					}
			
					$data['message_amount'] = number_format($item_total * $data['currency_value'], $data['decimal_place'], '.', '');
				}
			}
			
			if (($this->request->post['page_code'] == 'checkout') && ($this->cart->hasProducts() || !empty($this->session->data['vouchers']))) {
				if (!empty($this->session->data['order_id'])) {
					$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
				}
				
				if ($setting['button']['checkout']['status']) {
					$data['components'][] = 'buttons';
					$data['components'][] = 'funding-eligibility';
					
					$data['button_status'] = $setting['button']['checkout']['status'];
					$data['button_align'] = $setting['button']['checkout']['align'];
					$data['button_size'] = $setting['button']['checkout']['size'];
					$data['button_width'] = $setting['button_width'][$data['button_size']];
					$data['button_color'] = $setting['button']['checkout']['color'];
					$data['button_shape'] = $setting['button']['checkout']['shape'];
					$data['button_label'] = $setting['button']['checkout']['label'];
									
					$data['button_enable_funding'] = [];
					$data['button_disable_funding'] = [];
				
					foreach ($setting['button_funding'] as $button_funding) {
						if ($setting['button']['checkout']['funding'][$button_funding['code']] == 1) {
							$data['button_enable_funding'][] = $button_funding['code'];
						} 
				
						if ($setting['button']['checkout']['funding'][$button_funding['code']] == 2) {
							$data['button_disable_funding'][] = $button_funding['code'];
						}
					}
									
					if (version_compare(VERSION, '4.0.2.0', '>=')) {
						if (isset($this->session->data['payment_method']['code']) && ($this->session->data['payment_method']['code'] == 'paypal.paylater')) {
							$data['button_funding_source'] = 'paylater';
						}
					} else {
						if (isset($this->session->data['payment_method']) && ($this->session->data['payment_method'] == 'paypal_paylater')) {
							$data['button_funding_source'] = 'paylater';
						}
					}
				}
				
				if ($setting['googlepay_button']['checkout']['status']) {
					$data['components'][] = 'googlepay';
					
					$data['googlepay_button_status'] = $setting['googlepay_button']['checkout']['status'];
					$data['googlepay_button_align'] = $setting['googlepay_button']['checkout']['align'];
					$data['googlepay_button_size'] = $setting['googlepay_button']['checkout']['size'];
					$data['googlepay_button_width'] = $setting['googlepay_button_width'][$data['googlepay_button_size']];
					$data['googlepay_button_color'] = $setting['googlepay_button']['checkout']['color'];
					$data['googlepay_button_shape'] = $setting['googlepay_button']['checkout']['shape'];
					$data['googlepay_button_type'] = $setting['googlepay_button']['checkout']['type'];
					
					if (!empty($order_info)) {
						$data['googlepay_amount'] = number_format($order_info['total'] * $data['currency_value'], $data['decimal_place'], '.', '');
					} else {
						$item_total = 0;
								
						foreach ($this->cart->getProducts() as $product) {
							$product_price = $this->tax->calculate($product['price'], $product['tax_class_id'], true);
									
							$item_total += $product_price * $product['quantity'];
						}
						
						if (!empty($this->session->data['vouchers'])) {
							foreach ($this->session->data['vouchers'] as $voucher) {
								$item_total += $voucher['amount'];
							}
						}
			
						$data['googlepay_amount'] = number_format($item_total * $data['currency_value'], $data['decimal_place'], '.', '');
					}
				}
				
				if ($setting['applepay_button']['checkout']['status'] && !empty($this->session->data['paypal']['applepay'])) {
					$data['components'][] = 'applepay';
					
					$data['applepay_button_status'] = $setting['applepay_button']['checkout']['status'];
					$data['applepay_button_align'] = $setting['applepay_button']['checkout']['align'];
					$data['applepay_button_size'] = $setting['applepay_button']['checkout']['size'];
					$data['applepay_button_width'] = $setting['applepay_button_width'][$data['applepay_button_size']];
					$data['applepay_button_color'] = $setting['applepay_button']['checkout']['color'];
					$data['applepay_button_shape'] = $setting['applepay_button']['checkout']['shape'];
					$data['applepay_button_type'] = $setting['applepay_button']['checkout']['type'];
					
					if (!empty($order_info)) {
						$data['applepay_amount'] = number_format($order_info['total'] * $data['currency_value'], $data['decimal_place'], '.', '');
					} else {
						$item_total = 0;
								
						foreach ($this->cart->getProducts() as $product) {
							$product_price = $this->tax->calculate($product['price'], $product['tax_class_id'], true);
									
							$item_total += $product_price * $product['quantity'];
						}
						
						if (!empty($this->session->data['vouchers'])) {
							foreach ($this->session->data['vouchers'] as $voucher) {
								$item_total += $voucher['amount'];
							}
						}
			
						$data['applepay_amount'] = number_format($item_total * $data['currency_value'], $data['decimal_place'], '.', '');
					}
				}
				
				if ($setting['card']['status']) {										
					$data['components'][] = 'card-fields';
					
					$data['card_status'] = $setting['card']['status'];
					$data['card_align'] = $setting['card']['align'];
					$data['card_size'] = $setting['card']['size'];
					$data['card_width'] = $setting['card_width'][$data['card_size']];
					
					$data['card_customer_tokens'] = [];
					
					if ($setting['general']['vault_status'] && $this->customer->isLogged()) {
						$card_customer_tokens = $this->model_extension_paypal_payment_paypal->getPayPalCustomerTokens($this->customer->getId(), 'card');
			
						foreach ($card_customer_tokens as $card_customer_token) {
							$data['card_customer_tokens'][] = [
								'vault_id' => $card_customer_token['vault_id'],
								'card_type' => $card_customer_token['card_type'],
								'card_number' => sprintf($this->language->get('text_card_number'), $card_customer_token['card_nice_type'], $card_customer_token['card_last_digits'])
							];
						}
					}
				}
				
				if ($setting['fastlane']['status'] && ($setting['general']['country_code'] == 'US') && !$this->customer->isLogged()) {
					$data['components'][] = 'fastlane';
					
					$data['fastlane_status'] = $setting['fastlane']['status'];
					$data['fastlane_card_align'] = $setting['fastlane']['card']['align'];
					$data['fastlane_card_size'] = $setting['fastlane']['card']['size'];
					$data['fastlane_card_width'] = $setting['fastlane_card_width'][$data['fastlane_card_size']];
																				
					$data['error_fastlane_billing_address'] = $this->language->get('error_fastlane_billing_address');
				}
				
				if ($setting['message']['checkout']['status'] && ($data['currency_code'] == $setting['general']['currency_code'])) {
					$data['components'][] = 'messages';
					
					$data['message_status'] = $setting['message']['checkout']['status'];
					$data['message_layout'] = $setting['message']['checkout']['layout'];
					$data['message_logo_type'] = $setting['message']['checkout']['logo_type'];
					$data['message_logo_position'] = $setting['message']['checkout']['logo_position'];
					$data['message_text_color'] = $setting['message']['checkout']['text_color'];
					$data['message_text_size'] = $setting['message']['checkout']['text_size'];
					$data['message_flex_color'] = $setting['message']['checkout']['flex_color'];
					$data['message_flex_ratio'] = $setting['message']['checkout']['flex_ratio'];
									
					if (!empty($order_info)) {
						$data['message_amount'] = number_format($order_info['total'] * $data['currency_value'], $data['decimal_place'], '.', '');
					} else {
						$item_total = 0;
								
						foreach ($this->cart->getProducts() as $product) {
							$product_price = $this->tax->calculate($product['price'], $product['tax_class_id'], true);
									
							$item_total += $product_price * $product['quantity'];
						}
						
						if (!empty($this->session->data['vouchers'])) {
							foreach ($this->session->data['vouchers'] as $voucher) {
								$item_total += $voucher['amount'];
							}
						}
			
						$data['message_amount'] = number_format($item_total * $data['currency_value'], $data['decimal_place'], '.', '');
					}
				}
			}
			
			require_once DIR_EXTENSION . 'paypal/system/library/paypal.php';

			$paypal_info = [
				'partner_id' => $data['partner_id'],
				'client_id' => $data['client_id'],
				'secret' => $secret,
				'environment' => $data['environment'],
				'partner_attribution_id' => $data['partner_attribution_id']
			];
			
			if (isset($this->session->data['paypal_client_metadata_id'])) {
				$paypal_info['client_metadata_id'] = $this->session->data['paypal_client_metadata_id'];
			}
		
			$paypal = new \Opencart\System\Library\PayPal($paypal_info);
		
			$token_info = [
				'grant_type' => 'client_credentials'
			];	
				
			if ($setting['general']['vault_status'] && $this->customer->isLogged()) {
				$paypal_customer_token = $this->model_extension_paypal_payment_paypal->getPayPalCustomerMainToken($this->customer->getId(), 'paypal');
				
				if (!empty($paypal_customer_token['vault_customer_id'])) {
					$token_info['response_type'] = 'id_token';
					$token_info['target_customer_id'] = $paypal_customer_token['vault_customer_id'];
				}
			}
			
			if (($this->request->post['page_code'] == 'checkout') && $setting['fastlane']['status'] && ($setting['general']['country_code'] == 'US') && !$this->customer->isLogged()) {
				$token_info['response_type'] = 'client_token';
				$token_info['intent'] = 'sdk_init';
				$token_info['domains'][] = $this->request->server['HTTP_HOST'];
			}
							
			$result = $paypal->setAccessToken($token_info);
			
			if ($setting['general']['vault_status'] && !empty($result['id_token'])) {
				$data['id_token'] = $result['id_token'];
				
				$data['client_token'] = $paypal->getClientToken();
			}
			
			if (($this->request->post['page_code'] == 'checkout') && $setting['fastlane']['status'] && ($setting['general']['country_code'] == 'US') && !$this->customer->isLogged()) {
				$data['sdk_client_token'] = $paypal->getAccessToken();
				$data['client_metadata_id'] = $paypal->getToken();
				
				$this->session->data['paypal_client_metadata_id'] = $data['client_metadata_id'];
			}
						
			if ($paypal->hasErrors()) {
				$error_messages = [];
				
				$errors = $paypal->getErrors();
								
				foreach ($errors as $error) {
					if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
						$error['message'] = $this->language->get('error_timeout');
					}
				
					if (isset($error['details'][0]['description'])) {
						$error_messages[] = $error['details'][0]['description'];
					} elseif (isset($error['message'])) {
						$error_messages[] = $error['message'];
					}
									
					$this->model_extension_paypal_payment_paypal->log($error, $error['message']);
				}
				
				$this->error['warning'] = implode(' ', $error_messages);
			}

			if (!empty($this->error['warning'])) {
				$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
			}
		}
		
		$data['separator'] = $this->separator;
						
		$data['error'] = $this->error;
		
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));		
	}
		
	public function createOrder(): void {					
		$this->load->language('extension/paypal/payment/paypal');
		
		$this->load->model('extension/paypal/payment/paypal');
				
		if (!empty($this->request->post['page_code']) && !empty($this->request->post['payment_type'])) {
			$page_code = $this->request->post['page_code'];
			$payment_type = $this->request->post['payment_type'];
			
			$payment_method = '';
					
			if ($payment_type == 'button') {
				$payment_method = 'paypal';
			}
					
			if ($payment_type == 'card') {
				$payment_method = 'card';
			}
			
			$errors = [];
		
			$data['paypal_order_id'] = '';
			$data['url'] = '';
			
			if (!empty($this->request->post['product'])) {
				$this->request->post['product'] = $this->unserialize($this->request->post['product']);
			}
			
			if (($page_code == 'product') && (!empty($this->request->post['product']['product_id']))) {
				$product = $this->request->post['product'];
				$product_id = (int)$product['product_id'];
		
				$this->load->model('catalog/product');

				$product_info = $this->model_catalog_product->getProduct($product_id);

				if ($product_info) {
					if (isset($product['quantity'])) {
						$quantity = (int)$product['quantity'];
					} else {
						$quantity = 1;
					}

					if (isset($product['option'])) {
						$option = array_filter($product['option']);
					} else {
						$option = [];
					}

					$product_options = $this->model_catalog_product->getOptions($product_id);

					foreach ($product_options as $product_option) {
						if ($product_option['required'] && empty($option[$product_option['product_option_id']])) {
							$errors[] = sprintf($this->language->get('error_required'), $product_option['name']);
						}
					}
				
					if (isset($product['subscription_plan_id'])) {
						$subscription_plan_id = (int)$product['subscription_plan_id'];
					} else {
						$subscription_plan_id = 0;
					}

					$subscriptions = $this->model_catalog_product->getSubscriptions($product_info['product_id']);

					if ($subscriptions) {
						$subscription_plan_ids = [];

						foreach ($subscriptions as $subscription) {
							$subscription_plan_ids[] = $subscription['subscription_plan_id'];
						}

						if (!in_array($subscription_plan_id, $subscription_plan_ids)) {
							$errors[] = $this->language->get('error_subscription');
						}
					}
					
					if (!$errors) {					
						if (!$this->model_extension_paypal_payment_paypal->hasProductInCart($product_id, $option, $subscription_plan_id)) {
							$this->cart->add($product_id, $quantity, $option, $subscription_plan_id);
						}
																
						// Unset all shipping and payment methods
						unset($this->session->data['shipping_method']);
						unset($this->session->data['shipping_methods']);
						unset($this->session->data['payment_method']);
						unset($this->session->data['payment_methods']);
					}					
				}
			}
		
			if ($page_code == 'checkout') {
				$this->load->model('checkout/order');
				
				$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
				
				$shipping_info = [];

				if ($this->cart->hasShipping()) {
					$shipping_info['name']['full_name'] = $order_info['shipping_firstname'];
					$shipping_info['name']['full_name'] .= ($order_info['shipping_lastname'] ? (' ' . $order_info['shipping_lastname']) : '');			
					$shipping_info['address']['address_line_1'] = $order_info['shipping_address_1'];
					$shipping_info['address']['address_line_2'] = $order_info['shipping_address_2'];			
					$shipping_info['address']['admin_area_1'] = $order_info['shipping_zone'];
					$shipping_info['address']['admin_area_2'] = $order_info['shipping_city'];
					$shipping_info['address']['postal_code'] = $order_info['shipping_postcode'];
			
					if ($order_info['shipping_country_id']) {
						$country_info = $this->model_extension_paypal_payment_paypal->getCountry($order_info['shipping_country_id']);
			
						if ($country_info) {
							$shipping_info['address']['country_code'] = $country_info['iso_code_2'];
						}
					}
				}
			}

			if (!$errors) {					
				$_config = new \Opencart\System\Engine\Config();
				$_config->addPath(DIR_EXTENSION . 'paypal/system/config/');
				$_config->load('paypal');
			
				$config_setting = $_config->get('paypal_setting');
		
				$setting = array_replace_recursive((array)$config_setting, (array)$this->config->get('payment_paypal_setting'));
		
				$client_id = $this->config->get('payment_paypal_client_id');
				$secret = $this->config->get('payment_paypal_secret');
				$merchant_id = $this->config->get('payment_paypal_merchant_id');
				$environment = $this->config->get('payment_paypal_environment');
				$partner_id = $setting['partner'][$environment]['partner_id'];
				$partner_attribution_id = $setting['partner'][$environment]['partner_attribution_id'];
				$vault_status = $setting['general']['vault_status'];
				$transaction_method = $setting['general']['transaction_method'];			
						
				$currency_code = $this->session->data['currency'];
				$currency_value = $this->currency->getValue($this->session->data['currency']);
				
				if ((($payment_type == 'button') || ($payment_type == 'googlepay_button') || ($payment_type == 'applepay_button')) && empty($setting['currency'][$currency_code]['status'])) {
					$currency_code = $setting['general']['currency_code'];
					$currency_value = $setting['general']['currency_value'];
				}

				if (($payment_type == 'card') && empty($setting['currency'][$currency_code]['card_status'])) {
					$currency_code = $setting['general']['card_currency_code'];
					$currency_value = $setting['general']['card_currency_value'];
				}
				
				$decimal_place = $setting['currency'][$currency_code]['decimal_place'];
				
				require_once DIR_EXTENSION . 'paypal/system/library/paypal.php';

				$paypal_info = [
					'partner_id' => $partner_id,
					'client_id' => $client_id,
					'secret' => $secret,
					'environment' => $environment,
					'partner_attribution_id' => $partner_attribution_id
				];
				
				if (isset($this->session->data['paypal_client_metadata_id'])) {
					$paypal_info['client_metadata_id'] = $this->session->data['paypal_client_metadata_id'];
				}
		
				$paypal = new \Opencart\System\Library\PayPal($paypal_info);
			
				$token_info = [
					'grant_type' => 'client_credentials'
				];	
				
				$paypal->setAccessToken($token_info);
								
				$item_info = [];
			
				$item_total = 0;
				$tax_total = 0;
								
				foreach ($this->cart->getProducts() as $product) {
					$product_price = number_format($product['price'] * $currency_value, $decimal_place, '.', '');
				
					$product_info = [];
					
					$product_info['name'] = $product['name'];
					$product_info['quantity'] = $product['quantity'];
					$product_info['sku'] = $product['model'];
					$product_info['url'] = $this->url->link('product/product', 'language=' . $this->config->get('config_language') . '&product_id=' . $product['product_id']);
										
					$product_info['unit_amount'] = [
						'currency_code' => $currency_code,
						'value' => $product_price
					];

					$item_info[] = $product_info;
				
					$item_total += $product_price * $product['quantity'];
				
					if ($product['tax_class_id']) {
						$tax_rates = $this->tax->getRates($product['price'], $product['tax_class_id']);

						foreach ($tax_rates as $tax_rate) {
							$tax_total += ($tax_rate['amount'] * $product['quantity']);
						}
					}
				}
				
				if (!empty($this->session->data['vouchers'])) {
					foreach ($this->session->data['vouchers'] as $voucher) {
						$voucher_info = [];
	
						$voucher_info['name'] = $voucher['description'];
						$voucher_info['quantity'] = 1;
						
						$voucher_info['unit_amount'] = [
							'currency_code' => $currency_code,
							'value' => $voucher['amount']
						];
												
						$item_info[] = $voucher_info;
					
						$item_total += $voucher['amount'];
					}
				}
				
				$item_total = number_format($item_total, $decimal_place, '.', '');
				$tax_total = number_format($tax_total * $currency_value, $decimal_place, '.', '');
				$order_total = number_format($item_total + $tax_total, $decimal_place, '.', '');
				
				if ($page_code == 'checkout') {
					$discount_total = 0;
					$handling_total = 0;
					$shipping_total = 0;
		
					if (isset($this->session->data['shipping_method'])) {
						if (version_compare(VERSION, '4.0.2.0', '>=')) {
							$shipping = explode('.', $this->session->data['shipping_method']['code']);
						} else {
							$shipping = explode('.', $this->session->data['shipping_method']);
						}

						if (isset($shipping[0]) && isset($shipping[1]) && isset($this->session->data['shipping_methods'][$shipping[0]]['quote'][$shipping[1]])) {
							$shipping_method_info = $this->session->data['shipping_methods'][$shipping[0]]['quote'][$shipping[1]];
					
							$shipping_total = $this->tax->calculate($shipping_method_info['cost'], $shipping_method_info['tax_class_id'], true);
							$shipping_total = number_format($shipping_total * $currency_value, $decimal_place, '.', '');
						}
					}
		
					$order_total = number_format($order_info['total'] * $currency_value, $decimal_place, '.', '');
		
					$rebate = number_format($item_total + $tax_total + $shipping_total - $order_total, $decimal_place, '.', '');
		
					if ($rebate > 0) {
						$discount_total = $rebate;
					} elseif ($rebate < 0) {
						$handling_total = -$rebate;
					}
				} 
						
				$amount_info = [];
				
				$amount_info['currency_code'] = $currency_code;
				$amount_info['value'] = $order_total;
								
				$amount_info['breakdown']['item_total'] = [
					'currency_code' => $currency_code,
					'value' => $item_total
				];
				
				$amount_info['breakdown']['tax_total'] = [
					'currency_code' => $currency_code,
					'value' => $tax_total
				];
				
				if ($page_code == 'checkout') {
					$amount_info['breakdown']['shipping'] = [
						'currency_code' => $currency_code,
						'value' => $shipping_total
					];
					
					$amount_info['breakdown']['handling'] = [
						'currency_code' => $currency_code,
						'value' => $handling_total
					];
					
					$amount_info['breakdown']['discount'] = [
						'currency_code' => $currency_code,
						'value' => $discount_total
					];
				}
				
				$paypal_order_info = [];
				
				$paypal_order_info['intent'] = strtoupper($transaction_method);
				$paypal_order_info['purchase_units'][0]['reference_id'] = 'default';
				$paypal_order_info['purchase_units'][0]['items'] = $item_info;
				$paypal_order_info['purchase_units'][0]['amount'] = $amount_info;
				
				if ($page_code == 'checkout') {
					$paypal_order_info['purchase_units'][0]['description'] = 'Your order ' . $order_info['order_id'];
				
					if ($setting['general']['invoice_id_tokenization_status']) {
						$paypal_order_info['purchase_units'][0]['invoice_id'] = $order_info['order_id'] . '_' . date('Ymd_His');
					} else {
						$paypal_order_info['purchase_units'][0]['invoice_id'] = $order_info['order_id'];
					}
					
					if ($this->cart->hasShipping()) {
						$paypal_order_info['purchase_units'][0]['shipping'] = $shipping_info;
					}
				}
				
				if ($this->cart->hasShipping()) {			
					$shipping_preference = 'GET_FROM_FILE';
				} else {
					$shipping_preference = 'NO_SHIPPING';
				}
	
				$paypal_order_info['application_context']['shipping_preference'] = $shipping_preference;
				
				if ($setting['general']['vault_status'] && ($this->customer->isLogged() || $this->cart->hasSubscription())) {
					if ($payment_method == 'paypal') {
						$paypal_customer_token = [];
						
						if ($this->customer->isLogged()) {
							$paypal_customer_token = $this->model_extension_paypal_payment_paypal->getPayPalCustomerMainToken($this->customer->getId(), $payment_method);
						}
				
						if (empty($paypal_customer_token['vault_id'])) {
							$paypal_order_info['payment_source'][$payment_method]['attributes']['vault'] = [
								'permit_multiple_payment_tokens' => 'false',
								'store_in_vault' => 'ON_SUCCESS',
								'usage_type' => 'MERCHANT',
								'customer_type' => 'CONSUMER'
							];
						}
					}

					if ($payment_method == 'card') {
						if (isset($this->request->post['index'])) {
							$card_token_index = $this->request->post['index'];
							
							$card_customer_tokens = $this->model_extension_paypal_payment_paypal->getPayPalCustomerTokens($this->customer->getId(), $payment_method);
							
							if (!empty($card_customer_tokens[$card_token_index]['vault_id'])) {
								$paypal_order_info['payment_source'][$payment_method]['vault_id'] = $card_customer_tokens[$card_token_index]['vault_id'];							
								$paypal_order_info['payment_source'][$payment_method]['stored_credential']['payment_initiator'] = 'CUSTOMER';
								$paypal_order_info['payment_source'][$payment_method]['stored_credential']['payment_type'] = 'ONE_TIME';
								$paypal_order_info['payment_source'][$payment_method]['stored_credential']['usage'] = 'SUBSEQUENT';
							}
						} else {
							if (!empty($this->request->post['card_save']) || $this->cart->hasSubscription()) {
								$paypal_order_info['payment_source'][$payment_method]['attributes']['vault']['store_in_vault'] = 'ON_SUCCESS';								
								$paypal_order_info['payment_source'][$payment_method]['stored_credential']['payment_initiator'] = 'CUSTOMER';
								$paypal_order_info['payment_source'][$payment_method]['stored_credential']['usage'] = 'FIRST';
								
								if ($this->cart->hasSubscription()) {
									$paypal_order_info['payment_source'][$payment_method]['stored_credential']['payment_type'] = 'UNSCHEDULED';
								} else {
									$paypal_order_info['payment_source'][$payment_method]['stored_credential']['payment_type'] = 'ONE_TIME';
								}
							}
						}
					}
				}
				
				if ($payment_method) {
					$paypal_order_info['payment_source'][$payment_method]['attributes']['verification']['method'] = strtoupper($setting['card']['secure_method']);
					$paypal_order_info['payment_source'][$payment_method]['experience_context']['return_url'] = $this->url->link('extension/paypal/payment/paypal', 'callback_token=' . $setting['general']['callback_token'] . '&language=' . $this->config->get('config_language'), true);
					$paypal_order_info['payment_source'][$payment_method]['experience_context']['cancel_url'] = $this->url->link('checkout/checkout', 'language=' . $this->config->get('config_language'), true);
				}
								
				$result = $paypal->createOrder($paypal_order_info);
			
				if ($paypal->hasErrors()) {
					$error_messages = [];
				
					$errors = $paypal->getErrors();
								
					foreach ($errors as $error) {
						if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
							$error['message'] = $this->language->get('error_timeout');
						}
				
						if (isset($error['details'][0]['description'])) {
							$error_messages[] = $error['details'][0]['description'];
						} elseif (isset($error['message'])) {
							$error_messages[] = $error['message'];
						}
					
						$this->model_extension_paypal_payment_paypal->log($error, $error['message']);
					}
				
					$this->error['warning'] = implode(' ', $error_messages);
				}
		
				if (!empty($this->error['warning'])) {
					$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
				}
						
				if (isset($result['id']) && isset($result['status']) && !$this->error) {
					$this->model_extension_paypal_payment_paypal->log($result, 'Create Order');
			
					if ($result['status'] == 'VOIDED') {
						$this->error['warning'] = sprintf($this->language->get('error_order_voided'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
					}
			
					if (($result['status'] == 'COMPLETED') && empty($paypal_order_info['payment_source']['card']['vault_id'])) {
						$this->error['warning'] = sprintf($this->language->get('error_order_completed'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
					}
					
					if (($result['status'] == 'COMPLETED') && !empty($paypal_order_info['payment_source']['card']['vault_id'])) {
						$paypal_order_id = $result['id'];
						
						$vault_id = $card_customer_tokens[$card_token_index]['vault_id'];
						$vault_customer_id = $card_customer_tokens[$card_token_index]['vault_customer_id'];
						$card_type = $card_customer_tokens[$card_token_index]['card_type'];
						$card_nice_type = $card_customer_tokens[$card_token_index]['card_nice_type'];
						$card_last_digits = $card_customer_tokens[$card_token_index]['card_last_digits'];
						$card_expiry = $card_customer_tokens[$card_token_index]['card_expiry'];
					
						$paypal_order_info = $paypal->getOrder($paypal_order_id);
												
						if ($paypal->hasErrors()) {
							$error_messages = array();
				
							$errors = $paypal->getErrors();
								
							foreach ($errors as $error) {
								if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
									$error['message'] = $this->language->get('error_timeout');
								}
					
								if (isset($error['details'][0]['description'])) {
									$error_messages[] = $error['details'][0]['description'];
								} elseif (isset($error['message'])) {
									$error_messages[] = $error['message'];
								}
					
								$this->model_extension_paypal_payment_paypal->log($error, $error['message']);
							}
		
							$this->error['warning'] = implode(' ', $error_messages);
						}
							
						if (isset($paypal_order_info['payment_source']['card']) && !$this->error) {
							$this->model_extension_paypal_payment_paypal->log($paypal_order_info['payment_source']['card'], 'Card');
						
							$liability_shift = (isset($paypal_order_info['payment_source']['card']['authentication_result']['liability_shift']) ? $paypal_order_info['payment_source']['card']['authentication_result']['liability_shift'] : '');
							$enrollment_status = (isset($paypal_order_info['payment_source']['card']['authentication_result']['three_d_secure']['enrollment_status']) ? $paypal_order_info['payment_source']['card']['authentication_result']['three_d_secure']['enrollment_status'] : '');
							$authentication_status = (isset($paypal_order_info['payment_source']['card']['authentication_result']['three_d_secure']['authentication_status']) ? $paypal_order_info['payment_source']['card']['authentication_result']['three_d_secure']['authentication_status'] : '');
								
							if ($enrollment_status == 'Y') {
								if (($authentication_status == 'N') && !$setting['card']['secure_scenario']['failed_authentication']) {
									$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['failed_authentication']['error']);
								}
						
								if (($authentication_status == 'R') && !$setting['card']['secure_scenario']['rejected_authentication']) {
									$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['rejected_authentication']['error']);
								}
						
								if (($authentication_status == 'A') && !$setting['card']['secure_scenario']['attempted_authentication']) {
									$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['attempted_authentication']['error']);
								}
						
								if (($authentication_status == 'U') && !$setting['card']['secure_scenario']['unable_authentication']) {
									$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['unable_authentication']['error']);
								}
						
								if (($authentication_status == 'C') && !$setting['card']['secure_scenario']['challenge_authentication']) {
									$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['challenge_authentication']['error']);
								}
							}
					
							if (($enrollment_status == 'N') && !$setting['card']['secure_scenario']['card_ineligible']) {
								$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['card_ineligible']['error']);
							}
					
							if (($enrollment_status == 'U') && !$setting['card']['secure_scenario']['system_unavailable']) {
								$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['system_unavailable']['error']);
							}
					
							if (($enrollment_status == 'B') && !$setting['card']['secure_scenario']['system_bypassed']) {
								$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['system_bypassed']['error']);
							}
						}
		
						if (!empty($this->error['warning'])) {
							$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
						}
				
						if (!$this->error) {				
							$this->load->model('checkout/order');
				
							$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
						
							if ($transaction_method == 'authorize') {
								$this->model_extension_paypal_payment_paypal->log($result, 'Authorize Order');
					
								if (isset($result['purchase_units'][0]['payments']['authorizations'][0]['status']) && isset($result['purchase_units'][0]['payments']['authorizations'][0]['seller_protection']['status'])) {
									$authorization_id = $result['purchase_units'][0]['payments']['authorizations'][0]['id'];
									$authorization_status = $result['purchase_units'][0]['payments']['authorizations'][0]['status'];
									$seller_protection_status = $result['purchase_units'][0]['payments']['authorizations'][0]['seller_protection']['status'];							
									$order_status_id = 0;
									$transaction_status = '';
									$payment_method = 'card';
								
									if (!$this->cart->hasShipping()) {
										$seller_protection_status = 'NOT_ELIGIBLE';
									}
								
									if ($authorization_status == 'CREATED') {
										$order_status_id = $setting['order_status']['pending']['id'];
										$transaction_status = 'created';
									}

									if ($authorization_status == 'CAPTURED') {
										$this->error['warning'] = sprintf($this->language->get('error_authorization_captured'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
									}
						
									if ($authorization_status == 'DENIED') {
										$transaction_status = 'denied';
							
										$this->error['warning'] = $this->language->get('error_authorization_denied');
									}
						
									if ($authorization_status == 'EXPIRED') {
										$this->error['warning'] = sprintf($this->language->get('error_authorization_expired'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
									}
						
									if ($authorization_status == 'PENDING') {
										$order_status_id = $setting['order_status']['pending']['id'];
										$transaction_status = 'pending';
									}
														
									if (($authorization_status == 'CREATED') || ($authorization_status == 'DENIED') || ($authorization_status == 'PENDING')) {
										$this->model_extension_paypal_payment_paypal->deletePayPalOrder($this->session->data['order_id']);
									
										$paypal_order_data = array(
											'order_id' => $this->session->data['order_id'],
											'paypal_order_id' => $paypal_order_id,
											'transaction_id' => $authorization_id,
											'transaction_status' => $transaction_status,
											'payment_method' => $payment_method,
											'vault_id' => $vault_id,
											'vault_customer_id' => $vault_customer_id,
											'card_type' => $card_type,
											'card_nice_type' => $card_nice_type,
											'card_last_digits' => $card_last_digits,
											'card_expiry' => $card_expiry,
											'total' => $order_info['total'],
											'currency_code' => $order_info['currency_code'],
											'environment' => $environment
										);

										$this->model_extension_paypal_payment_paypal->addPayPalOrder($paypal_order_data);
									
										if ($vault_id && $this->customer->isLogged()) {
											$customer_id = $this->customer->getId();
										
											$paypal_customer_token_info = $this->model_extension_paypal_payment_paypal->getPayPalCustomerToken($customer_id, $payment_method, $vault_id);
								
											if (!$paypal_customer_token_info) {
												$paypal_customer_token_data = array(
													'customer_id' => $customer_id,
													'payment_method' => $payment_method,
													'vault_id' => $vault_id,
													'vault_customer_id' => $vault_customer_id,
													'card_type' => $card_type,
													'card_nice_type' => $card_nice_type,
													'card_last_digits' => $card_last_digits,
													'card_expiry' => $card_expiry
												);
					
												$this->model_extension_paypal_payment_paypal->addPayPalCustomerToken($paypal_customer_token_data);
											}
										
											$this->model_extension_paypal_payment_paypal->setPayPalCustomerMainToken($customer_id, $payment_method, $vault_id);
										}
									}
									
									if ($order_status_id) {
										$message = sprintf($this->language->get('text_order_message'), $seller_protection_status);
								
										$this->model_checkout_order->addHistory($this->session->data['order_id'], $order_status_id, $message);
									}
								
									if (($authorization_status == 'CREATED') || ($authorization_status == 'PENDING')) {										
										$subscriptions = $this->model_extension_paypal_payment_paypal->getSubscriptionsByOrderId($this->session->data['order_id']);
					
										foreach ($subscriptions as $subscription) {
											$this->model_extension_paypal_payment_paypal->subscriptionPayment($subscription, $order_info, $paypal_order_data);
										} 
									}
													
									if (($authorization_status == 'CREATED') || ($authorization_status == 'PARTIALLY_CAPTURED') || ($authorization_status == 'PARTIALLY_CREATED') || ($authorization_status == 'PENDING')) {
										$data['url'] = $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'));
									}
								}
							} else {
								$this->model_extension_paypal_payment_paypal->log($result, 'Capture Order');
					
								if (isset($result['purchase_units'][0]['payments']['captures'][0]['status']) && isset($result['purchase_units'][0]['payments']['captures'][0]['seller_protection']['status'])) {
									$capture_id = $result['purchase_units'][0]['payments']['captures'][0]['id'];
									$capture_status = $result['purchase_units'][0]['payments']['captures'][0]['status'];
									$seller_protection_status = $result['purchase_units'][0]['payments']['captures'][0]['seller_protection']['status'];
									
									$order_status_id = 0;
									$transaction_status = '';
									$payment_method = 'card';
																	
									if (!$this->cart->hasShipping()) {
										$seller_protection_status = 'NOT_ELIGIBLE';
									}
																						
									if ($capture_status == 'COMPLETED') {
										$order_status_id = $setting['order_status']['completed']['id'];
										$transaction_status = 'completed';
									}
						
									if ($capture_status == 'DECLINED') {
										$transaction_status = 'denied';
							
										$this->error['warning'] = $this->language->get('error_capture_declined');
									}
						
									if ($capture_status == 'FAILED') {
										$this->error['warning'] = sprintf($this->language->get('error_capture_failed'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
									}
						
									if ($capture_status == 'PENDING') {
										$order_status_id = $setting['order_status']['pending']['id'];
										$transaction_status = 'pending';
									}
														
									if (($capture_status == 'COMPLETED') || ($capture_status == 'DECLINED') || ($capture_status == 'PENDING')) {
										$this->model_extension_paypal_payment_paypal->deletePayPalOrder($this->session->data['order_id']);
									
										$paypal_order_data = array(
											'order_id' => $this->session->data['order_id'],
											'paypal_order_id' => $paypal_order_id,
											'transaction_id' => $capture_id,
											'transaction_status' => $transaction_status,
											'payment_method' => $payment_method,
											'vault_id' => $vault_id,
											'vault_customer_id' => $vault_customer_id,
											'card_type' => $card_type,
											'card_nice_type' => $card_nice_type,
											'card_last_digits' => $card_last_digits,
											'card_expiry' => $card_expiry,
											'total' => $order_info['total'],
											'currency_code' => $order_info['currency_code'],
											'environment' => $environment
										);

										$this->model_extension_paypal_payment_paypal->addPayPalOrder($paypal_order_data);
									
										if ($vault_id && $this->customer->isLogged()) {
											$customer_id = $this->customer->getId();
										
											$paypal_customer_token_info = $this->model_extension_paypal_payment_paypal->getPayPalCustomerToken($customer_id, $payment_method, $vault_id);
								
											if (!$paypal_customer_token_info) {
												$paypal_customer_token_data = array(
													'customer_id' => $customer_id,
													'payment_method' => $payment_method,
													'vault_id' => $vault_id,
													'vault_customer_id' => $vault_customer_id,
													'card_type' => $card_type,
													'card_nice_type' => $card_nice_type,
													'card_last_digits' => $card_last_digits,
													'card_expiry' => $card_expiry
												);
					
												$this->model_extension_paypal_payment_paypal->addPayPalCustomerToken($paypal_customer_token_data);
											}
										
											$this->model_extension_paypal_payment_paypal->setPayPalCustomerMainToken($customer_id, $payment_method, $vault_id);
										}
									}
									
									if ($order_status_id) {
										$message = sprintf($this->language->get('text_order_message'), $seller_protection_status);
								
										$this->model_checkout_order->addHistory($this->session->data['order_id'], $order_status_id, $message);
									}
								
									if (($capture_status == 'COMPLETED') || ($capture_status == 'PENDING')) {										
										$subscriptions = $this->model_extension_paypal_payment_paypal->getSubscriptionsByOrderId($this->session->data['order_id']);
					
										foreach ($subscriptions as $subscription) {
											$this->model_extension_paypal_payment_paypal->subscriptionPayment($subscription, $order_info, $paypal_order_data);
										} 
									}
						
									if (($capture_status == 'COMPLETED') || ($capture_status == 'PENDING')) {
										$data['url'] = $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'));
									}
								}
							}
						}
					}
					
					if (($result['status'] == 'PAYER_ACTION_REQUIRED') && !empty($paypal_order_info['payment_source']['card']['vault_id'])) {
						foreach ($result['links'] as $link) {
							if ($link['rel'] == 'payer-action') {
								$data['url'] = $link['href'];
								
								$this->session->data['paypal_order_id'] = $result['id'];
								$this->session->data['paypal_card_token_index'] = $card_token_index;
							}
						}
					}
			
					if (!$this->error) {
						$data['paypal_order_id'] = $result['id'];
					}
				}
			} else {
				$this->error['warning'] = implode(' ', $errors);
			}
		}
				
		$data['language'] = $this->config->get('config_language');
							
		$data['error'] = $this->error;
				
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}
	
	public function approveOrder(): void {
		$this->load->language('extension/paypal/payment/paypal');
		
		$this->load->model('extension/paypal/payment/paypal');
		
		if (!empty($this->request->post['page_code']) && !empty($this->request->post['payment_type'])) {
			$page_code = $this->request->post['page_code'];
			$payment_type = $this->request->post['payment_type'];
			
			if ($page_code != 'checkout') {
				if (isset($this->request->post['paypal_order_id'])) {
					$this->session->data['paypal_order_id'] = $this->request->post['paypal_order_id'];
				} else {	
					$data['url'] = $this->url->link('checkout/cart', 'language=' . $this->config->get('config_language'));
			
					$this->response->addHeader('Content-Type: application/json');
					$this->response->setOutput(json_encode($data));
				}
		
				// check checkout can continue due to stock checks or vouchers
				if ((!$this->cart->hasProducts() && empty($this->session->data['vouchers'])) || (!$this->cart->hasStock() && !$this->config->get('config_stock_checkout'))) {
					$data['url'] = $this->url->link('checkout/cart', 'language=' . $this->config->get('config_language'));
			
					$this->response->addHeader('Content-Type: application/json');
					$this->response->setOutput(json_encode($data));
				}

				// if user not logged in check that the guest checkout is allowed
				if (!$this->customer->isLogged() && (!$this->config->get('config_checkout_guest') || $this->config->get('config_customer_price') || $this->cart->hasDownload() || $this->cart->hasSubscription())) {
					$data['url'] = $this->url->link('checkout/cart', 'language=' . $this->config->get('config_language'));
			
					$this->response->addHeader('Content-Type: application/json');
					$this->response->setOutput(json_encode($data));
				}
			}
			
			$_config = new \Opencart\System\Engine\Config();
			$_config->addPath(DIR_EXTENSION . 'paypal/system/config/');
			$_config->load('paypal');
			
			$config_setting = $_config->get('paypal_setting');
		
			$setting = array_replace_recursive((array)$config_setting, (array)$this->config->get('payment_paypal_setting'));
				
			$client_id = $this->config->get('payment_paypal_client_id');
			$secret = $this->config->get('payment_paypal_secret');
			$environment = $this->config->get('payment_paypal_environment');
			$partner_id = $setting['partner'][$environment]['partner_id'];
			$partner_attribution_id = $setting['partner'][$environment]['partner_attribution_id'];
			$vault_status = $setting['general']['vault_status'];
			$transaction_method = $setting['general']['transaction_method'];
			
			require_once DIR_EXTENSION . 'paypal/system/library/paypal.php';
		
			$paypal_info = [
				'partner_id' => $partner_id,
				'client_id' => $client_id,
				'secret' => $secret,
				'environment' => $environment,
				'partner_attribution_id' => $partner_attribution_id
			];
			
			if (isset($this->session->data['paypal_client_metadata_id'])) {
				$paypal_info['client_metadata_id'] = $this->session->data['paypal_client_metadata_id'];
			}
		
			$paypal = new \Opencart\System\Library\PayPal($paypal_info);
		
			$token_info = [
				'grant_type' => 'client_credentials'
			];	
						
			$paypal->setAccessToken($token_info);
			
			if ($page_code != 'checkout') {
				$paypal_order_id = $this->session->data['paypal_order_id'];
				
				$paypal_order_info = $paypal->getOrder($paypal_order_id);
				
				if ($paypal->hasErrors()) {
					$error_messages = [];
				
					$errors = $paypal->getErrors();
								
					foreach ($errors as $error) {
						if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
							$error['message'] = $this->language->get('error_timeout');
						}
					
						if (isset($error['details'][0]['description'])) {
							$error_messages[] = $error['details'][0]['description'];
						} elseif (isset($error['message'])) {
							$error_messages[] = $error['message'];
						}
					
						$this->model_extension_paypal_payment_paypal->log($error, $error['message']);
					}
				
					$this->error['warning'] = implode(' ', $error_messages);
				}
		
				if (!empty($this->error['warning'])) {
					$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
				}
		
				if ($paypal_order_info && !$this->error) {
					$this->load->model('account/customer');
					$this->load->model('account/address');
			
					unset($this->session->data['shipping_method']);
					unset($this->session->data['shipping_methods']);
					unset($this->session->data['payment_method']);
					unset($this->session->data['payment_methods']);
			
					if ($this->customer->isLogged()) {
						$customer_info = $this->model_account_customer->getCustomer($this->customer->getId());

						$this->session->data['customer']['customer_id'] = $customer_info['customer_id'];
						$this->session->data['customer']['customer_group_id'] = $customer_info['customer_group_id'];
						$this->session->data['customer']['firstname'] = $customer_info['firstname'];
						$this->session->data['customer']['lastname'] = $customer_info['lastname'];
						$this->session->data['customer']['email'] = $customer_info['email'];
						$this->session->data['customer']['telephone'] = $customer_info['telephone'];
						$this->session->data['customer']['custom_field'] = json_decode($customer_info['custom_field'], true);
					} else {
						$this->session->data['customer']['customer_id'] = 0;
						$this->session->data['customer']['customer_group_id'] = $this->config->get('config_customer_group_id');
						$this->session->data['customer']['firstname'] = (isset($paypal_order_info['payer']['name']['given_name']) ? $paypal_order_info['payer']['name']['given_name'] : '');
						$this->session->data['customer']['lastname'] = (isset($paypal_order_info['payer']['name']['surname']) ? $paypal_order_info['payer']['name']['surname'] : '');
						$this->session->data['customer']['email'] = (isset($paypal_order_info['payer']['email_address']) ? $paypal_order_info['payer']['email_address'] : '');
						$this->session->data['customer']['telephone'] = (isset($paypal_order_info['payer']['phone']['phone_number']['national_number']) ? $paypal_order_info['payer']['phone']['phone_number']['national_number'] : '');
						$this->session->data['customer']['custom_field'] = [];
					}
								
					if ($this->customer->isLogged() && $this->customer->getAddressId()) {
						if (version_compare(VERSION, '4.0.2.0', '>=')) {
							$this->session->data['payment_address'] = $this->model_account_address->getAddress($this->session->data['customer']['customer_id'], $this->customer->getAddressId());
						} else {
							$this->session->data['payment_address'] = $this->model_account_address->getAddress($this->customer->getAddressId());
						}
					} else {
						$this->session->data['payment_address']['address_id'] = 0;
						$this->session->data['payment_address']['firstname'] = (isset($paypal_order_info['payer']['name']['given_name']) ? $paypal_order_info['payer']['name']['given_name'] : '');
						$this->session->data['payment_address']['lastname'] = (isset($paypal_order_info['payer']['name']['surname']) ? $paypal_order_info['payer']['name']['surname'] : '');
						$this->session->data['payment_address']['company'] = '';
						$this->session->data['payment_address']['address_1'] = '';
						$this->session->data['payment_address']['address_2'] = '';
						$this->session->data['payment_address']['city'] = '';
						$this->session->data['payment_address']['postcode'] = '';
						$this->session->data['payment_address']['country'] = '';
						$this->session->data['payment_address']['country_id'] = 0;
						$this->session->data['payment_address']['address_format'] = '';
						$this->session->data['payment_address']['zone'] = '';
						$this->session->data['payment_address']['zone_id'] = 0;
						$this->session->data['payment_address']['custom_field'] = [];
			
						if (isset($paypal_order_info['payer']['address']['country_code'])) {
							$country_info = $this->model_extension_paypal_payment_paypal->getCountryByCode($paypal_order_info['payer']['address']['country_code']);
			
							if ($country_info) {
								$this->session->data['payment_address']['country'] = $country_info['name'];
								$this->session->data['payment_address']['country_id'] = $country_info['country_id'];
								$this->session->data['payment_address']['address_format'] = $country_info['address_format'];
							}
						}
					}
				
					if ($this->cart->hasShipping()) {
						if ($this->customer->isLogged() && $this->customer->getAddressId()) {
							if (version_compare(VERSION, '4.0.2.0', '>=')) {
								$this->session->data['shipping_address'] = $this->model_account_address->getAddress($this->session->data['customer']['customer_id'], $this->customer->getAddressId());
							} else {
								$this->session->data['shipping_address'] = $this->model_account_address->getAddress($this->customer->getAddressId());
							}
						} else {
							$this->session->data['shipping_address']['address_id'] = 0;
							
							if (isset($paypal_order_info['purchase_units'][0]['shipping']['name']['full_name'])) {
								$shipping_name = explode(' ', $paypal_order_info['purchase_units'][0]['shipping']['name']['full_name']);
								$shipping_firstname = $shipping_name[0];
								unset($shipping_name[0]);
								$shipping_lastname = implode(' ', $shipping_name);
							}
					
							$this->session->data['shipping_address']['firstname'] = (isset($shipping_firstname) ? $shipping_firstname : '');
							$this->session->data['shipping_address']['lastname'] = (isset($shipping_lastname) ? $shipping_lastname : '');
							$this->session->data['shipping_address']['company'] = '';
							$this->session->data['shipping_address']['address_1'] = (isset($paypal_order_info['purchase_units'][0]['shipping']['address']['address_line_1']) ? $paypal_order_info['purchase_units'][0]['shipping']['address']['address_line_1'] : '');
							$this->session->data['shipping_address']['address_2'] = (isset($paypal_order_info['purchase_units'][0]['shipping']['address']['address_line_2']) ? $paypal_order_info['purchase_units'][0]['shipping']['address']['address_line_2'] : '');
							$this->session->data['shipping_address']['city'] = (isset($paypal_order_info['purchase_units'][0]['shipping']['address']['admin_area_2']) ? $paypal_order_info['purchase_units'][0]['shipping']['address']['admin_area_2'] : '');
							$this->session->data['shipping_address']['postcode'] = (isset($paypal_order_info['purchase_units'][0]['shipping']['address']['postal_code']) ? $paypal_order_info['purchase_units'][0]['shipping']['address']['postal_code'] : '');
							$this->session->data['shipping_address']['country'] = '';
							$this->session->data['shipping_address']['country_id'] = 0;
							$this->session->data['shipping_address']['address_format'] = '';
							$this->session->data['shipping_address']['zone'] = '';
							$this->session->data['shipping_address']['zone_id'] = 0;
							$this->session->data['shipping_address']['custom_field'] = [];
									
							if (isset($paypal_order_info['purchase_units'][0]['shipping']['address']['country_code'])) {
								$country_info = $this->model_extension_paypal_payment_paypal->getCountryByCode($paypal_order_info['purchase_units'][0]['shipping']['address']['country_code']);
			
								if ($country_info) {
									$this->session->data['shipping_address']['country_id'] = $country_info['country_id'];
									$this->session->data['shipping_address']['country'] = $country_info['name'];
									$this->session->data['shipping_address']['address_format'] = $country_info['address_format'];
													
									if (isset($paypal_order_info['purchase_units'][0]['shipping']['address']['admin_area_1'])) {
										$zone_info = $this->model_extension_paypal_payment_paypal->getZoneByCode($country_info['country_id'], $paypal_order_info['purchase_units'][0]['shipping']['address']['admin_area_1']);
			
										if ($zone_info) {
											$this->session->data['shipping_address']['zone_id'] = $zone_info['zone_id'];
											$this->session->data['shipping_address']['zone'] = $zone_info['name'];
										}
									}
								}
							}
						}
					}
					
					if (($payment_type == 'googlepay_button') && !empty($this->request->post['payment_data'])) {
						$payment_data = json_decode(htmlspecialchars_decode($this->request->post['payment_data']), true);
						
						if (isset($payment_data['paymentMethodData']['info']['billingAddress']['name'])) {
							$payment_name = explode(' ', $payment_data['paymentMethodData']['info']['billingAddress']['name']);
							$payment_firstname = $payment_name[0];
							unset($payment_name[0]);
							$payment_lastname = implode(' ', $payment_name);
						}
							
						$this->session->data['customer']['firstname'] = (isset($payment_firstname) ? $payment_firstname : '');
						$this->session->data['customer']['lastname'] = (isset($payment_lastname) ? $payment_lastname : '');
						$this->session->data['customer']['email'] = (isset($payment_data['email']) ? $payment_data['email'] : '');
						$this->session->data['customer']['telephone'] = (isset($payment_data['paymentMethodData']['info']['billingAddress']['phoneNumber']) ? $payment_data['paymentMethodData']['info']['billingAddress']['phoneNumber'] : '');
						
						$this->session->data['payment_address']['firstname'] = (isset($shipping_firstname) ? $shipping_firstname : '');
						$this->session->data['payment_address']['lastname'] = (isset($shipping_lastname) ? $shipping_lastname : '');
						$this->session->data['payment_address']['address_1'] = (isset($payment_data['paymentMethodData']['info']['billingAddress']['address1']) ? $payment_data['paymentMethodData']['info']['billingAddress']['address1'] : '');
						$this->session->data['payment_address']['city'] = (isset($payment_data['paymentMethodData']['info']['billingAddress']['locality']) ? $payment_data['paymentMethodData']['info']['billingAddress']['locality'] : '');
						$this->session->data['payment_address']['postcode'] = (isset($payment_data['paymentMethodData']['info']['billingAddress']['postalCode']) ? $payment_data['paymentMethodData']['info']['billingAddress']['postalCode'] : '');
							
						if (isset($payment_data['paymentMethodData']['info']['billingAddress']['countryCode'])) {
							$country_info = $this->model_extension_paypal_payment_paypal->getCountryByCode($payment_data['paymentMethodData']['info']['billingAddress']['countryCode']);
			
							if ($country_info) {
								$this->session->data['payment_address']['country'] = $country_info['name'];
								$this->session->data['payment_address']['country_id'] = $country_info['country_id'];
							}
						}
							
						if ($this->cart->hasShipping()) {
							if (isset($payment_data['shippingAddress']['name'])) {
								$shipping_name = explode(' ', $payment_data['shippingAddress']['name']);
								$shipping_firstname = $shipping_name[0];
								unset($shipping_name[0]);
								$shipping_lastname = implode(' ', $shipping_name);
							}
						
							$this->session->data['shipping_address']['firstname'] = (isset($shipping_firstname) ? $shipping_firstname : '');
							$this->session->data['shipping_address']['lastname'] = (isset($shipping_lastname) ? $shipping_lastname : '');
							$this->session->data['shipping_address']['address_1'] = (isset($payment_data['shippingAddress']['address1']) ? $payment_data['shippingAddress']['address1'] : '');
							$this->session->data['shipping_address']['city'] = (isset($payment_data['shippingAddress']['locality']) ? $payment_data['shippingAddress']['locality'] : '');
							$this->session->data['shipping_address']['postcode'] = (isset($payment_data['shippingAddress']['postalCode']) ? $payment_data['shippingAddress']['postalCode'] : '');
							
							if (isset($payment_data['shippingAddress']['countryCode'])) {
								$country_info = $this->model_extension_paypal_payment_paypal->getCountryByCode($payment_data['shippingAddress']['countryCode']);
			
								if ($country_info) {
									$this->session->data['shipping_address']['country'] = $country_info['name'];
									$this->session->data['shipping_address']['country_id'] = $country_info['country_id'];
								}
							}
						}
					}
					
					if (($payment_type == 'applepay_button') && !empty($this->request->post['payment_data'])) {
						$payment_data = json_decode(htmlspecialchars_decode($this->request->post['payment_data']), true);
					
						$this->session->data['customer']['firstname'] = (isset($payment_data['billingContact']['givenName']) ? $payment_data['billingContact']['givenName'] : '');
						$this->session->data['customer']['lastname'] = (isset($payment_data['billingContact']['familyName']) ? $payment_data['billingContact']['familyName'] : '');
						$this->session->data['customer']['email'] = (isset($payment_data['shippingContact']['emailAddress']) ? $payment_data['shippingContact']['emailAddress'] : '');
						$this->session->data['customer']['telephone'] = (isset($payment_data['shippingContact']['phoneNumber']) ? $payment_data['shippingContact']['phoneNumber'] : '');
						
						$this->session->data['payment_address']['firstname'] = (isset($payment_data['billingContact']['givenName']) ? $payment_data['billingContact']['givenName'] : '');
						$this->session->data['payment_address']['lastname'] = (isset($payment_data['billingContact']['familyName']) ? $payment_data['billingContact']['familyName'] : '');
						$this->session->data['payment_address']['address_1'] = (isset($payment_data['billingContact']['addressLines']) ? implode(', ', $payment_data['billingContact']['addressLines']) : '');
						$this->session->data['payment_address']['city'] = (isset($payment_data['billingContact']['locality']) ? $payment_data['billingContact']['locality'] : '');
						$this->session->data['payment_address']['postcode'] = (isset($payment_data['billingContact']['postalCode']) ? $payment_data['billingContact']['postalCode'] : '');
							
						if (isset($payment_data['billingContact']['countryCode'])) {
							$country_info = $this->model_extension_paypal_payment_paypal->getCountryByCode($payment_data['billingContact']['countryCode']);
			
							if ($country_info) {
								$this->session->data['payment_address']['country'] = $country_info['name'];
								$this->session->data['payment_address']['country_id'] = $country_info['country_id'];
							}
						}
							
						if ($this->cart->hasShipping()) {						
							$this->session->data['shipping_address']['firstname'] = (isset($payment_data['shippingContact']['givenName']) ? $payment_data['shippingContact']['givenName'] : '');
							$this->session->data['shipping_address']['lastname'] = (isset($payment_data['shippingContact']['familyName']) ? $payment_data['shippingContact']['familyName'] : '');
							$this->session->data['shipping_address']['address_1'] = (isset($payment_data['shippingContact']['addressLines']) ? implode(', ', $payment_data['shippingContact']['addressLines']) : '');
							$this->session->data['shipping_address']['city'] = (isset($payment_data['shippingContact']['locality']) ? $payment_data['shippingContact']['locality'] : '');
							$this->session->data['shipping_address']['postcode'] = (isset($payment_data['shippingContact']['postalCode']) ? $payment_data['shippingContact']['postalCode'] : '');
							
							if (isset($payment_data['shippingContact']['countryCode'])) {
								$country_info = $this->model_extension_paypal_payment_paypal->getCountryByCode($payment_data['shippingContact']['countryCode']);
			
								if ($country_info) {
									$this->session->data['shipping_address']['country'] = $country_info['name'];
									$this->session->data['shipping_address']['country_id'] = $country_info['country_id'];
								}
							}
						}
					}
					
					if ($payment_type == 'button') {
						if (version_compare(VERSION, '4.0.2.0', '>=')) {
							$this->session->data['payment_method'] = [
								'code' => 'paypal.paypal',
								'name' => $this->language->get('text_paypal_title')						
							];
						} else {
							$this->session->data['payment_method'] = 'paypal'; 
						}
					}
					
					if ($payment_type == 'googlepay_button') {
						if (version_compare(VERSION, '4.0.2.0', '>=')) {
							$this->session->data['payment_method'] = [
								'code' => 'paypal.googlepay',
								'name' => $this->language->get('text_paypal_googlepay_title')						
							];
						} else {
							$this->session->data['payment_method'] = 'paypal_googlepay'; 
						}
					}
					
					if ($payment_type == 'applepay_button') {
						if (version_compare(VERSION, '4.0.2.0', '>=')) {
							$this->session->data['payment_method'] = [
								'code' => 'paypal.applepay',
								'name' => $this->language->get('text_paypal_applepay_title')						
							];
						} else {	
							$this->session->data['payment_method'] = 'paypal_applepay'; 
						}
					}

					$data['url'] = $this->url->link('extension/paypal/payment/paypal' . $this->separator . 'confirmOrder', 'language=' . $this->config->get('config_language'));
				}
			} else {
				if (!empty($this->request->post['paypal_order_id'])) {
					$paypal_order_id = $this->request->post['paypal_order_id'];
				}
		
				if (($payment_type == 'card') && !empty($paypal_order_id)) {
					$paypal_order_info = $paypal->getOrder($paypal_order_id);
					
					if ($paypal->hasErrors()) {
						$error_messages = [];
				
						$errors = $paypal->getErrors();
								
						foreach ($errors as $error) {
							if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
								$error['message'] = $this->language->get('error_timeout');
							}
					
							if (isset($error['details'][0]['description'])) {
								$error_messages[] = $error['details'][0]['description'];
							} elseif (isset($error['message'])) {
								$error_messages[] = $error['message'];
							}
					
							$this->model_extension_paypal_payment_paypal->log($error, $error['message']);
						}
		
						$this->error['warning'] = implode(' ', $error_messages);
					}
							
					if (isset($paypal_order_info['payment_source']['card']) && !$this->error) {
						$this->model_extension_paypal_payment_paypal->log($paypal_order_info['payment_source']['card'], 'Card');
						
						$liability_shift = (isset($paypal_order_info['payment_source']['card']['authentication_result']['liability_shift']) ? $paypal_order_info['payment_source']['card']['authentication_result']['liability_shift'] : '');
						$enrollment_status = (isset($paypal_order_info['payment_source']['card']['authentication_result']['three_d_secure']['enrollment_status']) ? $paypal_order_info['payment_source']['card']['authentication_result']['three_d_secure']['enrollment_status'] : '');
						$authentication_status = (isset($paypal_order_info['payment_source']['card']['authentication_result']['three_d_secure']['authentication_status']) ? $paypal_order_info['payment_source']['card']['authentication_result']['three_d_secure']['authentication_status'] : '');
								
						if ($enrollment_status == 'Y') {
							if (($authentication_status == 'N') && !$setting['card']['secure_scenario']['failed_authentication']) {
								$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['failed_authentication']['error']);
							}
						
							if (($authentication_status == 'R') && !$setting['card']['secure_scenario']['rejected_authentication']) {
								$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['rejected_authentication']['error']);
							}
						
							if (($authentication_status == 'A') && !$setting['card']['secure_scenario']['attempted_authentication']) {
								$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['attempted_authentication']['error']);
							}
						
							if (($authentication_status == 'U') && !$setting['card']['secure_scenario']['unable_authentication']) {
								$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['unable_authentication']['error']);
							}
						
							if (($authentication_status == 'C') && !$setting['card']['secure_scenario']['challenge_authentication']) {
								$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['challenge_authentication']['error']);
							}
						}
					
						if (($enrollment_status == 'N') && !$setting['card']['secure_scenario']['card_ineligible']) {
							$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['card_ineligible']['error']);
						}
					
						if (($enrollment_status == 'U') && !$setting['card']['secure_scenario']['system_unavailable']) {
							$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['system_unavailable']['error']);
						}
					
						if (($enrollment_status == 'B') && !$setting['card']['secure_scenario']['system_bypassed']) {
							$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['system_bypassed']['error']);
						}
					}
		
					if (!empty($this->error['warning'])) {
						$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
					}
				}

				if (!empty($paypal_order_id) && !$this->error) {				
					if ($transaction_method == 'authorize') {
						$result = $paypal->setOrderAuthorize($paypal_order_id);
					} else {
						$result = $paypal->setOrderCapture($paypal_order_id);
					}
																		
					if ($paypal->hasErrors()) {
						$error_messages = [];
				
						$errors = $paypal->getErrors();
								
						foreach ($errors as $error) {
							if (isset($error['details'][0]['issue']) && ($error['details'][0]['issue'] == 'INSTRUMENT_DECLINED')) {
								$data['restart'] = true;
							}
					
							if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
								$error['message'] = $this->language->get('error_timeout');
							}
					
							if (isset($error['details'][0]['description'])) {
								$error_messages[] = $error['details'][0]['description'];
							} elseif (isset($error['message'])) {
								$error_messages[] = $error['message'];
							}
					
							$this->model_extension_paypal_payment_paypal->log($error, $error['message']);
						}
				
						$this->error['warning'] = implode(' ', $error_messages);
					}
			
					if (!empty($this->error['warning'])) {
						$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
					}
			
					if (!$this->error) {	
						$this->load->model('checkout/order');
				
						$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
						
						if ($transaction_method == 'authorize') {
							$this->model_extension_paypal_payment_paypal->log($result, 'Authorize Order');
					
							if (isset($result['purchase_units'][0]['payments']['authorizations'][0]['status']) && isset($result['purchase_units'][0]['payments']['authorizations'][0]['seller_protection']['status'])) {
								$authorization_id = $result['purchase_units'][0]['payments']['authorizations'][0]['id'];
								$authorization_status = $result['purchase_units'][0]['payments']['authorizations'][0]['status'];
								$seller_protection_status = $result['purchase_units'][0]['payments']['authorizations'][0]['seller_protection']['status'];							
								$order_status_id = 0;
								$transaction_status = '';
								$payment_method = '';
								$vault_id = '';
								$vault_customer_id = '';
								$card_type = (!empty($this->request->post['card_type']) ? $this->request->post['card_type'] : '');
								$card_nice_type = (!empty($this->request->post['card_nice_type']) ? $this->request->post['card_nice_type'] : '');
								$card_last_digits = '';
								$card_expiry = '';
								
								if (!$this->cart->hasShipping()) {
									$seller_protection_status = 'NOT_ELIGIBLE';
								}
								
								foreach ($result['payment_source'] as $payment_source_key => $payment_source) {
									$payment_method = $payment_source_key;
									$vault_id = (isset($payment_source['attributes']['vault']['id']) ? $payment_source['attributes']['vault']['id'] : '');
									$vault_customer_id = (isset($payment_source['attributes']['vault']['customer']['id']) ? $payment_source['attributes']['vault']['customer']['id'] : '');
									$card_last_digits = (isset($payment_source['last_digits']) ? $payment_source['last_digits'] : '');
									$card_expiry = (isset($payment_source['expiry']) ? $payment_source['expiry'] : '');
									
									break;
								}

								if ($authorization_status == 'CREATED') {
									$order_status_id = $setting['order_status']['pending']['id'];
									$transaction_status = 'created';
								}

								if ($authorization_status == 'CAPTURED') {
									$this->error['warning'] = sprintf($this->language->get('error_authorization_captured'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
								}
						
								if ($authorization_status == 'DENIED') {
									$transaction_status = 'denied';
							
									$this->error['warning'] = $this->language->get('error_authorization_denied');
								}
						
								if ($authorization_status == 'EXPIRED') {
									$this->error['warning'] = sprintf($this->language->get('error_authorization_expired'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
								}
						
								if ($authorization_status == 'PENDING') {
									$order_status_id = $setting['order_status']['pending']['id'];
									$transaction_status = 'pending';
								}
														
								if (($authorization_status == 'CREATED') || ($authorization_status == 'DENIED') || ($authorization_status == 'PENDING')) {
									if ($payment_method == 'paypal') {
										$paypal_customer_token = [];
						
										if ($setting['general']['vault_status'] && $this->customer->isLogged()) {
											$paypal_customer_token = $this->model_extension_paypal_payment_paypal->getPayPalCustomerMainToken($this->customer->getId(), $payment_method);
										}
										
										if (!empty($paypal_customer_token['vault_id'])) {
											$vault_id = $paypal_customer_token['vault_id'];
											$vault_customer_id = $paypal_customer_token['vault_customer_id'];
										}
									}
									
									$this->model_extension_paypal_payment_paypal->deletePayPalOrder($this->session->data['order_id']);
									
									$paypal_order_data = [
										'order_id' => $this->session->data['order_id'],
										'paypal_order_id' => $paypal_order_id,
										'transaction_id' => $authorization_id,
										'transaction_status' => $transaction_status,
										'payment_method' => $payment_method,
										'vault_id' => $vault_id,
										'vault_customer_id' => $vault_customer_id,
										'card_type' => $card_type,
										'card_nice_type' => $card_nice_type,
										'card_last_digits' => $card_last_digits,
										'card_expiry' => $card_expiry,
										'total' => $order_info['total'],
										'currency_code' => $order_info['currency_code'],
										'environment' => $environment
									];

									$this->model_extension_paypal_payment_paypal->addPayPalOrder($paypal_order_data);
									
									if ($vault_id && $this->customer->isLogged()) {
										$customer_id = $this->customer->getId();
										
										$paypal_customer_token_info = $this->model_extension_paypal_payment_paypal->getPayPalCustomerToken($customer_id, $payment_method, $vault_id);
								
										if (!$paypal_customer_token_info) {
											$paypal_customer_token_data = [
												'customer_id' => $customer_id,
												'payment_method' => $payment_method,
												'vault_id' => $vault_id,
												'vault_customer_id' => $vault_customer_id,
												'card_type' => $card_type,
												'card_nice_type' => $card_nice_type,
												'card_last_digits' => $card_last_digits,
												'card_expiry' => $card_expiry
											];
					
											$this->model_extension_paypal_payment_paypal->addPayPalCustomerToken($paypal_customer_token_data);
										}
										
										$this->model_extension_paypal_payment_paypal->setPayPalCustomerMainToken($customer_id, $payment_method, $vault_id);
									}
								}
									
								if ($order_status_id) {
									$message = sprintf($this->language->get('text_order_message'), $seller_protection_status);
											
									$this->model_checkout_order->addHistory($this->session->data['order_id'], $order_status_id, $message);
								}
								
								if (($authorization_status == 'CREATED') || ($authorization_status == 'PENDING')) {
									$subscriptions = $this->model_extension_paypal_payment_paypal->getSubscriptionsByOrderId($this->session->data['order_id']);
					
									foreach ($subscriptions as $subscription) {
										$this->model_extension_paypal_payment_paypal->subscriptionPayment($subscription, $order_info, $paypal_order_data);
									} 
								}
													
								if (($authorization_status == 'CREATED') || ($authorization_status == 'PARTIALLY_CAPTURED') || ($authorization_status == 'PARTIALLY_CREATED') || ($authorization_status == 'PENDING')) {
									$data['url'] = $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'));
								}
							}
						} else {
							$this->model_extension_paypal_payment_paypal->log($result, 'Capture Order');
					
							if (isset($result['purchase_units'][0]['payments']['captures'][0]['status']) && isset($result['purchase_units'][0]['payments']['captures'][0]['seller_protection']['status'])) {
								$capture_id = $result['purchase_units'][0]['payments']['captures'][0]['id'];
								$capture_status = $result['purchase_units'][0]['payments']['captures'][0]['status'];
								$seller_protection_status = $result['purchase_units'][0]['payments']['captures'][0]['seller_protection']['status'];
								$order_status_id = 0;
								$transaction_status = '';
								$payment_method = '';
								$vault_id = '';
								$vault_customer_id = '';
								$card_type = (!empty($this->request->post['card_type']) ? $this->request->post['card_type'] : '');
								$card_nice_type = (!empty($this->request->post['card_nice_type']) ? $this->request->post['card_nice_type'] : '');
								$card_last_digits = '';
								$card_expiry = '';
								
								if (!$this->cart->hasShipping()) {
									$seller_protection_status = 'NOT_ELIGIBLE';
								}
								
								foreach ($result['payment_source'] as $payment_source_key => $payment_source) {
									$payment_method = $payment_source_key;
									$vault_id = (isset($payment_source['attributes']['vault']['id']) ? $payment_source['attributes']['vault']['id'] : '');
									$vault_customer_id = (isset($payment_source['attributes']['vault']['customer']['id']) ? $payment_source['attributes']['vault']['customer']['id'] : '');
									$card_last_digits = (isset($payment_source['last_digits']) ? $payment_source['last_digits'] : '');
									$card_expiry = (isset($payment_source['expiry']) ? $payment_source['expiry'] : '');
									
									break;
								}
														
								if ($capture_status == 'COMPLETED') {
									$order_status_id = $setting['order_status']['completed']['id'];
									$transaction_status = 'completed';
								}
						
								if ($capture_status == 'DECLINED') {
									$transaction_status = 'denied';
							
									$this->error['warning'] = $this->language->get('error_capture_declined');
								}
						
								if ($capture_status == 'FAILED') {
									$this->error['warning'] = sprintf($this->language->get('error_capture_failed'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
								}
						
								if ($capture_status == 'PENDING') {
									$order_status_id = $setting['order_status']['pending']['id'];
									$transaction_status = 'pending';
								}
														
								if (($capture_status == 'COMPLETED') || ($capture_status == 'DECLINED') || ($capture_status == 'PENDING')) {
									if ($payment_method == 'paypal') {
										$paypal_customer_token = [];
						
										if ($setting['general']['vault_status'] && $this->customer->isLogged()) {
											$paypal_customer_token = $this->model_extension_paypal_payment_paypal->getPayPalCustomerMainToken($this->customer->getId(), $payment_method);
										}
										
										if (!empty($paypal_customer_token['vault_id'])) {
											$vault_id = $paypal_customer_token['vault_id'];
											$vault_customer_id = $paypal_customer_token['vault_customer_id'];
										}
									}
									
									$this->model_extension_paypal_payment_paypal->deletePayPalOrder($this->session->data['order_id']);
								
									$paypal_order_data = [
										'order_id' => $this->session->data['order_id'],
										'paypal_order_id' => $paypal_order_id,
										'transaction_id' => $capture_id,
										'transaction_status' => $transaction_status,
										'payment_method' => $payment_method,
										'vault_id' => $vault_id,
										'vault_customer_id' => $vault_customer_id,
										'card_type' => $card_type,
										'card_nice_type' => $card_nice_type,
										'card_last_digits' => $card_last_digits,
										'card_expiry' => $card_expiry,
										'total' => $order_info['total'],
										'currency_code' => $order_info['currency_code'],
										'environment' => $environment
									];

									$this->model_extension_paypal_payment_paypal->addPayPalOrder($paypal_order_data);
									
									if ($vault_id && $this->customer->isLogged()) {
										$customer_id = $this->customer->getId();
										
										$paypal_customer_token_info = $this->model_extension_paypal_payment_paypal->getPayPalCustomerToken($customer_id, $payment_method, $vault_id);
								
										if (!$paypal_customer_token_info) {
											$paypal_customer_token_data = [
												'customer_id' => $customer_id,
												'payment_method' => $payment_method,
												'vault_id' => $vault_id,
												'vault_customer_id' => $vault_customer_id,
												'card_type' => $card_type,
												'card_nice_type' => $card_nice_type,
												'card_last_digits' => $card_last_digits,
												'card_expiry' => $card_expiry
											];
					
											$this->model_extension_paypal_payment_paypal->addPayPalCustomerToken($paypal_customer_token_data);
										}
										
										$this->model_extension_paypal_payment_paypal->setPayPalCustomerMainToken($customer_id, $payment_method, $vault_id);
									}
								}
									
								if ($order_status_id) {
									$message = sprintf($this->language->get('text_order_message'), $seller_protection_status);
													
									$this->model_checkout_order->addHistory($this->session->data['order_id'], $order_status_id, $message);
								}
								
								if (($capture_status == 'COMPLETED') || ($capture_status == 'PENDING')) {
									$subscriptions = $this->model_extension_paypal_payment_paypal->getSubscriptionsByOrderId($this->session->data['order_id']);
					
									foreach ($subscriptions as $subscription) {
										$this->model_extension_paypal_payment_paypal->subscriptionPayment($subscription, $order_info, $paypal_order_data);
									} 
								}
						
								if (($capture_status == 'COMPLETED') || ($capture_status == 'PENDING')) {
									$data['url'] = $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'));
								}
							}
						}
					}
				}
			}
		}

		$data['language'] = $this->config->get('config_language');
		
		$data['error'] = $this->error;
				
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));		
	}
	
	public function confirmOrder(): void {
		$this->load->language('extension/paypal/payment/paypal');
		$this->load->language('checkout/cart');

		$this->load->model('tool/image');
		
		if (!isset($this->session->data['paypal_order_id']) && !isset($this->session->data['paypal_payment_token'])) {
			$this->response->redirect($this->url->link('checkout/cart', 'language=' . $this->config->get('config_language')));
		}
			
		// Coupon
		if (isset($this->request->post['coupon']) && $this->validateCoupon()) {
			$this->session->data['coupon'] = $this->request->post['coupon'];

			$this->session->data['success'] = $this->language->get('text_coupon');

			$this->response->redirect($this->url->link('extension/paypal/payment/paypal' . $this->separator . 'confirmOrder', 'language=' . $this->config->get('config_language')));
		}

		// Voucher
		if (isset($this->request->post['voucher']) && $this->validateVoucher()) {
			$this->session->data['voucher'] = $this->request->post['voucher'];

			$this->session->data['success'] = $this->language->get('text_voucher');

			$this->response->redirect($this->url->link('extension/paypal/payment/paypal' . $this->separator . 'confirmOrder', 'language=' . $this->config->get('config_language')));
		}

		// Reward
		if (isset($this->request->post['reward']) && $this->validateReward()) {
			$this->session->data['reward'] = abs($this->request->post['reward']);

			$this->session->data['success'] = $this->language->get('text_reward');

			$this->response->redirect($this->url->link('extension/paypal/payment/paypal' . $this->separator . 'confirmOrder', 'language=' . $this->config->get('config_language')));
		}
		
		if (isset($this->session->data['paypal_order_id'])) {
			$text_title = $this->language->get('text_paypal');
		}
		
		if (isset($this->session->data['paypal_payment_token'])) {
			$text_title = $this->language->get('text_paypal_fastlane');
		}
		
		$this->document->setTitle($text_title);
		
		$this->document->addStyle('extension/paypal/catalog/view/stylesheet/paypal.css');

		$data['heading_title'] = $text_title;

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/home', 'language=' . $this->config->get('config_language'))
		];
		
		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_cart'),
			'href' => $this->url->link('checkout/cart', 'language=' . $this->config->get('config_language'))
		];
		
		$data['breadcrumbs'][] = [
			'text' => $text_title,
			'href' => $this->url->link('extension/paypal/payment/paypal' . $this->separator . 'confirmOrder', 'language=' . $this->config->get('config_language'))
		];
				
		$points_total = 0;

		foreach ($this->cart->getProducts() as $product) {
			if ($product['points']) {
				$points_total += $product['points'];
			}
		}
		
		if (isset($this->request->post['next'])) {
			$data['next'] = $this->request->post['next'];
		} else {
			$data['next'] = '';
		}

		$this->load->model('tool/upload');

		if (!$this->cart->hasProducts() && empty($this->session->data['vouchers'])) {
			$this->response->redirect($this->url->link('checkout/cart', 'language=' . $this->config->get('config_language')));
		}
		
		$data['products'] = [];
		
		$products = $this->cart->getProducts();
		
		foreach ($products as $product) {
			$product_total = 0;

			foreach ($products as $product_2) {
				if ($product_2['product_id'] == $product['product_id']) {
					$product_total += $product_2['quantity'];
				}
			}

			if ($product['minimum'] > $product_total) {
				$data['error_warning'] = sprintf($this->language->get('error_minimum'), $product['name'], $product['minimum']);
			}

			if ($product['image']) {
				$image = $this->model_tool_image->resize(html_entity_decode($product['image'], ENT_QUOTES, 'UTF-8'), $this->config->get('config_image_cart_width'), $this->config->get('config_image_cart_height'));
			} else {
				$image = $this->model_tool_image->resize('placeholder.png', $this->config->get('config_image_cart_width'), $this->config->get('config_image_cart_height'));
			}

			$option_data = [];

			foreach ($product['option'] as $option) {
				if ($option['type'] != 'file') {
					$value = $option['value'];
				} else {
					$upload_info = $this->model_tool_upload->getUploadByCode($option['value']);

					if ($upload_info) {
						$value = $upload_info['name'];
					} else {
						$value = '';
					}
				}

				$option_data[] = [
					'name'  => $option['name'],
					'value' => ($this->strlen($value) > 20 ? utf8_substr($value, 0, 20) . '..' : $value)
				];
			}

			// Display prices
			if ($this->customer->isLogged() || !$this->config->get('config_customer_price')) {
				$unit_price = $this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax'));

				$price = $this->currency->format($unit_price, $this->session->data['currency']);
				$total = $this->currency->format($unit_price * $product['quantity'], $this->session->data['currency']);
			} else {
				$price = false;
				$total = false;
			}
			
			$description = '';

			if ($product['subscription']) {
				$trial_price = $this->currency->format($this->tax->calculate($product['subscription']['trial_price'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
				$trial_cycle = $product['subscription']['trial_cycle'];
				$trial_frequency = $this->language->get('text_' . $product['subscription']['trial_frequency']);
				$trial_duration = $product['subscription']['trial_duration'];

				if ($product['subscription']['trial_status']) {
					$description .= sprintf($this->language->get('text_subscription_trial'), $trial_price, $trial_cycle, $trial_frequency, $trial_duration);
				}

				$price = $this->currency->format($this->tax->calculate($product['subscription']['price'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
				$cycle = $product['subscription']['cycle'];
				$frequency = $this->language->get('text_' . $product['subscription']['frequency']);
				$duration = $product['subscription']['duration'];

				if ($duration) {
					$description .= sprintf($this->language->get('text_subscription_duration'), $price, $cycle, $frequency, $duration);
				} else {
					$description .= sprintf($this->language->get('text_subscription_cancel'), $price, $cycle, $frequency);
				}
			}

			$data['products'][] = [
				'cart_id'               => $product['cart_id'],
				'thumb'                 => $image,
				'name'                  => $product['name'],
				'model'                 => $product['model'],
				'option'                => $option_data,
				'subscription' 			=> $description,
				'quantity'              => $product['quantity'],
				'stock'                 => $product['stock'] ? true : !(!$this->config->get('config_stock_checkout') || $this->config->get('config_stock_warning')),
				'reward'                => ($product['reward'] ? sprintf($this->language->get('text_points'), $product['reward']) : ''),
				'price'                 => $price,
				'total'                 => $total,
				'href'                  => $this->url->link('product/product', 'language=' . $this->config->get('config_language') . '&product_id=' . $product['product_id'])
			];
		}
		
		// Gift Voucher
		$data['vouchers'] = [];
		
		if (!empty($this->session->data['vouchers'])) {
			foreach ($this->session->data['vouchers'] as $key => $voucher) {
				$data['vouchers'][] = [
					'key'         	   => $key,
					'description' 	   => $voucher['description'],
					'amount'      	   => $this->currency->format($voucher['amount'], $this->session->data['currency'])
				];
			}
		}
		
		$this->load->model('setting/extension');
		
		if ($this->cart->hasShipping()) {
			$data['has_shipping'] = true;
			
			$data['shipping_address'] = isset($this->session->data['shipping_address']) ? $this->session->data['shipping_address'] : [];
			
			if (!empty($data['shipping_address'])) {
				// Shipping Methods				
				$method_data = [];
				
				$results = $this->model_setting_extension->getExtensionsByType('shipping');

				if (!empty($results)) {
					foreach ($results as $result) {
						if ($this->config->get('shipping_' . $result['code'] . '_status')) {
							$this->load->model('extension/' . $result['extension'] . '/shipping/' . $result['code']);

							$quote = $this->{'model_extension_' . $result['extension'] . '_shipping_' . $result['code']}->getQuote($data['shipping_address']);

							if ($quote) {
								if (version_compare(VERSION, '4.0.2.0', '>=')) {
									$method_data[$result['code']] = $quote;
								} else {
									$method_data[$result['code']] = [
										'title'      => $quote['title'],
										'quote'      => $quote['quote'],
										'sort_order' => $quote['sort_order'],
										'error'      => $quote['error']
									];
								}
							}
						}
					}

					if (!empty($method_data)) {
						$sort_order = [];

						foreach ($method_data as $key => $value) {
							$sort_order[$key] = $value['sort_order'];
						}
						
						array_multisort($sort_order, SORT_ASC, $method_data);

						$this->session->data['shipping_methods'] = $method_data;
						$data['shipping_methods'] = $method_data;

						if (!isset($this->session->data['shipping_method'])) {
							//default the shipping to the very first option.
							$key1 = key($method_data);
							$key2 = key($method_data[$key1]['quote']);
							
							if (version_compare(VERSION, '4.0.2.0', '>=')) {
								$this->session->data['shipping_method'] = $method_data[$key1]['quote'][$key2];
							} else {
								$this->session->data['shipping_method'] = $method_data[$key1]['quote'][$key2]['code'];
							}
						}
						
						if (version_compare(VERSION, '4.0.2.0', '>=')) {
							$data['shipping_method_code'] = $this->session->data['shipping_method']['code'];
						} else {
							$data['shipping_method_code'] = $this->session->data['shipping_method'];
						}
						
						$data['action_shipping'] = $this->url->link('extension/paypal/payment/paypal' . $this->separator . 'confirmShipping', 'language=' . $this->config->get('config_language'));
					}
				} else {
					unset($this->session->data['shipping_methods']);
					unset($this->session->data['shipping_method']);
					
					$data['error_no_shipping'] = $this->language->get('error_no_shipping');
				}
			}
		} else {
			$data['has_shipping'] = false;
		}
				
		$data['customer'] = isset($this->session->data['customer']) ? $this->session->data['customer'] : [];
		$data['payment_address'] = isset($this->session->data['payment_address']) ? $this->session->data['payment_address'] : [];
		
		/**
		 * Payment methods
		 */
		$method_data = [];

		$results = $this->model_setting_extension->getExtensionsByType('payment');
		
		foreach ($results as $result) {
			if ($this->config->get('payment_' . $result['code'] . '_status')) {
				$this->load->model('extension/' . $result['extension'] . '/payment/' . $result['code']);
				
				if (version_compare(VERSION, '4.0.2.0', '>=')) {
					$payment_methods = $this->{'model_extension_' . $result['extension'] . '_payment_' . $result['code']}->getMethods($data['payment_address']);

					if ($payment_methods) {
						$method_data[$result['code']] = $payment_methods;
					}
				} else {
					$payment_method = $this->{'model_extension_' . $result['extension'] . '_payment_' . $result['code']}->getMethod($data['payment_address']);

					if ($payment_method) {
						$method_data[$result['code']] = $payment_method;
					}
				}
			}
		}

		$sort_order = [];

		foreach ($method_data as $key => $value) {
			$sort_order[$key] = $value['sort_order'];
		}

		array_multisort($sort_order, SORT_ASC, $method_data);
		
		$this->session->data['payment_methods'] = $method_data;
		$data['payment_methods'] = $method_data;
		
		if (!isset($method_data['paypal'])) {
			$this->session->data['error_warning'] = $this->language->get('error_unavailable');
			
			$this->response->redirect($this->url->link('checkout/checkout', 'language=' . $this->config->get('config_language')));
		}
		
		if (version_compare(VERSION, '4.0.2.0', '>=')) {
			if (isset($this->session->data['payment_method']['code'])) {
				$data['payment_method_code'] = $this->session->data['payment_method']['code'];
			} else {
				$this->session->data['payment_method'] = $method_data['paypal']['option']['paypal'];
			
				$data['payment_method_code'] = $this->session->data['payment_method']['code'];
			}
		} else {
			if (isset($this->session->data['payment_method'])) {
				$data['payment_method_code'] = $this->session->data['payment_method'];
			} else {
				$this->session->data['payment_method'] = $method_data['paypal']['code'];
						
				$data['payment_method_code'] = $this->session->data['payment_method'];
			}
		}

		// Custom Fields
		$this->load->model('account/custom_field');

		$data['custom_fields'] = $this->model_account_custom_field->getCustomFields();
		
		// Totals
		$totals = [];
		$taxes = $this->cart->getTaxes();
		$total = 0;

		// Display prices
		if ($this->customer->isLogged() || !$this->config->get('config_customer_price')) {
			$sort_order = [];

			$results = $this->model_setting_extension->getExtensionsByType('total');

			foreach ($results as $key => $value) {
				$sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
			}

			array_multisort($sort_order, SORT_ASC, $results);

			foreach ($results as $result) {
				if ($this->config->get('total_' . $result['code'] . '_status')) {
					$this->load->model('extension/' . $result['extension'] . '/total/' . $result['code']);

					// __call can not pass-by-reference so we get PHP to call it as an anonymous function.
					($this->{'model_extension_' . $result['extension'] . '_total_' . $result['code']}->getTotal)($totals, $taxes, $total);
				}
			}

			$sort_order = [];

			foreach ($totals as $key => $value) {
				$sort_order[$key] = $value['sort_order'];
			}

			array_multisort($sort_order, SORT_ASC, $totals);
		}

		$data['totals'] = [];

		foreach ($totals as $total) {
			$data['totals'][] = [
				'title' => $total['title'],
				'text'  => $this->currency->format($total['value'], $this->session->data['currency']),
			];
		}
		
		$data['action_confirm'] = $this->url->link('extension/paypal/payment/paypal' . $this->separator . 'completeOrder', 'language=' . $this->config->get('config_language'));
				
		if (isset($this->session->data['error_warning'])) {
			$data['error_warning'] = $this->session->data['error_warning'];
			unset($this->session->data['error_warning']);
		} else {
			$data['error_warning'] = '';
		}

		if (isset($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];
			unset($this->session->data['success']);
		} else {
			$data['success'] = '';
		}

		if (isset($this->session->data['attention'])) {
			$data['attention'] = $this->session->data['attention'];
			unset($this->session->data['attention']);
		} else {
			$data['attention'] = '';
		}

		$data['modules'] = [];

		$extensions = $this->model_setting_extension->getExtensionsByType('total');

		foreach ($extensions as $extension) {
		    $result = $this->load->controller('extension/' . $extension['extension'] . '/total/' . $extension['code']);

			if (!$result instanceof \Exception) {
				$data['modules'][] = $result;
			}
		}
		
		$data['separator'] = $this->separator;
				
		$data['language'] = $this->config->get('config_language');
		
		$data['config_telephone_display'] = $this->config->get('config_telephone_display');
		$data['config_telephone_required'] = $this->config->get('config_telephone_required');

		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');
		
		$this->response->setOutput($this->load->view('extension/paypal/payment/confirm', $data));
	}
	
	public function completeOrder(): void {		
		$this->load->language('extension/paypal/payment/paypal');
						
		$this->load->model('extension/paypal/payment/paypal');
				
		// Validate if payment address has been set.
		if (empty($this->session->data['payment_address'])) {
			$this->response->redirect($this->url->link('checkout/checkout', 'language=' . $this->config->get('config_language')));
		}

		// Validate if payment method has been set.
		if (!isset($this->session->data['payment_method'])) {
			$this->response->redirect($this->url->link('checkout/checkout', 'language=' . $this->config->get('config_language')));
		}
		
		if ($this->cart->hasShipping()) {
			// Validate if shipping address has been set.
			if (empty($this->session->data['shipping_address'])) {
				$this->response->redirect($this->url->link('checkout/checkout', 'language=' . $this->config->get('config_language')));
			}

			// Validate if shipping method has been set.
			if (!isset($this->session->data['shipping_method'])) {
				$this->response->redirect($this->url->link('checkout/checkout', 'language=' . $this->config->get('config_language')));
			}
		} else {
			unset($this->session->data['shipping_method']);
			unset($this->session->data['shipping_methods']);
		}
		
		// Validate cart has products and has stock.
		if ((!$this->cart->hasProducts() && empty($this->session->data['vouchers'])) || (!$this->cart->hasStock() && !$this->config->get('config_stock_checkout'))) {
			$this->response->redirect($this->url->link('checkout/cart', 'language=' . $this->config->get('config_language')));
		}
		
		if (isset($this->session->data['paypal_order_id']) || isset($this->session->data['paypal_payment_token'])) {	
			$order_data =[];
			
			// Totals
			$totals = [];
			$taxes = $this->cart->getTaxes();
			$total = 0;
		
			$sort_order = [];

			$results = $this->model_setting_extension->getExtensionsByType('total');

			foreach ($results as $key => $value) {
				$sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
			}

			array_multisort($sort_order, SORT_ASC, $results);

			foreach ($results as $result) {
				if ($this->config->get('total_' . $result['code'] . '_status')) {
					$this->load->model('extension/' . $result['extension'] . '/total/' . $result['code']);

					// __call can not pass-by-reference so we get PHP to call it as an anonymous function.
					($this->{'model_extension_' . $result['extension'] . '_total_' . $result['code']}->getTotal)($totals, $taxes, $total);
				}
			}
			
			$sort_order = [];

			foreach ($totals as $key => $value) {
				$sort_order[$key] = $value['sort_order'];
			}

			array_multisort($sort_order, SORT_ASC, $totals);
				
			$order_data['totals'] = $totals;
		
			$order_data['invoice_prefix'] = $this->config->get('config_invoice_prefix');
			$order_data['store_id'] = $this->config->get('config_store_id');
			$order_data['store_name'] = $this->config->get('config_name');
			$order_data['store_url'] = $this->config->get('config_url');
										
			$order_data['customer_id'] = $this->session->data['customer']['customer_id'];
			$order_data['customer_group_id'] = $this->session->data['customer']['customer_group_id'];
			$order_data['firstname'] = $this->session->data['customer']['firstname'];
			$order_data['lastname'] = $this->session->data['customer']['lastname'];
			$order_data['telephone'] = $this->session->data['customer']['telephone'];
			$order_data['custom_field'] = $this->session->data['customer']['custom_field'];
			
			if ($this->session->data['customer']['email']) {
				$order_data['email'] = $this->session->data['customer']['email'];
			} else {
				$order_data['email'] = $this->config->get('config_email');
			}
						
			$order_data['payment_address_id'] = $this->session->data['payment_address']['address_id'];
			$order_data['payment_firstname'] = $this->session->data['payment_address']['firstname'];
			$order_data['payment_lastname'] = $this->session->data['payment_address']['lastname'];
			$order_data['payment_company'] = $this->session->data['payment_address']['company'];
			$order_data['payment_address_1'] = $this->session->data['payment_address']['address_1'];
			$order_data['payment_address_2'] = $this->session->data['payment_address']['address_2'];
			$order_data['payment_city'] = $this->session->data['payment_address']['city'];
			$order_data['payment_postcode'] = $this->session->data['payment_address']['postcode'];
			$order_data['payment_zone'] = $this->session->data['payment_address']['zone'];
			$order_data['payment_zone_id'] = $this->session->data['payment_address']['zone_id'];
			$order_data['payment_country'] = $this->session->data['payment_address']['country'];
			$order_data['payment_country_id'] = $this->session->data['payment_address']['country_id'];
			$order_data['payment_address_format'] = $this->session->data['payment_address']['address_format'];
			$order_data['payment_custom_field'] = (isset($this->session->data['payment_address']['custom_field']) ? $this->session->data['payment_address']['custom_field'] : []);

			if (isset($this->session->data['payment_method'])) {
				if (version_compare(VERSION, '4.0.2.0', '>=')) {
					$payment = explode('.', $this->session->data['payment_method']['code']);
					
					if (isset($payment[0]) && isset($payment[1]) && isset($this->session->data['payment_methods'][$payment[0]]['option'][$payment[1]])) {
						$payment_method_info = $this->session->data['payment_methods'][$payment[0]]['option'][$payment[1]];
					}
				} else {
					if (isset($this->session->data['payment_methods'][$this->session->data['payment_method']])) {
						$payment_method_info = $this->session->data['payment_methods'][$this->session->data['payment_method']];
					}
				}
			}
			
			if (version_compare(VERSION, '4.0.2.0', '>=')) {
				$order_data['payment_method'] = $payment_method_info;
			} else {				
				if (isset($payment_method_info['title'])) {
					$order_data['payment_method'] = $payment_method_info['title'];
				} else {
					$order_data['payment_method'] = '';
				}

				if (isset($payment_method_info['code'])) {
					$order_data['payment_code'] = $payment_method_info['code'];
				} else {
					$order_data['payment_code'] = '';
				}
			}				

			if ($this->cart->hasShipping()) {
				$order_data['shipping_address_id'] = $this->session->data['shipping_address']['address_id'];
				$order_data['shipping_firstname'] = $this->session->data['shipping_address']['firstname'];
				$order_data['shipping_lastname'] = $this->session->data['shipping_address']['lastname'];
				$order_data['shipping_company'] = $this->session->data['shipping_address']['company'];
				$order_data['shipping_address_1'] = $this->session->data['shipping_address']['address_1'];
				$order_data['shipping_address_2'] = $this->session->data['shipping_address']['address_2'];
				$order_data['shipping_city'] = $this->session->data['shipping_address']['city'];
				$order_data['shipping_postcode'] = $this->session->data['shipping_address']['postcode'];
				$order_data['shipping_zone'] = $this->session->data['shipping_address']['zone'];
				$order_data['shipping_zone_id'] = $this->session->data['shipping_address']['zone_id'];
				$order_data['shipping_country'] = $this->session->data['shipping_address']['country'];
				$order_data['shipping_country_id'] = $this->session->data['shipping_address']['country_id'];
				$order_data['shipping_address_format'] = $this->session->data['shipping_address']['address_format'];
				$order_data['shipping_custom_field'] = (isset($this->session->data['shipping_address']['custom_field']) ? $this->session->data['shipping_address']['custom_field'] : []);

				if (isset($this->session->data['shipping_method'])) {
					if (version_compare(VERSION, '4.0.2.0', '>=')) {
						$shipping = explode('.', $this->session->data['shipping_method']['code']);
					} else {
						$shipping = explode('.', $this->session->data['shipping_method']);
					}

					if (isset($shipping[0]) && isset($shipping[1]) && isset($this->session->data['shipping_methods'][$shipping[0]]['quote'][$shipping[1]])) {
						$shipping_method_info = $this->session->data['shipping_methods'][$shipping[0]]['quote'][$shipping[1]];
					}
				}
				
				if (version_compare(VERSION, '4.0.2.0', '>=')) {
					$order_data['shipping_method'] = $shipping_method_info;
				} else {
					if (isset($shipping_method_info['title'])) {
						$order_data['shipping_method'] = $shipping_method_info['title'];
					} else {
						$order_data['shipping_method'] = '';
					}

					if (isset($shipping_method_info['code'])) {
						$order_data['shipping_code'] = $shipping_method_info['code'];
					} else {
						$order_data['shipping_code'] = '';
					}
				}
			} else {
				$order_data['shipping_address_id'] = 0;
				$order_data['shipping_firstname'] = '';
				$order_data['shipping_lastname'] = '';
				$order_data['shipping_company'] = '';
				$order_data['shipping_address_1'] = '';
				$order_data['shipping_address_2'] = '';
				$order_data['shipping_city'] = '';
				$order_data['shipping_postcode'] = '';
				$order_data['shipping_zone'] = '';
				$order_data['shipping_zone_id'] = 0;
				$order_data['shipping_country'] = '';
				$order_data['shipping_country_id'] = 0;
				$order_data['shipping_address_format'] = '';
				$order_data['shipping_custom_field'] = [];
				
				if (version_compare(VERSION, '4.0.2.0', '>=')) {
					$order_data['shipping_method'] = [];
				} else {
					$order_data['shipping_method'] = '';
					$order_data['shipping_code'] = '';
				}
			}

			$order_data['products'] = [];

			foreach ($this->cart->getProducts() as $product) {
				$option_data = [];

				foreach ($product['option'] as $option) {
					$option_data[] = [
						'product_option_id'       => $option['product_option_id'],
						'product_option_value_id' => $option['product_option_value_id'],
						'option_id'               => $option['option_id'],
						'option_value_id'         => $option['option_value_id'],
						'name'                    => $option['name'],
						'value'                   => $option['value'],
						'type'                    => $option['type']
					];
				}
				
				$subscription_data = [];

				if (version_compare(VERSION, '4.0.2.0', '>=') && $product['subscription']) {
					$subscription_data = [
						'subscription_plan_id' => $product['subscription']['subscription_plan_id'],
						'name'                 => $product['subscription']['name'],
						'trial_price'          => $product['subscription']['trial_price'],
						'trial_tax'            => $this->tax->getTax($product['subscription']['trial_price'], $product['tax_class_id']),
						'trial_frequency'      => $product['subscription']['trial_frequency'],
						'trial_cycle'          => $product['subscription']['trial_cycle'],
						'trial_duration'       => $product['subscription']['trial_duration'],
						'trial_remaining'      => $product['subscription']['trial_remaining'],
						'trial_status'         => $product['subscription']['trial_status'],
						'price'                => $product['subscription']['price'],
						'tax'                  => $this->tax->getTax($product['subscription']['price'], $product['tax_class_id']),
						'frequency'            => $product['subscription']['frequency'],
						'cycle'                => $product['subscription']['cycle'],
						'duration'             => $product['subscription']['duration']
					];
				} else {
					$subscription_data = $product['subscription'];
				}

				$order_data['products'][] = [
					'product_id' 	=> $product['product_id'],
					'master_id'  	=> $product['master_id'],
					'name'       	=> $product['name'],
					'model'      	=> $product['model'],
					'option'     	=> $option_data,
					'subscription' 	=> $subscription_data,
					'download'   	=> $product['download'],
					'quantity'   	=> $product['quantity'],
					'subtract'   	=> $product['subtract'],
					'price'      	=> $product['price'],
					'total'      	=> $product['total'],
					'tax'       	=> $this->tax->getTax($product['price'], $product['tax_class_id']),
					'reward'     	=> $product['reward']
				];
			}

			// Gift Voucher
			$order_data['vouchers'] = [];

			if (!empty($this->session->data['vouchers'])) {
				$order_data['vouchers'] = $this->session->data['vouchers'];
			}

			$order_data['comment'] = (isset($this->session->data['comment']) ? $this->session->data['comment'] : '');
						
			$total_data = [
				'totals' => $totals,
				'taxes'  => $taxes,
				'total'  => $total
			];

			$order_data = array_merge($order_data, $total_data);
			
			$order_data['affiliate_id'] = 0;
			$order_data['commission'] = 0;
			$order_data['marketing_id'] = 0;
			$order_data['tracking'] = '';

			if ($this->config->get('config_affiliate_status') && isset($this->session->data['tracking'])) {
				$subtotal = $this->cart->getSubTotal();

				// Affiliate
				$this->load->model('account/affiliate');

				$affiliate_info = $this->model_account_affiliate->getAffiliateByTracking($this->session->data['tracking']);

				if ($affiliate_info) {
					$order_data['affiliate_id'] = $affiliate_info['customer_id'];
					$order_data['commission'] = ($subtotal / 100) * $affiliate_info['commission'];
					$order_data['tracking'] = $this->session->data['tracking'];
				}
			}

			$order_data['language_id'] = $this->config->get('config_language_id');
			$order_data['language_code'] = $this->config->get('config_language');
			
			$order_data['currency_id'] = $this->currency->getId($this->session->data['currency']);
			$order_data['currency_code'] = $this->session->data['currency'];
			$order_data['currency_value'] = $this->currency->getValue($this->session->data['currency']);

			$order_data['ip'] = $this->request->server['REMOTE_ADDR'];

			if (!empty($this->request->server['HTTP_X_FORWARDED_FOR'])) {
				$order_data['forwarded_ip'] = $this->request->server['HTTP_X_FORWARDED_FOR'];
			} elseif (!empty($this->request->server['HTTP_CLIENT_IP'])) {
				$order_data['forwarded_ip'] = $this->request->server['HTTP_CLIENT_IP'];
			} else {
				$order_data['forwarded_ip'] = '';
			}

			if (isset($this->request->server['HTTP_USER_AGENT'])) {
				$order_data['user_agent'] = $this->request->server['HTTP_USER_AGENT'];
			} else {
				$order_data['user_agent'] = '';
			}

			if (isset($this->request->server['HTTP_ACCEPT_LANGUAGE'])) {
				$order_data['accept_language'] = $this->request->server['HTTP_ACCEPT_LANGUAGE'];
			} else {
				$order_data['accept_language'] = '';
			}
			
			$this->load->model('checkout/order');

			$this->session->data['order_id'] = $this->model_checkout_order->addOrder($order_data);
			
			$order_data['order_id'] = $this->session->data['order_id'];
									
			$_config = new \Opencart\System\Engine\Config();
			$_config->addPath(DIR_EXTENSION . 'paypal/system/config/');
			$_config->load('paypal');
			
			$config_setting = $_config->get('paypal_setting');
		
			$setting = array_replace_recursive((array)$config_setting, (array)$this->config->get('payment_paypal_setting'));
			
			$client_id = $this->config->get('payment_paypal_client_id');
			$secret = $this->config->get('payment_paypal_secret');
			$environment = $this->config->get('payment_paypal_environment');
			$partner_id = $setting['partner'][$environment]['partner_id'];
			$partner_attribution_id = $setting['partner'][$environment]['partner_attribution_id'];
			$vault_status = $setting['general']['vault_status'];
			$transaction_method = $setting['general']['transaction_method'];
			
			$currency_code = $this->session->data['currency'];
			$currency_value = $this->currency->getValue($this->session->data['currency']);
				
			if (empty($setting['currency'][$currency_code]['status'])) {
				$currency_code = $setting['general']['currency_code'];
				$currency_value = $setting['general']['currency_value'];
			}
			
			$decimal_place = $setting['currency'][$currency_code]['decimal_place'];
			
			require_once DIR_EXTENSION . 'paypal/system/library/paypal.php';
		
			$paypal_info = [
				'partner_id' => $partner_id,
				'client_id' => $client_id,
				'secret' => $secret,
				'environment' => $environment,
				'partner_attribution_id' => $partner_attribution_id
			];
			
			if (isset($this->session->data['paypal_client_metadata_id'])) {
				$paypal_info['client_metadata_id'] = $this->session->data['paypal_client_metadata_id'];
			}
		
			$paypal = new \Opencart\System\Library\PayPal($paypal_info);
			
			$token_info = [
				'grant_type' => 'client_credentials'
			];	
				
			$paypal->setAccessToken($token_info);
			
			if (isset($this->session->data['paypal_order_id'])) {
				$paypal_order_id = $this->session->data['paypal_order_id'];
				
				$paypal_order_info = $paypal->getOrder($paypal_order_id);
				
				if ($paypal->hasErrors()) {
					$error_messages = [];
					
					$errors = $paypal->getErrors();
									
					foreach ($errors as $error) {
						if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
							$error['message'] = $this->language->get('error_timeout');
						}
						
						if (isset($error['details'][0]['description'])) {
							$error_messages[] = $error['details'][0]['description'];
						} elseif (isset($error['message'])) {
							$error_messages[] = $error['message'];
						}
						
						$this->model_extension_paypal_payment_paypal->log($error, $error['message']);
					}
				
					$this->error['warning'] = implode(' ', $error_messages);
				}
			
				if (!empty($this->error['warning'])) {
					$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
				}
			
				$shipping_info_name = [];
				$shipping_info_address = [];
						
				if ($paypal_order_info && !$this->error) {
					$shipping_info_name = (isset($paypal_order_info['purchase_units'][0]['shipping']['name']) ? $paypal_order_info['purchase_units'][0]['shipping']['name'] : []); 
					$shipping_info_address = (isset($paypal_order_info['purchase_units'][0]['shipping']['address']) ? $paypal_order_info['purchase_units'][0]['shipping']['address'] : []);
				}
				
				$paypal_order_info = [];
				
				$paypal_order_info[] = [
					'op' => 'add',
					'path' => '/purchase_units/@reference_id==\'default\'/description',
					'value' => 'Your order ' . $this->session->data['order_id']
				];
				
				if ($setting['general']['invoice_id_tokenization_status']) {
					$paypal_order_info[] = array(
						'op' => 'add',
						'path' => '/purchase_units/@reference_id==\'default\'/invoice_id',
						'value' => $this->session->data['order_id'] . '_' . date('Ymd_His')
					);
				} else {
					$paypal_order_info[] = array(
						'op' => 'add',
						'path' => '/purchase_units/@reference_id==\'default\'/invoice_id',
						'value' => $this->session->data['order_id']
					);
				}
							
				$shipping_info = [];

				if ($this->cart->hasShipping()) {
					$shipping_info['name']['full_name'] = (isset($this->session->data['shipping_address']['firstname']) ? $this->session->data['shipping_address']['firstname'] : '');
					$shipping_info['name']['full_name'] .= (isset($this->session->data['shipping_address']['lastname']) ? (' ' . $this->session->data['shipping_address']['lastname']) : '');			
					$shipping_info['address']['address_line_1'] = (isset($this->session->data['shipping_address']['address_1']) ? $this->session->data['shipping_address']['address_1'] : '');
					$shipping_info['address']['address_line_2'] = (isset($this->session->data['shipping_address']['address_2']) ? $this->session->data['shipping_address']['address_2'] : '');			
					$shipping_info['address']['admin_area_1'] = (isset($this->session->data['shipping_address']['zone']) ? $this->session->data['shipping_address']['zone'] : '');
					$shipping_info['address']['admin_area_2'] = (isset($this->session->data['shipping_address']['city']) ? $this->session->data['shipping_address']['city'] : '');
					$shipping_info['address']['postal_code'] = (isset($this->session->data['shipping_address']['postcode']) ? $this->session->data['shipping_address']['postcode'] : '');
				
					if (isset($this->session->data['shipping_address']['country_id'])) {
						$country_info = $this->model_extension_paypal_payment_paypal->getCountry($this->session->data['shipping_address']['country_id']);
				
						if ($country_info) {
							$shipping_info['address']['country_code'] = $country_info['iso_code_2'];
						}
					}
					
					if ($shipping_info_name) {
						$paypal_order_info[] = array(
							'op' => 'replace',
							'path' => '/purchase_units/@reference_id==\'default\'/shipping/name',
							'value' => $shipping_info['name']
						);
					} else {
						$paypal_order_info[] = array(
							'op' => 'add',
							'path' => '/purchase_units/@reference_id==\'default\'/shipping/name',
							'value' => $shipping_info['name']
						);
					}
					
					if ($shipping_info_address) {
						$paypal_order_info[] = array(
							'op' => 'replace',
							'path' => '/purchase_units/@reference_id==\'default\'/shipping/address',
							'value' => $shipping_info['address']
						);
					} else {
						$paypal_order_info[] = array(
							'op' => 'add',
							'path' => '/purchase_units/@reference_id==\'default\'/shipping/address',
							'value' => $shipping_info['address']
						);
					}
				}
													
				$item_total = 0;
				$tax_total = 0;
					
				foreach ($this->cart->getProducts() as $product) {
					$product_price = number_format($product['price'] * $currency_value, $decimal_place, '.', '');
					
					$item_total += $product_price * $product['quantity'];
					
					if ($product['tax_class_id']) {
						$tax_rates = $this->tax->getRates($product['price'], $product['tax_class_id']);

						foreach ($tax_rates as $tax_rate) {
							$tax_total += ($tax_rate['amount'] * $product['quantity']);
						}
					}
				}
				
				if (!empty($this->session->data['vouchers'])) {
					foreach ($this->session->data['vouchers'] as $voucher) {
						$item_total += $voucher['amount'];
					}
				}
													
				$item_total = number_format($item_total, $decimal_place, '.', '');
				$tax_total = number_format($tax_total * $currency_value, $decimal_place, '.', '');
							
				$discount_total = 0;
				$handling_total = 0;
				$shipping_total = 0;
			
				if (isset($this->session->data['shipping_method'])) {
					if (version_compare(VERSION, '4.0.2.0', '>=')) {
						$shipping = explode('.', $this->session->data['shipping_method']['code']);
					} else {
						$shipping = explode('.', $this->session->data['shipping_method']);
					}

					if (isset($shipping[0]) && isset($shipping[1]) && isset($this->session->data['shipping_methods'][$shipping[0]]['quote'][$shipping[1]])) {
						$shipping_method_info = $this->session->data['shipping_methods'][$shipping[0]]['quote'][$shipping[1]];
						
						$shipping_total = $this->tax->calculate($shipping_method_info['cost'], $shipping_method_info['tax_class_id'], true);
						$shipping_total = number_format($shipping_total * $currency_value, $decimal_place, '.', '');
					}
				}
			
				$order_total = number_format($order_data['total'] * $currency_value, $decimal_place, '.', '');
			
				$rebate = number_format($item_total + $tax_total + $shipping_total - $order_total, $decimal_place, '.', '');
			
				if ($rebate > 0) {
					$discount_total = $rebate;
				} elseif ($rebate < 0) {
					$handling_total = -$rebate;
				}
				
				$amount_info = [];
					
				$amount_info['currency_code'] = $currency_code;
				$amount_info['value'] = $order_total;
									
				$amount_info['breakdown']['item_total'] = [
					'currency_code' => $currency_code,
					'value' => $item_total
				];
					
				$amount_info['breakdown']['tax_total'] = [
					'currency_code' => $currency_code,
					'value' => $tax_total
				];
					
				$amount_info['breakdown']['shipping'] = [
					'currency_code' => $currency_code,
					'value' => $shipping_total
				];
						
				$amount_info['breakdown']['handling'] = [
					'currency_code' => $currency_code,
					'value' => $handling_total
				];
						
				$amount_info['breakdown']['discount'] = [
					'currency_code' => $currency_code,
					'value' => $discount_total
				];			
				
				$paypal_order_info[] = [
					'op' => 'replace',
					'path' => '/purchase_units/@reference_id==\'default\'/amount',
					'value' => $amount_info
				];
						
				$result = $paypal->updateOrder($paypal_order_id, $paypal_order_info);
				
				if ($paypal->hasErrors()) {
					$error_messages = [];
					
					$errors = $paypal->getErrors();
								
					foreach ($errors as $error) {
						if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
							$error['message'] = $this->language->get('error_timeout');
						}
						
						if (isset($error['details'][0]['description'])) {
							$error_messages[] = $error['details'][0]['description'];
						} elseif (isset($error['message'])) {
							$error_messages[] = $error['message'];
						}
						
						$this->model_extension_paypal_payment_paypal->log($error, $error['message']);
					}
					
					$this->error['warning'] = implode(' ', $error_messages);
				}
				
				if (!empty($this->error['warning'])) {
					$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
				}
							
				if ($paypal_order_id && !$this->error) {				
					if ($transaction_method == 'authorize') {
						$result = $paypal->setOrderAuthorize($paypal_order_id);
					} else {
						$result = $paypal->setOrderCapture($paypal_order_id);
					}
				
					if ($paypal->hasErrors()) {
						$error_messages = [];
					
						$errors = $paypal->getErrors();
									
						foreach ($errors as $error) {
							if (isset($error['details'][0]['issue']) && ($error['details'][0]['issue'] == 'INSTRUMENT_DECLINED')) {
								$data['restart'] = true;
							}
						
							if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
								$error['message'] = $this->language->get('error_timeout');
							}
						
							if (isset($error['details'][0]['description'])) {
								$error_messages[] = $error['details'][0]['description'];
							} elseif (isset($error['message'])) {
								$error_messages[] = $error['message'];
							}
						
							$this->model_extension_paypal_payment_paypal->log($error, $error['message']);
						}
					
						$this->error['warning'] = implode(' ', $error_messages);
					}
				
					if (!empty($this->error['warning'])) {
						$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
					}
				
					if (!$this->error) {				
						if ($transaction_method == 'authorize') {
							$this->model_extension_paypal_payment_paypal->log($result, 'Authorize Order');
				
							if (isset($result['purchase_units'][0]['payments']['authorizations'][0]['status']) && isset($result['purchase_units'][0]['payments']['authorizations'][0]['seller_protection']['status'])) {
								$authorization_id = $result['purchase_units'][0]['payments']['authorizations'][0]['id'];
								$authorization_status = $result['purchase_units'][0]['payments']['authorizations'][0]['status'];
								$seller_protection_status = $result['purchase_units'][0]['payments']['authorizations'][0]['seller_protection']['status'];							
								$order_status_id = 0;
								$transaction_status = '';
								$payment_method = '';
								$vault_id = '';
								$vault_customer_id = '';
								$card_type = '';
								$card_nice_type = '';
								$card_last_digits = '';
								$card_expiry = '';
									
								if (!$this->cart->hasShipping()) {
									$seller_protection_status = 'NOT_ELIGIBLE';
								}
									
								foreach ($result['payment_source'] as $payment_source_key => $payment_source) {
									$payment_method = $payment_source_key;
									$vault_id = (isset($payment_source['attributes']['vault']['id']) ? $payment_source['attributes']['vault']['id'] : '');
									$vault_customer_id = (isset($payment_source['attributes']['vault']['customer']['id']) ? $payment_source['attributes']['vault']['customer']['id'] : '');
									$card_last_digits = (isset($payment_source['last_digits']) ? $payment_source['last_digits'] : '');
									$card_expiry = (isset($payment_source['expiry']) ? $payment_source['expiry'] : '');
										
									break;
								}

								if ($authorization_status == 'CREATED') {
									$order_status_id = $setting['order_status']['pending']['id'];
									$transaction_status = 'created';
								}

								if ($authorization_status == 'CAPTURED') {
									$this->error['warning'] = sprintf($this->language->get('error_authorization_captured'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
								}
							
								if ($authorization_status == 'DENIED') {
									$transaction_status = 'denied';
								
									$this->error['warning'] = $this->language->get('error_authorization_denied');
								}
							
								if ($authorization_status == 'EXPIRED') {
									$this->error['warning'] = sprintf($this->language->get('error_authorization_expired'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
								}
							
								if ($authorization_status == 'PENDING') {
									$order_status_id = $setting['order_status']['pending']['id'];
									$transaction_status = 'pending';
								}
															
								if (($authorization_status == 'CREATED') || ($authorization_status == 'DENIED') || ($authorization_status == 'PENDING')) {
									if ($payment_method == 'paypal') {
										$paypal_customer_token = [];
							
										if ($setting['general']['vault_status'] && $this->customer->isLogged()) {
											$paypal_customer_token = $this->model_extension_paypal_payment_paypal->getPayPalCustomerMainToken($this->customer->getId(), $payment_method);
										}
											
										if (!empty($paypal_customer_token['vault_id'])) {
											$vault_id = $paypal_customer_token['vault_id'];
											$vault_customer_id = $paypal_customer_token['vault_customer_id'];
										}
									}
										
									$this->model_extension_paypal_payment_paypal->deletePayPalOrder($this->session->data['order_id']);
										
									$paypal_order_data = [
										'order_id' => $this->session->data['order_id'],
										'paypal_order_id' => $paypal_order_id,
										'transaction_id' => $authorization_id,
										'transaction_status' => $transaction_status,
										'payment_method' => $payment_method,
										'vault_id' => $vault_id,
										'vault_customer_id' => $vault_customer_id,
										'card_type' => $card_type,
										'card_nice_type' => $card_nice_type,
										'card_last_digits' => $card_last_digits,
										'card_expiry' => $card_expiry,
										'total' => $order_data['total'],
										'currency_code' => $order_data['currency_code'],
										'environment' => $environment
									];

									$this->model_extension_paypal_payment_paypal->addPayPalOrder($paypal_order_data);
										
									if ($vault_id && $this->customer->isLogged()) {
										$customer_id = $this->customer->getId();
											
										$paypal_customer_token_info = $this->model_extension_paypal_payment_paypal->getPayPalCustomerToken($customer_id, $payment_method, $vault_id);
									
										if (!$paypal_customer_token_info) {
											$paypal_customer_token_data = [
												'customer_id' => $customer_id,
												'payment_method' => $payment_method,
												'vault_id' => $vault_id,
												'vault_customer_id' => $vault_customer_id,
												'card_type' => $card_type,
												'card_nice_type' => $card_nice_type,
												'card_last_digits' => $card_last_digits,
												'card_expiry' => $card_expiry,
											];
						
											$this->model_extension_paypal_payment_paypal->addPayPalCustomerToken($paypal_customer_token_data);
										}
											
										$this->model_extension_paypal_payment_paypal->setPayPalCustomerMainToken($customer_id, $payment_method, $vault_id);
									}
								}
								
								if ($order_status_id) {
									$message = sprintf($this->language->get('text_order_message'), $seller_protection_status);
												
									$this->model_checkout_order->addHistory($this->session->data['order_id'], $order_status_id, $message);
								}
									
								if ($order_status_id) {
									$subscriptions = $this->model_extension_paypal_payment_paypal->getSubscriptionsByOrderId($this->session->data['order_id']);
						
									foreach ($subscriptions as $subscription) {
										$this->model_extension_paypal_payment_paypal->subscriptionPayment($subscription, $order_data, $paypal_order_data);
									} 
								}

								if (($authorization_status == 'CREATED') || ($authorization_status == 'PARTIALLY_CAPTURED') || ($authorization_status == 'PARTIALLY_CREATED') || ($authorization_status == 'PENDING')) {
									$this->response->redirect($this->url->link('checkout/success', 'language=' . $this->config->get('config_language')));
								}
							}
						} else {
							$this->model_extension_paypal_payment_paypal->log($result, 'Capture Order');
						
							if (isset($result['purchase_units'][0]['payments']['captures'][0]['status']) && isset($result['purchase_units'][0]['payments']['captures'][0]['seller_protection']['status'])) {
								$capture_id = $result['purchase_units'][0]['payments']['captures'][0]['id'];
								$capture_status = $result['purchase_units'][0]['payments']['captures'][0]['status'];
								$seller_protection_status = $result['purchase_units'][0]['payments']['captures'][0]['seller_protection']['status'];
								$order_status_id = 0;
								$transaction_status = '';
								$payment_method = '';
								$vault_id = '';
								$vault_customer_id = '';
								$card_type = '';
								$card_nice_type = '';
								$card_last_digits = '';
								$card_expiry = '';
									
								if (!$this->cart->hasShipping()) {
									$seller_protection_status = 'NOT_ELIGIBLE';
								}
									
								foreach ($result['payment_source'] as $payment_source_key => $payment_source) {
									$payment_method = $payment_source_key;
									$vault_id = (isset($payment_source['attributes']['vault']['id']) ? $payment_source['attributes']['vault']['id'] : '');
									$vault_customer_id = (isset($payment_source['attributes']['vault']['customer']['id']) ? $payment_source['attributes']['vault']['customer']['id'] : '');
									$card_last_digits = (isset($payment_source['last_digits']) ? $payment_source['last_digits'] : '');
									$card_expiry = (isset($payment_source['expiry']) ? $payment_source['expiry'] : '');
										
									break;
								}
															
								if ($capture_status == 'COMPLETED') {
									$order_status_id = $setting['order_status']['completed']['id'];
									$transaction_status = 'completed';
								}
							
								if ($capture_status == 'DECLINED') {
									$transaction_status = 'denied';
								
									$this->error['warning'] = $this->language->get('error_capture_declined');
								}
							
								if ($capture_status == 'FAILED') {
									$this->error['warning'] = sprintf($this->language->get('error_capture_failed'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
								}
							
								if ($capture_status == 'PENDING') {
									$order_status_id = $setting['order_status']['pending']['id'];
									$transaction_status = 'pending';
								}
														
								if (($capture_status == 'COMPLETED') || ($capture_status == 'DECLINED') || ($capture_status == 'PENDING')) {
									if ($payment_method == 'paypal') {
										$paypal_customer_token = [];
							
										if ($setting['general']['vault_status'] && $this->customer->isLogged()) {
											$paypal_customer_token = $this->model_extension_paypal_payment_paypal->getPayPalCustomerMainToken($this->customer->getId(), $payment_method);
										}
											
										if (!empty($paypal_customer_token['vault_id'])) {
											$vault_id = $paypal_customer_token['vault_id'];
											$vault_customer_id = $paypal_customer_token['vault_customer_id'];
										}
									}
										
									$this->model_extension_paypal_payment_paypal->deletePayPalOrder($this->session->data['order_id']);
										
									$paypal_order_data = [
										'order_id' => $this->session->data['order_id'],
										'paypal_order_id' => $paypal_order_id,
										'transaction_id' => $capture_id,
										'transaction_status' => $transaction_status,
										'payment_method' => $payment_method,
										'vault_id' => $vault_id,
										'vault_customer_id' => $vault_customer_id,
										'card_type' => $card_type,
										'card_nice_type' => $card_nice_type,
										'card_last_digits' => $card_last_digits,
										'card_expiry' => $card_expiry,
										'total' => $order_data['total'],
										'currency_code' => $order_data['currency_code'],
										'environment' => $environment
									];

									$this->model_extension_paypal_payment_paypal->addPayPalOrder($paypal_order_data);
										
									if ($vault_id && $this->customer->isLogged()) {
										$customer_id = $this->customer->getId();
											
										$paypal_customer_token_info = $this->model_extension_paypal_payment_paypal->getPayPalCustomerToken($customer_id, $payment_method, $vault_id);
									
										if (!$paypal_customer_token_info) {
											$paypal_customer_token_data = [
												'customer_id' => $customer_id,
												'payment_method' => $payment_method,
												'vault_id' => $vault_id,
												'vault_customer_id' => $vault_customer_id,
												'card_type' => $card_type,
												'card_nice_type' => $card_nice_type,
												'card_last_digits' => $card_last_digits,
												'card_expiry' => $card_expiry,
											];
						
											$this->model_extension_paypal_payment_paypal->addPayPalCustomerToken($paypal_customer_token_data);
										}
											
										$this->model_extension_paypal_payment_paypal->setPayPalCustomerMainToken($customer_id, $payment_method, $vault_id);
									}
								}
								
								if ($order_status_id) {
									$message = sprintf($this->language->get('text_order_message'), $seller_protection_status);
												
									$this->model_checkout_order->addHistory($this->session->data['order_id'], $order_status_id, $message);
								}
									
								if (($capture_status == 'COMPLETED') || ($capture_status == 'PENDING')) {
									$subscriptions = $this->model_extension_paypal_payment_paypal->getSubscriptionsByOrderId($this->session->data['order_id']);
						
									foreach ($subscriptions as $subscription) {
										$this->model_extension_paypal_payment_paypal->subscriptionPayment($subscription, $order_data, $paypal_order_data);
									} 
								}
															
								if (($capture_status == 'COMPLETED') || ($capture_status == 'PENDING')) {
									$this->response->redirect($this->url->link('checkout/success', 'language=' . $this->config->get('config_language')));
								}
							}
						}
					}
				}
			}
			
			if (isset($this->session->data['paypal_payment_token'])) {	
				$payment_token = $this->session->data['paypal_payment_token'];		
				$payment_method = 'card';
				
				$shipping_info = [];

				if ($this->cart->hasShipping()) {
					$shipping_info['name']['full_name'] = (isset($this->session->data['shipping_address']['firstname']) ? $this->session->data['shipping_address']['firstname'] : '');
					$shipping_info['name']['full_name'] .= (isset($this->session->data['shipping_address']['lastname']) ? (' ' . $this->session->data['shipping_address']['lastname']) : '');			
					$shipping_info['address']['address_line_1'] = (isset($this->session->data['shipping_address']['address_1']) ? $this->session->data['shipping_address']['address_1'] : '');
					$shipping_info['address']['address_line_2'] = (isset($this->session->data['shipping_address']['address_2']) ? $this->session->data['shipping_address']['address_2'] : '');			
					$shipping_info['address']['admin_area_1'] = (isset($this->session->data['shipping_address']['zone']) ? $this->session->data['shipping_address']['zone'] : '');
					$shipping_info['address']['admin_area_2'] = (isset($this->session->data['shipping_address']['city']) ? $this->session->data['shipping_address']['city'] : '');
					$shipping_info['address']['postal_code'] = (isset($this->session->data['shipping_address']['postcode']) ? $this->session->data['shipping_address']['postcode'] : '');
			
					if (isset($this->session->data['shipping_address']['country_id'])) {
						$country_info = $this->model_extension_paypal_payment_paypal->getCountry($this->session->data['shipping_address']['country_id']);
			
						if ($country_info) {
							$shipping_info['address']['country_code'] = $country_info['iso_code_2'];
						}
					}
				}
				
				$item_info = [];
			
				$item_total = 0;
				$tax_total = 0;
								
				foreach ($this->cart->getProducts() as $product) {
					$product_price = number_format($product['price'] * $currency_value, $decimal_place, '.', '');
				
					$product_info = [];
					
					$product_info['name'] = $product['name'];
					$product_info['quantity'] = $product['quantity'];
					$product_info['sku'] = $product['model'];
					$product_info['url'] = $this->url->link('product/product', 'language=' . $this->config->get('config_language') . '&product_id=' . $product['product_id']);
										
					$product_info['unit_amount'] = [
						'currency_code' => $currency_code,
						'value' => $product_price
					];

					$item_info[] = $product_info;
				
					$item_total += $product_price * $product['quantity'];
				
					if ($product['tax_class_id']) {
						$tax_rates = $this->tax->getRates($product['price'], $product['tax_class_id']);

						foreach ($tax_rates as $tax_rate) {
							$tax_total += ($tax_rate['amount'] * $product['quantity']);
						}
					}
				}
				
				if (!empty($this->session->data['vouchers'])) {
					foreach ($this->session->data['vouchers'] as $voucher) {
						$voucher_info = [];
	
						$voucher_info['name'] = $voucher['description'];
						$voucher_info['quantity'] = 1;
						
						$voucher_info['unit_amount'] = [
							'currency_code' => $currency_code,
							'value' => $voucher['amount']
						];
												
						$item_info[] = $voucher_info;
					
						$item_total += $voucher['amount'];
					}
				}
				
				$item_total = number_format($item_total, $decimal_place, '.', '');
				$tax_total = number_format($tax_total * $currency_value, $decimal_place, '.', '');
				$order_total = number_format($item_total + $tax_total, $decimal_place, '.', '');
				
				$discount_total = 0;
				$handling_total = 0;
				$shipping_total = 0;
								
				if (isset($this->session->data['shipping_method'])) {
					if (version_compare(VERSION, '4.0.2.0', '>=')) {
						$shipping = explode('.', $this->session->data['shipping_method']['code']);
					} else {
						$shipping = explode('.', $this->session->data['shipping_method']);
					}

					if (isset($shipping[0]) && isset($shipping[1]) && isset($this->session->data['shipping_methods'][$shipping[0]]['quote'][$shipping[1]])) {
						$shipping_method_info = $this->session->data['shipping_methods'][$shipping[0]]['quote'][$shipping[1]];
						
						$shipping_total = $this->tax->calculate($shipping_method_info['cost'], $shipping_method_info['tax_class_id'], true);
						$shipping_total = number_format($shipping_total * $currency_value, $decimal_place, '.', '');
					}
				}
		
				$order_total = number_format($order_data['total'] * $currency_value, $decimal_place, '.', '');
		
				$rebate = number_format($item_total + $tax_total + $shipping_total - $order_total, $decimal_place, '.', '');
		
				if ($rebate > 0) {
					$discount_total = $rebate;
				} elseif ($rebate < 0) {
					$handling_total = -$rebate;
				}
				
				$amount_info = [];
				
				$amount_info['currency_code'] = $currency_code;
				$amount_info['value'] = $order_total;
								
				$amount_info['breakdown']['item_total'] = [
					'currency_code' => $currency_code,
					'value' => $item_total
				];
				
				$amount_info['breakdown']['tax_total'] = [
					'currency_code' => $currency_code,
					'value' => $tax_total
				];
				
				$amount_info['breakdown']['shipping'] = [
					'currency_code' => $currency_code,
					'value' => $shipping_total
				];
					
				$amount_info['breakdown']['handling'] = [
					'currency_code' => $currency_code,
					'value' => $handling_total
				];
					
				$amount_info['breakdown']['discount'] = [
					'currency_code' => $currency_code,
					'value' => $discount_total
				];
								
				$paypal_order_info = array();
				
				$paypal_order_info['intent'] = strtoupper($transaction_method);
				$paypal_order_info['purchase_units'][0]['reference_id'] = 'default';
				$paypal_order_info['purchase_units'][0]['items'] = $item_info;
				$paypal_order_info['purchase_units'][0]['amount'] = $amount_info;
				$paypal_order_info['purchase_units'][0]['description'] = 'Your order ' . $this->session->data['order_id'];
				
				if ($setting['general']['invoice_id_tokenization_status']) {
					$paypal_order_info['purchase_units'][0]['invoice_id'] = $this->session->data['order_id'] . '_' . date('Ymd_His');
				} else {
					$paypal_order_info['purchase_units'][0]['invoice_id'] = $this->session->data['order_id'];
				}
					
				if ($this->cart->hasShipping()) {
					$paypal_order_info['purchase_units'][0]['shipping'] = $shipping_info;
				}
								
				if ($this->cart->hasShipping()) {			
					$shipping_preference = 'GET_FROM_FILE';
				} else {
					$shipping_preference = 'NO_SHIPPING';
				}
	
				$paypal_order_info['application_context']['shipping_preference'] = $shipping_preference;
								
				if ($payment_method) {
					$paypal_order_info['payment_source'][$payment_method]['single_use_token'] = $payment_token;
					$paypal_order_info['payment_source'][$payment_method]['attributes']['verification']['method'] = strtoupper($setting['card']['secure_method']);
					$paypal_order_info['payment_source'][$payment_method]['experience_context']['return_url'] = $this->url->link('extension/paypal/payment/paypal', 'callback_token=' . $setting['general']['callback_token'] . '&language=' . $this->config->get('config_language'), true);
					$paypal_order_info['payment_source'][$payment_method]['experience_context']['cancel_url'] = $this->url->link('checkout/checkout', 'language=' . $this->config->get('config_language'), true);
				}
				
				$result = $paypal->createOrder($paypal_order_info);
				
				if ($paypal->hasErrors()) {
					$error_messages = array();
				
					$errors = $paypal->getErrors();
								
					foreach ($errors as $error) {
						if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
							$error['message'] = $this->language->get('error_timeout');
						}
				
						if (isset($error['details'][0]['description'])) {
							$error_messages[] = $error['details'][0]['description'];
						} elseif (isset($error['message'])) {
							$error_messages[] = $error['message'];
						}
					
						$this->model_extension_paypal_payment_paypal->log($error, $error['message']);
					}
				
					$this->error['warning'] = implode(' ', $error_messages);
				}
		
				if (!empty($this->error['warning'])) {
					$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'),  $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
				}
						
				if (isset($result['id']) && isset($result['status']) && !$this->error) {
					$this->model_extension_paypal_payment_paypal->log($result, 'Create Order');
								
					if ($result['status'] == 'VOIDED') {
						$this->error['warning'] = sprintf($this->language->get('error_order_voided'),  $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
					}
													
					if ($result['status'] == 'COMPLETED') {
						$paypal_order_id = $result['id'];
						
						$vault_id = '';
						$vault_customer_id = '';
						$card_type = '';
						$card_nice_type = '';
						$card_last_digits = '';
						$card_expiry = '';
					
						$paypal_order_info = $paypal->getOrder($paypal_order_id);
												
						if ($paypal->hasErrors()) {
							$error_messages = array();
				
							$errors = $paypal->getErrors();
								
							foreach ($errors as $error) {
								if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
									$error['message'] = $this->language->get('error_timeout');
								}
					
								if (isset($error['details'][0]['description'])) {
									$error_messages[] = $error['details'][0]['description'];
								} elseif (isset($error['message'])) {
									$error_messages[] = $error['message'];
								}
					
								$this->model_extension_paypal_payment_paypal->log($error, $error['message']);
							}
		
							$this->error['warning'] = implode(' ', $error_messages);
						}
							
						if (isset($paypal_order_info['payment_source']['card']) && !$this->error) {
							$this->model_extension_paypal_payment_paypal->log($paypal_order_info['payment_source']['card'], 'Card');
						
							$liability_shift = (isset($paypal_order_info['payment_source']['card']['authentication_result']['liability_shift']) ? $paypal_order_info['payment_source']['card']['authentication_result']['liability_shift'] : '');
							$enrollment_status = (isset($paypal_order_info['payment_source']['card']['authentication_result']['three_d_secure']['enrollment_status']) ? $paypal_order_info['payment_source']['card']['authentication_result']['three_d_secure']['enrollment_status'] : '');
							$authentication_status = (isset($paypal_order_info['payment_source']['card']['authentication_result']['three_d_secure']['authentication_status']) ? $paypal_order_info['payment_source']['card']['authentication_result']['three_d_secure']['authentication_status'] : '');
								
							if ($enrollment_status == 'Y') {
								if (($authentication_status == 'N') && !$setting['card']['secure_scenario']['failed_authentication']) {
									$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['failed_authentication']['error']);
								}
						
								if (($authentication_status == 'R') && !$setting['card']['secure_scenario']['rejected_authentication']) {
									$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['rejected_authentication']['error']);
								}
						
								if (($authentication_status == 'A') && !$setting['card']['secure_scenario']['attempted_authentication']) {
									$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['attempted_authentication']['error']);
								}
						
								if (($authentication_status == 'U') && !$setting['card']['secure_scenario']['unable_authentication']) {
									$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['unable_authentication']['error']);
								}
						
								if (($authentication_status == 'C') && !$setting['card']['secure_scenario']['challenge_authentication']) {
									$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['challenge_authentication']['error']);
								}
							}
					
							if (($enrollment_status == 'N') && !$setting['card']['secure_scenario']['card_ineligible']) {
								$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['card_ineligible']['error']);
							}
					
							if (($enrollment_status == 'U') && !$setting['card']['secure_scenario']['system_unavailable']) {
								$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['system_unavailable']['error']);
							}
					
							if (($enrollment_status == 'B') && !$setting['card']['secure_scenario']['system_bypassed']) {
								$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['system_bypassed']['error']);
							}
						}
		
						if (!empty($this->error['warning'])) {
							$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'),  $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
						}
				
						if (!$this->error) {				
							$this->load->model('checkout/order');
				
							$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
						
							if ($transaction_method == 'authorize') {
								$this->model_extension_paypal_payment_paypal->log($result, 'Authorize Order');
					
								if (isset($result['purchase_units'][0]['payments']['authorizations'][0]['status']) && isset($result['purchase_units'][0]['payments']['authorizations'][0]['seller_protection']['status'])) {
									$authorization_id = $result['purchase_units'][0]['payments']['authorizations'][0]['id'];
									$authorization_status = $result['purchase_units'][0]['payments']['authorizations'][0]['status'];
									$seller_protection_status = $result['purchase_units'][0]['payments']['authorizations'][0]['seller_protection']['status'];	  						
									$order_status_id = 0;
									$transaction_status = '';
									$payment_method = '';
								
									if (!$this->cart->hasShipping()) {
										$seller_protection_status = 'NOT_ELIGIBLE';
									}
								
									foreach ($result['payment_source'] as $payment_source_key => $payment_source) {
										$payment_method = $payment_source_key;
										$vault_id = (isset($payment_source['attributes']['vault']['id']) ? $payment_source['attributes']['vault']['id'] : '');
										$vault_customer_id = (isset($payment_source['attributes']['vault']['customer']['id']) ? $payment_source['attributes']['vault']['customer']['id'] : '');
										$card_last_digits = (isset($payment_source['last_digits']) ? $payment_source['last_digits'] : '');
										$card_expiry = (isset($payment_source['expiry']) ? $payment_source['expiry'] : '');
									
										break;
									}
								
									if ($authorization_status == 'CREATED') {
										$order_status_id = $setting['order_status']['pending']['id'];
										$transaction_status = 'created';
									}

									if ($authorization_status == 'CAPTURED') {
										$this->error['warning'] = sprintf($this->language->get('error_authorization_captured'),  $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
									}
						
									if ($authorization_status == 'DENIED') {
										$transaction_status = 'denied';
							
										$this->error['warning'] = $this->language->get('error_authorization_denied');
									}
						
									if ($authorization_status == 'EXPIRED') {
										$this->error['warning'] = sprintf($this->language->get('error_authorization_expired'),  $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
									}
						
									if ($authorization_status == 'PENDING') {
										$order_status_id = $setting['order_status']['pending']['id'];
										$transaction_status = 'pending';
									}
														
									if (($authorization_status == 'CREATED') || ($authorization_status == 'DENIED') || ($authorization_status == 'PENDING')) {
										$this->model_extension_paypal_payment_paypal->deletePayPalOrder($this->session->data['order_id']);
									
										$paypal_order_data = array(
											'order_id' => $this->session->data['order_id'],
											'paypal_order_id' => $paypal_order_id,
											'transaction_id' => $authorization_id,
											'transaction_status' => $transaction_status,
											'payment_method' => $payment_method,
											'vault_id' => $vault_id,
											'vault_customer_id' => $vault_customer_id,
											'card_type' => $card_type,
											'card_nice_type' => $card_nice_type,
											'card_last_digits' => $card_last_digits,
											'card_expiry' => $card_expiry,
											'total' => $order_info['total'],
											'currency_code' => $order_info['currency_code'],
											'environment' => $environment
										);

										$this->model_extension_paypal_payment_paypal->addPayPalOrder($paypal_order_data);
									
										if ($vault_id && $this->customer->isLogged()) {
											$customer_id = $this->customer->getId();
										
											$paypal_customer_token_info = $this->model_extension_paypal_payment_paypal->getPayPalCustomerToken($customer_id, $payment_method, $vault_id);
								
											if (!$paypal_customer_token_info) {
												$paypal_customer_token_data = array(
													'customer_id' => $customer_id,
													'payment_method' => $payment_method,
													'vault_id' => $vault_id,
													'vault_customer_id' => $vault_customer_id,
													'card_type' => $card_type,
													'card_nice_type' => $card_nice_type,
													'card_last_digits' => $card_last_digits,
													'card_expiry' => $card_expiry
												);
					
												$this->model_extension_paypal_payment_paypal->addPayPalCustomerToken($paypal_customer_token_data);
											}
										
											$this->model_extension_paypal_payment_paypal->setPayPalCustomerMainToken($customer_id, $payment_method, $vault_id);
										}
									}
									
									if ($order_status_id) {
										$message = sprintf($this->language->get('text_order_message'), $seller_protection_status);
								
										$this->model_checkout_order->addHistory($this->session->data['order_id'], $order_status_id, $message);
									}
									
									if (($authorization_status == 'CREATED') || ($authorization_status == 'PENDING')) {
										$subscriptions = $this->model_extension_paypal_payment_paypal->getSubscriptionsByOrderId($this->session->data['order_id']);
					
										foreach ($subscriptions as $subscription) {
											$this->model_extension_paypal_payment_paypal->subscriptionPayment($subscription, $order_info, $paypal_order_data);
										} 										
									}
													
									if (($authorization_status == 'CREATED') || ($authorization_status == 'PARTIALLY_CAPTURED') || ($authorization_status == 'PARTIALLY_CREATED') || ($authorization_status == 'PENDING')) {
										$this->response->redirect($this->url->link('checkout/success', 'language=' . $this->config->get('config_language')));
									}
								}
							} else {
								$this->model_extension_paypal_payment_paypal->log($result, 'Capture Order');
					
								if (isset($result['purchase_units'][0]['payments']['captures'][0]['status']) && isset($result['purchase_units'][0]['payments']['captures'][0]['seller_protection']['status'])) {
									$capture_id = $result['purchase_units'][0]['payments']['captures'][0]['id'];
									$capture_status = $result['purchase_units'][0]['payments']['captures'][0]['status'];
									$seller_protection_status = $result['purchase_units'][0]['payments']['captures'][0]['seller_protection']['status'];
									
									$order_status_id = 0;
									$transaction_status = '';
									$payment_method = '';
							
									if (!$this->cart->hasShipping()) {
										$seller_protection_status = 'NOT_ELIGIBLE';
									}
									
									foreach ($result['payment_source'] as $payment_source_key => $payment_source) {
										$payment_method = $payment_source_key;
										$vault_id = (isset($payment_source['attributes']['vault']['id']) ? $payment_source['attributes']['vault']['id'] : '');
										$vault_customer_id = (isset($payment_source['attributes']['vault']['customer']['id']) ? $payment_source['attributes']['vault']['customer']['id'] : '');
										$card_last_digits = (isset($payment_source['last_digits']) ? $payment_source['last_digits'] : '');
										$card_expiry = (isset($payment_source['expiry']) ? $payment_source['expiry'] : '');
									
										break;
									}
																						
									if ($capture_status == 'COMPLETED') {
										$order_status_id = $setting['order_status']['completed']['id'];
										$transaction_status = 'completed';
									}
						
									if ($capture_status == 'DECLINED') {
										$transaction_status = 'denied';
							
										$this->error['warning'] = $this->language->get('error_capture_declined');
									}
						
									if ($capture_status == 'FAILED') {
										$this->error['warning'] = sprintf($this->language->get('error_capture_failed'),  $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
									}
						
									if ($capture_status == 'PENDING') {
										$order_status_id = $setting['order_status']['pending']['id'];
										$transaction_status = 'pending';
									}
														
									if (($capture_status == 'COMPLETED') || ($capture_status == 'DECLINED') || ($capture_status == 'PENDING')) {
										$this->model_extension_paypal_payment_paypal->deletePayPalOrder($this->session->data['order_id']);
									
										$paypal_order_data = array(
											'order_id' => $this->session->data['order_id'],
											'paypal_order_id' => $paypal_order_id,
											'transaction_id' => $capture_id,
											'transaction_status' => $transaction_status,
											'payment_method' => $payment_method,
											'vault_id' => $vault_id,
											'vault_customer_id' => $vault_customer_id,
											'card_type' => $card_type,
											'card_nice_type' => $card_nice_type,
											'card_last_digits' => $card_last_digits,
											'card_expiry' => $card_expiry,
											'total' => $order_info['total'],
											'currency_code' => $order_info['currency_code'],
											'environment' => $environment
										);

										$this->model_extension_paypal_payment_paypal->addPayPalOrder($paypal_order_data);
									
										if ($vault_id && $this->customer->isLogged()) {
											$customer_id = $this->customer->getId();
										
											$paypal_customer_token_info = $this->model_extension_paypal_payment_paypal->getPayPalCustomerToken($customer_id, $payment_method, $vault_id);
								
											if (!$paypal_customer_token_info) {
												$paypal_customer_token_data = array(
													'customer_id' => $customer_id,
													'payment_method' => $payment_method,
													'vault_id' => $vault_id,
													'vault_customer_id' => $vault_customer_id,
													'card_type' => $card_type,
													'card_nice_type' => $card_nice_type,
													'card_last_digits' => $card_last_digits,
													'card_expiry' => $card_expiry
												);
					
												$this->model_extension_paypal_payment_paypal->addPayPalCustomerToken($paypal_customer_token_data);
											}
										
											$this->model_extension_paypal_payment_paypal->setPayPalCustomerMainToken($customer_id, $payment_method, $vault_id);
										}
									}
									
									if ($order_status_id) {
										$message = sprintf($this->language->get('text_order_message'), $seller_protection_status);
								
										$this->model_checkout_order->addHistory($this->session->data['order_id'], $order_status_id, $message);
									}
									
									if (($capture_status == 'COMPLETED') || ($capture_status == 'PENDING')) {
										$subscriptions = $this->model_extension_paypal_payment_paypal->getSubscriptionsByOrderId($this->session->data['order_id']);
					
										foreach ($subscriptions as $subscription) {
											$this->model_extension_paypal_payment_paypal->subscriptionPayment($subscription, $order_info, $paypal_order_data);
										} 	
									}
						
									if (($capture_status == 'COMPLETED') || ($capture_status == 'PENDING')) {
										$this->response->redirect($this->url->link('checkout/success', 'language=' . $this->config->get('config_language')));
									}
								}
							}
						}
					}
				}
			}
		
			unset($this->session->data['paypal_order_id']);
			unset($this->session->data['paypal_payment_token']);
			
			if ($this->error) {								
				$this->session->data['error'] = $this->error['warning'];
				
				$this->response->redirect($this->url->link('checkout/checkout', 'language=' . $this->config->get('config_language')));
			}
		}
		
		$this->response->redirect($this->url->link('checkout/cart', 'language=' . $this->config->get('config_language')));
	}
	
	public function paymentAddress(): void {
		$this->load->language('extension/paypal/payment/paypal');
		
		$data['language'] = $this->config->get('config_language');
		
		$data['customer'] = isset($this->session->data['customer']) ? $this->session->data['customer'] : [];
		$data['payment_address'] = isset($this->session->data['payment_address']) ? $this->session->data['payment_address'] : [];
				
		$this->load->model('localisation/country');

		$data['countries'] = $this->model_localisation_country->getCountries();
		
		$this->load->model('account/custom_field');

		$data['custom_fields'] = $this->model_account_custom_field->getCustomFields();
		
		$this->response->setOutput($this->load->view('extension/paypal/payment/payment_address', $data));
	}
	
	public function shippingAddress(): void {
		$this->load->language('extension/paypal/payment/paypal');
		
		$data['language'] = $this->config->get('config_language');
		
		$data['shipping_address'] = isset($this->session->data['shipping_address']) ? $this->session->data['shipping_address'] : [];
				
		$this->load->model('localisation/country');

		$data['countries'] = $this->model_localisation_country->getCountries();
		
		$this->load->model('account/custom_field');

		$data['custom_fields'] = $this->model_account_custom_field->getCustomFields();
		
		$this->response->setOutput($this->load->view('extension/paypal/payment/shipping_address', $data));
	}
	
	public function confirmShipping(): void {
		$this->validateShipping($this->request->post['shipping_method']);

		$this->response->redirect($this->url->link('extension/paypal/payment/paypal' . $this->separator . 'confirmOrder', 'language=' . $this->config->get('config_language')));
	}
	
	public function confirmPaymentAddress(): void {
		$this->load->language('extension/paypal/payment/paypal');
		
		$data['url'] = '';
		
		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validatePaymentAddress()) {			
			$this->session->data['customer']['firstname'] = $this->request->post['firstname'];
			$this->session->data['customer']['lastname'] = $this->request->post['lastname'];
			$this->session->data['customer']['email'] = $this->request->post['email'];
			$this->session->data['customer']['telephone'] = $this->request->post['telephone'];

			if (isset($this->request->post['custom_field']['account'])) {
				$this->session->data['customer']['custom_field'] = $this->request->post['custom_field']['account'];
			} else {
				$this->session->data['customer']['custom_field'] = [];
			}

			$this->session->data['payment_address']['firstname'] = $this->request->post['firstname'];
			$this->session->data['payment_address']['lastname'] = $this->request->post['lastname'];
			$this->session->data['payment_address']['company'] = $this->request->post['company'];
			$this->session->data['payment_address']['address_1'] = $this->request->post['address_1'];
			$this->session->data['payment_address']['address_2'] = $this->request->post['address_2'];
			$this->session->data['payment_address']['postcode'] = $this->request->post['postcode'];
			$this->session->data['payment_address']['city'] = $this->request->post['city'];
			$this->session->data['payment_address']['country_id'] = $this->request->post['country_id'];
			$this->session->data['payment_address']['zone_id'] = $this->request->post['zone_id'];

			$this->load->model('extension/paypal/payment/paypal');

			$country_info = $this->model_extension_paypal_payment_paypal->getCountry($this->request->post['country_id']);

			if ($country_info) {
				$this->session->data['payment_address']['country'] = $country_info['name'];
				$this->session->data['payment_address']['iso_code_2'] = $country_info['iso_code_2'];
				$this->session->data['payment_address']['iso_code_3'] = $country_info['iso_code_3'];
				$this->session->data['payment_address']['address_format'] = $country_info['address_format'];
			} else {
				$this->session->data['payment_address']['country'] = '';
				$this->session->data['payment_address']['iso_code_2'] = '';
				$this->session->data['payment_address']['iso_code_3'] = '';
				$this->session->data['payment_address']['address_format'] = '';
			}

			if (isset($this->request->post['custom_field']['address'])) {
				$this->session->data['payment_address']['custom_field'] = $this->request->post['custom_field']['address'];
			} else {
				$this->session->data['payment_address']['custom_field'] = [];
			}

			$this->load->model('localisation/zone');

			$zone_info = $this->model_localisation_zone->getZone($this->request->post['zone_id']);

			if ($zone_info) {
				$this->session->data['payment_address']['zone'] = $zone_info['name'];
				$this->session->data['payment_address']['zone_code'] = $zone_info['code'];
			} else {
				$this->session->data['payment_address']['zone'] = '';
				$this->session->data['payment_address']['zone_code'] = '';
			}
			
			$data['url'] = $this->url->link('extension/paypal/payment/paypal' . $this->separator . 'confirmOrder', 'language=' . $this->config->get('config_language'));
		}

		$data['error'] = $this->error;
		
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}
	
	public function confirmShippingAddress(): void {
		$this->load->language('extension/paypal/payment/paypal');
		
		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validateShippingAddress()) {			
			$this->session->data['shipping_address']['firstname'] = $this->request->post['firstname'];
			$this->session->data['shipping_address']['lastname'] = $this->request->post['lastname'];
			$this->session->data['shipping_address']['company'] = $this->request->post['company'];
			$this->session->data['shipping_address']['address_1'] = $this->request->post['address_1'];
			$this->session->data['shipping_address']['address_2'] = $this->request->post['address_2'];
			$this->session->data['shipping_address']['postcode'] = $this->request->post['postcode'];
			$this->session->data['shipping_address']['city'] = $this->request->post['city'];
			$this->session->data['shipping_address']['country_id'] = $this->request->post['country_id'];
			$this->session->data['shipping_address']['zone_id'] = $this->request->post['zone_id'];

			$this->load->model('extension/paypal/payment/paypal');

			$country_info = $this->model_extension_paypal_payment_paypal->getCountry($this->request->post['country_id']);

			if ($country_info) {
				$this->session->data['shipping_address']['country'] = $country_info['name'];
				$this->session->data['shipping_address']['iso_code_2'] = $country_info['iso_code_2'];
				$this->session->data['shipping_address']['iso_code_3'] = $country_info['iso_code_3'];
				$this->session->data['shipping_address']['address_format'] = $country_info['address_format'];
			} else {
				$this->session->data['shipping_address']['country'] = '';
				$this->session->data['shipping_address']['iso_code_2'] = '';
				$this->session->data['shipping_address']['iso_code_3'] = '';
				$this->session->data['shipping_address']['address_format'] = '';
			}

			$this->load->model('localisation/zone');

			$zone_info = $this->model_localisation_zone->getZone($this->request->post['zone_id']);

			if ($zone_info) {
				$this->session->data['shipping_address']['zone'] = $zone_info['name'];
				$this->session->data['shipping_address']['zone_code'] = $zone_info['code'];
			} else {
				$this->session->data['shipping_address']['zone'] = '';
				$this->session->data['shipping_address']['zone_code'] = '';
			}

			if (isset($this->request->post['custom_field'])) {
				$this->session->data['shipping_address']['custom_field'] = $this->request->post['custom_field']['address'];
			} else {
				$this->session->data['shipping_address']['custom_field'] = [];
			}
			
			$data['url'] = $this->url->link('extension/paypal/payment/paypal' . $this->separator . 'confirmOrder', 'language=' . $this->config->get('config_language'));
		}
		
		$data['error'] = $this->error;
		
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}
	
	public function deleteCustomerToken(): void {
		$this->load->language('extension/paypal/payment/paypal');
		
		$this->load->model('extension/paypal/payment/paypal');

		if ($this->customer->isLogged() && isset($this->request->post['index'])) {
			$card_token_index = $this->request->post['index'];
			
			$card_customer_tokens = $this->model_extension_paypal_payment_paypal->getPayPalCustomerTokens($this->customer->getId(), 'card');
			
			if (!empty($card_customer_tokens[$card_token_index]['vault_id'])) {
				$vault_id = $card_customer_tokens[$card_token_index]['vault_id'];
				
				$_config = new \Opencart\System\Engine\Config();
				$_config->addPath(DIR_EXTENSION . 'paypal/system/config/');
				$_config->load('paypal');
			
				$config_setting = $_config->get('paypal_setting');
		
				$setting = array_replace_recursive((array)$config_setting, (array)$this->config->get('payment_paypal_setting'));
		
				$client_id = $this->config->get('payment_paypal_client_id');
				$secret = $this->config->get('payment_paypal_secret');
				$merchant_id = $this->config->get('payment_paypal_merchant_id');
				$environment = $this->config->get('payment_paypal_environment');
				$partner_id = $setting['partner'][$environment]['partner_id'];
				$partner_attribution_id = $setting['partner'][$environment]['partner_attribution_id'];
																	
				require_once DIR_EXTENSION . 'paypal/system/library/paypal.php';
		
				$paypal_info = [
					'partner_id' => $partner_id,
					'client_id' => $client_id,
					'secret' => $secret,
					'environment' => $environment,
					'partner_attribution_id' => $partner_attribution_id
				];
		
				$paypal = new \Opencart\System\Library\PayPal($paypal_info);
			
				$token_info = [
					'grant_type' => 'client_credentials'
				];	
				
				$result = $paypal->setAccessToken($token_info);
				
				$result = $paypal->deletePaymentToken($vault_id);
				
				if ($paypal->hasErrors()) {
					$error_messages = [];
				
					$errors = $paypal->getErrors();
								
					foreach ($errors as $error) {
						if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
							$error['message'] = $this->language->get('error_timeout');
						}
				
						if (isset($error['details'][0]['description'])) {
							$error_messages[] = $error['details'][0]['description'];
						} elseif (isset($error['message'])) {
							$error_messages[] = $error['message'];
						}
									
						$this->model_extension_paypal_payment_paypal->log($error, $error['message']);
					}
					
					$this->error['warning'] = implode(' ', $error_messages);
				}

				if (!empty($this->error['warning'])) {
					$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
				}
				
				
				if (!empty($this->error['warning'])) {
					$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
				}
						
				if ($result && !$this->error) {
					$this->model_extension_paypal_payment_paypal->deletePayPalCustomerToken($this->customer->getId(), 'card', $vault_id);
					
					$data['success'] = true;
				}
			}
		}
					
		$data['error'] = $this->error;
				
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}
	
	public function fastlaneModal(): void {
		$this->load->language('extension/paypal/payment/paypal');
		
		$this->load->model('extension/paypal/payment/paypal');
		
		$data['shipping_required'] = $this->cart->hasShipping();
				
		$this->response->setOutput($this->load->view('extension/paypal/payment/fastlane_modal', $data));
	}

	public function fastlaneShipping(): void {
		$this->load->language('extension/paypal/payment/paypal');
		
		$this->load->model('extension/paypal/payment/paypal');
		
		if (!empty($this->request->post['authentication_state']) && ($this->request->post['authentication_state'] == 'succeeded') && !empty($this->request->post['profile_data'])) {
			$profile_data = $this->request->post['profile_data'];
			
			$data['customer']['telephone'] = (isset($profile_data['shippingAddress']['phoneNumber']['nationalNumber']) ? $profile_data['shippingAddress']['phoneNumber']['nationalNumber'] : '');
									
			$data['shipping_address']['firstname'] = (isset($profile_data['shippingAddress']['name']['firstName']) ? $profile_data['shippingAddress']['name']['firstName'] : '');
			$data['shipping_address']['lastname'] = (isset($profile_data['shippingAddress']['name']['lastName']) ? $profile_data['shippingAddress']['name']['lastName'] : '');
			$data['shipping_address']['address_1'] = (isset($profile_data['shippingAddress']['address']['addressLine1']) ? $profile_data['shippingAddress']['address']['addressLine1'] : '');
			$data['shipping_address']['address_2'] = (isset($profile_data['shippingAddress']['address']['addressLine2']) ? $profile_data['shippingAddress']['address']['addressLine2'] : '');
			$data['shipping_address']['city'] = (isset($profile_data['shippingAddress']['address']['adminArea2']) ? $profile_data['shippingAddress']['address']['adminArea2'] : '');
			$data['shipping_address']['postcode'] = (isset($profile_data['shippingAddress']['address']['postalCode']) ? $profile_data['shippingAddress']['address']['postalCode'] : '');
			$data['shipping_address']['country'] = (isset($profile_data['shippingAddress']['address']['countryCode']) ? $profile_data['shippingAddress']['address']['countryCode'] : '');
			$data['shipping_address']['zone'] = (isset($profile_data['shippingAddress']['address']['adminArea1']) ? $profile_data['shippingAddress']['address']['adminArea1'] : '');
			
			if (isset($profile_data['shippingAddress']['address']['countryCode'])) {
				$country_info = $this->model_extension_paypal_payment_paypal->getCountryByCode($profile_data['shippingAddress']['address']['countryCode']);
			
				if ($country_info) {
					$data['shipping_address']['country_id'] = $country_info['country_id'];
					$data['shipping_address']['country'] = $country_info['name'];
					
					if (isset($profile_data['shippingAddress']['address']['adminArea1'])) {
						$zone_info = $this->model_extension_paypal_payment_paypal->getZoneByCode($country_info['country_id'], $profile_data['shippingAddress']['address']['adminArea1']);
			
						if ($zone_info) {
							$data['shipping_address']['zone_id'] = $zone_info['zone_id'];
							$data['shipping_address']['zone'] = $zone_info['name'];
						}
					}
				}
			}
		}
		
		$data['error'] = $this->error;
		
		$this->response->setOutput($this->load->view('extension/paypal/payment/fastlane_shipping', $data));
	}
	
	public function fastlanePayment(): void {
		$this->load->language('extension/paypal/payment/paypal');
		
		$this->load->model('extension/paypal/payment/paypal');
		
		if (isset($this->request->post['payment_token'])) {
			$this->session->data['paypal_payment_token'] = $this->request->post['payment_token'];
			
			$data['url'] = $this->url->link('extension/paypal/payment/paypal' . $this->separator . 'confirmOrder', 'language=' . $this->config->get('config_language'));
		} else {	
			$data['url'] = $this->url->link('checkout/cart', 'language=' . $this->config->get('config_language'));
			
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($data));
		}
		
		// check checkout can continue due to stock checks or vouchers
		if ((!$this->cart->hasProducts() && empty($this->session->data['vouchers'])) || (!$this->cart->hasStock() && !$this->config->get('config_stock_checkout'))) {
			$data['url'] = $this->url->link('checkout/cart', 'language=' . $this->config->get('config_language'));
			
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($data));
		}

		// if user not logged in check that the guest checkout is allowed
		if (!$this->customer->isLogged() && (!$this->config->get('config_checkout_guest') || $this->config->get('config_customer_price') || $this->cart->hasDownload() || $this->cart->hasSubscription())) {
			$data['url'] = $this->url->link('checkout/cart', 'language=' . $this->config->get('config_language'));
			
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($data));
		}

		$data['error'] = $this->error;
		
		$this->response->setOutput($this->load->view('extension/paypal/payment/fastlane_payment', $data));
	}
	
	public function confirmFastlaneCustomer(): void {
		$this->load->language('extension/paypal/payment/paypal');
		
		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validateFastlaneCustomer()) {			
			$this->session->data['customer']['customer_id'] = 0;
			$this->session->data['customer']['customer_group_id'] = $this->config->get('config_customer_group_id');
			$this->session->data['customer']['firstname'] = '';
			$this->session->data['customer']['lastname'] = '';
			$this->session->data['customer']['email'] = $this->request->post['email'];
			$this->session->data['customer']['telephone'] = '';
			$this->session->data['customer']['custom_field'] = [];
																		
			$this->session->data['shipping_address']['firstname'] = '';
			$this->session->data['shipping_address']['lastname'] = '';
			$this->session->data['shipping_address']['company'] = '';
			$this->session->data['shipping_address']['address_1'] = '';
			$this->session->data['shipping_address']['address_2'] = '';
			$this->session->data['shipping_address']['city'] = '';
			$this->session->data['shipping_address']['postcode'] = '';
			$this->session->data['shipping_address']['country'] = '';
			$this->session->data['shipping_address']['country_id'] = 0;
			$this->session->data['shipping_address']['address_format'] = '';
			$this->session->data['shipping_address']['zone'] = '';
			$this->session->data['shipping_address']['zone_id'] = 0;
			$this->session->data['shipping_address']['custom_field'] = [];
			
			$this->session->data['payment_address']['firstname'] = '';
			$this->session->data['payment_address']['lastname'] = '';
			$this->session->data['payment_address']['company'] = '';
			$this->session->data['payment_address']['address_1'] = '';
			$this->session->data['payment_address']['address_2'] = '';
			$this->session->data['payment_address']['city'] = '';
			$this->session->data['payment_address']['postcode'] = '';
			$this->session->data['payment_address']['country'] = '';
			$this->session->data['payment_address']['country_id'] = 0;
			$this->session->data['payment_address']['address_format'] = '';
			$this->session->data['payment_address']['zone'] = '';
			$this->session->data['payment_address']['zone_id'] = 0;
			$this->session->data['payment_address']['custom_field'] = [];
						
			$data['success'] = true;
		}

		$data['error'] = $this->error;
		
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}
			
	public function confirmFastlaneShipping(): void {
		$this->load->language('extension/paypal/payment/paypal');
		
		$this->load->model('extension/paypal/payment/paypal');
						
		if ($this->request->server['REQUEST_METHOD'] == 'POST') {			
			$this->session->data['customer']['firstname'] = $this->request->post['firstname'];
			$this->session->data['customer']['lastname'] = $this->request->post['lastname'];
			$this->session->data['customer']['telephone'] = $this->request->post['telephone'];
																		
			$this->session->data['shipping_address']['firstname'] = $this->request->post['firstname'];
			$this->session->data['shipping_address']['lastname'] = $this->request->post['lastname'];
			$this->session->data['shipping_address']['company'] = '';
			$this->session->data['shipping_address']['address_1'] = $this->request->post['address_1'];
			$this->session->data['shipping_address']['address_2'] = $this->request->post['address_2'];
			$this->session->data['shipping_address']['city'] = $this->request->post['city'];
			$this->session->data['shipping_address']['postcode'] = $this->request->post['postcode'];
			$this->session->data['shipping_address']['country'] = '';
			$this->session->data['shipping_address']['country_id'] = 0;
			$this->session->data['shipping_address']['address_format'] = '';
			$this->session->data['shipping_address']['zone'] = '';
			$this->session->data['shipping_address']['zone_id'] = 0;
			$this->session->data['shipping_address']['custom_field'] = [];
			
			$data['shipping_address'] = [];

			$data['shipping_address']['name']['firstName'] = $this->request->post['firstname'];
			$data['shipping_address']['name']['lastName'] = $this->request->post['lastname'];
			$data['shipping_address']['address']['phone'] = $this->request->post['telephone'];
			$data['shipping_address']['address']['addressLine1'] = $this->request->post['address_1'];
			$data['shipping_address']['address']['addressLine2'] = $this->request->post['address_2'];
			$data['shipping_address']['address']['adminArea2'] = $this->request->post['city'];
			$data['shipping_address']['address']['postalCode'] = $this->request->post['postcode'];
									
			if (isset($this->request->post['country_id'])) {				
				$country_info = $this->model_extension_paypal_payment_paypal->getCountry($this->request->post['country_id']);
									
				if ($country_info) {
					$this->session->data['payment_address']['country_id'] = $country_info['country_id'];
					$this->session->data['payment_address']['country'] = $country_info['name'];
										
					$this->session->data['shipping_address']['country_id'] = $country_info['country_id'];
					$this->session->data['shipping_address']['country'] = $country_info['name'];
					$this->session->data['shipping_address']['address_format'] = $country_info['address_format'];
					
					$data['shipping_address']['address']['countryCode'] = $country_info['iso_code_2'];
													
					if (isset($this->request->post['zone_id'])) {
						$this->load->model('localisation/zone');
						
						$zone_info = $this->model_localisation_zone->getZone($this->request->post['zone_id']);
			
						if ($zone_info) {
							$this->session->data['payment_address']['zone_id'] = $zone_info['zone_id'];
							$this->session->data['payment_address']['zone'] = $zone_info['name'];
							
							$this->session->data['shipping_address']['zone_id'] = $zone_info['zone_id'];
							$this->session->data['shipping_address']['zone'] = $zone_info['name'];
							
							$data['shipping_address']['address']['adminArea1'] = $zone_info['name'];
						}
					}
				}
			}
				
			$data['success'] = true;
		}
		
		$data['error'] = $this->error;
		
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}
	
	public function confirmFastlanePayment(): void {
		$this->load->language('extension/paypal/payment/paypal');
				
		if (!empty($this->request->post['payment_token'])) {			
			$this->session->data['paypal_payment_token'] = $this->request->post['payment_token'];
			
			if (!empty($this->request->post['payment_source'])) {		
				$payment_source = $this->request->post['payment_source'];
				
				if (isset($payment_source['card']['name'])) {
					$payment_name = explode(' ', $payment_source['card']['name']);
					$payment_firstname = $payment_name[0];
					unset($payment_name[0]);
					$payment_lastname = implode(' ', $payment_name);
				}
							
				if (empty($this->session->data['customer']['firstname'])) {
					$this->session->data['customer']['firstname'] = (isset($payment_firstname) ? $payment_firstname : '');
				}
				
				if (empty($this->session->data['customer']['lastname'])) {
					$this->session->data['customer']['lastname'] = (isset($payment_lastname) ? $payment_lastname : '');
				}
				
				$this->session->data['payment_address']['firstname'] = (isset($payment_firstname) ? $payment_firstname : '');
				$this->session->data['payment_address']['lastname'] = (isset($payment_lastname) ? $payment_lastname : '');
				$this->session->data['payment_address']['company'] = '';
				$this->session->data['payment_address']['address_1'] = (isset($payment_source['card']['billingAddress']['streetAddress']) ? $payment_source['card']['billingAddress']['streetAddress'] : '');
				$this->session->data['payment_address']['address_2'] = (isset($payment_source['card']['billingAddress']['extendedAddress']) ? $payment_source['card']['billingAddress']['extendedAddress'] : '');
				$this->session->data['payment_address']['city'] = (isset($payment_source['card']['billingAddress']['locality']) ? $payment_source['card']['billingAddress']['locality'] : '');
				$this->session->data['payment_address']['postcode'] = (isset($payment_source['card']['billingAddress']['postalCode']) ? $payment_source['card']['billingAddress']['postalCode'] : '');
				$this->session->data['payment_address']['country'] = '';
				$this->session->data['payment_address']['country_id'] = 0;
				$this->session->data['payment_address']['address_format'] = '';
				$this->session->data['payment_address']['zone'] = '';
				$this->session->data['payment_address']['zone_id'] = 0;
				$this->session->data['payment_address']['custom_field'] = [];
			
				if (isset($payment_source['card']['billingAddress']['countryCodeAlpha2'])) {
					$this->load->model('extension/paypal/payment/paypal');
				
					$country_info = $this->model_extension_paypal_payment_paypal->getCountryByCode($payment_source['card']['billingAddress']['countryCodeAlpha2']);
			
					if ($country_info) {
						$this->session->data['payment_address']['country'] = $country_info['name'];
						$this->session->data['payment_address']['country_id'] = $country_info['country_id'];
						$this->session->data['payment_address']['address_format'] = $country_info['address_format'];
				
						if (isset($payment_source['card']['billingAddress']['region'])) {
							$zone_info = $this->model_extension_paypal_payment_paypal->getZoneByCode($country_info['country_id'], $payment_source['card']['billingAddress']['region']);
			
							if ($zone_info) {
								$this->session->data['payment_address']['zone_id'] = $zone_info['zone_id'];
								$this->session->data['payment_address']['zone'] = $zone_info['name'];
							}
						}
					}
				}
								
				if (version_compare(VERSION, '4.0.2.0', '>=')) {
					$this->session->data['payment_method'] = [
						'code' => 'paypal.fastlane',
						'name' => $this->language->get('text_paypal_fastlane_title')						
					];
				} else {	
					$this->session->data['payment_method'] = 'paypal_fastlane'; 
				}
				
				$data['url'] = $this->url->link('extension/paypal/payment/paypal' . $this->separator . 'confirmOrder', 'language=' . $this->config->get('config_language'));			
			} else {
				$data['url'] = $this->url->link('extension/paypal/payment/paypal' . $this->separator . 'completeOrder', 'language=' . $this->config->get('config_language'));
			}
		}

		$data['error'] = $this->error;
		
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}
	
	public function getFastlaneData(): void {
		$this->load->language('extension/paypal/payment/paypal');
		
		$this->load->model('extension/paypal/payment/paypal');
		
		if ($this->cart->hasShipping()) {
			$data['shipping_address'] = [];
						
			$data['shipping_address']['name']['firstName'] = $this->session->data['shipping_address']['firstname'];
			$data['shipping_address']['name']['lastName'] = $this->session->data['shipping_address']['lastname'];
			$data['shipping_address']['address']['phone'] = $this->session->data['customer']['telephone'];
			$data['shipping_address']['address']['addressLine1'] = $this->session->data['shipping_address']['address_1'];
			$data['shipping_address']['address']['addressLine2'] = $this->session->data['shipping_address']['address_2'];
			$data['shipping_address']['address']['adminArea2'] = $this->session->data['shipping_address']['city'];
			$data['shipping_address']['address']['postalCode'] = $this->session->data['shipping_address']['postcode'];
								
			if (isset($this->session->data['shipping_address']['country_id'])) {				
				$country_info = $this->model_extension_paypal_payment_paypal->getCountry($this->session->data['shipping_address']['country_id']);
			
				if ($country_info) {
					$data['shipping_address']['address']['countryCode'] = $country_info['iso_code_2'];
					
					if (isset($this->session->data['shipping_address']['zone_id'])) {
						$this->load->model('localisation/zone');
						
						$zone_info = $this->model_localisation_zone->getZone($this->session->data['shipping_address']['zone_id']);
			
						if ($zone_info) {							
							$data['shipping_address']['address']['adminArea1'] = $zone_info['code'];
						}
					}
				}
			}
		}
				
		$data['billing_address'] = [];
										
		if (!empty($this->session->data['payment_address']['address_1'])) {
			$data['billing_address']['addressLine1'] = $this->session->data['payment_address']['address_1'];
		} elseif (!empty($this->session->data['shipping_address']['address_1'])) {
			$data['billing_address']['addressLine1'] = $this->session->data['shipping_address']['address_1'];
		} else {
			$data['billing_address']['addressLine1'] = '';
		}
		
		if (!empty($this->session->data['payment_address']['address_2'])) {
			$data['billing_address']['addressLine2'] = $this->session->data['payment_address']['address_2'];
		} elseif (!empty($this->session->data['shipping_address']['address_2'])) {
			$data['billing_address']['addressLine2'] = $this->session->data['shipping_address']['address_2'];
		} else {
			$data['billing_address']['addressLine2'] = '';
		}
		
		if (!empty($this->session->data['payment_address']['city'])) {
			$data['billing_address']['adminArea2'] = $this->session->data['payment_address']['city'];
		} elseif (!empty($this->session->data['shipping_address']['city'])) {
			$data['billing_address']['adminArea2'] = $this->session->data['shipping_address']['city'];
		} else {
			$data['billing_address']['adminArea2'] = '';
		}
		
		if (!empty($this->session->data['payment_address']['postcode'])) {
			$data['billing_address']['postalCode'] = $this->session->data['payment_address']['postcode'];
		} elseif (!empty($this->session->data['shipping_address']['postcode'])) {
			$data['billing_address']['postalCode'] = $this->session->data['shipping_address']['postcode'];
		} else {
			$data['billing_address']['postalCode'] = '';
		}
				
		if (!empty($this->session->data['payment_address']['country_id'])) {
			$country_info = $this->model_extension_paypal_payment_paypal->getCountry($this->session->data['payment_address']['country_id']);
			
			if ($country_info) {
				$data['billing_address']['countryCode'] = $country_info['iso_code_2'];
					
				if (!empty($this->session->data['payment_address']['zone_id'])) {
					$this->load->model('localisation/zone');
						
					$zone_info = $this->model_localisation_zone->getZone($this->session->data['payment_address']['zone_id']);
			
					if ($zone_info) {							
						$data['billing_address']['adminArea1'] = $zone_info['code'];
					}
				}
			}
		} elseif (!empty($this->session->data['shipping_address']['country_id'])) {
			$country_info = $this->model_extension_paypal_payment_paypal->getCountry($this->session->data['shipping_address']['country_id']);
			
			if ($country_info) {
				$data['billing_address']['countryCode'] = $country_info['iso_code_2'];
					
				if (!empty($this->session->data['shipping_address']['zone_id'])) {
					$this->load->model('localisation/zone');
						
					$zone_info = $this->model_localisation_zone->getZone($this->session->data['shipping_address']['zone_id']);
			
					if ($zone_info) {							
						$data['billing_address']['adminArea1'] = $zone_info['code'];
					}
				}
			}
		}
		
		$data['cardholder_name'] = [];
		
		if (!empty($this->session->data['payment_address']['firstname'])) {
			$data['cardholder_name']['firstName'] = $this->session->data['payment_address']['firstname'];
		} elseif (!empty($this->session->data['shipping_address']['firstname'])) {
			$data['cardholder_name']['firstName'] = $this->session->data['shipping_address']['firstname'];
		} else {
			$data['cardholder_name']['firstName'] = '';
		}
		
		if (!empty($this->session->data['payment_address']['lastname'])) {
			$data['cardholder_name']['lastName'] = $this->session->data['payment_address']['lastname'];
		} elseif (!empty($this->session->data['shipping_address']['lastname'])) {
			$data['cardholder_name']['lastName'] = $this->session->data['shipping_address']['lastname'];
		} else {
			$data['cardholder_name']['lastName'] = '';
		}
		
		$data['cardholder_name']['fullName'] = $data['cardholder_name']['firstName'];
		$data['cardholder_name']['fullName'] .= ($data['cardholder_name']['lastName'] ? (' ' . $data['cardholder_name']['lastName']) : '');
					
		$data['success'] = true;
				
		$data['error'] = $this->error;
		
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}
	
	public function addOrderHistory(): void {
		if (!empty($this->request->get['order_history_token']) && !empty($this->request->post['order_id']) && !empty($this->request->post['order_status_id'])) {
			$this->load->language('extension/paypal/payment/paypal');
		
			$this->load->model('extension/paypal/payment/paypal');
			
			$order_id = $this->request->post['order_id'];
			$order_status_id = $this->request->post['order_status_id'];
			
			if (!empty($this->request->post['comment'])) {
				$comment = $this->request->post['comment'];
			} else {
				$comment = '';
			}
			
			if (!empty($this->request->post['notify'])) {
				$notify = $this->request->post['notify'];
			} else {
				$notify = false;
			}
			
			$_config = new \Opencart\System\Engine\Config();
			$_config->addPath(DIR_EXTENSION . 'paypal/system/config/');
			$_config->load('paypal');
			
			$config_setting = $_config->get('paypal_setting');
		
			$setting = array_replace_recursive((array)$config_setting, (array)$this->config->get('payment_paypal_setting'));
					
			if (hash_equals($setting['general']['order_history_token'], $this->request->get['order_history_token'])) {		
				$this->load->model('checkout/order');

				$this->model_checkout_order->addHistory($order_id, $order_status_id, $comment, $notify);
			
				$data['success'] = $this->language->get('success_order');
			}	
		}
							
		$data['error'] = $this->error;
				
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}
	
	public function callback(): bool {
		$this->load->language('extension/paypal/payment/paypal');
		
		$this->load->model('extension/paypal/payment/paypal');
		
		if (!empty($this->request->get['callback_token'])) {
			$_config = new \Opencart\System\Engine\Config();
			$_config->addPath(DIR_EXTENSION . 'paypal/system/config/');
			$_config->load('paypal');
			
			$config_setting = $_config->get('paypal_setting');
		
			$setting = array_replace_recursive((array)$config_setting, (array)$this->config->get('payment_paypal_setting'));
					
			if (hash_equals($setting['general']['callback_token'], $this->request->get['callback_token']) && !empty($this->session->data['order_id']) && !empty($this->session->data['paypal_order_id']) && isset($this->session->data['paypal_card_token_index'])) {
				$order_id = $this->session->data['order_id'];
				$paypal_order_id = $this->session->data['paypal_order_id'];
				$card_token_index = $this->session->data['paypal_card_token_index'];
				
				$card_customer_tokens = $this->model_extension_paypal_payment_paypal->getPayPalCustomerTokens($this->customer->getId(), 'card');

				if (!empty($card_customer_tokens[$card_token_index]['vault_id'])) {
					$vault_id = $card_customer_tokens[$card_token_index]['vault_id'];
					$vault_customer_id = $card_customer_tokens[$card_token_index]['vault_customer_id'];
					$card_type = $card_customer_tokens[$card_token_index]['card_type'];
					$card_nice_type = $card_customer_tokens[$card_token_index]['card_nice_type'];
					$card_last_digits = $card_customer_tokens[$card_token_index]['card_last_digits'];
					$card_expiry = $card_customer_tokens[$card_token_index]['card_expiry'];
				
					$client_id = $this->config->get('payment_paypal_client_id');
					$secret = $this->config->get('payment_paypal_secret');
					$environment = $this->config->get('payment_paypal_environment');
					$partner_id = $setting['partner'][$environment]['partner_id'];
					$partner_attribution_id = $setting['partner'][$environment]['partner_attribution_id'];
					$vault_status = $setting['general']['vault_status'];
					$transaction_method = $setting['general']['transaction_method'];
			
					require_once DIR_EXTENSION . 'paypal/system/library/paypal.php';
		
					$paypal_info = [
						'partner_id' => $partner_id,
						'client_id' => $client_id,
						'secret' => $secret,
						'environment' => $environment,
						'partner_attribution_id' => $partner_attribution_id
					];
		
					$paypal = new \Opencart\System\Library\PayPal($paypal_info);
		
					$token_info = [
						'grant_type' => 'client_credentials'
					];	
						
					$paypal->setAccessToken($token_info);
					
					$paypal_order_info = $paypal->getOrder($paypal_order_id);
					
					if ($paypal->hasErrors()) {
						$error_messages = [];
				
						$errors = $paypal->getErrors();
								
						foreach ($errors as $error) {
							if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
								$error['message'] = $this->language->get('error_timeout');
							}
					
							if (isset($error['details'][0]['description'])) {
								$error_messages[] = $error['details'][0]['description'];
							} elseif (isset($error['message'])) {
								$error_messages[] = $error['message'];
							}
					
							$this->model_extension_paypal_payment_paypal->log($error, $error['message']);
						}
		
						$this->error['warning'] = implode(' ', $error_messages);
					}
							
					if (isset($paypal_order_info['payment_source']['card']) && !$this->error) {
						$this->model_extension_paypal_payment_paypal->log($paypal_order_info['payment_source']['card'], 'Card');
						
						$liability_shift = (isset($paypal_order_info['payment_source']['card']['authentication_result']['liability_shift']) ? $paypal_order_info['payment_source']['card']['authentication_result']['liability_shift'] : '');
						$enrollment_status = (isset($paypal_order_info['payment_source']['card']['authentication_result']['three_d_secure']['enrollment_status']) ? $paypal_order_info['payment_source']['card']['authentication_result']['three_d_secure']['enrollment_status'] : '');
						$authentication_status = (isset($paypal_order_info['payment_source']['card']['authentication_result']['three_d_secure']['authentication_status']) ? $paypal_order_info['payment_source']['card']['authentication_result']['three_d_secure']['authentication_status'] : '');
								
						if ($enrollment_status == 'Y') {
							if (($authentication_status == 'N') && !$setting['card']['secure_scenario']['failed_authentication']) {
								$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['failed_authentication']['error']);
							}
						
							if (($authentication_status == 'R') && !$setting['card']['secure_scenario']['rejected_authentication']) {
								$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['rejected_authentication']['error']);
							}
						
							if (($authentication_status == 'A') && !$setting['card']['secure_scenario']['attempted_authentication']) {
								$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['attempted_authentication']['error']);
							}
						
							if (($authentication_status == 'U') && !$setting['card']['secure_scenario']['unable_authentication']) {
								$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['unable_authentication']['error']);
							}
						
							if (($authentication_status == 'C') && !$setting['card']['secure_scenario']['challenge_authentication']) {
								$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['challenge_authentication']['error']);
							}
						}
					
						if (($enrollment_status == 'N') && !$setting['card']['secure_scenario']['card_ineligible']) {
							$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['card_ineligible']['error']);
						}
					
						if (($enrollment_status == 'U') && !$setting['card']['secure_scenario']['system_unavailable']) {
							$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['system_unavailable']['error']);
						}
					
						if (($enrollment_status == 'B') && !$setting['card']['secure_scenario']['system_bypassed']) {
							$this->error['warning'] = $this->language->get($setting['card_secure_scenario']['system_bypassed']['error']);
						}
					}
		
					if (!empty($this->error['warning'])) {
						$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
					}
				
					if (!$this->error) {				
						if ($transaction_method == 'authorize') {
							$result = $paypal->setOrderAuthorize($paypal_order_id);
						} else {
							$result = $paypal->setOrderCapture($paypal_order_id);
						}
																		
						if ($paypal->hasErrors()) {
							$error_messages = [];
				
							$errors = $paypal->getErrors();
								
							foreach ($errors as $error) {
								if (isset($error['details'][0]['issue']) && ($error['details'][0]['issue'] == 'INSTRUMENT_DECLINED')) {
									$data['restart'] = true;
								}
					
								if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
									$error['message'] = $this->language->get('error_timeout');
								}
					
								if (isset($error['details'][0]['description'])) {
									$error_messages[] = $error['details'][0]['description'];
								} elseif (isset($error['message'])) {
									$error_messages[] = $error['message'];
								}
					
								$this->model_extension_paypal_payment_paypal->log($error, $error['message']);
							}
				
							$this->error['warning'] = implode(' ', $error_messages);
						}
			
						if (!empty($this->error['warning'])) {
							$this->error['warning'] .= ' ' . sprintf($this->language->get('error_payment'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
						}
			
						if (!$this->error) {	
							$this->load->model('checkout/order');
				
							$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
						
							if ($transaction_method == 'authorize') {
								$this->model_extension_paypal_payment_paypal->log($result, 'Authorize Order');
					
								if (isset($result['purchase_units'][0]['payments']['authorizations'][0]['status']) && isset($result['purchase_units'][0]['payments']['authorizations'][0]['seller_protection']['status'])) {
									$authorization_id = $result['purchase_units'][0]['payments']['authorizations'][0]['id'];
									$authorization_status = $result['purchase_units'][0]['payments']['authorizations'][0]['status'];
									$seller_protection_status = $result['purchase_units'][0]['payments']['authorizations'][0]['seller_protection']['status'];							
									$order_status_id = 0;
									$transaction_status = '';
									$payment_method = 'card';
								
									if (!$this->cart->hasShipping()) {
										$seller_protection_status = 'NOT_ELIGIBLE';
									}
								
									if ($authorization_status == 'CREATED') {
										$order_status_id = $setting['order_status']['pending']['id'];
										$transaction_status = 'created';
									}

									if ($authorization_status == 'CAPTURED') {
										$this->error['warning'] = sprintf($this->language->get('error_authorization_captured'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
									}
						
									if ($authorization_status == 'DENIED') {
										$transaction_status = 'denied';
							
										$this->error['warning'] = $this->language->get('error_authorization_denied');
									}
						
									if ($authorization_status == 'EXPIRED') {
										$this->error['warning'] = sprintf($this->language->get('error_authorization_expired'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
									}
						
									if ($authorization_status == 'PENDING') {
										$order_status_id = $setting['order_status']['pending']['id'];
										$transaction_status = 'pending';
									}
														
									if (($authorization_status == 'CREATED') || ($authorization_status == 'DENIED') || ($authorization_status == 'PENDING')) {
										$this->model_extension_paypal_payment_paypal->deletePayPalOrder($this->session->data['order_id']);
									
										$paypal_order_data = [
											'order_id' => $this->session->data['order_id'],
											'paypal_order_id' => $paypal_order_id,
											'transaction_id' => $authorization_id,
											'transaction_status' => $transaction_status,
											'payment_method' => $payment_method,
											'vault_id' => $vault_id,
											'vault_customer_id' => $vault_customer_id,
											'card_type' => $card_type,
											'card_nice_type' => $card_nice_type,
											'card_last_digits' => $card_last_digits,
											'card_expiry' => $card_expiry,
											'total' => $order_info['total'],
											'currency_code' => $order_info['currency_code'],
											'environment' => $environment
										];

										$this->model_extension_paypal_payment_paypal->addPayPalOrder($paypal_order_data);
									
										if ($vault_id && $this->customer->isLogged()) {
											$customer_id = $this->customer->getId();
										
											$paypal_customer_token_info = $this->model_extension_paypal_payment_paypal->getPayPalCustomerToken($customer_id, $payment_method, $vault_id);
								
											if (!$paypal_customer_token_info) {
												$paypal_customer_token_data = [
													'customer_id' => $customer_id,
													'payment_method' => $payment_method,
													'vault_id' => $vault_id,
													'vault_customer_id' => $vault_customer_id,
													'card_type' => $card_type,
													'card_nice_type' => $card_nice_type,
													'card_last_digits' => $card_last_digits,
													'card_expiry' => $card_expiry
												];
					
												$this->model_extension_paypal_payment_paypal->addPayPalCustomerToken($paypal_customer_token_data);
											}
										
											$this->model_extension_paypal_payment_paypal->setPayPalCustomerMainToken($customer_id, $payment_method, $vault_id);
										}
									}
									
									if ($order_status_id) {
										$message = sprintf($this->language->get('text_order_message'), $seller_protection_status);
											
										$this->model_checkout_order->addHistory($this->session->data['order_id'], $order_status_id, $message);
									}
								
									if (($authorization_status == 'CREATED') || ($authorization_status == 'PENDING')) {
										$subscriptions = $this->model_extension_paypal_payment_paypal->getSubscriptionsByOrderId($this->session->data['order_id']);
					
										foreach ($subscriptions as $subscription) {
											$this->model_extension_paypal_payment_paypal->subscriptionPayment($subscription, $order_info, $paypal_order_data);
										} 										
									}
													
									if (($authorization_status == 'CREATED') || ($authorization_status == 'PARTIALLY_CAPTURED') || ($authorization_status == 'PARTIALLY_CREATED') || ($authorization_status == 'PENDING')) {
										$this->response->redirect($this->url->link('checkout/success', 'language=' . $this->config->get('config_language')));
									}
								}
							} else {
								$this->model_extension_paypal_payment_paypal->log($result, 'Capture Order');
					
								if (isset($result['purchase_units'][0]['payments']['captures'][0]['status']) && isset($result['purchase_units'][0]['payments']['captures'][0]['seller_protection']['status'])) {
									$capture_id = $result['purchase_units'][0]['payments']['captures'][0]['id'];
									$capture_status = $result['purchase_units'][0]['payments']['captures'][0]['status'];
									$seller_protection_status = $result['purchase_units'][0]['payments']['captures'][0]['seller_protection']['status'];
									
									$order_status_id = 0;
									$transaction_status = '';
									$payment_method = 'card';
																	
									if (!$this->cart->hasShipping()) {
										$seller_protection_status = 'NOT_ELIGIBLE';
									}
																						
									if ($capture_status == 'COMPLETED') {
										$order_status_id = $setting['order_status']['completed']['id'];
										$transaction_status = 'completed';
									}
						
									if ($capture_status == 'DECLINED') {
										$transaction_status = 'denied';
							
										$this->error['warning'] = $this->language->get('error_capture_declined');
									}
						
									if ($capture_status == 'FAILED') {
										$this->error['warning'] = sprintf($this->language->get('error_capture_failed'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
									}
						
									if ($capture_status == 'PENDING') {
										$order_status_id = $setting['order_status']['pending']['id'];
										$transaction_status = 'pending';
									}
														
									if (($capture_status == 'COMPLETED') || ($capture_status == 'DECLINED') || ($capture_status == 'PENDING')) {
										$this->model_extension_paypal_payment_paypal->deletePayPalOrder($this->session->data['order_id']);
									
										$paypal_order_data = [
											'order_id' => $this->session->data['order_id'],
											'paypal_order_id' => $paypal_order_id,
											'transaction_id' => $capture_id,
											'transaction_status' => $transaction_status,
											'payment_method' => $payment_method,
											'vault_id' => $vault_id,
											'vault_customer_id' => $vault_customer_id,
											'card_type' => $card_type,
											'card_nice_type' => $card_nice_type,
											'card_last_digits' => $card_last_digits,
											'card_expiry' => $card_expiry,
											'total' => $order_info['total'],
											'currency_code' => $order_info['currency_code'],
											'environment' => $environment
										];

										$this->model_extension_paypal_payment_paypal->addPayPalOrder($paypal_order_data);
									
										if ($vault_id && $this->customer->isLogged()) {
											$customer_id = $this->customer->getId();
										
											$paypal_customer_token_info = $this->model_extension_paypal_payment_paypal->getPayPalCustomerToken($customer_id, $payment_method, $vault_id);
								
											if (!$paypal_customer_token_info) {
												$paypal_customer_token_data = [
													'customer_id' => $customer_id,
													'payment_method' => $payment_method,
													'vault_id' => $vault_id,
													'vault_customer_id' => $vault_customer_id,
													'card_type' => $card_type,
													'card_nice_type' => $card_nice_type,
													'card_last_digits' => $card_last_digits,
													'card_expiry' => $card_expiry
												];
					
												$this->model_extension_paypal_payment_paypal->addPayPalCustomerToken($paypal_customer_token_data);
											}
										
											$this->model_extension_paypal_payment_paypal->setPayPalCustomerMainToken($customer_id, $payment_method, $vault_id);
										}
									}
									
									if ($order_status_id) {
										$message = sprintf($this->language->get('text_order_message'), $seller_protection_status);
													
										$this->model_checkout_order->addHistory($this->session->data['order_id'], $order_status_id, $message);
									}
								
									if (($capture_status == 'COMPLETED') || ($capture_status == 'PENDING')) {
										$subscriptions = $this->model_extension_paypal_payment_paypal->getSubscriptionsByOrderId($this->session->data['order_id']);
					
										foreach ($subscriptions as $subscription) {
											$this->model_extension_paypal_payment_paypal->subscriptionPayment($subscription, $order_info, $paypal_order_data);
										} 	
									}
						
									if (($capture_status == 'COMPLETED') || ($capture_status == 'PENDING')) {
										$this->response->redirect($this->url->link('checkout/success', 'language=' . $this->config->get('config_language')));
									}
								}
							}
						}
					}
				}
			
				$this->document->setTitle($this->language->get('text_failure_page_title'));
						
				$data['breadcrumbs'] = [];

				$data['breadcrumbs'][] = [
					'text' => $this->language->get('text_home'),
					'href' => $this->url->link('common/home', 'language=' . $this->config->get('config_language'))
				];
		
				$data['breadcrumbs'][] = [
					'text' => $this->language->get('text_cart'),
					'href' => $this->url->link('checkout/cart', 'language=' . $this->config->get('config_language'))
				];

				$data['breadcrumbs'][] = [
					'text' => $this->language->get('text_paypal'),
					'href' => $this->url->link('extension/paypal/payment/paypal' . $this->separator . 'callback', 'language=' . $this->config->get('config_language'))
				];
						
				$data['text_title'] = $this->language->get('text_failure_page_title');
				$data['text_message'] = sprintf($this->language->get('text_failure_page_message'), $this->url->link('information/contact', 'language=' . $this->config->get('config_language')));
		
				if (!empty($this->error['warning'])) {
					$data['text_message'] = $this->error['warning'];
				}
								
				$data['continue'] = $this->url->link('common/home');
		
				$data['column_left'] = $this->load->controller('common/column_left');
				$data['column_right'] = $this->load->controller('common/column_right');
				$data['content_top'] = $this->load->controller('common/content_top');
				$data['content_bottom'] = $this->load->controller('common/content_bottom');
				$data['footer'] = $this->load->controller('common/footer');
				$data['header'] = $this->load->controller('common/header');
		
				$this->response->setOutput($this->load->view('extension/paypal/payment/failure', $data));
			
				return true;
			}
		}
				
		return false;
	}
		
	public function webhook(): bool {		
		if (!empty($this->request->get['webhook_token'])) {
			$_config = new \Opencart\System\Engine\Config();
			$_config->addPath(DIR_EXTENSION . 'paypal/system/config/');
			$_config->load('paypal');
			
			$config_setting = $_config->get('paypal_setting');
		
			$setting = array_replace_recursive((array)$config_setting, (array)$this->config->get('payment_paypal_setting'));
		
			$webhook_info = json_decode(html_entity_decode(file_get_contents('php://input')), true);
			
			if (hash_equals($setting['general']['webhook_token'], $this->request->get['webhook_token']) && !empty($webhook_info['id']) && !empty($webhook_info['event_type'])) {	
				$this->load->model('extension/paypal/payment/paypal');
				$this->load->model('checkout/order');
				
				$this->model_extension_paypal_payment_paypal->log($webhook_info, 'Webhook');
			
				$webhook_event_id = $webhook_info['id'];
			
				$client_id = $this->config->get('payment_paypal_client_id');
				$secret = $this->config->get('payment_paypal_secret');
				$environment = $this->config->get('payment_paypal_environment');
				$partner_id = $setting['partner'][$environment]['partner_id'];
				$partner_attribution_id = $setting['partner'][$environment]['partner_attribution_id'];
				$vault_status = $setting['general']['vault_status'];
				$transaction_method = $this->config->get('payment_paypal_transaction_method');
			
				require_once DIR_EXTENSION . 'paypal/system/library/paypal.php';
		
				$paypal_info = [
					'partner_id' => $partner_id,
					'client_id' => $client_id,
					'secret' => $secret,
					'environment' => $environment,
					'partner_attribution_id' => $partner_attribution_id
				];
		
				$paypal = new \Opencart\System\Library\PayPal($paypal_info);
			
				$token_info = [
					'grant_type' => 'client_credentials'
				];	
		
				$paypal->setAccessToken($token_info);
			
				$webhook_repeat = 1;
								
				while ($webhook_repeat) {
					$webhook_event = $paypal->getWebhookEvent($webhook_event_id);

					$errors = [];
				
					$webhook_repeat = 0;
			
					if ($paypal->hasErrors()) {
						$error_messages = [];
				
						$errors = $paypal->getErrors();
							
						foreach ($errors as $error) {
							if (isset($error['name']) && ($error['name'] == 'CURLE_OPERATION_TIMEOUTED')) {
								$webhook_repeat = 1;
							}
						}
					}
				}
									
				if (!empty($webhook_event['resource']['invoice_id']) && !$errors) {
					$invoice_id = explode('_', $webhook_event['resource']['invoice_id']);
					$order_id = reset($invoice_id);
					
					$order_info = $this->model_checkout_order->getOrder($order_id);
					
					$paypal_order_info = $this->model_extension_paypal_payment_paypal->getPayPalOrder($order_id);

					if ($order_info && $paypal_order_info) {
						$order_status_id = 0;
						$transaction_id = $paypal_order_info['transaction_id'];
						$transaction_status = $paypal_order_info['transaction_status'];
					
						if ($webhook_event['event_type'] == 'PAYMENT.AUTHORIZATION.CREATED') {
							$order_status_id = $setting['order_status']['pending']['id'];
							$transaction_status = 'created';
						}
		
						if ($webhook_event['event_type'] == 'PAYMENT.AUTHORIZATION.VOIDED') {
							if ($order_info['order_status_id'] != 0) {
								$order_status_id = $setting['order_status']['voided']['id'];
							}
							
							$transaction_status = 'voided';
						}
			
						if ($webhook_event['event_type'] == 'PAYMENT.CAPTURE.COMPLETED') {
							if (!empty($webhook_event['resource']['final_capture'])) {
								$order_status_id = $setting['order_status']['completed']['id'];
								$transaction_status = 'completed';
							} else {
								$order_status_id = $setting['order_status']['partially_captured']['id'];
								$transaction_status = 'partially_captured';
							}
						}
		
						if ($webhook_event['event_type'] == 'PAYMENT.CAPTURE.DENIED') {
							if ($order_info['order_status_id'] != 0) {
								$order_status_id = $setting['order_status']['denied']['id'];
							}
							
							$transaction_status = 'denied';
						}
		
						if ($webhook_event['event_type'] == 'PAYMENT.CAPTURE.PENDING') {
							$order_status_id = $setting['order_status']['pending']['id'];
							$transaction_status = 'pending';
						}
		
						if ($webhook_event['event_type'] == 'PAYMENT.CAPTURE.REFUNDED') {
							$result = $paypal->getPaymentCapture($transaction_id);
							
							if (!empty($result['status'] == 'REFUNDED')) {
								if ($order_info['order_status_id'] != 0) {
									$order_status_id = $setting['order_status']['refunded']['id'];
								}
								
								$transaction_status = 'refunded';
							} elseif (!empty($result['status'] == 'PARTIALLY_REFUNDED')) {
								if ($order_info['order_status_id'] != 0) {
									$order_status_id = $setting['order_status']['partially_refunded']['id'];
								}
								
								$transaction_status = 'partially_refunded';
							}
						}
		
						if ($webhook_event['event_type'] == 'PAYMENT.CAPTURE.REVERSED') {
							if ($order_info['order_status_id'] != 0) {
								$order_status_id = $setting['order_status']['reversed']['id'];
							}
							
							$transaction_status = 'reversed';
						}
		
						if ($webhook_event['event_type'] == 'CHECKOUT.ORDER.COMPLETED') {
							$order_status_id = $setting['order_status']['completed']['id'];
						}
								
						if (isset($webhook_event['resource']['id']) && $transaction_status) {
							$transaction_id = $webhook_event['resource']['id'];
											
							$paypal_order_data = array();
							
							$paypal_order_data['order_id'] = $order_id;
							$paypal_order_data['transaction_status'] = $transaction_status;
												
							if (($transaction_status == 'created') && ($transaction_status == 'completed')) {
								$paypal_order_data['transaction_id'] = $transaction_id;
							}

							$this->model_extension_payapl_payment_paypal->editPayPalOrder($paypal_order_data);
						}
						
						if ($order_status_id && ($order_info['order_status_id'] != $order_status_id) && !in_array($order_info['order_status_id'], $setting['final_order_status'])) {
							$this->model_checkout_order->addHistory($order_id, $order_status_id, '', true);
						}
					}
				}
				
				if (($webhook_event['event_type'] == 'VAULT.PAYMENT-TOKEN.CREATED') && !empty($webhook_info['resource']['id']) && !empty($webhook_info['resource']['customer']['id']) && !empty($webhook_event['resource']['metadata']['order_id']) && !$errors) {
					$paypal_order_id = $webhook_event['resource']['metadata']['order_id'];
					
					$paypal_order_info = $this->model_extension_paypal_payment_paypal->getPayPalOrderByPayPalOrderId($paypal_order_id);
										
					if ($paypal_order_info) {
						$order_id = $paypal_order_info['order_id'];
						$payment_method = $paypal_order_info['payment_method'];
						$vault_id = $webhook_event['resource']['id'];
						$vault_customer_id = $webhook_event['resource']['customer']['id'];
						$card_type = $paypal_order_info['card_type'];
						$card_nice_type = $paypal_order_info['card_nice_type'];
						$card_last_digits = (isset($webhook_event['resource']['payment_source']['card']['last_digits']) ? $webhook_event['resource']['payment_source']['card']['last_digits'] : '');
						$card_expiry = (isset($webhook_event['resource']['payment_source']['card']['expiry']) ? $webhook_event['resource']['payment_source']['card']['expiry'] : '');
					
						$paypal_order_data = [
							'order_id' => $order_id,
							'vault_id' => $vault_id,
							'vault_customer_id' => $vault_customer_id,
							'card_last_digits' => $card_last_digits,
							'card_expiry' => $card_expiry
						];
						
						$this->model_extension_paypal_payment_paypal->editPayPalOrder($paypal_order_data);
						
						$order_info = $this->model_checkout_order->getOrder($order_id);
						
						if ($vault_id && !empty($order_info['customer_id'])) {
							$customer_id = $order_info['customer_id'];
										
							$paypal_customer_token_info = $this->model_extension_paypal_payment_paypal->getPayPalCustomerToken($customer_id, $payment_method, $vault_id);
								
							if (!$paypal_customer_token_info) {
								$paypal_customer_token_data = [
									'customer_id' => $customer_id,
									'payment_method' => $payment_method,
									'vault_id' => $vault_id,
									'vault_customer_id' => $vault_customer_id,
									'card_type' => $card_type,
									'card_nice_type' => $card_nice_type,
									'card_last_digits' => $card_last_digits,
									'card_expiry' => $card_expiry
								];
								
								$this->model_extension_paypal_payment_paypal->addPayPalCustomerToken($paypal_customer_token_data);
							}
										
							$this->model_extension_paypal_payment_paypal->setPayPalCustomerMainToken($customer_id, $payment_method, $vault_id);
						}
					}
				}

				header('HTTP/1.1 200 OK');
				
				return true;
			}
		}
		
		return false;
	}
	
	public function cron(): bool {
		if (!empty($this->request->get['cron_token'])) {
			$_config = new \Opencart\System\Engine\Config();
			$_config->addPath(DIR_EXTENSION . 'paypal/system/config/');
			$_config->load('paypal');
			
			$config_setting = $_config->get('paypal_setting');
		
			$setting = array_replace_recursive((array)$config_setting, (array)$this->config->get('payment_paypal_setting'));
			
			if (hash_equals($setting['general']['cron_token'], $this->request->get['cron_token'])) {
				$this->load->model('extension/paypal/payment/paypal');
				
				$this->model_extension_paypal_payment_paypal->cronPayment();
			
				return true;
			}
		}
		
		return false;
	}
	
	public function update(): void {
		$this->load->model('extension/paypal/payment/paypal');
		
		$this->model_extension_paypal_payment_paypal->update();
	}
		
	public function content_top_before(string $route, array &$data): void {
		$this->load->model('extension/paypal/payment/paypal');
		
		$agree_status = $this->model_extension_paypal_payment_paypal->getAgreeStatus();
		
		if ($this->config->get('payment_paypal_status') && $this->config->get('payment_paypal_client_id') && $this->config->get('payment_paypal_secret') && $agree_status) {
			$_config = new \Opencart\System\Engine\Config();
			$_config->addPath(DIR_EXTENSION . 'paypal/system/config/');
			$_config->load('paypal');
			
			$config_setting = $_config->get('paypal_setting');
		
			$setting = array_replace_recursive((array)$config_setting, (array)$this->config->get('payment_paypal_setting'));
			
			$currency_code = $this->session->data['currency'];
					
			if (empty($setting['currency'][$currency_code]['status'])) {
				$currency_code = $setting['general']['currency_code'];
			}
			
			if (isset($this->request->get['route'])) {
				$route = $this->request->get['route'];
			} else {
				$route = 'common/home';
			} 
			
			$params = array();
			
			if (($route == 'common/home') && ($setting['message']['home']['status'] && !empty($setting['paylater_country'][$setting['general']['country_code']]) && ($currency_code == $setting['general']['currency_code']))) {
				$params['page_code'] = 'home';
			}
			
			if (($route == 'product/product') && ($setting['button']['product']['status'] || $setting['googlepay_button']['product']['status'] || $setting['applepay_button']['product']['status'] || ($setting['message']['product']['status'] && !empty($setting['paylater_country'][$setting['general']['country_code']]) && ($currency_code == $setting['general']['currency_code'])))) {
				$params['page_code'] = 'product';
			}
			
			if (($route == 'checkout/cart') && ($setting['button']['cart']['status'] || $setting['googlepay_button']['cart']['status'] || $setting['applepay_button']['cart']['status'] || ($setting['message']['cart']['status'] && !empty($setting['paylater_country'][$setting['general']['country_code']]) && ($currency_code == $setting['general']['currency_code'])))) {
				$params['page_code'] = 'cart';
			}
			
			if (($route == $setting['general']['checkout_route']) && ($setting['button']['checkout']['status'] || $setting['googlepay_button']['checkout']['status'] || $setting['applepay_button']['checkout']['status'] || $setting['card']['status'] || ($setting['message']['checkout']['status'] && !empty($setting['paylater_country'][$setting['general']['country_code']]) && ($currency_code == $setting['general']['currency_code'])))) {
				$params['page_code'] = 'checkout';
			}
			
			if ($params) {
				$this->document->addStyle('extension/paypal/catalog/view/stylesheet/paypal.css');
				
				if (!empty($setting['googlepay_button'][$params['page_code']]['status'])) {
					$this->document->addScript('https://pay.google.com/gp/p/js/pay.js');
				}
				
				if (!empty($setting['applepay_button'][$params['page_code']]['status'])) {
					$this->document->addScript('https://applepay.cdn-apple.com/jsapi/v1/apple-pay-sdk.js');
				}
								
				if ($params['page_code'] == 'checkout') {			
					if ($setting['card']['status']) {
						$this->document->addStyle('extension/paypal/catalog/view/stylesheet/card.css');
					}
				}
				
				$params['separator'] = $this->separator;
						
				$this->document->addScript('extension/paypal/catalog/view/javascript/paypal.js?' . http_build_query($params));
			}
		}			
	}
	
	public function extension_get_extensions_by_type_after(string $route, array $data, array &$output): void {
		if ($this->config->get('payment_paypal_status') && $this->config->get('payment_paypal_client_id') && $this->config->get('payment_paypal_secret')) {
			$type = $data[0];
			
			if ($type == 'payment') {			
				$_config = new \Opencart\System\Engine\Config();
				$_config->addPath(DIR_EXTENSION . 'paypal/system/config/');
				$_config->load('paypal');
			
				$config_setting = $_config->get('paypal_setting');
		
				$setting = array_replace_recursive((array)$config_setting, (array)$this->config->get('payment_paypal_setting'));
				
				$currency_code = $this->session->data['currency'];
				
				if (!empty($setting['paylater_country'][$setting['general']['country_code']]) && ($currency_code == $setting['general']['currency_code']) && ($setting['button']['checkout']['funding']['paylater'] != 2)) {
					$this->config->set('payment_paypal_paylater_status', 1);
					
					$output[] = [
						'extension_id' => 0,
						'extension' => 'paypal',
						'type' => 'payment',
						'code' => 'paypal_paylater'
					];
				}
				
				if ($setting['googlepay_button']['checkout']['status']) {
					$this->config->set('payment_paypal_googlepay_status', 1);
					
					$output[] = [
						'extension_id' => 0,
						'extension' => 'paypal',
						'type' => 'payment',
						'code' => 'paypal_googlepay'
					];
				}
				
				if ($setting['applepay_button']['checkout']['status'] && !empty($this->session->data['paypal']['applepay'])) {
					$this->config->set('payment_paypal_applepay_status', 1);
					
					$output[] = [
						'extension_id' => 0,
						'extension' => 'paypal',
						'type' => 'payment',
						'code' => 'paypal_applepay'
					];
				}
								
				if ($setting['fastlane']['status'] && ($setting['general']['country_code'] == 'US') && !$this->customer->isLogged()) {
					$this->config->set('payment_paypal_fastlane_status', 1);
					
					$output[] = [
						'extension_id' => 0,
						'extension' => 'paypal',
						'type' => 'payment',
						'code' => 'paypal_fastlane'
					];
				}
			}
		}			
	}
	
	public function extension_get_extension_by_code_after(string $route, array $data, array &$output): void {
		if ($this->config->get('payment_paypal_status') && $this->config->get('payment_paypal_client_id') && $this->config->get('payment_paypal_secret')) {
			$type = $data[0];
			$code = $data[1];
			
			if ($type == 'payment') {			
				$_config = new \Opencart\System\Engine\Config();
				$_config->addPath(DIR_EXTENSION . 'paypal/system/config/');
				$_config->load('paypal');
			
				$config_setting = $_config->get('paypal_setting');
		
				$setting = array_replace_recursive((array)$config_setting, (array)$this->config->get('payment_paypal_setting'));
				
				$currency_code = $this->session->data['currency'];
					
				if (($code == 'paypal_paylater') && !empty($setting['paylater_country'][$setting['general']['country_code']]) && ($currency_code == $setting['general']['currency_code']) && ($setting['button']['checkout']['funding']['paylater'] != 2)) {
					$this->config->set('payment_paypal_paylater_status', 1);
					
					$output = [
						'extension_id' => 0,
						'extension' => 'paypal',
						'type' => 'payment',
						'code' => 'paypal_paylater'
					];
				}
				
				if (($code == 'paypal_googlepay') && $setting['googlepay_button']['checkout']['status']) {
					$this->config->set('payment_paypal_googlepay_status', 1);
					
					$output = [
						'extension_id' => 0,
						'extension' => 'paypal',
						'type' => 'payment',
						'code' => 'paypal_googlepay'
					];
				}
				
				if (($code == 'paypal_applepay') && $setting['applepay_button']['checkout']['status'] && !empty($this->session->data['paypal']['applepay'])) {
					$this->config->set('payment_paypal_applepay_status', 1);
					
					$output = [
						'extension_id' => 0,
						'extension' => 'paypal',
						'type' => 'payment',
						'code' => 'paypal_applepay'
					];
				}
				
				if (($code == 'paypal_fastlane') && $setting['fastlane']['status'] && ($setting['general']['country_code'] == 'US') && !$this->customer->isLogged()) {
					$this->config->set('payment_paypal_fastlane_status', 1);
					
					$output = [
						'extension_id' => 0,
						'extension' => 'paypal',
						'type' => 'payment',
						'code' => 'paypal_fastlane'
					];
				}
			}
		}			
	}
	
	public function order_delete_order_before(string $route, array $data): void {
		$this->load->model('extension/paypal/payment/paypal');

		$order_id = $data[0];
		
		$this->model_extension_paypal_payment_paypal->deletePayPalOrder($order_id);
	}
	
	private function validateShipping(string $code): bool {
		$this->load->language('checkout/cart');
		$this->load->language('extension/paypal/payment/paypal');

		if (empty($code)) {
			$this->session->data['error_warning'] = $this->language->get('error_shipping');
			
			return false;
		} else {
			$shipping = explode('.', $code);

			if (!isset($shipping[0]) || !isset($shipping[1]) || !isset($this->session->data['shipping_methods'][$shipping[0]]['quote'][$shipping[1]])) {
				$this->session->data['error_warning'] = $this->language->get('error_shipping');
				
				return false;
			} else {
				if (version_compare(VERSION, '4.0.2.0', '>=')) {
					$this->session->data['shipping_method'] = $this->session->data['shipping_methods'][$shipping[0]]['quote'][$shipping[1]];
				} else {
					$this->session->data['shipping_method'] = $this->session->data['shipping_methods'][$shipping[0]]['quote'][$shipping[1]]['code'];
				}
				
				$this->session->data['success'] = $this->language->get('text_shipping_updated');
				
				return true;
			}
		}
	}
	
	private function validatePaymentAddress(): bool {
		if (($this->strlen(trim($this->request->post['firstname'])) < 1) || ($this->strlen(trim($this->request->post['firstname'])) > 32)) {
			$this->error['firstname'] = $this->language->get('error_firstname');
		}

		if (($this->strlen(trim($this->request->post['lastname'])) < 1) || ($this->strlen(trim($this->request->post['lastname'])) > 32)) {
			$this->error['lastname'] = $this->language->get('error_lastname');
		}

		if (($this->strlen($this->request->post['email']) > 96) || !filter_var($this->request->post['email'], FILTER_VALIDATE_EMAIL)) {
			$this->error['email'] = $this->language->get('error_email');
		}

		if ($this->config->get('config_telephone_required') && (($this->strlen($this->request->post['telephone']) < 3) || ($this->strlen($this->request->post['telephone']) > 32))) {
			$this->error['telephone'] = $this->language->get('error_telephone');
		}

		if (($this->strlen(trim($this->request->post['address_1'])) < 3) || ($this->strlen(trim($this->request->post['address_1'])) > 128)) {
			$this->error['address_1'] = $this->language->get('error_address_1');
		}

		if (($this->strlen(trim($this->request->post['city'])) < 2) || ($this->strlen(trim($this->request->post['city'])) > 128)) {
			$this->error['city'] = $this->language->get('error_city');
		}

		$this->load->model('extension/paypal/payment/paypal');

		$country_info = $this->model_extension_paypal_payment_paypal->getCountry($this->request->post['country_id']);

		if ($country_info && $country_info['postcode_required'] && ($this->strlen(trim($this->request->post['postcode'])) < 2 || $this->strlen(trim($this->request->post['postcode'])) > 10)) {
			$this->error['postcode'] = $this->language->get('error_postcode');
		}

		if ($this->request->post['country_id'] == '') {
			$this->error['country'] = $this->language->get('error_country');
		}

		if (!isset($this->request->post['zone_id']) || $this->request->post['zone_id'] == '' || !is_numeric($this->request->post['zone_id'])) {
			$this->error['zone'] = $this->language->get('error_zone');
		}
				
		$customer_group_id = $this->customer->getGroupId();
		
		// Custom field validation
		$this->load->model('account/custom_field');

		$custom_fields = $this->model_account_custom_field->getCustomFields($customer_group_id);
		
		foreach ($custom_fields as $custom_field) {
			if ($custom_field['required'] && empty($this->request->post['custom_field'][$custom_field['custom_field_id']])) {
				$this->error['custom_field' . $custom_field['custom_field_id']] = sprintf($this->language->get('error_custom_field'), $custom_field['name']);
			} elseif (($custom_field['type'] == 'text') && !empty($custom_field['validation']) && !preg_match(html_entity_decode($custom_field['validation'], ENT_QUOTES, 'UTF-8'), $this->request->post['custom_field'][$custom_field['custom_field_id']])) {
				$this->error['custom_field' . $custom_field['custom_field_id']] = sprintf($this->language->get('error_regex'), $custom_field['name']);
			}
		}
		
		return !$this->error;
	}
	
	private function validateShippingAddress(): bool {
		if (($this->strlen(trim($this->request->post['firstname'])) < 1) || ($this->strlen(trim($this->request->post['firstname'])) > 32)) {
			$this->error['firstname'] = $this->language->get('error_firstname');
		}

		if (($this->strlen(trim($this->request->post['lastname'])) < 1) || ($this->strlen(trim($this->request->post['lastname'])) > 32)) {
			$this->error['lastname'] = $this->language->get('error_lastname');
		}

		if (($this->strlen(trim($this->request->post['address_1'])) < 3) || ($this->strlen(trim($this->request->post['address_1'])) > 128)) {
			$this->error['address_1'] = $this->language->get('error_address_1');
		}

		if (($this->strlen(trim($this->request->post['city'])) < 2) || ($this->strlen(trim($this->request->post['city'])) > 128)) {
			$this->error['city'] = $this->language->get('error_city');
		}

		$this->load->model('extension/paypal/payment/paypal');

		$country_info = $this->model_extension_paypal_payment_paypal->getCountry($this->request->post['country_id']);

		if ($country_info && $country_info['postcode_required'] && ($this->strlen(trim($this->request->post['postcode'])) < 2 || Helper\Utf8\strlen(trim($this->request->post['postcode'])) > 10)) {
			$this->error['postcode'] = $this->language->get('error_postcode');
		}

		if ($this->request->post['country_id'] == '') {
			$this->error['country'] = $this->language->get('error_country');
		}

		if (!isset($this->request->post['zone_id']) || $this->request->post['zone_id'] == '' || !is_numeric($this->request->post['zone_id'])) {
			$this->error['zone'] = $this->language->get('error_zone');
		}
		
		$customer_group_id = $this->customer->getGroupId();
		
		// Custom field validation
		$this->load->model('account/custom_field');

		$custom_fields = $this->model_account_custom_field->getCustomFields($customer_group_id);

		foreach ($custom_fields as $custom_field) {
			if ($custom_field['location'] == 'address') {
				if ($custom_field['required'] && empty($this->request->post['custom_field'][$custom_field['custom_field_id']])) {
					$this->error['custom_field' . $custom_field['custom_field_id']] = sprintf($this->language->get('error_custom_field'), $custom_field['name']);
				} elseif (($custom_field['type'] == 'text') && !empty($custom_field['validation']) && !preg_match(html_entity_decode($custom_field['validation'], ENT_QUOTES, 'UTF-8'), $this->request->post['custom_field'][$custom_field['custom_field_id']])) {
					$this->error['custom_field' . $custom_field['custom_field_id']] = sprintf($this->language->get('error_regex'), $custom_field['name']);
				}
			}
		}
		
		return !$this->error;
	}
	
	private function validateCoupon(): bool {
		$this->load->model('marketing/coupon');

		$coupon_info = $this->model_marketing_coupon->getCoupon($this->request->post['coupon']);

		if ($coupon_info) {
			return true;
		} else {
			$this->session->data['error_warning'] = $this->language->get('error_coupon');
			
			return false;
		}
	}

	private function validateVoucher(): bool {
		$this->load->model('checkout/voucher');

		$voucher_info = $this->model_checkout_voucher->getVoucher($this->request->post['voucher']);
		
		if ($voucher_info) {
			return true;
		} else {
			$this->session->data['error_warning'] = $this->language->get('error_voucher');
			
			return false;
		}
	}

	private function validateReward(): bool {
		$points = $this->customer->getRewardPoints();

		$points_total = 0;

		foreach ($this->cart->getProducts() as $product) {
			if ($product['points']) {
				$points_total += $product['points'];
			}
		}

		$error = '';

		if (empty($this->request->post['reward'])) {
			$error = $this->language->get('error_reward');
		}

		if ($this->request->post['reward'] > $points) {
			$error = sprintf($this->language->get('error_points'), $this->request->post['reward']);
		}

		if ($this->request->post['reward'] > $points_total) {
			$error = sprintf($this->language->get('error_maximum'), $points_total);
		}

		if (!$error) {
			return true;
		} else {
			$this->session->data['error_warning'] = $error;
			
			return false;
		}
	}
	
	private function validateFastlaneCustomer(): bool {
		if (($this->strlen($this->request->post['email']) > 96) || !filter_var($this->request->post['email'], FILTER_VALIDATE_EMAIL)) {
			$this->error['email'] = $this->language->get('error_email');
		}
		
		return !$this->error;
	}
	
	private function unserialize(string $str): array {
		$data = [];
				
		$str = str_replace('&amp;', '&', $str);
		
		parse_str($str, $data);
		
		return $data;
	}
		
	private function strlen(string $str): int {
		if (version_compare(VERSION, '4.0.2.0', '>=')) {
			return (int)oc_strlen($str);
		} elseif (version_compare(VERSION, '4.0.1.0', '>=')) {
			return (int)Helper\Utf8\strlen($str);
		} else {
			return (int)utf8_strlen($str);
		}
	}
}