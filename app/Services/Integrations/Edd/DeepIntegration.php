<?php

namespace FluentCampaign\App\Services\Integrations\Edd;

use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCampaign\App\Services\Commerce\ContactRelationModel;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\Framework\Support\Arr;

class DeepIntegration
{
    public function init()
    {
        add_action('edd_update_payment_status', array($this, 'handlePaymentStatusChanged'), 9, 3);
        add_action('edd_complete_purchase', array($this, 'handlePurchaseComplete'), 9, 2);

        add_action('edd_recurring_add_subscription_payment', array($this, 'handleSubscriptionPaymentAdd'), 100, 1);
        add_filter('fluent_crm/edd_purchase_sidebar_html', array($this, 'pushCommerceWidget'), 10, 3);
        add_filter('fluent_crm/contact_purchase_stat_edd', array($this, 'getPurchaseStat'), 10, 2);

        add_filter('fluentcrm_advanced_filter_options', array($this, 'addAdvancedFilterOptions'), 10, 1);
        add_filter('fluentcrm_deep_integration_providers', array($this, 'addDeepIntegrationProvider'), 10, 1);
        add_filter('fluentcrm_deep_integration_sync_edd', array($this, 'syncEddCustomers'), 10, 2);
        add_filter('fluentcrm_deep_integration_save_edd', array($this, 'saveSettings'), 10, 2);
        add_filter('fluentcrm_contacts_filter_edd', array($this, 'addAdvancedFilter'), 10, 2);
        add_filter('fluentcrm_ajax_options_edd_coupons', array($this, 'getEddCoupons'), 10, 2);

        add_filter('fluent_crm/advanced_report_providers', function ($providers) {

            if (apply_filters('fluent_crm/user_can_view_edd_report', current_user_can('view_shop_sensitive_data'))) {
                $providers['edd'] = [
                    'title' => 'EDD'
                ];
            }

            return $providers;
        }, 10, 1);
        add_filter('fluentcrm_advanced_filter_suggestions', function ($suggestions) {
            if (!Commerce::isEnabled('edd')) {
                $suggestions[] = [
                    'title'    => __('Sync EDD Customers to FluentCRM to segment them by their purchases, lifetime values and other purchase data.', 'fluentcampaign-pro'),
                    'btn_text' => __('View Settings', 'fluentcampaign-pro'),
                    'provider' => 'edd',
                    'btn_url'  => admin_url('admin.php?page=fluentcrm-admin#/settings/integration_settings?selected_integration=edd')
                ];
            }

            return $suggestions;
        });

        (new AutomationConditions())->init();
    }

    /**
     * @param \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder $query
     * @param array $filters
     * @return \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder
     */
    public function addAdvancedFilter($query, $filters)
    {
        return Subscriber::providerQueryBuilder($query, $filters, 'edd');
    }

    public function handlePaymentStatusChanged($paymentId, $newStatus, $oldStatus)
    {
        if (!Commerce::isEnabled('edd')) {
            return false;
        }

        $resyncTypes = ['processing', 'publish', 'complete', 'completed', 'refunded'];

        if ($newStatus == $oldStatus || !in_array($newStatus, $resyncTypes) || !Commerce::isEnabled('edd')) {
            return false;
        }

        $payment = edd_get_payment($paymentId);

        $customerId = $payment->customer_id;

        if ($customerId) {
            $syncSettings = $this->getSyncSettings();
            EddCommerceHelper::syncCommerceCustomer($customerId, $syncSettings['contact_status'], [], $syncSettings['tags'], $syncSettings['lists']);
            Commerce::cacheStoreAverage('edd');
            return true;
        }

        return false;
    }


    public function handlePurchaseComplete($paymentId, $payment)
    {
        if (!Commerce::isEnabled('edd')) {
            return false;
        }

        $customerId = $payment->customer_id;

        if ($customerId) {
            $syncSettings = $this->getSyncSettings();
            EddCommerceHelper::syncCommerceCustomer($customerId, $syncSettings['contact_status'], [], $syncSettings['tags'], $syncSettings['lists']);
            Commerce::cacheStoreAverage('edd');
            return true;
        }

        return false;
    }

