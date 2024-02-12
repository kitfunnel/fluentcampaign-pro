<?php

namespace FluentCampaign\App\Services\Integrations\SureCart;

use FluentCrm\App\Services\Funnel\BaseBenchMark;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class SureCartPaymentSuccessBenchmark extends BaseBenchMark
{
    public function __construct()
    {
        $this->triggerName = 'fluent_surecart_purchase_created_wrap';
        $this->priority = 20;
        $this->actionArgNum = 2;

        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'title'       => __('Order Received in SureCart', 'fluentcampaign-pro'),
            'description' => __('This will run once new order has been placed as processing', 'fluentcampaign-pro'),
            'icon'        => 'el-icon-shopping-cart-full',
            'settings'    => [
                'product_ids'        => [],
                'type'               => 'required',
                'can_enter'          => 'yes'
            ]
        ];
    }

    public function getDefaultSettings()
    {
        return [
            'product_ids'        => [],
            'type'               => 'required',
            'can_enter'          => 'yes'
        ];
    }

    public function getBlockFields($funnel)
    {
        return [
            'title'     => __('Order Received in SureCart', 'fluentcampaign-pro'),
            'sub_title' => __('This will run once new order will be placed as processing', 'fluentcampaign-pro'),
            'fields'    => [
                'product_ids'  => [
                    'type'        => 'rest_selector',
                    'option_key'  => 'surecart_products',
                    'is_multiple' => true,
                    'label'       => __('Target Products', 'fluentcampaign-pro'),
                    'help'        => __('Select for which products this automation will run', 'fluentcampaign-pro'),
                    'inline_help' => __('Keep it blank to run to any product purchase', 'fluentcampaign-pro')
                ],
                'type'         => $this->benchmarkTypeField(),
                'can_enter'    => $this->canEnterField()
            ]
        ];
    }

    /*
     * @todo: remove at January 2023
     */
    public function canEnterField()
    {
        return [
            'type'              => 'yes_no_check',
            'check_label'       => __('Contacts can enter directly to this sequence point. If you enable this then any contact meet with goal will enter in this goal point.', 'fluentcampaign-pro'),
            'default_set_value' => 'yes'
        ];
    }

    public function handle($benchMark, $originalArgs)
    {
        $purchaseData = $originalArgs[0];
        $orderId = $purchaseData['order_id'];
        $subscriberData = $purchaseData['customer'];
        $subscriberData['source'] = 'surecart';

        $willProcess = $this->isMatched($benchMark, $purchaseData);

        if (!$willProcess) {
            return;
        }

        if (empty($subscriberData['email'])) {
            return;
        }

        $subscriber = FunnelHelper::createOrUpdateContact($subscriberData);

        $order = \SureCart\Models\Order::with(['checkout'])->find($orderId);

        $funnelProcessor = new FunnelProcessor();
        $funnelProcessor->startFunnelFromSequencePoint($benchMark, $subscriber, [], [
            'benchmark_value'    => intval($order->checkout->total_amount / 100), // converted to cents
            'benchmark_currency' => $order->checkout->currency,
        ]);
    }

    private function isMatched($funnel, $purchaseData)
    {
        $conditions = (array)$funnel->conditions;

        // Check the product ID conditions
        $productIds = Arr::get($conditions, 'product_ids', []);
        if ($productIds) {
            if (!array_intersect($purchaseData['product_ids'], $productIds)) {
                return false;
            }
        }

        return true;
    }
}
