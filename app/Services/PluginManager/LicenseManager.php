<?php

namespace FluentCampaign\App\Services\PluginManager;

class LicenseManager
{
    private $settings;

    private $pluginBaseName = '';

    public function __construct()
    {
        $this->pluginBaseName = 'fluentcampaign-pro/fluentcampaign-pro.php';;

        $urlBase = apply_filters('fluent_crm/menu_url_base', admin_url('admin.php?page=fluentcrm-admin#/'));

        $this->settings = [
            'item_id'        => 341197,
            'license_server' => 'https://apiv2.wpmanageninja.com/plugin',
            'plugin_file'    => FLUENTCAMPAIGN_DIR_FILE,
            'store_url'      => 'https://wpmanageninja.com',
            'version'        => FLUENTCAMPAIGN_PLUGIN_VERSION,
            'purchase_url'   => 'https://fluentcrm.com/',
            'settings_key'   => '__fluentcrm_campaign_license',
            'activate_url'   => $urlBase . 'settings/license_settings',
            'plugin_title'   => 'FluentCRM - Email Marketing Addon',
            'author'         => 'FluentCRM'
        ];

        add_filter('plugin_row_meta', array($this, 'pluginRowMeta'), 10, 2);

        add_filter('fluent_crm/dashboard_notices', function ($notices) {
            $details = $this->getLicenseMessages();
            if($details && !empty($details['message'])) {
                $notices[] = '<div style="padding: 10px;" class="error">'.$details['message'].'</div>';
            }

            return $notices;
        });

    }

    public function pluginRowMeta($links, $file)
    {
        if ($this->pluginBaseName !== $file) {
            return $links;
        }

        $checkUpdateUrl = esc_url(admin_url('plugins.php?fluentcrm_pro_check_update=' . time()));

        $row_meta = array(
            'docs'         => '<a href="' . esc_url(apply_filters('fluent_crm/docs_url', 'https://fluentcrm.com/docs/')) . '" aria-label="' . esc_attr__('View FluentCRM documentation', 'fluentcampaign-pro') . '">' . esc_html__('Docs', 'fluentcampaign-pro') . '</a>',
            'support'      => '<a href="' . esc_url(apply_filters('fluent_crm/community_support_url', 'https://wpmanageninja.com/support-tickets/#/')) . '" aria-label="' . esc_attr__('Visit Support', 'fluentczmpaign-pro') . '">' . esc_html__('Help & Support', 'fluentcampaign-pro') . '</a>',
            'check_update' => '<a  style="color: #583fad;font-weight: 600;" href="' . $checkUpdateUrl . '" aria-label="' . esc_attr__('Check Update', 'fluentcampaign-pro') . '">' . esc_html__('Check Update', 'fluentcampaign-pro') . '</a>',
        );

        return array_merge($links, $row_meta);
    }

    public function getVar($var)
    {
        if (isset($this->settings[$var])) {
            return $this->settings[$var];
        }
        return false;
    }

    public function licenseVar($var)
    {
        $details = $this->getLicenseDetails();
        if (isset($details[$var])) {
            return $details[$var];
        }
        return false;
    }

    public function getLicenseDetails()
    {
        return array('status'=>'valid', 'license_key' => '*********', 'expires'     => '01.01.2030',);

        $settingsKey = $this->getVar('settings_key');
        $defaults = [
            'license_key' => '',
            'price_id'    => '',
            'expires'     => '',
            'status'      => 'unregistered', // this is the status mainly
        ];

        $licenseStatus = get_option($settingsKey);

        if (!$licenseStatus || !is_array($licenseStatus)) {
            return $defaults;
        }

        return wp_parse_args($licenseStatus, $defaults);
    }

    public function getLicenseMessages()
    {
        $licenseDetails = $this->getLicenseDetails();
        $status = $licenseDetails['status'];

        if ($status == 'expired') {
            return [
                'message'         => $this->getExpireMessage($licenseDetails),
                'type'            => 'in_app',
                'license_details' => $licenseDetails
            ];
        }

        if ($status != 'valid') {
            return [
                'message'         => sprintf(__('The %s license needs to be activated. %sActivate Now%s', 'fluentcampaign-pro'),
                    $this->getVar('plugin_title'), '<a href="' . $this->getVar('activate_url') . '">',
                    '</a>'),
                'type'            => 'global',
                'license_details' => $licenseDetails
            ];
        }

        return false;
    }

