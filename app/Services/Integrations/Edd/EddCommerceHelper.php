<?php

namespace FluentCampaign\App\Services\Integrations\Edd;

use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCampaign\App\Services\Commerce\ContactRelationItemsModel;
use FluentCampaign\App\Services\Commerce\ContactRelationModel;
use FluentCrm\Framework\Support\Arr;

class EddCommerceHelper
{
    public static function stats()
    {
        $sum = fluentCrmDb()->table('fc_contact_relations')
            ->where('provider', 'edd')
            ->select(fluentCrmDb()->raw('SUM(total_order_value) as total_sales'))
            ->first();

        $customerCount = ContactRelationModel::provider('edd')->where('total_order_count', '>', 0)->count();
        $orderCount = ContactRelationItemsModel::provider('edd')
            ->distinct()
            ->count('origin_id');

        return [
            'sales'     => [
                'type'   => __('Total Sales', 'fluentcampaign-pro'),
                'amount' => number_format($sum->total_sales, 2, '.', ',')
            ],
            'customers' => [
                'type'   => __('Paid Customers', 'fluentcampaign-pro'),
                'amount' => number_format($customerCount, 2, '.', ',')
            ],
            'orders'    => [
                'type'   => __('Total Orders', 'fluentcampaign-pro'),
                'amount' => number_format($orderCount, 2, '.', ',')
            ]
        ];
    }

    public static function productsStats($period = '')
    {
        $uniqueProducts = ContactRelationItemsModel::provider('edd')->groupBy('item_id')
            ->select('item_id')
            ->get();

        $productItems = [];
        $total = 0;
        foreach ($uniqueProducts as $uniqueProduct) {
            $post = get_post($uniqueProduct->item_id);

            $salesRowQuery = fluentCrmDb()->table('fc_contact_relation_items')
                ->where('provider', 'edd')
                ->where('item_id', $uniqueProduct->item_id)
                ->select(fluentCrmDb()->raw('SUM(item_value) as total_sales'));

            if ($dateRange = Commerce::getRangeFromPeriod($period)) {
                $salesRowQuery->whereBetween('created_at', $dateRange[0], $dateRange[1]);
            }

            $salesRow = $salesRowQuery->first();

            if (!intval($salesRow->total_sales)) {
                continue;
            }

            $total += $salesRow->total_sales;

            $productItems[$uniqueProduct->item_id] = [
                'id'              => $uniqueProduct->item_id,
                'name'            => ($post) ? $post->post_title : $uniqueProduct->item_id,
                'sales'           => $salesRow->total_sales,
                'formatted_sales' => number_format($salesRow->total_sales, 2, '.', ','),
                'percent'         => 0
            ];
        }

        usort($productItems, function ($item1, $item2) {
            if ($item1['sales'] == $item2['sales']) return 0;
            return $item1['sales'] < $item2['sales'] ? 1 : -1;
        });

        if ($total) {
            foreach ($productItems as $itemIndex => $item) {
                $productItems[$itemIndex]['percent'] = number_format(($item['sales'] / $total) * 100, 2, '.', '') . '%';
            }
        }

        return $productItems;
    }

    public static function productStat($productId, $period = '')
    {
        $stats = [];

        $salesRowQuery = fluentCrmDb()->table('fc_contact_relation_items')
            ->where('provider', 'edd')
            ->where('item_id', $productId);

        if ($period = Commerce::getRangeFromPeriod($period)) {
            $salesRowQuery->whereBetween('created_at', $period[0], $period[1]);
        }

        $salesRow = $salesRowQuery->select(fluentCrmDb()->raw('SUM(item_value) as total_sales, COUNT(id) as count'))
            ->first();

        $stats[] = [
            'name'            => __('Overall Sales', 'fluentcampaign-pro'),
            'type'            => 'Overall',
            'percent'         => '100%',
            'sales'           => $salesRow->total_sales,
            'count'           => $salesRow->count,
            'formatted_sales' => number_format($salesRow->total_sales, 2, '.', ',')
        ];

        $variations = edd_get_variable_prices($productId);

        if ($variations && $salesRow->total_sales) {
            foreach ($variations as $variationIndex => $variation) {
                $variationStat = fluentCrmDb()->table('fc_contact_relation_items')
                    ->where('provider', 'edd')
                    ->where('item_id', $productId)
                    ->where('item_sub_id', $variationIndex)
                    ->select(fluentCrmDb()->raw('SUM(item_value) as total_sales, COUNT(id) as count'))
                    ->first();

                $stats[] = [
                    'name'            => $variation['name'],
                    'type'            => ($variation['is_lifetime']) ? 'Lifetime' : 'recurring',
                    'sales'           => $variationStat->total_sales,
                    'count'           => $variationStat->count,
                    'formatted_sales' => number_format($variationStat->total_sales, 2, '.', ','),
                    'percent'         => number_format(($variationStat->total_sales / $salesRow->total_sales) * 100, 2, '.', '') . '%'
                ];
            }
        }

        usort($stats, function ($item1, $item2) {
            if ($item1['sales'] == $item2['sales']) return 0;
            return $item1['sales'] < $item2['sales'] ? 1 : -1;
        });

        return $stats;
    }

