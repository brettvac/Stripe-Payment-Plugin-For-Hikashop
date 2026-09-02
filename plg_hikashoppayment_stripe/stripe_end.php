<?php
/**
 * @package    HikaShop Payment Plugin - Stripe Checkout (Bridge)
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

// Read the publishable key with PHP 8.1+ safe fallbacks
$isSandbox = !empty($this->payment_params->sandbox);
$publishableKey = trim($this->payment_params->publishable_key ?? '');

if ($isSandbox && $publishableKey !== '' && strpos($publishableKey, 'pk_test_') !== 0) {
    Factory::getApplication()->enqueueMessage(Text::_('HIKASHOP_STRIPE_CHECKOUT_PUBLISHABLE_KEY_NOT_MATCH'),'warning');
}

$debugMode = !empty($this->payment_params->debug) ? '1' : '0';

$js = '
    function goToStripeGateway() {
        try {
            var debug = "'. $debugMode .'";
            jQuery("#stripeGoToGateway").prop("disabled", true);
            
            // Replaced stripe.redirectToCheckout with a standard window redirect
            var checkoutUrl = "'. (isset($this->stripe_session_url) ? $this->stripe_session_url : '') .'";
            
            if (checkoutUrl !== "") {
                window.location.href = checkoutUrl;
            } else {
                throw new Error("'. Text::_('HIKASHOP_STRIPE_CHECKOUT_URL_MISSING') .'");
            }
            
        } catch(e) {
            jQuery("#stripeGoToGateway").prop("disabled", false);
            if(debug == "1") {
                if(e.message) {
                    alert(e.message);
                } else {
                    alert(e);
                }
            }
            alert("'. Text::_('STRIPE_CHECKOUT_GENERAL_ERROR') .'");
        }
    }
    
    jQuery(document).ready(function() {
        goToStripeGateway();
    });
';

$doc = Factory::getDocument();
// $doc->addScript('https://js.stripe.com/v3/'); removed as Stripe.js is no longer needed purely for redirects.
hikashop_loadJsLib('jquery');
$doc->addScriptDeclaration($js);

?>

<fieldset>
    <legend><?php echo Text::_('HIKASHOP_STRIPE_CHECKOUT_GOING_TO_GATEWAY'); ?></legend>
    <div id="stripeCheckoutContainer" style="width:100%;margin:auto;text-align:center;">
        <button id="stripeGoToGateway" class="btn btn-success btn-lg btn-block" onclick="goToStripeGateway();">
            <?php echo Text::_('HIKASHOP_STRIPE_CHECKOUT_GO_TO_GATEWAY'); ?>
        </button>
    </div>
</fieldset>