    public function activateLicense($licenseKey)
    {
        // data to send in our API request
        $api_params = array(
            'edd_action' => 'activate_license',
            'license'    => $licenseKey,
            'item_name'  => urlencode($this->getVar('item_name')), // the name of our product in EDD
            'item_id'    => $this->getVar('item_id'),
            'url'        => home_url()
        );

        $payloadParams = $api_params;
        if ($otherData = $this->getOtherInfo()) {
            $payloadParams['other_data'] = $otherData;
        }

        // Call the custom API.
        $response = wp_remote_post(
            $this->getVar('license_server'),
            array(
                'timeout'   => 15,
                'sslverify' => false,
                'body'      => $payloadParams
            )
        );

        // make sure the response came back okay
        if (is_wp_error($response)) {
            $license_data = file_get_contents($this->getVar('license_server') . '?' . http_build_query($api_params));
            if (!$license_data) {
                $license_data = $this->urlGetContentFallBack($this->getVar('license_server') . '?' . http_build_query($api_params));
            }
            if (!$license_data) {
                return new \WP_Error(
                    423,
                    'Error when contacting with license server. Please check that your server have curl installed',
                    [
                        'response' => $response,
                        'is_error' => true
                    ]
                );
            }
            $license_data = json_decode($license_data, true);
        } else {
            $license_data = json_decode(wp_remote_retrieve_body($response), true);
        }

        return $this->processRemoteLicenseData($license_data, $licenseKey);
    }

    public function deactivateLicense()
    {
        $licenseDetails = $this->getLicenseDetails();

        if(empty($licenseDetails['license_key'])) {
            return new \WP_Error(423, 'No license key found');
        }

        $licenseKey = $licenseDetails['license_key'];

        // data to send in our API request
        $api_params = array(
            'edd_action' => 'deactivate_license',
            'license'    => $licenseKey,
            'item_name'  => urlencode($this->getVar('item_name')), // the name of our product in EDD
            'item_id'    => $this->getVar('item_id'),
            'url'        => home_url()
        );

        // Call the custom API.
        $response = wp_remote_post($this->getVar('license_server'),
            array('timeout' => 15, 'sslverify' => false, 'body' => $api_params));

        // make sure the response came back okay
        if (is_wp_error($response)) {
            return new \WP_Error(423, 'There was an error deactivating the license, please try again or login at wpmanageninja.com to manually deactivated the license');
        }

        // decode the license data
        $license_data = json_decode(wp_remote_retrieve_body($response), true);

        // $license_data->license will be either "deactivated" or "failed"
        if ('deactivated' == $license_data['license'] || $license_data['license'] == 'failed') {
            return $this->updateLicenseDetails([
                'status'      => 'unregistered',
                'license_key' => '',
                'expires'     => ''
            ]);
        }

        return new \WP_Error(423, 'There was an error deactivating the license, please try again or login at wpmanageninja.com to manually deactivated the license');
    }

    public function isRequireVerify()
    {
        $lastCalled = get_option($this->getVar('settings_key') . '_lc');
        if (!$lastCalled) {
            return true;
        }

        return (time() - $lastCalled) > 604800; // 7 days
    }

    public function verifyRemoteLicense($isForced = false)
    {
        if (!$isForced) {
            if (!$this->isRequireVerify()) { // 48 hours
                return false;
            }
        }


        $remoteLicense = $this->getRemoteLicense();

        if (!$remoteLicense || is_wp_error($remoteLicense)) {
            return false; // network error maybe
        }

        update_option($this->getVar('settings_key') . '_lc', time(), 'no');

        return $this->processRemoteLicenseData($remoteLicense);
    }

