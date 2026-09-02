<?php
/**
 * @package    HikaShop Payment Plugin - Stripe Checkout
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Menu\AbstractMenu;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

class plgHikashoppaymentStripe extends hikashopPaymentPlugin
{
    protected $autoloadLanguage = true;

    public $multiple = true;          // Allow the plugin to support multiple instances
    public $name = 'stripe';          // Internal name of the plugin (matches plugin entry file)
    public $doc_form = 'stripe';      // Generate the help link in the backend configuration    
    public $use_cache = false;

    private const STRIPE_APP_NAME = 'Hikashop Stripe Checkout';
    private const STRIPE_APP_VERSION = '1.0';
    private const CHECKOUT_COMPLETED_EVENT = 'checkout.session.completed';
    private const REDIRECT_DEFAULT = 'default';
    private const REDIRECT_CONTROL_PANEL = 'controlpanel';
    private const REDIRECT_DOWNLOAD = 'download';

    public $pluginConfig = array(
        'sandbox' => array('HIKASHOP_STRIPE_CHECKOUT_SANDBOX', 'boolean', '0'),
        'publishable_key' => array('STRIPE_PUBLISHABLE_KEY', 'input', ''),
        'secret_key' => array('STRIPE_SECRET_KEY', 'stripepassword', ''),
        'webhook_secret' => array('HIKASHOP_STRIPE_CHECKOUT_WEBHOOK_SECRET', 'stripepassword', ''),
        'webhook_url' => array('HIKASHOP_STRIPE_CHECKOUT_WEBHOOK_URL', 'stripewebhookurl', ''),
        'redirect_url' => array('HIKASHOP_STRIPE_CHECKOUT_REDIRECT_URL', 'striperedirecturl', ''),
        'items_details' => array('HIKASHOP_STRIPE_CHECKOUT_ITEMS_DETAILS', 'boolean', '1'),
        'coupon_details' => array('HIKASHOP_STRIPE_CHECKOUT_COUPON_DETAILS', 'boolean', '1'),
        'create_customer' => array('HIKASHOP_STRIPE_CHECKOUT_CREATE_CUSTOMER', 'boolean', '1'),
        'debug' => array('DEBUG', 'boolean', '0'),
        'invalid_status' => array('HIKASHOP_STRIPE_CHECKOUT_INVALID_STATUS', 'orderstatus'),
        'verified_status' => array('HIKASHOP_STRIPE_CHECKOUT_VERIFIED_STATUS', 'orderstatus')
    );

    public $accepted_currencies = array(
        'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'AED', 'AFN', 'ALL',
        'AMD', 'ANG', 'AOA', 'ARS', 'AWG', 'AZN', 'BAM', 'BBD',
        'BDT', 'BGN', 'BIF', 'BMD', 'BND', 'BOB', 'BRL', 'BSD',
        'BWP', 'BZD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP', 'CRC',
        'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ETB',
        'FJD', 'FKP', 'GEL', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD',
        'HKD', 'HNL', 'HRK', 'HTG', 'HUF', 'IDR', 'ILS', 'INR',
        'ISK', 'JMD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KRW',
        'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD',
        'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRO', 'MUR',
        'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO',
        'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR',
        'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR',
        'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLL', 'SOS', 'SRD',
        'STD', 'SZL', 'THB', 'TJS', 'TOP', 'TRY', 'TTD', 'TWD',
        'TZS', 'UAH', 'UGX', 'UYU', 'UZS', 'VND', 'VUV', 'WST',
        'XAF', 'XCD', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW'
    );

    private $initialized = null;

    /**
     * Initialize the plugin and Stripe SDK.
     *
     * @return bool
     */
    protected function init()
    {
        if ($this->initialized !== null) {
            return $this->initialized;
        }

        $app = Factory::getApplication();

        if (version_compare(PHP_VERSION, '7.2.5', '<')) {
            hikashop_writeToLog(Text::sprintf('HIKASHOP_STRIPE_CHECKOUT_PHP_VERSION_DETECTED', PHP_VERSION));

            if ($app->isClient('administrator')) {
                $app->enqueueMessage(Text::_('HIKASHOP_STRIPE_CHECKOUT_PHP_VERSION'), 'error');
            }

            return $this->initialized = false;
        }

        if (!class_exists('Stripe\\StripeClient')) {
            $sdkInitPath = JPATH_PLUGINS . '/hikashoppayment/' . $this->name . '/vendor/stripe/init.php';

            if (!file_exists($sdkInitPath)) {
                hikashop_writeToLog(Text::sprintf('HIKASHOP_STRIPE_CHECKOUT_SDK_INIT_NOT_FOUND', $sdkInitPath));

                if ($app->isClient('administrator')) {
                    $app->enqueueMessage(Text::_('HIKASHOP_STRIPE_CHECKOUT_CLASS_NOT_FOUND'), 'error');
                }

                return $this->initialized = false;
            }

            require_once $sdkInitPath;

            if (!class_exists('Stripe\\StripeClient')) {
                hikashop_writeToLog(Text::_('HIKASHOP_STRIPE_CHECKOUT_STRIPE_CLIENT_NOT_AVAILABLE'));

                if ($app->isClient('administrator')) {
                    $app->enqueueMessage(Text::_('HIKASHOP_STRIPE_CHECKOUT_CLASS_NOT_FOUND'), 'error');
                }

                return $this->initialized = false;
            }
        }

        return $this->initialized = true;
    }


     /**
     * Render custom HikaShop configuration fields.
     *
     * @return string
     */ 
      public function pluginConfigDisplay($optionType, $extraOption, $type, $paramsType, $key, $element, $options)
          {
              $fieldType = is_array($optionType) ? $optionType[0] : $optionType;
              $map = 'data[' . $type . '][' . $paramsType . '][' . $key . ']';
              $value = isset($element->$paramsType->$key) ? $element->$paramsType->$key : (isset($options[2]) ? $options[2] : '');

              if (!is_array($optionType)) {
                  switch ($fieldType) {
                      case 'stripepassword':
                      case 'password':
                          return '<input type="password" id="data_' . $type . '_' . $paramsType . '_' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" name="' . htmlspecialchars($map, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" class="inputbox" size="50" autocomplete="new-password" />';

                      case 'stripewebhookurl':
                          $url = rtrim(HIKASHOP_LIVE, '/') . '/index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=' . rawurlencode($this->name) . '&tmpl=component';
                          $html = '<input type="text" value="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="inputbox" size="100" readonly="readonly" onclick="this.select();" />';
                          $instructionKey = 'HIKASHOP_STRIPE_CHECKOUT_WEBHOOK_URL_INSTRUCTIONS';
                          $instruction = Text::_($instructionKey);

                          if ($instruction === $instructionKey) {
                              $instruction = 'Use this URL as the webhook endpoint in Stripe.';
                          }

                          return $html . '<div style="margin-top:6px;color:#666;font-size:.9em;">' . $instruction . '</div>';

                      case 'striperedirecturl':
                          $languages = LanguageHelper::getLanguages();

                          if (empty($languages)) {
                              return '';
                          }

                          $menu = AbstractMenu::getInstance('site');
                          $html = '';

                          foreach ($languages as $language) {
                              $langCode = $language->lang_code;
                              $redirectKey = 'redirect_url_' . $langCode;
                              $redirectMap = 'data[' . $type . '][' . $paramsType . '][' . $redirectKey . ']';
                              $currentValue = !empty($element->$paramsType->$redirectKey) ? (string) $element->$paramsType->$redirectKey : self::REDIRECT_DEFAULT;

                              $groups = array();

                              // Default hikashop (after_end) view
                              $translated = Text::_('HIKASHOP_STRIPE_CHECKOUT_REDIRECT_URL_DEFAULT');
                              $defaultText = $translated !== 'HIKASHOP_STRIPE_CHECKOUT_REDIRECT_URL_DEFAULT' ? $translated : 'Default HikaShop after_end view';

                              // Customer download space
                              $translated = Text::_('HIKASHOP_STRIPE_CHECKOUT_REDIRECT_URL_DOWNLOAD');
                              $downloadText = $translated !== 'HIKASHOP_STRIPE_CHECKOUT_REDIRECT_URL_DOWNLOAD' ? $translated : 'Show the customer download';
                              
                              // User control panel
                              $translated = Text::_('HIKASHOP_STRIPE_CHECKOUT_REDIRECT_URL_CONTROL_PANEL');
                              $controlPanelText = $translated !== 'HIKASHOP_STRIPE_CHECKOUT_REDIRECT_URL_CONTROL_PANEL' ? $translated : 'Show the customer receipt';

                              $standardItems = array(
                                  HTMLHelper::_('select.option', self::REDIRECT_DEFAULT, $defaultText),
                                  HTMLHelper::_('select.option', self::REDIRECT_DOWNLOAD, $downloadText),
                                  HTMLHelper::_('select.option', self::REDIRECT_CONTROL_PANEL, $controlPanelText)
                              );

                              $groups[] = array('value' => '', 'text' => '', 'items' => $standardItems);

                              $menuItems = $menu->getItems(array('language', 'type'), array(array('*', $langCode), 'component'));

                              if (!empty($menuItems)) {
                                  $menuOptions = array();

                                  foreach ($menuItems as $menuItem) {
                                      if (empty($menuItem->id)) {
                                          continue;
                                      }

                                      $menuOptions[] = HTMLHelper::_('select.option', 'menu:' . (int) $menuItem->id, $menuItem->title);
                                  }

                                  if (!empty($menuOptions)) {
                                      // Choose a menu item (such as a thank you article or upsell page)
                                      $translated = Text::_('HIKASHOP_STRIPE_CHECKOUT_REDIRECT_URL_OTHER_ITEMS');
                                      $otherItemsText = $translated !== 'HIKASHOP_STRIPE_CHECKOUT_REDIRECT_URL_OTHER_ITEMS' ? $translated : 'Custom menu item (non guest checkout only)';
                                      $groups[] = array('value' => '', 'text' => $otherItemsText, 'items' => $menuOptions);
                                  }
                              }

                              $html .= '<div style="margin-bottom:12px;"><div style="margin-bottom:5px;"><strong>' . 
                                  htmlspecialchars($langCode, ENT_QUOTES, 'UTF-8') . '</strong></div>' . 
                                  HTMLHelper::_('select.groupedlist', $groups, $redirectMap, 
                                  array(
                                      'list.select' => $currentValue,
                                      'list.attr' => 'class="no-chzn custom-select" style="width:fit-content;"'
                                       )) 
                                  . '</div>';
                          }

                          return $html;

                      default:
                          return '';
                  }
              }

              return '';
          }

    /**
     * Display additional configuration fields and retrieve Stripe information.
     *
     * @param object $element
     *
     * @return bool
     */
    public function onPaymentConfiguration(&$element)
    {
        parent::onPaymentConfiguration($element);
        
        if (!$this->init()) {
            return true;
        }

        $params = isset($element->payment_params) ? $element->payment_params : null;

        if (empty($params)) {
            return true;
        }
        
        $secretKey = trim(isset($params->secret_key) ? $params->secret_key : '');
        $publishableKey = trim(isset($params->publishable_key) ? $params->publishable_key : '');
        $sandbox = !empty($params->sandbox);

        if ($secretKey === '' || $publishableKey === '') {
            return true;
        }

        $secretPrefix = $sandbox ? 'sk_test_' : 'sk_live_';
        $publishablePrefix = $sandbox ? 'pk_test_' : 'pk_live_';

        if (strpos($secretKey, $secretPrefix) !== 0 || strpos($publishableKey, $publishablePrefix) !== 0) {
            return true;
        }

        return true;
    }

    /**
     * Validate Stripe credentials and verify or create webhook.
     *
     * @param object $element
     *
     * @return bool
     */
    public function onPaymentConfigurationSave(&$element)
    {
        if (!$this->init()) {
            return true;
        }

        $app = Factory::getApplication();
        $params = isset($element->payment_params) ? $element->payment_params : null;

        if (empty($params)) {
            return true;
        }


        $secretKey = trim(isset($params->secret_key) ? $params->secret_key : '');
        $publishableKey = trim(isset($params->publishable_key) ? $params->publishable_key : '');
        $webhookSecret = trim(isset($params->webhook_secret) ? $params->webhook_secret : '');
        $sandbox = !empty($params->sandbox);

        if ($secretKey === '' || $publishableKey === '') {
            $app->enqueueMessage(Text::_('HIKASHOP_STRIPE_CHECKOUT_KEYS_REQUIRED'), 'warning');
            return true;
        }

        $secretPrefix = $sandbox ? 'sk_test_' : 'sk_live_';
        $publishablePrefix = $sandbox ? 'pk_test_' : 'pk_live_';

        if (strpos($secretKey, $secretPrefix) !== 0) {
            $app->enqueueMessage(Text::_('HIKASHOP_STRIPE_CHECKOUT_SECRET_KEY_NOT_MATCH'), 'error');
            return true;
        }

        if (strpos($publishableKey, $publishablePrefix) !== 0) {
            $app->enqueueMessage(Text::_('HIKASHOP_STRIPE_CHECKOUT_PUBLISHABLE_KEY_NOT_MATCH'), 'error');
            return true;
        }

        try {
            $stripe = new StripeClient(array(
                'api_key' => $secretKey,
                'app_info' => array(
                    'name' => self::STRIPE_APP_NAME,
                    'version' => self::STRIPE_APP_VERSION
                )
            ));

            $stripe->balance->retrieve();

            $webhookUrl = rtrim(HIKASHOP_LIVE, '/') . '/index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=' . rawurlencode($this->name) . '&tmpl=component';
            $startingAfter = null;
            $webhookExists = false;

            do {
                $apiParams = array('limit' => 100);

                if ($startingAfter !== null) {
                    $apiParams['starting_after'] = $startingAfter;
                }

                $endpoints = $stripe->webhookEndpoints->all($apiParams);

                foreach ($endpoints->data as $endpoint) {
                    if ($endpoint->url === $webhookUrl) {
                        $webhookExists = true;
                        break;
                    }

                    $startingAfter = $endpoint->id;
                }

                if ($webhookExists) {
                    break;
                }
            } while (!empty($endpoints->has_more));

            if ($webhookExists) {
                if ($webhookSecret === '') {
                    $app->enqueueMessage( Text::sprintf('HIKASHOP_STRIPE_CHECKOUT_WEBHOOK_SECRET_REQUIRED',  $endpoint->id ), 'warning');
                } else {
                    $app->enqueueMessage(Text::_('HIKASHOP_STRIPE_CHECKOUT_WEBHOOK_VERIFICATION_SUCCESS'), 'message');
                }
            } else {
                $endpoint = $stripe->webhookEndpoints->create(array(
                    'url' => $webhookUrl,
                    'enabled_events' => array(self::CHECKOUT_COMPLETED_EVENT)
                ));

                $app->enqueueMessage(Text::sprintf('HIKASHOP_STRIPE_CHECKOUT_WEBHOOK_CREATION_SUCCESS', $endpoint->id), 'info');
            }
        } catch (\Throwable $e) {
            $app->enqueueMessage(
                Text::sprintf('HIKASHOP_STRIPE_CHECKOUT_SECRET_KEY_INVALID', $e->getMessage()),
                'error'
            );
        }

        return true;
    }

    /**
     * Display payment methods on checkout.
     *
     * @return mixed
     */
    public function onPaymentDisplay(&$order, &$methods, &$usable_methods)
    {
        if (!$this->init()) {
            return false;
        }

        $result = parent::onPaymentDisplay($order, $methods, $usable_methods);

        if (empty($usable_methods)) {
            return $result;
        }

        foreach ($usable_methods as $key => $method) {
            if (!isset($method->payment_type) || $method->payment_type !== $this->name) {
                continue;
            }

            $params = isset($method->payment_params) ? $method->payment_params : null;
            $secretKey = trim(isset($params->secret_key) ? $params->secret_key : '');
            $publishableKey = trim(isset($params->publishable_key) ? $params->publishable_key : '');

            if ($secretKey !== '' && $publishableKey !== '') {
                continue;
            }

            $warningHtml = '<div style="margin-top:8px;padding:8px 12px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;color:#856404;font-size:.9em;">' . Text::_('HIKASHOP_STRIPE_CHECKOUT_STRIPE_ERROR_MISSING_KEYS_FRONTEND') . '</div>';
            $method->payment_description = (isset($method->payment_description) ? $method->payment_description : '') . $warningHtml;
            $paymentId = (int) $method->payment_id;

            $method->custom_html = '<script>(function(){function disableStripe(){document.querySelectorAll("input[type=radio][name=\\"checkout[payment][id]\\"]").forEach(function(r){if(r.value=="' . $paymentId . '"){r.disabled=true;var el=r.closest("label,.hikashop_payment_name,tr,li,div.hikashop_payment");if(el){el.style.opacity="0.5";}}});}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",disableStripe);}else{disableStripe();}if(window.Oby){window.Oby.registerAjax(["checkout.payment.updated"],disableStripe);}})();</script>';
        }

        return $result;
    }

    /**
     * Build the success redirect URL for an order based on the selected configuration.
     *
     * @param object $order
     * @param int|null $itemId
     *
     * @return string
     */
    protected function buildRedirectUrl($order, $itemId = null)
    {
        $language = Factory::getLanguage();
        $redirectKey = 'redirect_url_' . $language->getTag();

        $redirectValue = isset($this->payment_params->$redirectKey)
            ? $this->payment_params->$redirectKey
            : self::REDIRECT_DEFAULT;

        $redirectValue = empty($redirectValue)
            ? self::REDIRECT_DEFAULT
            : (string) $redirectValue;

        // Restrict to our strictly allowed options.
        $validValues = array(
            self::REDIRECT_DEFAULT,
            self::REDIRECT_CONTROL_PANEL,
            self::REDIRECT_DOWNLOAD
        );

        if (!in_array($redirectValue, $validValues, true) && strpos($redirectValue, 'menu:') !== 0) {
            $redirectValue = self::REDIRECT_DEFAULT;
        }

        $app = Factory::getApplication();

        // Determine the Itemid if the caller did not provide one.
        if ($itemId === null) {
            if (isset($this->Itemid)) {
                $itemId = (int) $this->Itemid;
            } else {
                $itemId = $app->getMenu()->getActive()
                    ? (int) $app->getMenu()->getActive()->id
                    : 0;
            }
        } else {
            $itemId = (int) $itemId;
        }

        // Custom menu item.
        if (strpos($redirectValue, 'menu:') === 0) {
            $menuId = (int) substr($redirectValue, 5);

            if ($menuId > 0 && $app->getMenu()->getItem($menuId) !== null) {
                $redirectUrl = HIKASHOP_LIVE
                    . 'index.php?Itemid=' . $menuId
                    . '&order_id=' . (int) $order->order_id;

                if (!empty($order->order_token)) {
                    $redirectUrl .= '&order_token=' . rawurlencode($order->order_token);
                }

                return $redirectUrl;
            }

            // Fallback if the menu item does not exist.
            $redirectValue = self::REDIRECT_DEFAULT;
        }

        // Customer download space.
        if ($redirectValue === self::REDIRECT_DOWNLOAD) {
            $redirectUrl = HIKASHOP_LIVE
                . 'index.php?option=com_hikashop'
                . '&ctrl=order'
                . '&task=show'
                . '&cid=' . (int) $order->order_id;

            if (!empty($order->order_token)) {
                $redirectUrl .= '&order_token=' . rawurlencode($order->order_token);
            }

            $redirectUrl .= '&view=user&layout=downloads';

            return $redirectUrl;
        }

        // User control panel.
        if ($redirectValue === self::REDIRECT_CONTROL_PANEL) {
            $redirectUrl = HIKASHOP_LIVE
                . 'index.php?option=com_hikashop'
                . '&ctrl=order'
                . '&task=show'
                . '&cid=' . (int) $order->order_id;

            if (!empty($order->order_token)) {
                $redirectUrl .= '&order_token=' . rawurlencode($order->order_token);
            }

            $redirectUrl .= '&view=user&layout=cpanel';

            return $redirectUrl;
        }

        // Default HikaShop destination (after_end).
        $redirectUrl = HIKASHOP_LIVE
            . 'index.php?option=com_hikashop'
            . '&ctrl=checkout'
            . '&task=after_end'
            . '&order_id=' . (int) $order->order_id
            . '&Itemid=' . $itemId;

        if (!empty($order->order_token)) {
            $redirectUrl .= '&order_token=' . rawurlencode($order->order_token);
        }

        if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
            hikashop_writeToLog('Stripe redirect URL built: ' . $redirectUrl);
        }
        
        return $redirectUrl;
    }



    /**
     * Create the Stripe Checkout Session.
     *
     * @param object $order
     * @param array  $methods
     * @param int    $method_id
     *
     * @return mixed
     */
    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        parent::onAfterOrderConfirm($order, $methods, $method_id);

        if (!$this->init()) {
            return false;
        }

        $secretKey = isset($this->payment_params->secret_key) ? trim((string) $this->payment_params->secret_key) : '';
        $publishableKey = isset($this->payment_params->publishable_key) ? trim((string) $this->payment_params->publishable_key) : '';
        $webhookSecret = isset($this->payment_params->webhook_secret) ? trim((string) $this->payment_params->webhook_secret) : '';

        if ($secretKey === '' || $publishableKey === '') {
            $reason = Text::_('HIKASHOP_STRIPE_CHECKOUT_ERROR_MISSING_KEYS');
            $this->cancelOrderOnError($order, $reason);
            $app = Factory::getApplication();
            $app->enqueueMessage($reason, 'error');
            $app->redirect(HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=step&step=0');
            return false;
        }

        if ($webhookSecret === '') {
            $reason = Text::_('HIKASHOP_STRIPE_CHECKOUT_ERROR_MISSING_WEBHOOK_SECRET');
            $this->cancelOrderOnError($order, $reason);
            $app = Factory::getApplication();
            $app->enqueueMessage($reason, 'error');
            $app->redirect(HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=step&step=0');
            return false;
        }

        $sandbox = isset($this->payment_params->sandbox) && ($this->payment_params->sandbox == '1' || $this->payment_params->sandbox === true);
        $expectedSecretPrefix = $sandbox ? 'sk_test_' : 'sk_live_';

        if (strpos($secretKey, $expectedSecretPrefix) !== 0) {
            $errorKey = $sandbox ? 'HIKASHOP_STRIPE_CHECKOUT_ERROR_INVALID_TEST_SECRET_KEY' : 'HIKASHOP_STRIPE_CHECKOUT_SECRET_KEY_NOT_MATCH';
            $reason = Text::_($errorKey);
            $this->cancelOrderOnError($order, $reason);
            $app = Factory::getApplication();
            $app->enqueueMessage($reason, 'error');
            $app->redirect(HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=step&step=0');
            return false;
        }

        try {
            $stripe = new StripeClient(array(
                'api_key' => $secretKey,
                'app_info' => array(
                    'name' => self::STRIPE_APP_NAME,
                    'version' => self::STRIPE_APP_VERSION
                )
            ));
        } catch (\Throwable $e) {
            $reason = Text::sprintf('HIKASHOP_STRIPE_CHECKOUT_ERROR_INITIALIZATION_FAILED', $e->getMessage());
            $this->cancelOrderOnError($order, $reason);
            $app = Factory::getApplication();
            $app->enqueueMessage($reason, 'error');
            $app->redirect(HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=step&step=0');
            return false;
        }

        $customer = null;
        $customerId = null;

        if (!empty($this->user->user_params) && !empty($this->user->user_params->stripe_customer_id)) {
            $customerId = $this->user->user_params->stripe_customer_id;

            try {
                $customer = $stripe->customers->retrieve($customerId);
            } catch (\Throwable $e) {
                hikashop_writeToLog('Stripe customer retrieve error: ' . $e->getMessage());
            }
        }

        $createCustomer = isset($this->payment_params->create_customer) && ($this->payment_params->create_customer == '1' || $this->payment_params->create_customer === true);

        if ($customer === null && $createCustomer && !empty($this->user->user_email)) {
            try {
                $billing = isset($order->cart->billing_address) ? $order->cart->billing_address : null;
                $firstName = isset($billing->address_firstname) ? $billing->address_firstname : '';
                $lastName = isset($billing->address_lastname) ? $billing->address_lastname : '';
                $customer = $stripe->customers->create(array(
                    'email' => $this->user->user_email,
                    'name' => trim($firstName . ' ' . $lastName)
                ));

                $user = new \stdClass();
                $user->user_id = $this->user->user_id;
                $user->user_params = $this->user->user_params;

                if (!is_object($user->user_params)) {
                    $user->user_params = new \stdClass();
                }

                $user->user_params->stripe_customer_id = $customer->id;
                hikashop_get('class.user')->save($user);
            } catch (\Throwable $e) {
                hikashop_writeToLog('Stripe customer creation error: ' . $e->getMessage());
            }
        }

        $currencyCode = strtolower($this->currency->currency_code);
        $fractionDigits = isset($this->currency->currency_locale['int_frac_digits']) ? (int) $this->currency->currency_locale['int_frac_digits'] : 2;
        $toStripeAmount = static function ($amount) use ($fractionDigits) { return (int) round((float) $amount * ($fractionDigits === 0 ? 1 : 100)); };

        $lineItems = array();
        $itemsDetails = isset($this->payment_params->items_details) && ($this->payment_params->items_details == '1' || $this->payment_params->items_details === true);

        if (!empty($order->cart->products) && $itemsDetails) {
            $hsConfig = hikashop_config();
            $uploadFolder = $hsConfig->get('uploadfolder');
            $baseUrl = rtrim(HIKASHOP_LIVE, '/') . '/' . ltrim($uploadFolder, '/');

            foreach ($order->cart->products as $product) {
                $price = (float) $product->order_product_price;
                $price += (float) (isset($product->order_product_tax) ? $product->order_product_tax : 0);

                $productData = array('name' => strip_tags($product->order_product_name));

                if (!empty($product->order_product_code)) {
                    $productData['description'] = $product->order_product_code;
                }

                if (!empty($product->images)) {
                    $images = array();

                    foreach ($product->images as $image) {
                        if (!empty($image->file_path)) {
                            $images[] = $baseUrl . $image->file_path;
                        }
                    }

                    if (!empty($images)) {
                        $productData['images'] = array_slice($images, 0, 8);
                    }
                }

                $lineItems[] = array(
                    'price_data' => array(
                        'currency' => $currencyCode,
                        'unit_amount' => $toStripeAmount($price),
                        'product_data' => $productData
                    ),
                    'quantity' => (int) $product->order_product_quantity
                );
            }
        } else {
            $total = 0.0;

            if (isset($order->cart->full_total->prices[0]->price_value_with_tax)) {
                $total = (float) $order->cart->full_total->prices[0]->price_value_with_tax;
            }

            $lineItems[] = array(
                'price_data' => array(
                    'currency' => $currencyCode,
                    'unit_amount' => $toStripeAmount($total),
                    'product_data' => array('name' => Text::_('ORDER_NUMBER') . ' : ' . $order->order_number)
                ),
                'quantity' => 1
            );
        }

        if (!empty($order->order_shipping_price) && bccomp((string) $order->order_shipping_price, '0', 5) !== 0) {
            $lineItems[] = array(
                'price_data' => array(
                    'currency' => $currencyCode,
                    'unit_amount' => $toStripeAmount($order->order_shipping_price),
                    'product_data' => array('name' => Text::_('SHIPPING_FEE') ?: 'Shipping Fee')
                ),
                'quantity' => 1
            );
        }

        if (!empty($order->order_payment_price) && bccomp((string) $order->order_payment_price, '0', 5) !== 0) {
            $lineItems[] = array(
                'price_data' => array(
                    'currency' => $currencyCode,
                    'unit_amount' => $toStripeAmount($order->order_payment_price),
                    'product_data' => array('name' => Text::_('PAYMENT_FEE') ?: 'Payment Fee')
                ),
                'quantity' => 1
            );
        }

        $app = Factory::getApplication();

        if (isset($this->Itemid)) {
            $itemId = (int) $this->Itemid;
        } else {
            $itemId = $app->getMenu()->getActive()
                ? (int) $app->getMenu()->getActive()->id
                : 0;
        }

        $successUrl = $this->buildRedirectUrl($order, $itemId);

        $cancelUrl = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=order&task=cancel_order&order_id=' . (int) $order->order_id . '&Itemid=' . $itemId;

        $sessionAttrs = array(
            'mode' => 'payment',
            'client_reference_id' => (string) $order->order_id,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items' => $lineItems
        );

        $couponDetails = isset($this->payment_params->coupon_details) && ($this->payment_params->coupon_details == '1' || $this->payment_params->coupon_details === true);

        if (!empty($order->order_discount_price) && bccomp((string) $order->order_discount_price, '0', 5) !== 0 && $couponDetails) {
            $discountAmount = abs((float) $order->order_discount_price);

            try {
                $coupon = $stripe->coupons->create(array(
                    'name' => 'Order Discount (' . $order->order_number . ')',
                    'amount_off' => $toStripeAmount($discountAmount),
                    'currency' => $currencyCode,
                    'duration' => 'once'
                ));

                $sessionAttrs['discounts'] = array(array('coupon' => $coupon->id));
            } catch (\Throwable $e) {
                hikashop_writeToLog('Stripe coupon creation error: ' . $e->getMessage());
            }
        }

        if (!empty($customer->id)) {
            $sessionAttrs['customer'] = $customer->id;
        } elseif (!empty($this->user->user_email)) {
            $sessionAttrs['customer_email'] = $this->user->user_email;
        }

        try {
            $session = $stripe->checkout->sessions->create($sessionAttrs);
        } catch (\Throwable $e) {
            $reason = Text::sprintf('HIKASHOP_STRIPE_CHECKOUT_ERROR_SESSION_CREATION_FAILED', $e->getMessage());
            $this->cancelOrderOnError($order, $reason);
            $app = Factory::getApplication();
            $app->enqueueMessage($reason, 'error');
            $app->redirect(HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=step&step=0');
            return false;
        }

        if (empty($session->id)) {
            return false;
        }

        $this->stripe_session_id = $session->id;
        $this->stripe_session_url = isset($session->url) ? $session->url : '';

        $paymentParams = isset($order->order_payment_params) ? $order->order_payment_params : null;

        if (!empty($paymentParams) && is_string($paymentParams)) {
            $paymentParams = @unserialize($paymentParams);
        }

        if (empty($paymentParams) || !is_object($paymentParams)) {
            $paymentParams = new \stdClass();
        }

        $paymentParams->stripe_session_id = $session->id;

        if (!empty($session->payment_intent)) {
            $paymentParams->stripe_payment_intent_id = $session->payment_intent;
        }

        $updateOrder = new \stdClass();
        $updateOrder->order_id = $order->order_id;
        $updateOrder->order_payment_params = serialize($paymentParams);
        hikashop_get('class.order')->save($updateOrder);

        if (isset($this->payment_params->debug) && ($this->payment_params->debug == '1' )) {
            $result = hikashop_get('class.order')->save($updateOrder);

            hikashop_writeToLog(
                'Stripe session ID saved: order_id=' . (int) $order->order_id .
                ' | session_id=' . $session->id .
                ' | save_result=' . var_export($result, true)
            );
        }

        $this->removeCart = true;

        return $this->showPage('end');
    }

    /**
     * Process Stripe webhook and legacy HikaShop notification.
     *
     * @param array $statuses
     *
     * @return bool
     */
    public function onPaymentNotification(&$statuses)
    {
        if (!$this->init()) {
            return false;
        }

        $app = Factory::getApplication();
        
        // Retrieve raw POST data
        $payload = file_get_contents('php://input');
        // Get Stripe signature from headers
        $signature = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';

        /*
         * First identify the HikaShop order from the raw Stripe webhook payload.
         * Then load that order's payment parameters.
         * Only AFTER loadPaymentParams() may we access:
         *   - $this->payment_params->debug
         *   - $this->payment_params->webhook_secret
         *   - $this->payment_params->verified_status
         *   - etc.
         */
        if (!empty($payload) || !empty($signature)) {

            /*
             * Decode the payload only to obtain the HikaShop order ID.
             * The payload is NOT considered trusted until the Stripe signature is verified below.
             */
            $rawData = json_decode($payload, true);

            if (!is_array($rawData)) {
                if (isset($this->payment_params->debug) && ($this->payment_params->debug == '1' )) {
                   hikashop_writeToLog('Stripe webhook: invalid JSON payload');
                }
                http_response_code(400);
                echo 'Invalid JSON payload';
                $app->close();
                return false;
            }

            $orderId = isset($rawData['data']['object']['client_reference_id']) ? (int) $rawData['data']['object']['client_reference_id'] : 0;

            if ($orderId <= 0) {
                if (isset($this->payment_params->debug) && ($this->payment_params->debug == '1' )) {
                   hikashop_writeToLog('Stripe webhook: client_reference_id missing');
                }
                http_response_code(400);
                echo 'Missing client_reference_id';
                $app->close();
                return false;
            }

            // NOW load the HikaShop order.
            $dbOrder = $this->getOrder($orderId);

            if (empty($dbOrder)) {
                if (isset($this->payment_params->debug) && ($this->payment_params->debug == '1' )) {
                    hikashop_writeToLog('Stripe webhook: HikaShop order ' . $orderId . ' not found');
                }
                http_response_code(400);
                echo 'Order not found';
                $app->close();
                return false;
            }

             // Load the payment parameters BEFORE accessing ANY $this->payment_params property.
            $this->loadPaymentParams($dbOrder);

            if (empty($this->payment_params)) {
                hikashop_writeToLog('Stripe webhook: payment parameters could not be loaded for order ' . $orderId);
                http_response_code(400);
                echo 'Payment parameters missing';
                $app->close();
                return false;
            }

            // NOW the debug parameter is available.
            if (isset($this->payment_params->debug) && ($this->payment_params->debug == '1' )) {
                hikashop_writeToLog('Stripe webhook/notification payload received: ' . (!empty($payload) ? 'yes' : 'no'));
            }

            // NOW retrieve the webhook secret.
            $webhookSecret = isset($this->payment_params->webhook_secret) ? trim((string) $this->payment_params->webhook_secret) : '';

            if ($webhookSecret === '') {
                if (isset($this->payment_params->debug) && ($this->payment_params->debug == '1' )) {
                    hikashop_writeToLog('Stripe webhook: webhook_secret is missing for order ' . $orderId);
                }
                http_response_code(400);
                echo 'Webhook secret missing';
                $app->close();
                return false;
            }

            // NOW verify the Stripe signature.
            try {
                $event = Webhook::constructEvent($payload, $signature, $webhookSecret);
            } catch (UnexpectedValueException | SignatureVerificationException $e) {
                hikashop_writeToLog('Stripe webhook signature verification failed: ' . $e->getMessage());
                http_response_code(400);
                echo 'Invalid Payload or Signature';
                $app->close();
                return false;
            }

            // The event is now cryptographically verified.
            $eventId = isset($event->id) ? $event->id : 'unknown';
            $eventType = isset($event->type) ? $event->type : 'unknown';

            if (isset($this->payment_params->debug) && ($this->payment_params->debug == '1' )) {
                hikashop_writeToLog('Stripe webhook verified. Event ID: ' . $eventId . ', Type: ' . $eventType);
            }

            // Only process checkout.session.completed
            if ($eventType !== self::CHECKOUT_COMPLETED_EVENT) {
                if (isset($this->payment_params->debug) && ($this->payment_params->debug == '1' )) {
                   hikashop_writeToLog('Stripe webhook event received, but it was not checkout.session.completed');
                }
                http_response_code(200);
                echo json_encode(array('status' => 'ignored'));
                $app->close();
                return true;
            }

            // From here onward, use the VERIFIED Stripe event.
            $session = $event->data->object;

            $verifiedOrderId = isset($session->client_reference_id) ? (int) $session->client_reference_id : 0;

            if ($verifiedOrderId <= 0) {
                if (isset($this->payment_params->debug) && ($this->payment_params->debug == '1' )) {
                    hikashop_writeToLog('Stripe verified event has no valid client_reference_id');
                }
                http_response_code(400);
                echo 'Missing client_reference_id';
                $app->close();
                return false;
            }

            if ($verifiedOrderId !== $orderId) {
                hikashop_writeToLog('Stripe webhook order ID mismatch: initial=' . $orderId . ' verified=' . $verifiedOrderId);
                http_response_code(400);
                echo 'Order ID mismatch';
                $app->close();
                return false;
            }

            if (isset($this->payment_params->debug) && ($this->payment_params->debug == '1' )) {
                hikashop_writeToLog('Stripe webhook checkout.session.completed for HikaShop order ID: ' . $orderId);
            }

            // Retrieve the Stripe Checkout Session ID that was stored in the HikaShop order when the Checkout Session was created.
            $storedSessionId = '';
            if (!empty($dbOrder->order_payment_params)) {
                $paymentParams = $dbOrder->order_payment_params;
                if (is_string($paymentParams)) {
                    $paymentParams = @unserialize($paymentParams);
                }
                if (is_object($paymentParams) && !empty($paymentParams->stripe_session_id)) {
                    $storedSessionId = (string) $paymentParams->stripe_session_id;
                }
            }

            // Make sure this webhook belongs to the exact Stripe Checkout Session that was created for this HikaShop order.
            if ($storedSessionId === '' || !hash_equals($storedSessionId, (string) $session->id)) {
                hikashop_writeToLog(
                    'Stripe session mismatch for HikaShop order ' . $orderId .
                    '. Stored session: ' . ($storedSessionId !== '' ? $storedSessionId : '[none]') .
                    ', webhook session: ' . (isset($session->id) ? $session->id : '[none]')
                );
                http_response_code(400);
                echo 'Stripe session mismatch';
                $app->close();
                return false;
            }

            /*
             * Make sure the Checkout Session was actually paid. checkout.session.completed can occur when a session completes,
             * but we should only confirm the HikaShop order when Stripe reports the payment as paid.
             */
            $paymentStatus = isset($session->payment_status) ? (string) $session->payment_status : '';
            if ($paymentStatus !== 'paid') {
                hikashop_writeToLog(
                    'Stripe checkout.session.completed received for HikaShop order ' .
                    $orderId . ' but payment_status is: ' . $paymentStatus
                );
                http_response_code(200);
                echo json_encode(array('status' => 'payment_not_paid'));
                $app->close();
                return true;
            }

            /*
             * All validation has passed!
             * 1. Stripe webhook signature is valid.
             * 2. Event is checkout.session.completed.
             * 3. client_reference_id contains a valid HikaShop order ID.
             * 4. HikaShop order exists.
             * 5. The Stripe Session ID matches the Session ID stored in that HikaShop order.
             * 6. Stripe says the payment is paid.
             * Now we can confirm the HikaShop order!
             */
             if (isset($this->payment_params->verified_status) && !empty($this->payment_params->verified_status)) {
                $verifiedStatus = $this->payment_params->verified_status;
            } else {
                $verifiedStatus = 'confirmed';
            }

            if (isset($this->payment_params->debug) && ($this->payment_params->debug == '1' )) {
                hikashop_writeToLog(
                    'Stripe about to confirm HikaShop order: ' .
                    'order_id=' . $orderId .
                    ' | status=' . $verifiedStatus .
                    ' | current_status=' . $dbOrder->order_status
                );
            }

            /*
             * Idempotency: Stripe may deliver the same webhook event more than once.
             * If the HikaShop order is already in the verified status, do not call modifyOrder() again.
             */
            if ($dbOrder->order_status === $verifiedStatus) {

                if (isset($this->payment_params->debug) && ($this->payment_params->debug == '1' )) {
                    hikashop_writeToLog(
                        'Stripe order already has verified status. ' .
                        'Skipping modifyOrder(): order_id=' . $orderId .
                        ' | status=' . $verifiedStatus
                    );
                }

            } else {

                $this->modifyOrder($orderId, $verifiedStatus, true, true);

                if (isset($this->payment_params->debug) && ($this->payment_params->debug == '1' )) {
                    hikashop_writeToLog(
                        'Stripe modifyOrder() completed for order_id=' . $orderId
                    );
                }
            }

            if (isset($this->payment_params->debug) && ($this->payment_params->debug == '1' )) {
                hikashop_writeToLog(
                    'Stripe webhook successfully processed HikaShop order ID: ' . $orderId
                );
            }

            http_response_code(200);
            echo json_encode(array('status' => 'success'));
            $app->close();
            return true;
        }

        $orderId = $app->input->get('order_id', 0, 'int');
        $orderToken = $app->input->get('order_token', '', 'string');
        $itemId = $app->input->get('Itemid', 0, 'int');
        
        $dbOrder = $this->getOrder($orderId);
        
        if (empty($dbOrder)) {
            return false;
        }

        $this->loadPaymentParams($dbOrder);
        $this->loadOrderData($dbOrder);

        if (empty($orderToken) || empty($dbOrder->order_token) || md5($dbOrder->order_token) !== $orderToken) {
            $app->redirect(HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=order&task=cancel_order&order_id=' . (int) $orderId . '&Itemid=' . (int) $itemId);
            return false;
        }

        $redirectUrl = $this->buildRedirectUrl($dbOrder, $itemId);

        $app->redirect($redirectUrl);

        return true;
    }

    /**
     * Cancel an order after a Stripe failure.
     *
     * @param object $order
     * @param string $reason
     *
     * @return void
     */
    protected function cancelOrderOnError($order, $reason)
    {
        try {
            $history = new \stdClass();
            $history->notified = 0;
            $history->data = 'Stripe checkout aborted: ' . $reason;
            $orderStatus = isset($this->payment_params->invalid_status) && !empty($this->payment_params->invalid_status) ? $this->payment_params->invalid_status : 'cancelled';
            $orderId = (int) $order->order_id;

            hikashop_writeToLog(sprintf('cancelOrderOnError: order_id=%d, reason=%s', $orderId, $reason));
                       
            $this->modifyOrder($orderId, $orderStatus, $history, null);    
            if (isset($this->payment_params->debug) && ($this->payment_params->debug == '1' )) {
                hikashop_writeToLog(
                    'Stripe modifyOrder() cancelled for order_id=' . $orderId
                );
            }            
            
        } catch (\Throwable $e) {
            hikashop_writeToLog('cancelOrderOnError failed: ' . $e->getMessage());
        }
    }

    /**
     * Sets default values when saving a new payment method of this type in the backend.
     *
     * @param object $element
     *
     * @return void
     */
      public function getPaymentDefaultValues(&$element)
      {
          $element->payment_name = Text::_('HIKASHOP_STRIPE_CHECKOUT_DEFAULT_PAYMENT_NAME');
          $element->payment_description = Text::_('HIKASHOP_STRIPE_CHECKOUT_DEFAULT_PAYMENT_DESCRIPTION');
          $element->payment_images = 'Stripe,MasterCard,VISA,American_Express';

          $element->payment_params->sandbox = '0';

          $element->payment_params->publishable_key = '';
          $element->payment_params->secret_key = '';
          $element->payment_params->webhook_secret = '';
          $element->payment_params->webhook_url = '';

          $element->payment_params->redirect_url = '';

          $element->payment_params->items_details = '1';
          $element->payment_params->coupon_details = '1';
          $element->payment_params->create_customer = '1';
          $element->payment_params->debug = '0';
          $element->payment_params->invalid_status = 'cancelled';
          $element->payment_params->verified_status = 'confirmed';
      }
}