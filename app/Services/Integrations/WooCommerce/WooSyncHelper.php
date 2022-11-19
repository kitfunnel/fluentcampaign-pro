<?php

namespace FluentCampaign\App\Services\Integrations\WooCommerce;

use FluentCampaign\App\Services\Commerce\ContactRelationItemsModel;
use FluentCampaign\App\Services\Commerce\ContactRelationModel;
use FluentCrm\App\Models\Subscriber;
use FluentCampaign\App\Services\Integrations\WooCommerce\Helper;
use FluentCrm\Framework\Support\Arr;

class WooSyncHelper
{
    public static function syncCommerceCustomer($customer, $contactStatus = 'subscribed', $paymentStatuses = [], $tags = [], $lists = [], $sendDoubleOptin = true)
    {
        if (!$paymentStatuses) {
            $paymentStatuses = wc_get_is_paid_statuses();
        }

        if (is_numeric($customer)) {
            $customer = fluentCrmDb()->table('wc_customer_lookup')->where('customer_id', $customer)->first();
        }

        if (!$customer || !$customer->email) {
            return false;
        }

        $orderArgs = [
            'status'  => $paymentStatuses,
            'limit'   => -1,
            'orderby' => 'date',
            'order'   => 'ASC',
        ];

        if ($customer->user_id > 0) {
            $orderArgs['customer_id'] = $customer->user_id;
        } else {
            $orderArgs['customer'] = $customer->email;
        }


        try {
            $orders = wc_get_orders($orderArgs);

            if(!$orders && empty($orderArgs['customer'])) {
                $orders = wc_get_orders([
                    'status'  => $paymentStatuses,
                    'limit'   => -1,
                    'orderby' => 'date',
                    'order'   => 'ASC',
                    'customer' => $customer->email
                ]);
            }

        } catch (\Exception $e) {
            return false;
        }

        if (!$orders) {
            return false;
        }

        $totalValue = 0;
        $orderDates = [];

        $relationItems = [];
        $discountCodes = [];
        $productIds = [];

        $billingPhone = false;

        $processedOrderIds = [];

        foreach ($orders as $order) {

            if (!$order->get_id()) {
                continue;
            }

            $subscriptionItems = [];
            if (defined('WCS_INIT_TIMESTAMP')) {
                $subscriptions = wcs_get_subscriptions_for_order($order);
                foreach ($subscriptions as $subscription) {
                    foreach ($subscription->get_items() as $item) {
                        $subscriptionItems[$item->get_product_id()] = $subscription->get_id();
                        if ($variationId = $item->get_variation_id()) {
                            $subscriptionItems[$variationId] = $subscription->get_id();
                        }
                    }
                }
            }

            $orderDate = $order->get_date_created()->date('Y-m-d H:i:s');
            $orderDates[] = $orderDate;
            $orderItems = $order->get_items();
            $items = [];

            foreach ($orderItems as $orderItem) {
                $itemType = $orderItem->get_type();
                $productId = $orderItem->get_product_id();
                $getTotal = (int)$orderItem->get_total();
                $getQuantity = $orderItem->get_quantity();
                $itemValue = 0;
                if ($getTotal > 0 && $getQuantity > 0) {
                    $itemValue = $getTotal / $getQuantity;
                }
                $itemData = [
                    'origin_id'   => $order->get_id(),
                    'item_id'     => $productId,
                    'item_sub_id' => $orderItem->get_variation_id(),
                    'item_value'  => $itemValue,
                    'quantity'    => $orderItem->get_quantity(),
                    'status'      => $order->get_status(),
                    'item_type'   => $itemType,
                    'created_at'  => $orderDate
                ];

                if ($subscriptionItems) {
                    if (!empty($subscriptionItems[$productId])) {
                        $itemData['meta_col'] = $subscriptionItems[$productId];
                    } else if (!empty($item['item_sub_id']) && $subscriptionItems[$item['item_sub_id']]) {
                        $itemData['meta_col'] = $subscriptionItems[$item['item_sub_id']];
                    }
                    $itemProduct = $orderItem->get_product();
                    if ($itemProduct && ($itemProduct->get_type() == 'subscription' || $itemProduct->get_type() == 'subscription_variation')) {
                        $itemData['item_type'] = 'renewal_signup';
                    }
                } else if (defined('WCS_INIT_TIMESTAMP') && $renewalId = $order->get_meta('_subscription_renewal')) {
                    $itemData['item_type'] = 'renewal';
                    $itemData['meta_col'] = $renewalId;
                }

                $items[$orderItem->get_id()] = $itemData;
                $productIds[] = $productId;

                if (!$billingPhone) {
                    $billingPhone = $order->get_billing_phone('edit');
                }
                $processedOrderIds[$order->get_id()] = $order->get_id();
            }
            foreach ($order->get_refunds() as $refund) {
                foreach ($refund->get_items() as $refundItem) {
                    $itemId = $refundItem->get_meta('_refunded_item_id');
                    $refundTotal = $refund->get_subtotal();
                    if (isset($items[$itemId])) {
                        $quantity = Arr::get($items[$itemId], 'quantity');
                        if ($quantity && $quantity > 0) {
                            $items[$itemId]['item_value'] += $refundTotal / $quantity;
                        } else {
                            $items[$itemId]['item_value'] += $refundTotal;
                        }
                    }
                }
            }
            $totalValue += $order->get_total('edit') - $order->get_total_refunded();

            if ($coupons = $order->get_coupon_codes()) {
                $discountCodes = array_merge($discountCodes, $coupons);
            }

            if ($items) {
                $relationItems = array_merge($relationItems, $items);
            }
        }

        if (!$relationItems) {
            return false;
        }

        $productIds = array_values(array_unique($productIds));


        $contactEmail = $customer->email;
        if ($customer->user_id && $customer->user_id > 0) {
            $user = get_user_by('ID', $customer->user_id);
            if ($user) {
                $contactEmail = $user->user_email;
            }
        } else {
            $user = get_user_by('email', $customer->email);
        }

        $subscriber = FluentCrmApi('contacts')->getContact($contactEmail);

        if ($user) {
            $contactData = \FluentCrm\App\Services\Helper::getWPMapUserInfo($user);
        } else {
            $contactData = [
                'email'       => $customer->email,
                'first_name'  => $customer->first_name,
                'last_name'   => $customer->last_name,
                'status'      => $contactStatus,
                'postal_code' => $customer->postcode,
                'city'        => $customer->city,
                'state'       => $customer->state,
                'country'     => $customer->country,
                'phone'       => $billingPhone
            ];
        }

        $contactData['contact_type'] = 'customer';

        $contactData = array_filter($contactData);

        if ($subscriber) {
            $subscriber->fill($contactData)->save();
        } else {
            $contactData['status'] = $contactStatus;
            $subscriber = FluentCrmApi('contacts')->createOrUpdate($contactData);
        }

        if (!$subscriber) {
            return false;
        }

        if ($contactStatus == 'pending' && $subscriber->status == 'pending' && $sendDoubleOptin) {
            $subscriber->sendDoubleOptinEmail();
        }

        if ($tags) {
            $subscriber->attachTags($tags);
        }

        if ($lists) {
            $subscriber->attachLists($lists);
        }

        $firstOrderDate = reset($orderDates);
        $lastOrderDate = end($orderDates);
        $relationData = [
            'subscriber_id'     => $subscriber->id,
            'provider'          => 'woo',
            'provider_id'       => $customer->customer_id,
            'created_at'        => ($customer->date_registered) ? $customer->date_registered : $firstOrderDate,
            'total_order_count' => count($orderDates),
            'total_order_value' => $totalValue,
            'first_order_date'  => $firstOrderDate,
            'last_order_date'   => $lastOrderDate
        ];

        if ($discountCodes) {
            $discountCodes = array_values(array_unique($discountCodes));
            $relationData['commerce_coupons'] = json_encode(array_map('strval', $discountCodes));
        }

        if ($productIds) {
            $taxonomies = Helper::getTagsCatsByProductIds($productIds);

            if ($taxonomies) {
                $relationData['commerce_taxonomies'] = json_encode(array_map('strval', $taxonomies));
            }
        }

        $contactRelation = ContactRelationModel::updateOrCreate([
            'subscriber_id' => $subscriber->id,
            'provider'      => 'woo'
        ], $relationData);

        if (!$contactRelation) {
            return false;
        }

        $contactRelation->syncItems($relationItems, true, true);

        return [
            'relation'   => $contactRelation,
            'subscriber' => $subscriber,
            'orders_count' => count($processedOrderIds)
        ];
    }