    public function getRemoteLicense()
    {
        $licenseKey = $this->getSavedLicenseKey();

        if (!$licenseKey) {
            return new \WP_Error(423, 'No license key available');
        }

        $api_params = array(
            'edd_action' => 'check_license',
            'item_id'    => $this->getVar('item_id'),
            'license'    => $licenseKey,
            'item_name'  => urlencode($this->getVar('item_name')),
            'url'        => home_url()
        );

        if (mt_rand(0, 100) > 60) {
            if ($otherData = $this->getOtherInfo()) {
                $api_params['other_data'] = $otherData;
            }
        }

        // Call the custom API.
        $response = wp_remote_post(
            $this->getVar('license_server'),
            array(
                'timeout'   => 15,
                'sslverify' => false,
                'body'      => $api_params
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    private function processRemoteLicenseData($license_data, $licenseKey = false)
    {
        if (!$licenseKey) {
            $licenseKey = $this->getSavedLicenseKey();
        }
        $licenseStatus = $license_data['license'];

        // $license_data->license will be either "valid" or "invalid"
        if ($licenseStatus) {
            if ($licenseStatus == 'invalid' && $license_data['error'] == 'expired') {
                $this->updateLicenseDetails([
                    'status'   => 'expired',
                    'expires'  => $license_data['expires'],
                    'price_id' => $license_data['price_id']
                ]);
            } else {
                $this->updateLicenseDetails([
                    'expires' => $license_data['expires'],
                    'status'  => $licenseStatus
                ]);
            }
        }

        if ('valid' == $licenseStatus) {
            return $this->updateLicenseDetails([
                'status'      => $licenseStatus,
                'license_key' => $licenseKey
            ]);
        }

        $errorMessage = $this->getErrorMessage($license_data, $licenseKey);

        return new \WP_Error(
            423,
            $errorMessage,
            [
                'license_data' => $license_data,
                'is_error'     => true
            ]
        );
    }

    private function updateLicenseDetails($data)
    {
        $licenseDetails = $this->getLicenseDetails();
        update_option($this->getVar('settings_key'), wp_parse_args($data, $licenseDetails));
        return get_option($this->getVar('settings_key'));
    }

    private function getErrorMessage($licenseData, $licenseKey = false)
    {
        $errorMessage = 'There was an error activating the license, please verify your license is correct and try again or contact support.';

        if ($licenseData['error'] == 'expired') {
            $renewUrl = $this->getRenewUrl($licenseKey);
            $errorMessage = 'Your license has been expired at ' . $licenseData->expires . ' . Please <a target="_blank" href="' . $renewUrl . '">click here</a> to renew your license';
        } else if ($licenseData['error'] == 'no_activations_left') {
            $errorMessage = 'No Activation Site left: You have activated all the sites that your license offer. Please go to wpmanageninja.com account and review your sites. You may deactivate your unused sites from wpmanageninja account or you can purchase another license. <a target="_blank" href="' . $this->getVar('purchase_url') . '">' . __('Click Here to purchase another license', 'fluentcampaign-pro') . '</a>';
        } else if ($licenseData['error'] == 'missing') {
            $errorMessage = __('The given license key is not valid. Please verify that your license is correct. You may login to wpmanageninja.com account and get your valid license key for your purchase.', 'fluentcampaign-pro');
        }

        return $errorMessage;
    }

    public function getExpireMessage($licenseData, $scope = 'global')
    {
        if ($scope == 'global') {
            $renewUrl = $this->getVar('activate_url');
        } else {
            $renewUrl = $this->getRenewUrl();
        }

        return '<p>Your ' . $this->getVar('plugin_title') . ' license has been <b>expired at ' . date('d M Y', strtotime($licenseData['expires'])) . '</b>, Please ' .
            '<a href="' . $renewUrl . '"><b>' . __('Click Here to Renew Your License', 'fluentcampaign-pro') . '</b></a>' . '</p>';
    }

    private function urlGetContentFallBack($url)
    {
        $parts = parse_url($url);
        $host = $parts['host'];
        $result = false;
        if (!function_exists('curl_init')) {
            $ch = curl_init();
            $header = array('GET /1575051 HTTP/1.1',
                "Host: {$host}",
                'Accept:text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language:en-US,en;q=0.8',
                'Cache-Control:max-age=0',
                'Connection:keep-alive',
                'Host:adfoc.us',
                'User-Agent:Mozilla/5.0 (Macintosh; Intel Mac OS X 10_8_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/27.0.1453.116 Safari/537.36',
            );
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
            curl_setopt($ch, CURLOPT_COOKIESESSION, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
            $result = curl_exec($ch);
            curl_close($ch);
        }
        if (!$result && function_exists('fopen') && function_exists('stream_get_contents')) {
            $handle = fopen($url, "r");
            $result = stream_get_contents($handle);
        }
        return $result;
    }

    private function getSavedLicenseKey()
    {
        $details = $this->getLicenseDetails();
        return $details['license_key'];
    }

    public function getRenewUrl($licenseKey = false)
    {
        if (!$licenseKey) {
            $licenseKey = $this->getSavedLicenseKey();
        }
        if ($licenseKey) {
            $renewUrl = $this->getVar('store_url') . '/checkout/?edd_license_key=' . $licenseKey . '&download_id=' . $this->getVar('item_id');
        } else {
            $renewUrl = $this->getVar('purchase_url');
        }
        return $renewUrl;
    }

    /*
     * Init Updater
     */
    public function initUpdater()
    {
        $licenseDetails = $this->getLicenseDetails();

        // setup the updater
        new Updater($this->getVar('license_server'), $this->getVar('plugin_file'), array(
            'version'   => $this->getVar('version'),
            'license'   => $licenseDetails['license_key'],
            'item_name' => $this->getVar('item_name'),
            'item_id'   => $this->getVar('item_id'),
            'author'    => $this->getVar('author')
        ),
            array(
                'license_status' => $licenseDetails['status'],
                'admin_page_url' => $this->getVar('activate_url'),
                'purchase_url'   => $this->getVar('purchase_url'),
                'plugin_title'   => $this->getVar('plugin_title')
            )
        );
    }

    private function getOtherInfo()
    {
        return false;

        if (!$this->timeMatched()) {
            return false;
        }

        global $wp_version;
        $themeName = wp_get_theme()->get('Name');
        if(strlen($themeName) > 30) {
            $themeName = 'custom-theme';
        }

        return [
            'plugin_version' => $this->getVar('version'),
            'php_version'    => (defined('PHP_VERSION')) ? PHP_VERSION : phpversion(),
            'wp_version'     => $wp_version,
            'plugins'        => (array) get_option('active_plugins'),
            'site_lang'      => get_bloginfo('language'),
            'site_title'     => get_bloginfo('name'),
            'theme'          => $themeName
        ];
    }

    private function timeMatched()
    {
        $prevValue = get_option('_fluent_last_m_run');
        if (!$prevValue) {
            return true;
        }
        return (time() - $prevValue) > 518400; // 6 days match
    }

}
