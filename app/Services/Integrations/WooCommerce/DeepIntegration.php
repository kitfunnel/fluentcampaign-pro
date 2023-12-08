<?php

namespace FluentCampaign\App\Services\Integrations\WooCommerce;

use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCampaign\App\Services\Commerce\ContactRelationModel;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\Framework\Support\Arr;

class DeepIntegration
{
    protected $integrationkey = 'woo';

    public function init()
    {
        add_filter('fluent_crm/woo_purchase_sidebar_html', array($this, 'pushCommerceWidget'), 10, 3);
        add_filter('fluent_crm/contact_purchase_stat_woo', array($this, 'getPurchaseStat'), 10, 2);
        add_action('woocommerce_order_status_changed', array($this, 'handlePaymentStatusChanged'), 100, 4);

        add_filter('fluentcrm_advanced_filter_options', array($this, 'addAdvancedFilterOptions'), 10, 1);
        add_filter('fluentcrm_deep_integration_providers', array($this, 'addDeepIntegrationProvider'), 10, 1);
        add_filter('fluentcrm_deep_integration_sync_woo', array($this, 'syncWooCustomers'), 10, 2);
        add_filter('fluentcrm_deep_integration_save_woo', array($this, 'saveSettings'), 10, 2);

        add_action('fluentcrm_contacts_filter_woo', array($this, 'addAdvancedFilter'), 10, 2);
        add_filter('fluentcrm_ajax_options_woo_coupons', array($this, 'getWooCoupons'), 10, 2);

        add_filter('fluent_crm/advanced_report_providers', function ($providers) {

            if (apply_filters('fluent_crm/user_can_view_woo_report', current_user_can('view_woocommerce_reports'))) {
                $providers['woo'] = [
                    'title' => __('WooCommerce', 'fluentcampaign-pro')
                ];
            }

            return $providers;
        }, 10, 1);

        add_filter('fluentcrm_advanced_filter_suggestions', function ($suggestions) {
            if (!Commerce::isEnabled('woo')) {
                $suggestions[] = [
                    'title'    => __('Sync WooCommerce Customers to FluentCRM to segment them by their purchases, lifetime values and other purchase data.', 'fluentcampaign-pro'),
                    'btn_text' => __('View Settings', 'fluentcampaign-pro'),
                    'provider' => 'woo',
                    'btn_url'  => admin_url('admin.php?page=fluentcrm-admin#/settings/integration_settings?selected_integration=woo')
                ];
            }

            return $suggestions;
        });


        // We need sync for woo status: 'processing', 'completed', 'refunded'

        $syncTypes = ['processing', 'completed', 'refunded'];

        foreach ($syncTypes as $syncType) {
            add_action('woocommerce_order_status_' . $syncType, function ($orderId, $order) use ($syncType) {
                if (!Commerce::isEnabled('woo')) {
                    return false;
                }

                $syncRequired = $syncType == 'refunded';
                $this->syncWooOrder($order, $syncRequired);
            }, 10, 2);
        }

    }

    /**
     * @param \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder $query
     * @param array $filters
     * @return \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder
     */
    public function addAdvancedFilter($query, $filters)
    {
        return Subscriber::providerQueryBuilder($query, $filters, 'woo');
    }

