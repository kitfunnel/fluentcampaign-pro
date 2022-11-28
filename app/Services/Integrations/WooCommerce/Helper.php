<?php

namespace FluentCampaign\App\Services\Integrations\WooCommerce;

use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class Helper
{
    public static function getProducts()
    {
        $products = \wc_get_products([
            'limit'  => 2000,
            'status' => ['publish']
        ]);
        $formattedProducts = [];
        foreach ($products as $product) {
            $formattedProducts[] = [
                'id'    => strval($product->get_id()),
                'title' => $product->get_title()
            ];
        }

        return $formattedProducts;
    }

    public static function getCategories()
    {
        $categories = get_terms('product_cat', array(
            'orderby'    => 'name',
            'order'      => 'asc',
            'hide_empty' => true,
        ));

        $formattedOptions = [];
        foreach ($categories as $category) {
            $formattedOptions[] = [
                'id'    => strval($category->term_id),
                'title' => $category->name
            ];
        }

        return $formattedOptions;
    }

    public static function purchaseTypeOptions()
    {
        return [
            [
                'id'    => 'all',
                'title' => __('Any type of purchase', 'fluentcampaign-pro')
            ],
            [
                'id'    => 'first_purchase',
                'title' => __('Only for first purchase', 'fluentcampaign-pro')
            ],
            [
                'id'    => 'from_second',
                'title' => __('From 2nd Purchase', 'fluentcampaign-pro')
            ]
        ];
    }

    /**
     * @param $order \WC_Order
     * @return array
     * @throws \Exception
     */
    public static function prepareSubscriberData($order)
    {
        $customer_id = $order->get_customer_id();

        if ($customer_id !== 0) {
            $userId = $order->get_user_id();
            $billingAddress = [];
            try {
                $customer = new \WC_Customer($customer_id);
                $billingAddress = $customer->get_billing();
            } catch (\Exception $exception) {

            }

            if ($userId) {
                $subscriberData = FunnelHelper::prepareUserData($userId);
            } else {
                $subscriberData = [
                    'first_name' => $customer->get_first_name(),
                    'last_name'  => $customer->get_last_name(),
                    'email'      => $customer->get_email(),
                    'phone'      => $order->get_billing_phone('edit')
                ];
            }

        } else {
            // this was a guest checkout
            $subscriberData = [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'email'      => $order->get_billing_email()
            ];
            $billingAddress = $order->get_address('billing');
        }

        $subscriberData = array_merge($subscriberData, [
            'address_line_1' => Arr::get($billingAddress, 'address_1'),
            'address_line_2' => Arr::get($billingAddress, 'address_2'),
            'postal_code'    => Arr::get($billingAddress, 'postcode'),
            'city'           => Arr::get($billingAddress, 'city'),
            'state'          => Arr::get($billingAddress, 'state'),
            'country'        => Arr::get($billingAddress, 'country'),
            'source'         => 'woocommerce',
            'contact_type'   => 'customer',
            'phone'          => $order->get_billing_phone('edit')
        ]);

        if ($ipAddress = $order->get_customer_ip_address()) {
            $subscriberData['ip'] = $ipAddress;
        }

        return array_filter($subscriberData);
    }

    public static function isProductIdCategoryMatched($order, $conditions)
    {
        $items = $order->get_items();
        $purchaseProductIds = [];
        foreach ($items as $item) {
            $purchaseProductIds[] = $item->get_product_id();
        }

        // check the products ids
        if (!empty($conditions['product_ids'])) {
            if (array_intersect($purchaseProductIds, $conditions['product_ids'])) {
                return true;
            }

            if (empty($conditions['product_categories'])) {
                return false;
            }
        }

        if (!empty($conditions['product_categories'])) {
            $categoryMatch = fluentCrmDb()->table('term_relationships')
                ->whereIn('term_taxonomy_id', $conditions['product_categories'])
                ->whereIn('object_id', $purchaseProductIds)
                ->count();

            if (!$categoryMatch) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param $order \WC_Order
     * @param $purchaseType
     */
    public static function isPurchaseTypeMatch($order, $purchaseType)
    {
        if (!$purchaseType) {
            return true;
        }

        if ($purchaseType == 'from_second') {
            $orderCounts = self::getPaidOrderCountByReferenceOrder($order);
            return $orderCounts >= 2;
        } else if ($purchaseType == 'first_purchase') {
            $orderCounts = self::getPaidOrderCountByReferenceOrder($order);
            return $orderCounts <= 1;
        }

        return true;
    }

    public static function isProductPurchased($productIds, $subscriber)
    {
        $userId = $subscriber->user_id;
        if (!$userId) {
            $user = get_user_by('email', $subscriber->email);
            if ($user) {
                $userId = $user->ID;
            } else {
                return false;
            }
        }

        foreach ($productIds as $productId) {
            if (wc_customer_bought_product($subscriber->email, $userId, $productId)) {
                return true;
            }
        }

        return false;
    }

    public static function isWooTrigger($triggerName)
    {
        $supportedTriggers = [
            'woocommerce_order_status_changed',
            'woocommerce_order_status_completed',
            'woocommerce_order_refunded',
            'woocommerce_order_status_processing'
        ];

        return in_array($triggerName, $supportedTriggers);
    }

    public static function getShippingMethods($formatted = false)
    {
        $methods = [];
        foreach (WC()->shipping()->get_shipping_methods() as $method_id => $method) {
            if ($formatted) {
                $methods[] = [
                    'id'    => $method_id,
                    'title' => is_callable(array($method, 'get_method_title')) ? $method->get_method_title() : $method->get_title(),
                    'slug'  => $method_id
                ];
            } else {
                $methods[$method_id] = is_callable(array($method, 'get_method_title')) ? $method->get_method_title() : $method->get_title();
            }
        }
        return $methods;
    }

    public static function getPaymentGateways($formatted = false)
    {
        $gateways = [];
        foreach (WC()->payment_gateways()->payment_gateways() as $gateway) {
            if ($gateway->enabled === 'yes') {
                if ($formatted) {
                    $gateways[] = [
                        'id'    => $gateway->id,
                        'title' => $gateway->get_title(),
                        'slug'  => $gateway->id
                    ];
                } else {
                    $gateways[$gateway->id] = $gateway->get_title();
                }
            }
        }
        return $gateways;
    }

    public static function getOrderStatuses($formatted = false)
    {
        $allStatuses = [];
        $statuses = wc_get_order_statuses();
        if (!$formatted) {
            return $statuses;
        }

        foreach ($statuses as $statusKey => $status) {
            $allStatuses[] = [
                'id'    => $statusKey,
                'title' => $status,
                'slug'  => $statusKey
            ];
        }

        return $allStatuses;
    }


    /**
     * @param $order \WC_Order
     */
    public
    static function getPaidOrderCountByReferenceOrder($order)
    {
        $customerId = $order->get_customer_id();

        if (!$customerId) {
            $customerLookup = fluentCrmDb()->table('wc_customer_lookup')
                ->where('email', $order->get_billing_email('edit'))
                ->first();
            if ($customerLookup) {
                $customerId = $customerLookup->customer_id;
            } else {
                return 1;
            }
        }

        $orderStats = fluentCrmDb()->table('wc_order_stats')
            ->where('customer_id', $customerId)
            ->where('order_id', '!=', $order->get_id())
            ->select(['order_id'])
            ->whereIn('status', ['wc-processing', 'wc-completed'])
            ->get();

        $orderIds = [];

        foreach ($orderStats as $orderStat) {
            $orderIds[] = $orderStat->order_id;
        }

        return count(array_unique($orderIds)) + 1;
    }

    public
    static function getTagsCatsByProductIds($productIds)
    {
        $items = fluentCrmDb()->table('term_relationships')
            ->select(['term_taxonomy.term_id'])
            ->whereIn('term_relationships.object_id', $productIds)
            ->join('term_taxonomy', 'term_taxonomy.term_taxonomy_id', '=', 'term_relationships.term_taxonomy_id')
            ->whereIn('term_taxonomy.taxonomy', ['product_tag', 'product_cat'])
            ->get();

        $formattedItems = [];

        foreach ($items as $item) {
            $formattedItems[] = $item->term_id;
        }

        return array_values(array_unique($formattedItems));
    }

    public
    static function getProductName($productId, $variationId = false, $separator = ' - ', $after = '', $disableCache = false)
    {
        $cacheKey = $productId . '_' . $variationId;

        static $names = [];

        if (isset($names[$cacheKey]) && !$disableCache) {
            return $names[$cacheKey];
        }

        $post = get_post($productId);

        if (!$post) {
            $names[$cacheKey] = '';
            return '';
        }

        $title = $post->post_title;

        $names[$cacheKey] = $title;

        return $names[$cacheKey];
    }

    /**
     * @param $order \WC_Order
     * @return false|\stdClass
     */
    public
    static function getDbCustomerFromOrder($order)
    {
        if ($customerUserId = $order->get_customer_id()) {
            $customer = fluentCrmDb()->table('wc_customer_lookup')
                ->where('user_id', $customerUserId)
                ->first();
            if ($customer) {
                return $customer;
            }
        }

        $customerId = false;

        $lookup = fluentCrmDb()->table('wc_order_product_lookup')
            ->where('order_id', $order->get_id())
            ->first();

        if ($lookup) {
            $customerId = $lookup->customer_id;
        } else {
            if (class_exists('\Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore')) {
                if (!is_a($order, '\Automattic\WooCommerce\Admin\Overrides\Order')) {
                    $order = new \Automattic\WooCommerce\Admin\Overrides\Order($order);
                }

                $customerId = \Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore::get_or_create_customer_from_order($order);
            }
        }

        if (!$customerId) {
            $lookup = fluentCrmDb()->table('wc_order_stats')
                ->where('order_id', $order->get_id())
                ->first();
            if ($lookup) {
                $customerId = $lookup->customer_id;
            }
        }

        if (!$customerId) {
            $customerEmail = $order->get_billing_email();
            if ($customerEmail) {
                return fluentCrmDb()->table('wc_customer_lookup')
                    ->where('email', $customerEmail)
                    ->first();
            } else {
                return false;
            }
        }

        return fluentCrmDb()->table('wc_customer_lookup')
            ->where('customer_id', $customerId)
            ->first();
    }
}
