<?php

/**
 * @var $app \FluentCrm\Includes\Core\Application
 */
// Let's push dashboard stats

$app->addFilter('fluentcrm_is_require_verify', function ($status) {
    $licenseManager = new \FluentCampaign\App\Services\PluginManager\LicenseManager();
    return $licenseManager->isRequireVerify() && $licenseManager->licenseVar('status') == 'valid';
});


add_filter('fluentcrm_commerce_provider', function ($defaultProvider) {
    return \FluentCampaign\App\Services\Commerce\Commerce::getCommerceProvider($defaultProvider);
}, 10, 1);

add_filter('fluentcrm_currency_sign', function ($currencySign) {
    return \FluentCampaign\App\Services\Commerce\Commerce::getDefaultCurrencySign($currencySign);
}, 10, 2);


add_filter('fluent_crm/contact_lifetime_value', function ($value, $contact) {
    return \FluentCampaign\App\Services\Commerce\Commerce::getLifetimeValue($value, $contact);
}, 10, 2);


if (defined('FL_BUILDER_VERSION')) {
    add_filter('fl_builder_subscribe_form_services', function ($services) {
        if (is_array($services)) {
            return array_merge([
                'fluentcrm' => [
                    'type'      => 'autoresponder',
                    'name'      => 'FluentCRM',
                    'class'     => '\FluentCampaign\App\Hooks\Handlers\FLBuilderServiceFluentCrm',
                    'namespace' => true,
                ]
            ], $services);
        }

        return $services;
    });
}

add_filter('fluent_crm/dashboard_notices', function ($notices) {
    if (version_compare(FLUENTCAMPAIGN_CORE_MIN_VERSION, FLUENTCRM_PLUGIN_VERSION, '>')) {
        $updateUrl = admin_url('plugins.php?s=fluent-crm&plugin_status=all');
        $notices[] = '<div style="padding: 15px 10px;" class="updated"><b>Heads UP: </b> FluentCRM Core needs to be updated to the latest version. <a href="' . esc_url($updateUrl) . '">Click here to update</a></div>';
    }
    return $notices;
});

if (defined('WC_PLUGIN_FILE')) {
    $app->addFilter('woocommerce_checkout_fields', 'FluentCampaign\App\Hooks\Handlers\IntegrationHandler@maybeFillUpWooCheckoutFields', 99, 1);
}

add_filter('fluent_crm/double_optin_options', function ($config, $subscriber) {
    return (new \FluentCampaign\App\Hooks\Handlers\Cleanup())->routingDoiRedirect($config, $subscriber);
}, 10, 2);