    public function pushCommerceWidget($html, $subscriber, $page)
    {
        if ($page != 1 || !Commerce::isEnabled('woo')) {
            return $html;
        }

        $commerce = ContactRelationModel::provider('woo')
            ->with(['items' => function ($query) {
                $query->orderBy('created_at', 'DESC');
            }])
            ->where('subscriber_id', $subscriber->id)
            ->first();

        if (!$commerce) {
            return ' ';
        }

        $sign = get_woocommerce_currency_symbol();

        $html = '';

        if(Commerce::getCommerceProvider() != 'woo') {
            $blocks = $this->getCommerceStats($commerce);

            $html = '<div class="fc_payment_summary"><h3 class="history_title">' . __('Customer Summary', 'fluentcampaign-pro') . '</h3>';
            $html .= '<div class="fc_history_widget"><ul class="fc_full_listed">';
            foreach ($blocks as $block) {
                $html .= '<li><span class="fc_list_sub">' . $block['title'] . '</span><span class="fc_list_value">' . $block['value'] . '</span></li>';
            }

            $html .= '</ul></div>';
        }

        $html .= '<h3 class="history_title">' . __('Purchased Products', 'fluentcampaign-pro') . '</h3>';
        $html .= '<div class="fc_history_widget"><ul class="fc_full_listed max_height_340">';
        foreach ($commerce->items as $item) {
            $productName = Helper::getProductName($item->item_id, $item->item_sub_id, ' <span class="variation_name">', '</span>');

            if (!$productName) {
                continue;
            }

            $orderUrl = admin_url('post.php?action=edit&post=' . $item->origin_id);

            $badges = '<span class="fc_purchase_badge fc_badge_price">' . $sign . number_format_i18n($item->item_value, 2) . '</span>';
            $badges .= '<span class="fc_purchase_badge fc_badge_date"><a target="_blank" rel="noopener" href="' . $orderUrl . '">' . date(get_option('date_format'), strtotime($item->created_at)) . '</a></span>';

            $html .= '<li class="fc_product_name">' . $productName . ' ' . $badges . '</li>';
        }
        $html .= '</ul></div>';

        return $html . '</div>';
    }

    /**
     * @param $paymentId int
     * @param $oldStatus string
     * @param $newStatus string
     * @param $order \WC_Order
     * @return bool
     */
    public function handlePaymentStatusChanged($paymentId, $oldStatus, $newStatus, $order)
    {
        if ($oldStatus == $newStatus) {
            return false;
        }

        if (!Commerce::isEnabled('woo')) {
            return false;
        }

        $paidStatuses = wc_get_is_paid_statuses();
        if (in_array($oldStatus, $paidStatuses) && in_array($newStatus, $paidStatuses)) {
            return false;
        }

        $requireSync = false;
        if (!in_array($newStatus, $paidStatuses)) {
            // It maybe a refund so we have sync all the orders. Sorry!
            $requireSync = true;
        }

        return $this->syncWooOrder($order, $requireSync);
    }

    private function syncWooOrder($order, $requireSync = false)
    {
        $customer = Helper::getDbCustomerFromOrder($order);
        if (!$customer) {
            return false;
        }

        if (Commerce::isOrderSyncedCache('woo_' . $order->get_id())) {
            return false;
        }

        $syncSettings = $this->getSyncSettings();

        if ($requireSync) {
            WooSyncHelper::syncCommerceCustomer($customer, $syncSettings['contact_status'], [], $syncSettings['tags'], $syncSettings['lists']);
            Commerce::cacheStoreAverage('woo');
            return true;
        }

        // we will just process the order here
        WooSyncHelper::syncCustomerOrder($customer, $order, $syncSettings['contact_status'], $syncSettings['tags'], $syncSettings['lists']);
        Commerce::cacheStoreAverage('woo');

        return true;
    }

