<?php
/**
 * @package     Joomla.Site
 * @subpackage  plg_hikashoppayment_stripe
 * @version     1.1
 * @license     GNU General Public License version 3
 */

defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

// Debug configuration
$debugMode = !empty($this->payment_params->debug) ? '1' : '0';

// Stripe checkout URL
$checkoutUrl = $this->stripe_session_url ?? '';

// Log template execution and URL availability
if ($debugMode) {
    if ($checkoutUrl !== '') {
        $urlHost = parse_url($checkoutUrl, PHP_URL_HOST);
        $urlScheme = parse_url($checkoutUrl, PHP_URL_SCHEME);
        hikashop_writeToLog('Stripe end template: URL ' . ($checkoutUrl !== '' ? 'available' : 'missing') . ' | scheme=' . ($urlScheme ?: 'unknown') . ' | host=' . ($urlHost ?: 'unknown'));
    }
}

// Read the publishable key
$isSandbox = !empty($this->payment_params->sandbox);
$publishableKey = trim($this->payment_params->publishable_key ?? '');

if ($isSandbox && $publishableKey !== '' && strpos($publishableKey, 'pk_test_') !== 0) {
    Factory::getApplication()->enqueueMessage(
        Text::_('HIKASHOP_STRIPE_CHECKOUT_PUBLISHABLE_KEY_NOT_MATCH'),
        'warning'
    );
}

// Prepare JavaScript values safely
$jsCheckoutUrl = json_encode(
    $checkoutUrl,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);

$jsUrlMissing = json_encode(
    Text::_('HIKASHOP_STRIPE_CHECKOUT_URL_MISSING'),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);

$jsGeneralError = json_encode(
    Text::_('STRIPE_CHECKOUT_GENERAL_ERROR'),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);

$jsDebugMode = json_encode($debugMode === '1');

// Prepare a safe HTML link
$htmlCheckoutUrl = htmlspecialchars(
    $checkoutUrl !== '' ? $checkoutUrl : '#',
    ENT_QUOTES,
    'UTF-8'
);
?>

<fieldset>
    <legend>
        <?php echo Text::_('HIKASHOP_STRIPE_CHECKOUT_GOING_TO_GATEWAY'); ?>
    </legend>

    <div id="stripeCheckoutContainer" style="width:100%;margin:auto;text-align:center;">
        <a
            id="stripeGoToGateway"
            class="btn btn-success btn-lg btn-block"
            href="<?php echo $htmlCheckoutUrl; ?>"
        >
            <?php echo Text::_('HIKASHOP_STRIPE_CHECKOUT_GO_TO_GATEWAY'); ?>
        </a>
    </div>
</fieldset>

<script>

(function () {
    "use strict";

    const debug = <?php echo $jsDebugMode; ?>;
    const checkoutUrl = <?php echo $jsCheckoutUrl; ?>;
    const urlMissingMessage = <?php echo $jsUrlMissing; ?>;
    const generalErrorMessage = <?php echo $jsGeneralError; ?>;

    function goToStripeGateway() {
        if (!checkoutUrl) {
            if (debug) {
                console.error("Stripe Checkout:", urlMissingMessage);
            }

            alert(generalErrorMessage);
            return;
        }

        if (debug) {
            console.log("Redirecting to Stripe Checkout:", checkoutUrl);
        }

        window.location.assign(checkoutUrl);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", goToStripeGateway);
    } else {
        goToStripeGateway();
    }

})();
</script>
