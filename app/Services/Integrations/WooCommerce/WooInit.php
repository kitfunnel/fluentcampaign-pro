<?php

namespace FluentCampaign\App\Services\Integrations\WooCommerce;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\AutoSubscribe;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class WooInit
{
    public function init()
    {
        new \FluentCampaign\App\Services\Integrations\WooCommerce\WooOrderSuccessTrigger();
        new \FluentCampaign\App\Services\Integrations\WooCommerce\WooOrderSuccessBenchmark();
        new \FluentCampaign\App\Services\Integrations\WooCommerce\WooOrderCompletedTrigger();
        new \FluentCampaign\App\Services\Integrations\WooCommerce\WooOrderStatusChangeTrigger();
        new \FluentCampaign\App\Services\Integrations\WooCommerce\WooOrderRefundedTrigger();
        new \FluentCampaign\App\Services\Integrations\WooCommerce\OrderStatusChangeAction();
        new \FluentCampaign\App\Services\Integrations\WooCommerce\AddOrderNoteAction();

        add_filter('fluentcrm_sales_stats', array($this, 'pushStatus'));

        add_action('woocommerce_checkout_create_order', array($this, 'maybeCampaignMeta'));
        add_action('woocommerce_order_status_processing', array($this, 'maybeRecordPayment'), 10, 2);
        add_action('woocommerce_order_status_completed', array($this, 'maybeRecordPayment'), 10, 2);

        add_action('add_meta_boxes', array($this, 'maybeAddOrderWidget'), 99, 2);

        add_action('woocommerce_before_order_notes', array($this, 'addSubscribeBox'), 999);
        add_action('woocommerce_checkout_update_order_meta', array($this, 'maybeSubscriptionChecked'), 99, 1);

        (new WooProductAdmin())->init();

        (new DeepIntegration())->init();
        new WooImporter();

        (new AutomationConditions())->init();
        (new WooSmartCodeParse())->init();
    }

    public function pushStatus($stats)
    {
        if (current_user_can('view_woocommerce_reports') || current_user_can('manage_woocommerce') || current_user_can('publish_shop_orders')) {

            if (!class_exists('\WC_Report_Sales_By_Date')) {
                global $woocommerce;
                include_once($woocommerce->plugin_path() . '/includes/admin/reports/class-wc-admin-report.php');
                include_once($woocommerce->plugin_path() . '/includes/admin/reports/class-wc-report-sales-by-date.php');
            }


            $todaySalesQuery = new \WC_Report_Sales_By_Date();
            $todaySalesQuery->start_date = strtotime(date('Y-m-d', current_time('timestamp')));
            $todaySalesQuery->end_date = strtotime(date('Y-m-d', current_time('timestamp')));
            $todaySalesQuery->chart_groupby = 'month';
            $todaySalesQuery->group_by_query = 'YEAR(posts.post_date), MONTH(posts.post_date), DAY(posts.post_date)';
            $todayData = $todaySalesQuery->get_report_data();

            $monthSalesQuery = new \WC_Report_Sales_By_Date();
            $monthSalesQuery->start_date = strtotime(date('Y-m-01', current_time('timestamp')));
            $monthSalesQuery->end_date = strtotime(date('Y-m-d', current_time('timestamp')));
            $monthSalesQuery->chart_groupby = 'month';
            $monthSalesQuery->group_by_query = 'YEAR(posts.post_date), MONTH(posts.post_date), DAY(posts.post_date)';
            $monthData = $monthSalesQuery->get_report_data();

            $wooStats = [
                [
                    'title'   => __('Sales (Today)', 'fluentcampaign-pro'),
                    'content' => wc_price($todayData->net_sales)
                ],
                [
                    'title'   => __('Sales (This Month)', 'fluentcampaign-pro'),
                    'content' => wc_price($monthData->net_sales)
                ]
            ];
            $stats = array_merge($stats, $wooStats);
        }
        return $stats;
    }

