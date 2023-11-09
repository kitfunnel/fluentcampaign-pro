<?php

namespace FluentCampaign\App\Services\Integrations\WooCommerce;

class WooDataHelper
{

    static $customerCache = [];

    public static function getCustomerItem($key, $wooCustomer, $default = false)
    {
        if (!$wooCustomer) {
            return $default;
        }

        switch ($key) {
            case 'purchased_items':
            case 'purchased_products':
                return self::getCustomerProductIds($wooCustomer->customer_id);
            case 'purchased_categories':
            case 'cat_purchased':
                return self::getPurchasedCategoryIds($wooCustomer->customer_id);
            case 'total_order_count':
            case 'order_count':
                return self::getOrderCount($wooCustomer);
            case 'total_order_value':
            case 'total_spend':
                return self::getTotalSpend($wooCustomer);
            case 'billing_country':
                return $wooCustomer->country;
            case 'guest_user':
                return ($wooCustomer->user_id) ? 'no' : 'yes';
            default:
                return $default;
        }

    }

    /**
     * @param $key string
     * @param $order \WC_Order
     * @return mixed
     */
    public static function getOrderItem($key, $order)
    {
        if (!$order) {
            return '';
        }

        switch ($key) {
            case 'total_value':
                return $order->get_total('edit');
            case 'cat_purchased':
                $items = $order->get_items();
                $purchaseProductIds = [];
                foreach ($items as $item) {
                    $purchaseProductIds[] = $item->get_product_id();
                }
                return self::getCatIdsByProductIds($purchaseProductIds);
            case 'product_ids':
                $items = $order->get_items();
                $purchaseProductIds = [];
                foreach ($items as $item) {
                    $purchaseProductIds[] = $item->get_product_id();
                }
                return array_unique($purchaseProductIds);
            case 'billing_country':
                return $order->get_billing_country('edit');
            case 'shipping_method':
                return self::getOrderShippingMethodId($order);
            case 'payment_gateway':
                return $order->get_payment_method('edit');
            case 'order_status':
                return $order->get_status('edit');
            default:
                return apply_filters('fluent_crm/get_woo_data', '', $key, $order);
        }
    }

    public static function getOrderShippingMethodId($order)
    {
        $orderMethod = $order->get_shipping_method();
        $shippingMethods = $order->get_shipping_methods();
        foreach ($shippingMethods as $method) {
            if ($method->get_method_title() == $orderMethod) {
                return $method->get_method_id();
            }
        }
        return '';
    }

    /**
     * @param int|object wc_customer_lookup table row
     * @return mixed
     */
    public static function getTotalSpend($wooCustomer)
    {
        if ($wooCustomer->user_id) {
            $customer = self::getCustomer($wooCustomer->user_id);
            return $customer->get_total_spent();
        }

        $orderStat = fluentCrmDb()->table('wc_order_stats')
            ->select(fluentCrmDb()->raw('SUM(net_total) as total_spent'))
            ->where('customer_id', $wooCustomer->customer_id)
            ->first();

        return $orderStat->total_spent;

    }

    public static function getOrderCount($wooCustomer)
    {
        if ($wooCustomer->user_id) {
            $customer = self::getCustomer($wooCustomer->user_id);
            return $customer->get_order_count();
        }

        return fluentCrmDb()->table('wc_order_stats')
            ->where('customer_id', $wooCustomer->customer_id)
            ->count();
    }

    public static function getPurchasedCategoryIds($customerId)
    {
        $productIds = self::getCustomerProductIds($customerId);
        if (!$productIds) {
            return [];
        }
        return self::getCatIdsByProductIds($productIds);
    }

    public static function getCatIdsByProductIds($productIds)
    {
        if (!$productIds) {
            return [];
        }
        $allCategories = fluentCrmDb()->table('term_taxonomy')
            ->select(['term_taxonomy_id'])
            ->where('taxonomy', 'product_cat')
            ->get();

        $allCategoryIds = [];

        foreach ($allCategories as $allCategory) {
            $allCategoryIds[] = $allCategory->term_taxonomy_id;
        }

        if (!$allCategoryIds) {
            return [];
        }

        $relationships = fluentCrmDb()->table('term_relationships')
            ->whereIn('object_id', $productIds)
            ->whereIn('term_taxonomy_id', $allCategoryIds)
            ->get();

        if (!$relationships) {
            return [];
        }

        $catIds = [];

        foreach ($relationships as $relationship) {
            $catIds[] = $relationship->term_taxonomy_id;
        }

        return array_unique($catIds);
    }

    public static function getCustomerProductIds($customerId)
    {
        $productLookUps = fluentCrmDb()->table('wc_order_product_lookup')
            ->select(['product_id'])
            ->groupBy('product_id')
            ->where('customer_id', $customerId)
            ->get();

        if (!$productLookUps) {
            return [];
        }

        $productIds = [];

        foreach ($productLookUps as $productLookUp) {
            $productIds[] = $productLookUp->product_id;
        }

        return $productIds;
    }

    private static function getCustomer($userId)
    {
        if (isset(self::$customerCache[$userId])) {
            return self::$customerCache[$userId];
        }

        self::$customerCache[$userId] = new \WC_Customer($userId);

        return self::$customerCache[$userId];
    }

    public static function getProductPurchaseCount($productId, $subscriberId)
    {
        return fluentCrmDb()->table('fc_contact_relation_items')
            ->where('subscriber_id', $subscriberId)
            ->where('item_id', $productId)
            ->count();
    }
}