    public static function getLicenseStats()
    {
        $statusCounts = fluentCrmDb()->table('edd_licenses')
            ->select(['status', fluentCrmDb()->raw('count(*) as total')])
            ->groupBy('status')
            ->orderBy('total', 'DESC')
            ->get();

        $items = [
            'all' => [
                'label' => __('All licenses', 'fluentcampaign-pro'),
                'count' => 0
            ]
        ];

        $totalLicenses = 0;
        foreach ($statusCounts as $statusCount) {
            $totalLicenses += $statusCount->total;
            $items[$statusCount->status] = [
                'label' => ucfirst($statusCount->status) . ' License',
                'count' => number_format($statusCount->total)
            ];
        }

        $items['all']['count'] = number_format($totalLicenses);

        $lifetimeLicenses = fluentCrmDb()->table('edd_licenses')
            ->whereIn('status', ['active', 'inactive'])
            ->where('expiration', '<', 1)
            ->count();

        $recurringLicenses = fluentCrmDb()->table('edd_licenses')
            ->whereIn('status', ['active', 'inactive'])
            ->where('expiration', '>', 1)
            ->count();

        $items['total_lifetime'] = [
            'label' => __('Total Lifetime Licenses', 'fluentcampaign-pro'),
            'count' => number_format($lifetimeLicenses)
        ];

        $items['total_recurring'] = [
            'label' => __('Total Recurring Licenses', 'fluentcampaign-pro'),
            'count' => number_format($recurringLicenses)
        ];

        $items['activated_sites'] = [
            'label' => __('Activated Sites', 'fluentcampaign-pro'),
            'count' => number_format(fluentCrmDb()->table('edd_license_activations')->count())
        ];

        return $items;
    }

    public static function getLicenseActivations()
    {
        $uniqueProducts = fluentCrmDb()->table('edd_licenses')
            ->groupBy('download_id')
            ->select('download_id')
            ->get();

        $items = [];
        foreach ($uniqueProducts as $uniqueProduct) {
            $product = get_post($uniqueProduct->download_id);
            $count = fluentCrmDb()->table('edd_license_activations')
                ->join('edd_licenses', 'edd_license_activations.license_id', '=', 'edd_licenses.id')
                ->where('edd_licenses.download_id', $uniqueProduct->download_id)
                ->count();
            $items[] = [
                'label'           => $product->post_title,
                'activated_sites' => number_format($count),
                'count'           => $count
            ];
        }

        usort($items, function ($item1, $item2) {
            if ($item1['count'] == $item2['count']) return 0;
            return $item1['count'] < $item2['count'] ? 1 : -1;
        });

        return $items;
    }

