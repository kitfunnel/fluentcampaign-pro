<?php

namespace FluentCampaign\App\Services\Integrations\Edd;


use FluentCrm\App\Services\PermissionManager;

class EddInit
{
    public function init()
    {
        new \FluentCampaign\App\Services\Integrations\Edd\EddPaymentSuccessTrigger();
        new \FluentCampaign\App\Services\Integrations\Edd\EddOrderSuccessBenchmark();

        if (defined('EDD_SL_VERSION')) {
            new \FluentCampaign\App\Services\Integrations\Edd\EddLicenseExpiredTrigger();
        }

        if (defined('EDD_RECURRING_VERSION')) {
            new \FluentCampaign\App\Services\Integrations\Edd\EddRecurringPaymentTrigger();
            new \FluentCampaign\App\Services\Integrations\Edd\EddRecurringExpired();
            new \FluentCampaign\App\Services\Integrations\Edd\EddSubscriptionActiveBenchmark();
        }


        add_filter('fluent_crm/sales_stats', array($this, 'pushStats'));
        add_action('edd_insert_payment', array($this, 'maybeCampaignMeta'), 10, 2);
        add_action('edd_update_payment_status', array($this, 'maybeRecordPayment'), 10, 3);

        add_action('edd_view_order_details_sidebar_after', array($this, 'printCrmProfileWidget'));

        if (!apply_filters('fluentcrm_disable_integration_metaboxes', false, 'edd')) {
            (new \FluentCampaign\App\Services\Integrations\Edd\EddMetaBoxes())->init();
        }

        (new EddImporter)->init();
        (new DeepIntegration())->init();
        (new EddSmartCodeParse())->init();

    }

    public function pushStats($stats)
    {
        if (current_user_can(apply_filters('edd_dashboard_stats_cap', 'view_shop_reports'))) {

            $eddStat = new \EDD_Payment_Stats;
            $eddStats = [
                [
                    'title'   => __('Earnings (Today)', 'fluentcampaign-pro'),
                    'content' => edd_currency_filter(edd_format_amount($eddStat->get_earnings(0, 'today')))
                ],
                [
                    'title'   => __('Earnings (Current Month)', 'fluentcampaign-pro'),
                    'content' => edd_currency_filter(edd_format_amount($eddStat->get_earnings(0, 'this_month')))
                ],
                [
                    'title'   => __('Earnings (All Time)', 'fluentcampaign-pro'),
                    'content' => edd_currency_filter(edd_format_amount(edd_get_total_earnings()))
                ]
            ];
            $stats = array_merge($stats, $eddStats);
        }
        return $stats;
    }

    public function maybeCampaignMeta($paymentId, $paymentData)
    {
        if (isset($_COOKIE['fc_cid'])) {
            $campaignId = intval($_COOKIE['fc_cid']);
            if ($campaignId) {
                edd_update_payment_meta($paymentId, '_fc_cid', $campaignId);
            }
        }

        if (isset($_COOKIE['fc_sid'])) {
            $subscriberId = intval($_COOKIE['fc_sid']);
            if ($subscriberId) {
                edd_update_payment_meta($paymentId, '_fc_sid', $subscriberId);
            }
        }
    }

    public function maybeRecordPayment($paymentId, $newStatus, $oldStatus)
    {
        if ($newStatus == $oldStatus) {
            return;
        }

        $successStatuses = ['publish', 'complete', 'completed'];

        if (in_array($newStatus, $successStatuses)) {
            $campaignId = edd_get_payment_meta($paymentId, '_fc_cid', true);
            if ($campaignId) {
                $payment = edd_get_payment($paymentId);
                if (edd_get_payment_meta($paymentId, '_fc_revenue_recorded', true) == 'yes') {
                    return;
                }
                edd_update_payment_meta($paymentId, '_fc_revenue_recorded', 'yes');
                $paymentTotal = $payment->total * 100;
                if (method_exists('\FluentCrm\App\Services\Helper', 'recordCampaignRevenue')) {
                    \FluentCrm\App\Services\Helper::recordCampaignRevenue($campaignId, $paymentTotal, $payment->currency);
                }
            }
        }
    }

    public function printCrmProfileWidget($paymentId)
    {
        $hasPermission = apply_filters('fluent_crm/can_view_contact_card_in_plugin', PermissionManager::currentUserCan('fcrm_read_contacts'), 'edd');

        if (!$hasPermission) {
            return;
        }

        $payment = edd_get_payment($paymentId);
        $userId = $payment->user_id;
        if (!$userId) {
            $userId = $payment->email;
        }

        $profileHtml = fluentcrm_get_crm_profile_html($userId, false);

        if (!$profileHtml) {
            return;
        }

        ?>
        <div id="fc-profile" class="postbox edd-fc-profile">
            <h3 class="hndle">
                <span><?php _e('FluentCRM Profile', 'fluentcampaign-pro'); ?></span>
            </h3>
            <div class="inside">
                <?php echo $profileHtml; ?>
            </div>
        </div>
        <?php
    }
}