    public function maybeCampaignMeta($order)
    {
        if (isset($_COOKIE['fc_cid'])) {
            $campaignId = intval($_COOKIE['fc_cid']);
            if ($campaignId) {
                $order->update_meta_data('_fc_cid', $campaignId);
            }
        }

        if (isset($_COOKIE['fc_sid'])) {
            $subscriberId = intval($_COOKIE['fc_sid']);
            if ($subscriberId) {
                $order->update_meta_data('_fc_sid', $subscriberId);
            }
        }
    }


    /**
     * @param $orderId int
     * @param $order \WC_Order
     */
    public function maybeRecordPayment($orderId, $order)
    {
        $campaignId = $order->get_meta('_fc_cid');
        if ($campaignId) {
            if ($order->get_meta('_fc_revenue_recorded') == 'yes') {
                return;
            }
            $order->update_meta_data('_fc_revenue_recorded', 'yes');
            $paymentTotal = intval($order->get_total() * 100);
            \FluentCrm\App\Services\Helper::recordCampaignRevenue($campaignId, $paymentTotal, $order->get_currency());
        }
    }

    public function maybeAddOrderWidget($postType, $post)
    {
        if ($postType != 'shop_order') {
            return;
        }

        $contactPermission = apply_filters('fluentcrm_permission', 'manage_options', 'contacts', 'admin_menu');
        if (!current_user_can($contactPermission)) {
            return;
        }

        $order = wc_get_order($post->ID);
        $userId = $order->get_user_id();
        if (!$userId) {
            $userId = $order->get_billing_email();
        }
        if (!function_exists('\fluentcrm_get_crm_profile_html')) {
            return;
        }

        $profileHtml = fluentcrm_get_crm_profile_html($userId, false);

        if (!$profileHtml) {
            return;
        }

        add_meta_box('fluentcrm_woo_order_widget', __('FluentCRM Profile', 'fluentcampaign-pro'), function () use ($profileHtml) {
            echo $profileHtml;
        }, 'shop_order', 'side', 'low');
    }

    public function addSubscribeBox()
    {
        $settings = fluentcrm_get_option('woo_checkout_form_subscribe_settings', []);

        if (!$settings || Arr::get($settings, 'status') != 'yes') {
            return false;
        }

        if (Arr::get($settings, 'show_only_new') == 'yes') {
            if ($userId = get_current_user_id()) {
                $user = get_user_by('ID', $userId);
                $contact = Subscriber::where('user_id', $userId)->orWhere('email', $user->user_email)->first();
                if ($contact && $contact->status == 'subscribed') {
                    return false;
                }
            }
        }

        $heading = Arr::get($settings, 'checkbox_label');

        $defaultValue = \WC()->checkout->get_value('_fc_woo_checkout_subscribe');

        if (Arr::get($settings, 'auto_checked') == 'yes') {
            $defaultValue = 1;
        }

        \woocommerce_form_field('_fc_woo_checkout_subscribe', array(
            'type'        => 'checkbox',
            'label_class' => 'fc_woo',
            'class'       => array('input-checkbox', 'fc_subscribe_woo'),
            'label'       => $heading,
        ), $defaultValue);
    }

    public function maybeSubscriptionChecked($oderId)
    {
        if (empty($_POST['_fc_woo_checkout_subscribe'])) {
            return;
        }

        $settings = (new AutoSubscribe())->getWooCheckoutSettings();

        $order = wc_get_order($oderId);
        $subscriberData = Helper::prepareSubscriberData($order);

        if ($listId = Arr::get($settings, 'target_list')) {
            $subscriberData['lists'] = [$listId];
        }

        if ($tags = Arr::get($settings, 'target_tags')) {
            $subscriberData['tags'] = $tags;
        }

        $isDoubleOptin = Arr::get($settings, 'double_optin') == 'yes';

        if ($isDoubleOptin) {
            $subscriberData['status'] = 'pending';
        } else {
            $subscriberData['status'] = 'subscribed';
        }

        $contact = FunnelHelper::createOrUpdateContact($subscriberData);

        if (!$contact) {
            return false;
        }

        if ($contact->status == 'pending') {
            $contact->sendDoubleOptinEmail();
        }

    }

}
