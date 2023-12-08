<?php

namespace FluentCampaign\App\Services\Integrations\WooCommerce;

use FluentCrm\App\Services\Funnel\BaseBenchMark;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class WooOrderSuccessBenchmark extends BaseBenchMark
{
    public function __construct()
    {
        $this->triggerName = 'woocommerce_order_status_processing';
        $this->actionArgNum = 2;
        $this->priority = 20;

        parent::__construct();
        add_action('fluentcrm_funnel_benchmark_start_woocommerce_order_status_completed', array($this, 'handle'), 10, 2);
    }

    public function getBlock()
    {
        return [
            'title'       => __('Order Received in WooCommerce', 'fluentcampaign-pro'),
            'description' => __('This will run once new order has been placed as processing', 'fluentcampaign-pro'),
            'icon' => 'fc-icon-woo_purchased',
            'settings'    => [
                'product_ids'        => [],
                'product_categories' => [],
                'purchase_type'      => 'all',
                'type'               => 'required',
                'can_enter' => 'yes'
            ]
        ];
    }

    public function getDefaultSettings()
    {
        return [
            'product_ids'        => [],
            'product_categories' => [],
            'purchase_type'      => '',
            'type'               => 'required',
            'can_enter' => 'yes'
        ];
    }

    public function getBlockFields($funnel)
    {
        return [
            'title'     => __('Order Received in WooCommerce', 'fluentcampaign-pro'),
            'sub_title' => __('This will run once new order will be placed as processing', 'fluentcampaign-pro'),
            'fields'    => [
                'product_ids'        => [
                    'type'        => 'rest_selector',
                    'option_key'  => 'woo_products',
                    'is_multiple'    => true,
                    'label'       => __('Target Products', 'fluentcampaign-pro'),
                    'help'        => __('Select for which products this goal will run', 'fluentcampaign-pro'),
                    'inline_help' => __('Keep it blank to run to any product purchase', 'fluentcampaign-pro')
                ],
                'product_categories' => [
                    'type'        => 'rest_selector',
                    'option_key'  => 'woo_categories',
                    'is_multiple'    => true,
                    'label'       => __('OR Target Product Categories', 'fluentcampaign-pro'),
                    'help'        => __('Select for which product category the goal will run', 'fluentcampaign-pro'),
                    'inline_help' => __('Keep it blank to run to any category products', 'fluentcampaign-pro')
                ],
                'purchase_type'      => [
                    'type'        => 'radio',
                    'label'       => __('Purchase Type', 'fluentcampaign-pro'),
                    'help'        => __('Select the purchase type', 'fluentcampaign-pro'),
                    'options'     => Helper::purchaseTypeOptions(),
                    'inline_help' => __('For what type of purchase you want to run this goal', 'fluentcampaign-pro')
                ],
                'type'               => $this->benchmarkTypeField(),
                'can_enter' => $this->canEnterField()
            ]
        ];
    }

    /*
     * @todo: remove at January 2023
     */
    public function canEnterField()
    {
        return [
            'type'        => 'yes_no_check',
            'check_label' => __('Contacts can enter directly to this sequence point. If you enable this then any contact meet with goal will enter in this goal point.', 'fluentcampaign-pro'),
            'default_set_value' => 'yes'
        ];
    }

    public function handle($benchMark, $originalArgs)
    {
        $order = $originalArgs[1];
        $conditions = $benchMark->settings;


        if (!$this->isMatched($conditions, $order)) {
            return; // It's not a match
        }

        $subscriberData = Helper::prepareSubscriberData($order);

        $subscriberData = FunnelHelper::maybeExplodeFullName($subscriberData);

        if (!is_email($subscriberData['email'])) {
            return;
        }

        $subscriberData['status'] = 'subscribed';

        $subscriber = FunnelHelper::createOrUpdateContact($subscriberData);

        $funnelProcessor = new FunnelProcessor();
        $funnelProcessor->startFunnelFromSequencePoint($benchMark, $subscriber, [], [
            'benchmark_value'    => intval($order->get_total() * 100), // converted to cents
            'benchmark_currency' => $order->get_currency(),
        ]);
    }

    private function isMatched($conditions, $order)
    {
        $purchaseType = Arr::get($conditions, 'purchase_type');
        return Helper::isPurchaseTypeMatch($order, $purchaseType) && Helper::isProductIdCategoryMatched($order, $conditions);
    }
}