    public function handleSubscriptionPaymentAdd($payment)
    {
        if (!Commerce::isEnabled('edd')) {
            return false;
        }

        if ($payment->status != 'edd_subscription') {
            return false;
        }

        $customerId = $payment->customer_id;

        if ($customerId) {
            $syncSettings = $this->getSyncSettings();
            EddCommerceHelper::syncCommerceCustomer($customerId, $syncSettings['contact_status'], [], $syncSettings['tags'], $syncSettings['lists']);
            Commerce::cacheStoreAverage('edd');
            return true;
        }

        return false;
    }

    public function pushCommerceWidget($html, $subscriber, $page = 1)
    {
        if ($page != 1 || !Commerce::isEnabled('edd')) {
            return $html;
        }

        $commerce = ContactRelationModel::provider('edd')
            ->with(['items' => function ($query) {
                $query->orderBy('created_at', 'DESC');
            }])
            ->where('subscriber_id', $subscriber->id)
            ->first();

        if (!$commerce) {
            return $html;
        }

        $sign = edd_currency_symbol();

        $blocks = $this->getCommerceStats($commerce);

        $html = '';

        if(Commerce::getCommerceProvider() != 'edd') {
            $html = '<div class="fc_payment_summary"><h3 class="history_title">' . __('Customer Summary', 'fluentcampaign-pro') . '</h3>';
            $html .= '<div class="fc_history_widget"><ul class="fc_full_listed">';
            foreach ($blocks as $block) {
                $html .= '<li><span class="fc_list_sub">' . $block['title'] . '</span><span class="fc_list_value">' . $block['value'] . '</span></li>';
            }
        }

        $html .= '</ul></div>';

        $pushedItems = [];

        $html .= '<h3 class="history_title">' . esc_html__('Purchased Products', 'fluentcampaign-pro') . '</h3>';
        $html .= '<div class="fc_history_widget"><ul class="fc_full_listed max_height_550">';
        foreach ($commerce->items as $item) {

            $uId = $item->item_id.'_'.$item->item_sub_id;

            if(in_array($uId, $pushedItems)) {
                continue;
            }

            $productName = Helper::getProductName($item->item_id, $item->item_sub_id, ' <span class="variation_name">', '</span>');
            if (!$productName) {
                continue;
            }

            $pushedItems[] = $uId;

            $orderUrl = admin_url('edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=' . $item->origin_id);

            $badges = '<span class="fc_purchase_badge fc_badge_price">' . $sign . number_format_i18n($item->item_value, 2) . '</span>';
            $badges .= '<span class="fc_purchase_badge fc_badge_date"><a target="_blank" rel="noopener" href="' . $orderUrl . '">' . date(get_option('date_format'), strtotime($item->created_at)) . '</a></span>';

            $html .= '<li class="fc_product_name">' . $productName . ' ' . $badges . '</li>';
        }
        $html .= '</ul></div>';

        return $html . '</div>';
    }

    public function getPurchaseStat($stats, $subscriberId)
    {
        if (!Commerce::isEnabled('edd')) {
            return $stats;
        }

        $commerce = ContactRelationModel::provider('edd')
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
        $sign = edd_currency_symbol();
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

        $storeAverage = Commerce::getStoreAverage('edd');

        $aocIndicator = '';
        if ($storeAverage && !empty($storeAverage['aoc'])) {
            $aocIndicator = Commerce::getPercentChangeHtml($commerce->total_order_count, $storeAverage['aoc']);
        }

        $blocks[] = [
            'title'        => __('Order Count', 'fluentcampaign-pro'),
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
            $blocks[] = [
                'title'        => __('AOV', 'fluentcampaign-pro'),
                'value'        => $sign . number_format_i18n($aov, 2),
                'actual_value' => $aov,
                'key'          => 'aov'
            ];
        }

        return $blocks;
    }

