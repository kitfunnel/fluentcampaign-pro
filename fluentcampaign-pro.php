<?php
/*
Plugin Name:  FluentCRM Pro
Plugin URI:   https://fluentcrm.com
Description:  Pro Email Automation and Integration Addon for FluentCRM
Version:      2.8.45
Author:       Fluent CRM
Author URI:   https://fluentcrm.com
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  fluentcampaign-pro
Domain Path:  /languages
*/

if (defined('FLUENTCAMPAIGN_DIR_FILE')) {
    return;
}

define('FLUENTCAMPAIGN_DIR_FILE', __FILE__);
define('FLUENTCAMPAIGN_PLUGIN_PATH', plugin_dir_path(__FILE__));

require_once FLUENTCAMPAIGN_PLUGIN_PATH.'fluentcampaign_boot.php';

add_action('fluentcrm_loaded', function ($app) {
    if (defined('FLUENTCRM_FRAMEWORK_VERSION') && FLUENTCRM_FRAMEWORK_VERSION >= 3) {
        (new \FluentCampaign\App\Application($app));
        do_action('fluentcampaign_loaded', $app);
    } else {
        // We have to show a notice here to update the core version
        add_action('admin_notices', function () {
            echo '<div class="fc_notice notice notice-error fc_notice_error"><h3>Update FluentCRM Plugin</h3><p>FluentCRM Pro requires the latest version of the FluentCRM Core Plugin. <a href="' . admin_url('plugins.php?s=fluent-crm&plugin_status=all') . '">' . __('Please update FluentCRM to latest version', 'fluentcampaign-pro') . '</a>.</p></div>';
        });
    }
});

function fluentCampaignProDeactivate()
{
    wp_clear_scheduled_hook('fluentcrm_check_daily_birthday');
}

register_activation_hook(
    __FILE__, array('FluentCampaign\App\Migration\Migrate', 'run')
);

register_deactivation_hook(__FILE__, 'fluentCampaignProDeactivate');

// Handle Newtwork new Site Activation
add_action('wp_insert_site', function ($new_site) {
    if (is_plugin_active_for_network('fluentcampaign-pro/fluentcampaign-pro.php')) {
        switch_to_blog($new_site->blog_id);
        \FluentCampaign\App\Migration\Migrate::run(false);
        restore_current_blog();
    }
});

add_action('init', function () {
    load_plugin_textdomain('fluentcampaign-pro', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

add_action('plugins_loaded', function () {
    $licenseManager = new \FluentCampaign\App\Services\PluginManager\LicenseManager();
    $licenseManager->initUpdater();

    $licenseMessage = $licenseManager->getLicenseMessages();

    if ($licenseMessage) {
        add_action('admin_notices', function () use ($licenseMessage) {
            if (defined('FLUENTCRM')) {
                $class = 'notice notice-error fc_message';
                $message = $licenseMessage['message'];
                printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
            }
        });
    }
}, 0);