    public function addAdvancedFilterOptions($groups)
    {
        $disabled = !Commerce::isEnabled('woo');

        $groups['woo'] = [
            'label'    => __('WooCommerce', 'fluentcampaign-pro'),
            'value'    => 'woo',
            'children' => [
                [
                    'value'    => 'total_order_count',
                    'label'    => __('Total Order Count', 'fluentcampaign-pro'),
                    'type'     => 'numeric',
                    'disabled' => $disabled,
                    'min'      => 1,
                    'help'     => 'will filter the contacts who have at least one order'
                ],
                [
                    'value'    => 'total_order_value',
                    'label'    => __('Total Order Value', 'fluentcampaign-pro'),
                    'type'     => 'numeric',
                    'min'      => 1,
                    'disabled' => $disabled,
                    'help'     => 'will filter the contacts who have at least one order'
                ],
                [
                    'value'    => 'last_order_date',
                    'label'    => __('Last Order Date', 'fluentcampaign-pro'),
                    'type'     => 'dates',
                    'disabled' => $disabled,
                    'help'     => 'will filter the contacts who have at least one order'
                ],
                [
                    'value'    => 'first_order_date',
                    'label'    => __('First Order Date', 'fluentcampaign-pro'),
                    'type'     => 'dates',
                    'disabled' => $disabled,
                    'help'     => 'will filter the contacts who have at least one order'
                ],
                [
                    'value'       => 'purchased_items',
                    'label'       => __('Purchased Products', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'product_selector',
                    'is_multiple' => true,
                    'disabled'    => $disabled,
                    'help'        => 'will filter the contacts who have at least one order'
                ],
                [
                    'value'       => 'purchased_categories',
                    'label'       => __('Purchased Categories', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'tax_selector',
                    'taxonomy'    => 'product_cat',
                    'is_multiple' => true,
                    'disabled'    => $disabled,
                    'help'        => 'will filter the contacts who have at least one order'
                ],
                [
                    'value'       => 'purchased_tags',
                    'label'       => __('Purchased Tags', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'tax_selector',
                    'taxonomy'    => 'product_tag',
                    'is_multiple' => true,
                    'disabled'    => $disabled,
                    'help'        => 'will filter the contacts who have at least one order'
                ],
                [
                    'value'       => 'commerce_coupons',
                    'label'       => __('Used Coupons', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'ajax_selector',
                    'option_key'  => 'woo_coupons',
                    'is_multiple' => true,
                    'disabled'    => $disabled,
                    'help'        => 'will filter the contacts who have at least one order'
                ],
                [
                    'value'             => 'commerce_exist',
                    'label'             => 'Is a customer?',
                    'type'              => 'selections',
                    'is_multiple'       => false,
                    'disable_values'    => true,
                    'value_description' => 'This filter will check if a contact has at least one shop order or not',
                    'custom_operators'  => [
                        'exist'     => 'Yes',
                        'not_exist' => 'No',
                    ],
                    'disabled'          => $disabled
                ]
            ],
        ];

        return $groups;
    }

    public function addDeepIntegrationProvider($providers)
    {
        $providers['woo'] = [
            'title'       => __('WooCommerce', 'fluentcampaign-pro'),
            'sub_title'   => __('With WooCommerce deep integration with FluentCRM, you easily segment your purchases, lifetime values, purchase dates and target your customers more efficiently.', 'fluentcampaign-pro'),
            'sync_title'  => __('WooCommerce customers are not synced with FluentCRM yet.', 'fluentcampaign-pro'),
            'sync_desc'   => __('To sync and enable deep integration with WooCommerce customers with FluentCRM, please configure and enable sync.', 'fluentcampaign-pro'),
            'sync_button' => __('Sync WooCommerce Customers', 'fluentcampaign-pro'),
            'settings'    => $this->getSyncSettings()
        ];

        return $providers;
    }

    public function getSyncSettings()
    {
        $defaults = [
            'tags'           => [],
            'lists'          => [],
            'contact_status' => 'subscribed'
        ];

        $settings = fluentcrm_get_option('_woo_sync_settings', []);

        $settings = wp_parse_args($settings, $defaults);

        $settings['is_enabled'] = Commerce::isEnabled('woo');

        $settings['tags'] = array_map('intval', $settings['tags']);
        $settings['lists'] = array_map('intval', $settings['lists']);

        return $settings;
    }

    public function saveSettings($returnData, $config)
    {
        $tags = Arr::get($config, 'tags', []);
        $lists = Arr::get($config, 'lists', []);
        $contactStatus = Arr::get($config, 'contact_status', 'subscribed');

        $settings = [
            'tags'           => $tags,
            'lists'          => $lists,
            'contact_status' => $contactStatus
        ];

        if (Arr::get($config, 'action') == 'disable') {
            Commerce::disableModule($this->integrationkey);
            $settings['disabled_at'] = current_time('mysql');
        }

        fluentcrm_update_option('_woo_sync_settings', $settings);

        return [
            'message'  => __('Settings have been saved', 'fluentcampaign-pro'),
            'settings' => $this->getSyncSettings()
        ];
    }

    public function enable()
    {
        $settings = $this->getSyncSettings();
        if (!$settings['is_enabled']) {
            $settings['is_enabled'] = true;
            fluentcrm_update_option('_woo_sync_settings', $settings);
        }
    }

    public function syncWooCustomers($returnData, $config)
    {
        $tags = Arr::get($config, 'tags', []);
        $lists = Arr::get($config, 'lists', []);
        $contactStatus = Arr::get($config, 'contact_status', 'subscribed');

        $settings = [
            'tags'           => $tags,
            'lists'          => $lists,
            'contact_status' => $contactStatus
        ];

        fluentcrm_update_option('_woo_sync_settings', $settings);

        $status = $this->syncCustomers([
            'tags'               => $tags,
            'lists'              => $lists,
            'new_status'         => $contactStatus,
            'double_optin_email' => ($contactStatus == 'pending') ? 'yes' : 'no',
            'import_silently'    => 'yes'
        ], $config['syncing_page']);

        return [
            'syncing_status' => $status
        ];
    }

    public function syncCustomers($config, $page)
    {
        $inputs = Arr::only($config, [
            'lists', 'tags', 'new_status', 'double_optin_email', 'import_silently'
        ]);

        $inputs = wp_parse_args($inputs, [
            'lists'              => [],
            'tags'               => [],
            'new_status'         => 'subscribed',
            'double_optin_email' => 'no',
            'import_silently'    => 'yes'
        ]);

        if (Arr::get($inputs, 'import_silently') == 'yes') {
            if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
                define('FLUENTCRM_DISABLE_TAG_LIST_EVENTS', true);
            }
        }

        $sendDoubleOptin = Arr::get($inputs, 'double_optin_email') == 'yes';
        $contactStatus = Arr::get($inputs, 'new_status', 'subscribed');

        $startTime = time();

        $runTime = 30;
        if ($page == 1) {
            if (!Commerce::isMigrated(true)) {
                Commerce::migrate();
            } else {
                Commerce::resetModuleData($this->integrationkey);
            }

            fluentcrm_update_option('_woo_customer_sync_count', 0);
            $runTime = 5;
        }

        $paymentStatuses = wc_get_is_paid_statuses();
        $run = true;

        $lastCustomerId = false;

        while ($run) {
            $offset = fluentcrm_get_option('_woo_customer_sync_count', 0);
            $customers = fluentCrmDb()->table('wc_customer_lookup')
                ->limit(10)
                ->offset($offset)
                ->orderBy('customer_id', 'ASC')
                ->get();

            if ($customers) {
                foreach ($customers as $customer) {
                    WooSyncHelper::syncCommerceCustomer($customer, $contactStatus, $paymentStatuses, $inputs['tags'], $inputs['lists'], $sendDoubleOptin);
                    fluentcrm_update_option('_woo_customer_sync_count', $offset + 1);
                    $offset += 1;
                    $lastCustomerId = $customer->customer_id;
                    if ((time() - $startTime) > $runTime) {
                        Commerce::cacheStoreAverage('woo');
                        return $this->getCustomerSyncStatus($lastCustomerId);
                    }
                }
            } else {
                $run = false;
            }
        }

        Commerce::cacheStoreAverage('woo');
        return $this->getCustomerSyncStatus($lastCustomerId);
    }

    public function getCustomerSyncStatus($lastCustomerId = false)
    {
        $total = fluentCrmDb()->table('wc_customer_lookup')->count();
        $completedCount = fluentcrm_get_option('_woo_customer_sync_count', 0);

        $hasMore = $total > $completedCount;

        if (!$hasMore) {
            Commerce::enableModule('woo');
        }

        return [
            'page_total'   => $total,
            'record_total' => $total,
            'has_more'     => $hasMore,
            'current_page' => $completedCount,
            'next_page'    => $completedCount + 1,
            'reload_page'  => !$hasMore,
            'last_sync_id' => $lastCustomerId
        ];
    }

    public function getWooCoupons($coupons, $search)
    {
        $args = [
            'post_type'      => 'shop_coupon',
            'posts_per_page' => 20,
            'post_status'    => 'any',
        ];

        if ($search) {
            $args['s'] = $search;
        }

        $coupons = get_posts($args);

        $formattedCoupons = [];
        foreach ($coupons as $coupon) {
            $formattedCoupons[] = [
                'id'   => $coupon->post_title,
                'name' => $coupon->post_title
            ];
        }

        return $formattedCoupons;
    }

    public function getPurchaseStat($stats, $subscriberId)
    {
        if (!Commerce::isEnabled('woo')) {
            return $stats;
        }

        $commerce = ContactRelationModel::provider('woo')
            ->where('subscriber_id', $subscriberId)
            ->first();

        if (!$commerce) {
            return $stats;
        }

        return $this->getCommerceStats($commerce);
    }

    public function getCommerceStats($commerce)
    {
        $blocks = [];

        if ($commerce->first_order_date && $commerce->first_order_date != '0000-00-00 00:00:00') {
            $blocks[] = [
                'title'        => __('Customer Since', 'fluentcampaign-pro'),
                'value'        => date(get_option('date_format'), strtotime($commerce->first_order_date)),
                'key'          => 'customer_since',
                'actual_value' => $commerce->first_order_date
            ];
        }

        if ($commerce->last_order_date && $commerce->last_order_date != '0000-00-00 00:00:00') {
            $blocks[] = [
                'title'        => __('Last Order', 'fluentcampaign-pro'),
                'value'        => date(get_option('date_format'), strtotime($commerce->last_order_date)),
                'key'          => 'last_order_date',
                'actual_value' => $commerce->last_order_date,
            ];
        }

        $sign = get_woocommerce_currency_symbol();

        $storeAverage = Commerce::getStoreAverage('woo');

        $aocIndicator = '';
        if ($storeAverage && !empty($storeAverage['aoc'])) {
            $aocIndicator = Commerce::getPercentChangeHtml($commerce->total_order_count, $storeAverage['aoc']);
        }

        $blocks[] = [
            'title'        => __('Order Count (paid)', 'fluentcampaign-pro'),
            'value'        => number_format_i18n($commerce->total_order_count) . ' ' . $aocIndicator,
            'key'          => 'order_count',
            'actual_value' => $commerce->total_order_count,
        ];

        $aovIndicator = '';
        if ($storeAverage && !empty($storeAverage['aov'])) {
            $aovIndicator = Commerce::getPercentChangeHtml($commerce->total_order_value, $storeAverage['aov']);
        }

        $blocks[] = [
            'title'        => __('Lifetime Value', 'fluentcampaign-pro'),
            'value'        => $sign . number_format_i18n($commerce->total_order_value, 2) . ' ' . $aovIndicator,
            'key'          => 'lifetime_value',
            'actual_value' => $commerce->total_order_value,
        ];

        if ($commerce->total_order_count) {
            $aov = $commerce->total_order_value / $commerce->total_order_count;
            $aovIndicator = '';
            if ($storeAverage && !empty($storeAverage['aov'])) {
                $aovIndicator = Commerce::getPercentChangeHtml($aov, $storeAverage['aov']);
            }
            $blocks[] = [
                'title'        => __('AOV', 'fluentcampaign-pro'),
                'value'        => $sign . number_format_i18n($aov, 2) . ' ' . $aovIndicator,
                'actual_value' => $aov,
                'key'          => 'aov'
            ];
        }

        return $blocks;
    }


    public function syncCustomerBySubscriber($subscriber)
    {
        if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
            define('FLUENTCRM_DISABLE_TAG_LIST_EVENTS', true);
        }

        $customer = fluentCrmDb()->table('wc_customer_lookup')->where('email', $subscriber->email)->first();

        if (!$customer) {
            $userId = $subscriber->getWpUserId();
            if ($userId) {
                $customer = fluentCrmDb()->table('wc_customer_lookup')->where('user_id', $userId)->first();
            }
        }

        if (!$customer) {
            return false;
        }

        if ($customer->user_id && $subscriber->user_id != $customer->user_id) {
            $subscriber->user_id = $customer->user_id;
            $subscriber->save();
        }

        $syncSettings = $this->getSyncSettings();
        return WooSyncHelper::syncCommerceCustomer($customer, 'subscribed', [], $syncSettings['tags'], $syncSettings['lists'], false);
    }

}
