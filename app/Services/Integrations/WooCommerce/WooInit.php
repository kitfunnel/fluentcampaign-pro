<?php

namespace FluentCampaign\App\Services\Integrations\WooCommerce;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\AutoSubscribe;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\PermissionManager;
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

        add_filter('fluent_crm/sales_stats', array($this, 'pushStatus'));

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

        if (defined('WCS_INIT_TIMESTAMP')) {
            new WooSubscriptionStartedTrigger();
            new WooSubscriptionRenewalPaymentTrigger();
            new WooSubscriptionRenewalFailedTrigger();
            new WooSubscriptionExpiredTrigger();
            add_filter('fluent_crm/subscriber_top_widgets', array($this, 'pushSubscriptionWidgets'), 10, 2);
        }
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
        if (!in_array($postType, ['woocommerce_page_wc-orders', 'shop_order'])) {
            return;
        }

        $hasPermission = apply_filters('fluent_crm/can_view_contact_card_in_plugin', PermissionManager::currentUserCan('fcrm_read_contacts'), 'woo');

        if (!$hasPermission) {
            return;
        }

	    if($postType == 'woocommerce_page_wc-orders') {
		    $orderId = $post->get_id(); // for WooCommerce High Performance Order storage
	    } else {
			$orderId = $post->ID; // for WordPress post tables $postType = 'shop_order';
	    }
        $order = wc_get_order($orderId);

        $userId = $order->get_user_id();
        if (!$userId) {
            $userId = $order->get_billing_email();
        }

        $profileHtml = fluentcrm_get_crm_profile_html($userId, false);

        if (!$profileHtml) {
            return;
        }

        add_meta_box('fluentcrm_woo_order_widget', __('FluentCRM Profile', 'fluentcampaign-pro'), function () use ($profileHtml) {
            echo $profileHtml;
        }, $postType, 'side', 'low');
    }

    public function addSubscribeBox()
    {
        $settings = fluentcrm_get_option('woo_checkout_form_subscribe_settings', []);

        if (!$settings || Arr::get($settings, 'status') != 'yes') {
            return false;
        }

        if (Arr::get($settings, 'show_only_new') == 'yes') {
            $contact = fluentcrm_get_current_contact();
            if ($contact && $contact->status == 'subscribed') {
                return false;
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

    public function pushSubscriptionWidgets($widgets, $subscriber)
    {
        if (!$subscriber->user_id || apply_filters('fluent_crm/disable_woo_subscriptions_widget', false, $subscriber)) {
            return $widgets;
        }

        if (!function_exists('wcs_get_users_subscriptions')) {
            return $widgets;
        }

        $subscriptions = wcs_get_users_subscriptions($subscriber->user_id);

        if (!$subscriptions) {
            return $widgets;
        }

        $html = '<div class="max_height_340"><ul class="fc_full_listed">';

        foreach ($subscriptions as $subscription) {

            $statusName = wcs_get_subscription_status_name($subscription->get_status());

            $items = $subscription->get_items();
            $names = [];
            foreach ($items as $item) {
                $names[] = $item->get_name();
            }

            $name = implode(' & ', $names);

            $pricing = $subscription->get_formatted_order_total();

            $html .= '<li><span style="font-weight: bold;">' . $name . ' <span class="subscription-status status-' . $subscription->get_status() . '">' . $statusName . '</span></span>';

            $startDate = sprintf('<time class="%s" title="%s">%s</time>', esc_attr('start_date'), esc_attr(date(__('Y/m/d g:i:s A', 'woocommerce-subscriptions'), $subscription->get_time('start_date', 'site'))), esc_html($subscription->get_date_to_display('start_date')));

            $html .= '<p style="margin: 5px 0 0;font-size: 12px;color: #5e5d5d;">' . $pricing . '<span class="fc_middot">·</span>Started at: ' . $startDate;

            if ($nextDate = $subscription->get_time('next_payment_date', 'site')) {
                $html .= '<span class="fc_middot">·</span>Next Payment: ' . sprintf('<time class="%s" title="%s">%s</time>', esc_attr('next_payment_date'), esc_attr(date(__('Y/m/d g:i:s A', 'woocommerce-subscriptions'), $nextDate)), esc_html($subscription->get_date_to_display('next_payment_date')));
            }


            $html .= '</p></li>';
        }
        $html .= '</ul></div>';

        $html .= '<style>.subscription-status {font-weight: normal; display: inline-flex;color: #777;background: #e5e5e5;border-radius: 4px;border-bottom: 1px solid rgba(0,0,0,.05);white-space: nowrap;max-width: 100%;padding: 0px 7px; }.subscription-status.status-active { background: #c6e1c6;color: #5b841b;}.status-cancelled{ background: #9d0303;color: white; }.subscription-status.status-expired {background: #bd94af;color: #724663;}.subscription-status.status-pending-cancel {background: #bfbfbf;color: #737373;}</style>';

        $widgets[] = [
            'title'   => sprintf(__('Woo Subscriptions (%d)', 'fluentcampaign-pro'), count($subscriptions)),
            'content' => $html
        ];

        return $widgets;

    }
}