    /**
     * @param $customer
     * @param $order \WC_Order
     * @return array|false|void
     */
    public static function syncCustomerOrder($customer, $order, $status = 'subscribed', $tags = [], $lists = [])
    {
        // Check if we have an existing relationship
        $contactRelation = ContactRelationModel::provider('woo')
            ->where('provider_id', $customer->customer_id)
            ->first();

        if (!$contactRelation) {
            return self::syncCommerceCustomer($customer, $status, [], $tags, $lists);
        }

        $subscriber = Subscriber::where('id', $contactRelation->subscriber_id)->first();
        if (!$subscriber) {
            return false;
        }

        $existingItem = ContactRelationItemsModel::provider('woo')
            ->where('origin_id', $order->get_id())
            ->first();

        if ($existingItem) {
            ContactRelationItemsModel::provider('woo')
                ->where('origin_id', $order->get_id())
                ->update([
                    'status' => $order->get_status(),
                ]);

            return [
                'relation' => $contactRelation
            ];
        }

        if ($tags) {
            $subscriber->attachTags($tags);
        }

        if ($lists) {
            $subscriber->attachLists($lists);
        }

        // We have already a relation so we have to
        // - sync the items
        // - update last_order_date
        // - update total_order_count
        // - total_order_value
        // - commerce_taxonomies
        // - commerce_coupons

        $orderDate = $order->get_date_created()->date('Y-m-d H:i:s');
        $orderItems = $order->get_items();
        $items = [];
        $productIds = [];
        foreach ($orderItems as $orderItem) {
            $itemValue = $orderItem->get_total();
            $productId = $orderItem->get_product_id();
            $quantity = $orderItem->get_quantity();
            if ($quantity > 0) {
                $itemValue = $orderItem->get_total() / $quantity;
            }
            $items[$orderItem->get_id()] = [
                'origin_id'   => $order->get_id(),
                'item_id'     => $productId,
                'item_sub_id' => $orderItem->get_variation_id(),
                'item_value'  => $itemValue,
                'quantity'    => $quantity,
                'status'      => $order->get_status(),
                'item_type'   => $orderItem->get_type(),
                'created_at'  => $orderDate
            ];
            $productIds[] = $productId;
        }
        foreach ($order->get_refunds() as $refund) {
            foreach ($refund->get_items() as $refundItem) {
                $itemId = $refundItem->get_meta('_refunded_item_id');
                $refundTotal = $refund->get_subtotal();
                if (isset($items[$itemId])) {
                    $quantity = Arr::get($items[$itemId], 'quantity');
                    if ($quantity && $quantity > 0) {
                        $items[$itemId]['item_value'] += $refundTotal / $quantity;
                    } else {
                        $items[$itemId]['item_value'] += $refundTotal;
                    }
                }
            }
        }

        $orderValue = $order->get_total() - $order->get_total_refunded();
        $discountCodes = $order->get_coupon_codes();

        if (!$items) {
            return false;
        }

        if ($existingItem) {
            // This will be an addition actually
            ContactRelationItemsModel::provider('woo')
                ->where('origin_id', $order->get_id())
                ->delete();
        }

        foreach ($items as $item) {
            $item['provider'] = $contactRelation->provider;
            $item['relation_id'] = $contactRelation->id;
            $item['subscriber_id'] = $contactRelation->subscriber_id;
            unset($item['quantity']);
            ContactRelationItemsModel::insert($item);
        }

        if ($productIds) {
            $taxonomies = Helper::getTagsCatsByProductIds($productIds);
            if ($taxonomies) {
                if ($contactRelation->commerce_taxonomies) {
                    $existingTaxonomies = json_decode($contactRelation->commerce_taxonomies, true);
                    $taxonomies = array_merge($existingTaxonomies, $taxonomies);
                    $taxonomies = array_values(array_filter($taxonomies));
                }
                $contactRelation->commerce_taxonomies = json_encode(array_map('strval', $taxonomies));
            }
        }

        if ($discountCodes) {
            if ($contactRelation->commerce_coupons) {
                $existingCodes = json_decode($contactRelation->commerce_coupons, true);
                $discountCodes = array_merge($existingCodes, $discountCodes);
                $discountCodes = array_values(array_filter($discountCodes));
            }
            $contactRelation->commerce_coupons = json_encode(array_map('strval', $discountCodes));
        }

        $contactRelation->last_order_date = $orderDate;
        $contactRelation->total_order_count = $contactRelation->total_order_count + 1;
        $contactRelation->total_order_value = $contactRelation->total_order_value + $orderValue;
        $contactRelation->save();

        return [
            'relation' => $contactRelation
        ];
    }
}
