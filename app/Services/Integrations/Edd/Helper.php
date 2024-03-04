<?php

namespace FluentCampaign\App\Services\Integrations\Edd;

use FluentCrm\App\Services\Funnel\FunnelHelper;

class Helper
{
    public static function getProducts()
    {
        // get edd products order by title
        $downloads = get_posts([
            'post_type'   => 'download',
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC'
        ]);

        $formattedProducts = [];
        foreach ($downloads as $download) {
            $formattedProducts[] = [
                'id'    => strval($download->ID),
                'title' => $download->post_title
            ];
        }

        return $formattedProducts;
    }

    public static function getCategories()
    {
        $categories = get_terms('download_category', array(
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
     * @param $payment \EDD_Payment
     * @param $conditions
     * @return bool
     */
    public static function isProductIdCategoryMatched($payment, $conditions)
    {
        $purchaseProductIds = [];
        foreach ($payment->cart_details as $item) {
            $purchaseProductIds[] = $item['id'];
        }

        if (!empty($conditions['product_ids'])) {
            if (array_intersect($purchaseProductIds, $conditions['product_ids'])) {
                return true;
            }

            if (empty($conditions['product_categories'])) {
                return false;
            }

        }

        if ($targetCategories = $conditions['product_categories']) {

            $categoryMatch = fluentCrmDb()->table('term_relationships')
                ->join('term_taxonomy', 'term_relationships.term_taxonomy_id', '=', 'term_taxonomy.term_taxonomy_id')
                ->whereIn('term_relationships.object_id', $purchaseProductIds)
                ->whereIn('term_taxonomy.term_id', $targetCategories)
                ->count();

            if (!$categoryMatch) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param $payment \EDD_Payment
     * @param $purchaseType
     * @return bool
     */
    public static function isPurchaseTypeMatch($payment, $purchaseType)
    {
        if (!$purchaseType) {
            return true;
        }

        $customer = new \EDD_Customer($payment->customer_id);

        if ($purchaseType == 'from_second') {
            if ($customer->purchase_count < 2) {
                return false;
            }
        } else if ($purchaseType == 'first_purchase') {
            if ($customer->purchase_count > 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param $payment \EDD_Payment
     * @return array
     */
    public static function prepareSubscriberData($payment)
    {
        if ($payment->user_id) {
            $subscriberData = FunnelHelper::prepareUserData($payment->user_id);
        } else {
            $subscriberData = [
                'first_name' => $payment->first_name,
                'last_name'  => $payment->last_name,
                'email'      => $payment->email,
                'ip'         => $payment->ip
            ];
        }

        return FunnelHelper::maybeExplodeFullName($subscriberData);
    }

    public static function isProductPurchased($productIds, $subscriber)
    {
        if (!$productIds) {
            return false;
        }

        $args = [
            'output' => 'payments',
            'status' => ['publish', 'processing', 'complete', 'completed']
        ];

        $userId = $subscriber->getWpUserId();

        if ($userId) {
            $args['user'] = $userId;
        }

        if (!isset($args['user'])) {
            $customer = fluentCrmDb()->table('edd_customers')
                ->where('email', $subscriber->email)
                ->first();
            if (!$customer) {
                return false;
            }
            $args['customer'] = $customer->id;
        }

        $payments = edd_get_payments($args);

        if (!$payments) {
            return false;
        }

        foreach ($payments as $payment) {
            foreach ($payment->cart_details as $cart_detail) {
                if (in_array($cart_detail['id'], $productIds)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function getTagsCatsByProductIds($productIds)
    {
        $items = fluentCrmDb()->table('term_relationships')
            ->select(['term_taxonomy.term_id'])
            ->whereIn('term_relationships.object_id', $productIds)
            ->join('term_taxonomy', 'term_taxonomy.term_taxonomy_id', '=', 'term_relationships.term_taxonomy_id')
            ->whereIn('term_taxonomy.taxonomy', ['download_tag', 'download_category'])
            ->get();

        $formattedItems = [];

        foreach ($items as $item) {
            $formattedItems[] = $item->term_id;
        }

        return array_unique($formattedItems);
    }

    public static function getProductName($productId, $priceId = false, $separator = ' - ', $after = '', $disableCache = false)
    {
        $cacheKey = $productId . '_' . $priceId;

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

        if ($priceId) {
            $variations = edd_get_variable_prices($productId);
            if ($variations && isset($variations[$priceId]['name'])) {
                $title .= $separator . $variations[$priceId]['name'] . $after;
            }
        }

        $names[$cacheKey] = $title;

        return $names[$cacheKey];
    }

    public static function getSubscriptionLicenseProp($prop, $subscriber, $productId)
    {
        if ($prop == 'has_active_license') {
            return self::hasValidLicense($subscriber, $productId);
        }

        if ($prop == 'has_active_subscription') {
            return self::hasSubscriptionInStatus($subscriber, $productId, ['active']);
        }
        return false;
    }

    public static function hasValidLicense($subscriber, $productIds)
    {

        if (!defined('EDD_SL_VERSION') || empty($productIds)) {
            return false;
        }

        $userId = $subscriber->getWpUserId();
        if (!$userId) {
            return false;
        }

        $hasLicense = fluentCrmDb()->table('edd_licenses')
            ->where('user_id', $userId)
            ->whereIn('status', ['active', 'inactive'])
            ->whereIn('download_id', $productIds)
            ->first();

        return !!$hasLicense;
    }

    public static function hasSubscriptionInStatus($subscriber, $productIds, $statuses = ['active'])
    {
        if (!defined('EDD_RECURRING_VERSION') || empty($productIds)) {
            return false;
        }

        $userId = $subscriber->getWpUserId();

        if (!$userId) {
            return false;
        }

        $customer = fluentCrmDb()->table('edd_customers')
            ->where('user_id', $userId)
            ->orWhere('email', $subscriber->email)
            ->first();

        if (!$customer) {
            return false;
        }

        $hasSubscription = fluentCrmDb()->table('edd_subscriptions')
            ->where('customer_id', $customer->id)
            ->whereIn('status', $statuses)
            ->whereIn('product_id', $productIds)
            ->first();

        return !!$hasSubscription;
    }

    public static function isEddTrigger($triggerName)
    {
        $supportedTriggers = [
            'edd_update_payment_status',
            'edd_fc_order_refunded_simulation',
            'edd_update_payment_status',
            'edd_recurring_add_subscription_payment'
        ];

        return in_array($triggerName, $supportedTriggers);
    }

    public static function isEdd3()
    {
        return class_exists('\EDD\Models\Download');
    }
}
