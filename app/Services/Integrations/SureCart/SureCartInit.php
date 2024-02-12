<?php

namespace FluentCampaign\App\Services\Integrations\SureCart;

class SureCartInit
{
    public function register()
    {
        new SureCartPaymentSuccessTrigger();
        new SureCartPaymentRefundTrigger();
        new SureCartPaymentSuccessBenchmark();

        add_action('surecart/purchase_created', function ($purchase) {
            if (!has_action('fluent_surecart_purchase_created_wrap')) {
                return;
            }

            $initialOrderId = $purchase->initial_order;

            if (empty($initialOrderId || !is_string($initialOrderId))) {
                return;
            }

            $orderData = $this->getFormattedDataFromOrderId($initialOrderId);

            do_action('fluent_surecart_purchase_created_wrap', $orderData);
        });

        add_action('surecart/purchase_revoked', function ($purchase) {

            if (!has_action('fluent_surecart_purchase_refund_wrap')) {
                return;
            }

            $initialOrderId = $purchase->initial_order;

            if (empty($initialOrderId || !is_string($initialOrderId))) {
                return;
            }

            $orderData = $this->getFormattedDataFromOrderId($initialOrderId);

            do_action('fluent_surecart_purchase_refund_wrap', $orderData);
        });

        add_filter('fluentcrm_ajax_options_surecart_products', [$this, 'getProductsOptions'], 10, 3);

        add_filter('fluent_crm/purchase_history_providers', [$this, 'registerPurchaseHistoryProvider']);

        add_filter('fluent_crm/purchase_history_surecart', [$this, 'getPurchaseHistory'], 10, 2);
    }

    private function getFormattedDataFromOrderId($orderId)
    {
        static $cached = [];
        if (isset($cached[$orderId])) {
            return $cached[$orderId];
        }

        $order = \SureCart\Models\Order::with(['checkout', 'checkout.purchases'])->find($orderId);

        if (!$order || is_wp_error($order)) {
            return null;
        }

        $customer = array_filter([
            'email'      => $order->checkout->email,
            'first_name' => $order->checkout->first_name,
            'last_name'  => $order->checkout->last_name,
            'phone'      => $order->checkout->phone,
            'ip'         => $order->checkout->ip_address
        ]);

        $productIds = [];
        foreach ($order->checkout->purchases->data as $purchaseItem) {
            $productIds[] = $purchaseItem->product;
        }

        $data = [
            'product_ids' => $productIds,
            'customer'    => $customer,
            'order_id'    => $orderId
        ];

        $cached[$orderId] = $data;

        return $cached[$orderId];
    }

    public function getProductsOptions($options, $search, $includedIds)
    {
        $products = \SureCart\Models\Product::where(
            [
                'archived' => false,
                'query'    => $search
            ]
        )->paginate([
            'page'     => 1,
            'per_page' => 10
        ]);

        $formattedProducts = [];

        foreach ($products->data as $product) {
            $formattedProducts[$product->id] = [
                'id'    => $product->id,
                'title' => $product->name,
            ];
        }

        if ($includedIds) {
            $missingIds = array_diff($includedIds, array_keys($formattedProducts));
            if ($missingIds) {
                $missingProducts = \SureCart\Models\Product::where(
                    [
                        'ids' => $missingIds
                    ]
                )->get();

                foreach ($missingProducts as $product) {
                    $formattedProducts[$product->id] = [
                        'id'    => $product->id,
                        'title' => $product->name,
                    ];
                }
            }
        }


        return array_values($formattedProducts);
    }

    public function registerPurchaseHistoryProvider($providers)
    {
        $providers['surecart'] = [
            'title' => __('SureCart Purchase History', 'fluentcampaign-pro'),
            'name' => __('SureCart', 'fluentcampaign-pro'),
        ];

        return $providers;
    }

    public function getPurchaseHistory($data, $subscriber)
    {
        $customer = \SureCart\Models\Customer::byEmail($subscriber->email);

        if(!$customer || is_wp_error($customer)) {
            return $data;
        }

        $page = isset($_REQUEST['page']) ? $_REQUEST['page'] : 1;
        $perPage = isset($_REQUEST['per_page']) ? $_REQUEST['per_page'] : 10;

        $orders = \SureCart\Models\Order::where([
            'customer_ids' =>  [$customer->id]
        ])->with(['checkout'])->paginate([
            'page' => $page,
            'per_page' => $perPage
        ]);

        $formattedOrders = [];

        foreach ($orders->data  as $order) {
            $formattedOrders[] = [
                'Order' => '<a target="_blank" rel="noopener" href="'.admin_url('admin.php?page=sc-orders&action=edit&id='.$order->id).'">#'.$order->number.'</a>',
                'type' => $order->order_type,
                'status' => $order->status,
                'date' => date('Y-m-d H:i:s', $order->created_at),
                'total' => strtoupper($order->checkout->currency).' '.number_format($order->checkout->total_amount / 100, 2),
            ];
        }

        $returnData = [
            'data' => $formattedOrders,
            'total' => $orders->pagination->count,
            'per_page' => $orders->pagination->limit,
        ];

        if($page == 1) {
            $returnData['after_html'] = '<p><a target="_blank" rel="noopener" href="'.admin_url('admin.php?page=sc-customers&action=edit&id='.$customer->id).'">View Customer Profile</a></p>';
        }

        return $returnData;
    }

}