    public static function syncCommerceCustomer($customer, $contactStatus = 'subscribed', $paymentStatuses = [], $tags = [], $lists = [], $sendDoubleOptin = true)
    {
        if(!$paymentStatuses) {
            $paymentStatuses = ['edd_subscription', 'processing', 'publish', 'complete', 'completed'];
        }

        if (is_numeric($customer)) {
            $customer = fluentCrmDb()->table('edd_customers')->find($customer);
        }

        if (!$customer) {
            return false;
        }

        if(\FluentCampaign\App\Services\Integrations\Edd\Helper::isEdd3()) {
            // EDD 3 Here
            $orders = fluentCrmDb()->table('edd_orders')->select(['id'])
                ->where('customer_id', $customer->id)
                ->where('type', 'sale')
                ->whereIn('status', ['edd_subscription', 'partially_refunded', 'complete', 'processing'])
                ->get();

            if (!$orders){
                return false;
            }

            $paymentIds = [];
            foreach ($orders as $order) {
                $paymentIds[] = $order->id;
            }
        } else {
            $paymentIds = array_filter(array_map('absint', explode(',', $customer->payment_ids)));

            if (!$paymentIds && $customer->user_id && $customer->purchase_count) {
                // We have to fix this customer
                $paymentPosts = fluentCrmDb()->table('posts')
                    ->where('post_author', $customer->user_id)
                    ->where('post_type', 'edd_payment')
                    ->get();

                if ($paymentPosts) {
                    foreach ($paymentPosts as $paymentPost) {
                        $paymentIds[] = $paymentPost->ID;
                    }
                } else {
                    return false;
                }
            }
        }

        if (!$paymentIds) {
            return false;
        }

        asort($paymentIds);

        $customerPayments = [];
        $totalValue = 0;
        $orderDates = [];

        $relationItems = [];
        $discountCodes = [];
        $productIds = [];
        foreach ($paymentIds as $paymentId) {
            $payment = edd_get_payment($paymentId);

            if (!$payment) {
                continue;
            }
            if (empty($paymentStatuses) || in_array($payment->status, $paymentStatuses)) {
                $customerPayments[] = $payment;

                if($payment->total) {
                    $totalValue += $payment->total;
                }

                $orderDates[] = $payment->date;

                $cartItems = $payment->cart_details;

                if (($discounts = $payment->discounts) && $payment->discounts != 'none') {
                    $discounts = array_map('trim', explode(',', $discounts));
                    $discountCodes = array_merge($discountCodes, $discounts);
                }

                $paymentItems = [];

                $cartItemsTotal = 0;
                foreach ($cartItems as $cartItem) {
                    $itemType = ($payment->status == 'edd_subscription') ? 'renewal' : 'product';

                    $item = [
                        'origin_id'   => $payment->ID,
                        'item_id'     => $cartItem['id'],
                        'item_sub_id' => Arr::get($cartItem, 'item_number.options.price_id'),
                        'item_value'  => $cartItem['price'],
                        'status'      => $payment->status,
                        'item_type'   => $itemType,
                        'created_at'  => $payment->date
                    ];

                    if($itemType == 'product' && Arr::get($cartItem, 'item_number.options.recurring')) {
                        $item['item_type'] = 'renewal_signup';
                    }

                    if($itemType == 'renewal') {
                        $item['meta_col'] = $payment->parent_payment;
                    }

                    $paymentItems[] = $item;

                    $cartItemsTotal += $cartItem['price'];

                    $productIds[] = $cartItem['id'];
                }

                if ($cartItemsTotal != $payment->total) {
                    foreach ($paymentItems as $index => $paymentItem) {
                        if ($cartItemsTotal) {
                            $paymentItems[$index]['item_value'] = ($paymentItem['item_value'] / $cartItemsTotal) * $payment->total;
                        } else {
                            $paymentItems[$index]['item_value'] = 0;
                        }
                    }
                }

                $relationItems = array_merge($relationItems, $paymentItems);
            }
        }

        if (!$relationItems) {
            return false;
        }

        $productIds = array_unique($productIds);

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
            $contactData = [
                'email'      => $user->user_email,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'user_id'    => $user->ID,
            ];

            if ($subscriber) {
                $subscriber->fill($contactData)->save();
            }
        } else {
            $contactData = [
                'email'     => $customer->email,
                'full_name' => $customer->name,
                'status'    => $contactStatus
            ];
        }

        if (!$subscriber) {
            $subscriber = FluentCrmApi('contacts')->createOrUpdate($contactData);
            if (!$subscriber) {
                return false;
            }
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

        $relationData = [
            'subscriber_id'     => $subscriber->id,
            'provider'          => 'edd',
            'provider_id'       => $customer->id,
            'created_at'        => $customer->date_created,
            'total_order_count' => count($customerPayments),
            'total_order_value' => $totalValue
        ];

        if ($discountCodes) {
            $discountCodes = array_values(array_unique($discountCodes));
            $relationData['commerce_coupons'] = json_encode(array_map('strval', $discountCodes));
        }

        if ($orderDates) {
            $relationData['first_order_date'] = reset($orderDates);
            $relationData['last_order_date'] = end($orderDates);
        }

        $contactRelation = ContactRelationModel::updateOrCreate([
            'subscriber_id' => $subscriber->id,
            'provider'      => 'edd'
        ], $relationData);

        if (!$contactRelation) {
            return false;
        }

        $contactRelation->syncItems($relationItems, true, true);

        return [
            'relation'   => $contactRelation,
            'subscriber' => $subscriber
        ];
    }
}