    public function addAdvancedFilterOptions($groups)
    {
        $disabled = !Commerce::isEnabled('edd');

        $groups['edd'] = [
            'label'    => __('EDD', 'fluentcampaign-pro'),
            'value'    => 'edd',
            'children' => [
                [
                    'value'    => 'total_order_count',
                    'label'    => __('Total Order Count', 'fluentcampaign-pro'),
                    'type'     => 'numeric',
                    'disabled' => $disabled,
                    'min'      => 1,
                ],
                [
                    'value'    => 'total_order_value',
                    'label'    => __('Total Order Value', 'fluentcampaign-pro'),
                    'type'     => 'numeric',
                    'disabled' => $disabled,
                    'min'      => 1,
                ],
                [
                    'value'    => 'last_order_date',
                    'label'    => __('Last Order Date', 'fluentcampaign-pro'),
                    'type'     => 'dates',
                    'disabled' => $disabled
                ],
                [
                    'value'    => 'first_order_date',
                    'label'    => __('First Order Date', 'fluentcampaign-pro'),
                    'type'     => 'dates',
                    'disabled' => $disabled
                ],
                [
                    'value'       => 'purchased_items',
                    'label'       => __('Purchased Products', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'product_selector',
                    'is_multiple' => true,
                    'cacheable'   => true,
                    'disabled'    => $disabled
                ],
                [
                    'value'       => 'purchased_categories',
                    'label'       => __('Purchased Categories', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'tax_selector',
                    'taxonomy'    => 'download_category',
                    'is_multiple' => true,
                    'disabled'    => $disabled
                ],
                [
                    'value'       => 'purchased_tags',
                    'label'       => __('Purchased Tags', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'tax_selector',
                    'taxonomy'    => 'download_tag',
                    'is_multiple' => true,
                    'disabled'    => $disabled
                ],
                [
                    'value'       => 'commerce_coupons',
                    'label'       => __('Used Coupons', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'ajax_selector',
                    'option_key'  => 'edd_coupons',
                    'is_multiple' => true,
                    'disabled'    => $disabled
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
                    'disabled'    => $disabled
                ]
            ],
        ];

        return $groups;
    }

    public function addDeepIntegrationProvider($providers)
    {
        $providers['edd'] = [
            'title'       => __('Easy Digital Downloads', 'fluentcampaign-pro'),
            'sub_title'   => __('With EDD deep integration with FluentCRM, you easily segment your purchases, lifetime values, purchase dates and target your customers more efficiently.', 'fluentcampaign-pro'),
            'sync_title'  => __('Easy Digital Downloads customers are not synced with FluentCRM yet.', 'fluentcampaign-pro'),
            'sync_desc'   => __('To sync and enable deep integration with Easy Digital Downloads customers with FluentCRM, please configure and enable sync.', 'fluentcampaign-pro'),
            'sync_button' => __('Sync EDD Customers', 'fluentcampaign-pro'),
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

        $settings = fluentcrm_get_option('_edd_sync_settings', []);

        $settings = wp_parse_args($settings, $defaults);

        $settings['tags'] = array_map('intval', $settings['tags']);
        $settings['lists'] = array_map('intval', $settings['lists']);

        $settings['is_enabled'] = Commerce::isEnabled('edd');

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

        if(Arr::get($config, 'action') == 'disable') {
            Commerce::disableModule('edd');
            $settings['disabled_at'] = current_time('mysql');
        }

        fluentcrm_update_option('_edd_sync_settings', $settings);

        return [
            'message' => __('Settings have been saved', 'fluentcampaign-pro')
        ];
    }

    public function enable()
    {
        $settings = $this->getSyncSettings();
        if (!$settings['is_enabled']) {
            $settings['is_enabled'] = true;
            fluentcrm_update_option('_edd_sync_settings', $settings);
        }
    }

    public function syncEddCustomers($returnData, $config)
    {
        $tags = Arr::get($config, 'tags', []);
        $lists = Arr::get($config, 'lists', []);
        $contactStatus = Arr::get($config, 'contact_status', 'subscribed');

        $settings = [
            'tags'           => $tags,
            'lists'          => $lists,
            'contact_status' => $contactStatus
        ];

        fluentcrm_update_option('_edd_sync_settings', $settings);

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
                Commerce::resetModuleData('edd');
            }
            
            fluentcrm_update_option('_edd_customer_sync_count', 0);
            $runTime = 5;
        }

        $paymentStatuses = ['edd_subscription', 'processing', 'publish', 'complete', 'completed'];
        $run = true;

        $lastCustomerId = false;

        while ($run) {
            $offset = fluentcrm_get_option('_edd_customer_sync_count', 0);
            $customers = fluentCrmDb()->table('edd_customers')
                ->limit(10)
                ->offset($offset)
                ->orderBy('id', 'ASC')
                ->get();

            if ($customers) {
                foreach ($customers as $customer) {
                    EddCommerceHelper::syncCommerceCustomer($customer, $contactStatus, $paymentStatuses, $inputs['tags'], $inputs['lists'], $sendDoubleOptin);
                    fluentcrm_update_option('_edd_customer_sync_count', $offset + 1);
                    $offset += 1;

                    $lastCustomerId = $customer->id;
                    if (time() - $startTime > $runTime) {
                        Commerce::cacheStoreAverage('edd');
                        return $this->getCustomerSyncStatus($lastCustomerId);
                    }
                }
            } else {
                $run = false;
            }
        }

        Commerce::cacheStoreAverage('edd');
        return $this->getCustomerSyncStatus($lastCustomerId);
    }

    private function getCustomerSyncStatus($lastCustomerId = false)
    {
        $total = fluentCrmDb()->table('edd_customers')->count();
        $completedCount = fluentcrm_get_option('_edd_customer_sync_count', 0);

        $hasMore = $total > $completedCount;

        if (!$hasMore) {
            Commerce::enableModule('edd');
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

    public function getEddCoupons($coupons, $search)
    {

        if(class_exists('\EDD\Models\Download')) {
            // This is for edd 3
            $discountsQuery = fluentCrmDb()->table('edd_adjustments')
                ->select(['code'])
                ->limit(40);
            if($search) {
                $discountsQuery = $discountsQuery->where('code', 'LIKE', '%%'.$search.'%%');
            }

            $discounts = $discountsQuery->get();

            $formattedCoupons = [];

            foreach ($discounts as $discount) {
                $formattedCoupons[] = [
                    'id'    => $discount->code,
                    'title' => $discount->code
                ];
            }

            return $formattedCoupons;
        }

        $args = [
            'post_type'      => 'edd_discount',
            'posts_per_page' => 40,
            'post_status'    => 'any'
        ];

        if ($search) {
            $args['meta_query'] = [
                [
                    'key'   => '_edd_discount_code',
                    'value' => $search
                ]
            ];
        }


        $allCoupons = get_posts($args);

        $formattedCoupons = [];
        foreach ($allCoupons as $coupon) {
            $code = get_post_meta($coupon->ID, '_edd_discount_code', true);

            if($code) {
                $formattedCoupons[] = [
                    'id'    => $code,
                    'title' => $code
                ];
            }
        }

        return $formattedCoupons;
    }

    public function syncCustomerBySubscriber($subscriber)
    {
        if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
            define('FLUENTCRM_DISABLE_TAG_LIST_EVENTS', true);
        }

        $userId = $subscriber->getWpUserId();
        $customer = fluentCrmDb()->table('edd_customers');
        if ($userId) {
            $customer = $customer->where('user_id', $userId);
        } else {
            $customer = $customer->where('email', $subscriber->email);
        }

        $customer = $customer->first();

        if (!$customer) {
            return false;
        }

        $syncSettings = $this->getSyncSettings();
        return EddCommerceHelper::syncCommerceCustomer($customer, 'subscribed', [], $syncSettings['tags'], $syncSettings['lists'], false);
    }
}
