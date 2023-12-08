<?php

!defined('WPINC') && die;

define('FLUENTCAMPAIGN', 'fluentcampaign');
define('FLUENTCAMPAIGN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FLUENTCAMPAIGN_PLUGIN_VERSION', '2.8.33');
define('FLUENTCAMPAIGN_CORE_MIN_VERSION', '2.8.33');
define('FLUENTCAMPAIGN_FRAMEWORK_VERSION', 3);

spl_autoload_register(function ($class) {
    $match = 'FluentCampaign';
    if (!preg_match("/\b{$match}\b/", $class)) {
        return;
    }

    $path = plugin_dir_path(__FILE__);
    $file = str_replace(
        ['FluentCampaign', '\\', '/App/'],
        ['', DIRECTORY_SEPARATOR, 'app/'],
        $class
    );
    require(trailingslashit($path) . trim($file, '/') . '.php');
});

class Fluent_Campaign_Pro_Dependency
{
    public function init()
    {
        $this->injectDependency();
    }

    /**
     * Notify the user about the FluentForm dependency and instructs to install it.
     */
    protected function injectDependency()
    {
        add_action('admin_notices', function () {
            $pluginInfo = $this->getInstallationDetails();

            $class = 'notice notice-error';

            $install_url_text = 'Click Here to Install the Plugin';

            if ($pluginInfo->action == 'activate') {
                $install_url_text = 'Click Here to Activate the Plugin';
            }

            $message = 'FluentCRM Email Campaign Pro Add-On Requires FluentCRM Base Plugin, <b><a href="' . $pluginInfo->url
                . '">' . $install_url_text . '</a></b>';

            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
        });
    }

    /**
     * Get the FluentForm plugin installation information e.g. the URL to install.
     *
     * @return \stdClass $activation
     */
    protected function getInstallationDetails()
    {
        $activation = (object)[
            'action' => 'install',
            'url'    => ''
        ];

        $allPlugins = get_plugins();

        if (isset($allPlugins['fluent-crm/fluent-crm.php'])) {
            $url = wp_nonce_url(
                self_admin_url('plugins.php?action=activate&plugin=fluent-crm/fluent-crm.php'),
                'activate-plugin_fluent-crm/fluent-crm.php'
            );

            $activation->action = 'activate';
        } else {
            $api = (object)[
                'slug' => 'fluent-crm'
            ];

            $url = wp_nonce_url(
                self_admin_url('update.php?action=install-plugin&plugin=' . $api->slug),
                'install-plugin_' . $api->slug
            );
        }

        $activation->url = $url;

        return $activation;
    }
}

add_action('init', function () {
    if (!defined('FLUENTCRM')) {
        (new Fluent_Campaign_Pro_Dependency())->init();
    }
});
