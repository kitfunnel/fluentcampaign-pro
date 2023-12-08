<?php

namespace FluentCampaign\App\Services\Integrations\Edd;

use FluentCrm\App\Services\Funnel\BaseBenchMark;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class EddOrderSuccessBenchmark extends BaseBenchMark
{
    public function __construct()
    {
        $this->triggerName = 'edd_update_payment_status';
        $this->actionArgNum = 3;
        $this->priority = 20;

        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'title'       => __('New Order Success', 'fluentcampaign-pro'),
            'description' => __('This will run once new order will be placed as completed in EDD', 'fluentcampaign-pro'),
            'icon'        => 'fc-icon-edd',
            'settings'    => [
                'product_ids'        => [],
                'product_categories' => [],
                'purchase_type'      => 'all',
                'type'               => 'required',
                'can_enter'          => 'yes'
            ]
        ];
    }

    public function getDefaultSettings()
    {
        return [
            'product_ids'        => [],
            'product_categories' => [],
            'purchase_type'      => 'all',
            'type'               => 'required',
            'can_enter'          => 'yes'
        ];
    }

    public function getBlockFields($funnel)
    {
        return [
            'title'     => __('New Order Success in EDD', 'fluentcampaign-pro'),
            'sub_title' => __('This will run once new order will be placed as completed in EDD', 'fluentcampaign-pro'),
            'fields'    => [
                'product_ids'        => [
                    'type'        => 'rest_selector',
                    'option_key'  => 'edd_products',
                    'is_multiple' => true,
                    'label'       => __('Target Products', 'fluentcampaign-pro'),
                    'help'        => __('Select for which products this goal will run', 'fluentcampaign-pro'),
                    'inline_help' => __('Keep it blank to run to any product purchase', 'fluentcampaign-pro')
                ],
                'product_categories' => [
                    'type'        => 'tax_selector',
                    'taxonomy'    => 'download_category',
                    'is_multiple' => true,
                    'label'       => __('Or Target Product Categories', 'fluentcampaign-pro'),
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
                'can_enter'          => $this->canEnterField()
            ]
        ];
    }

    /*
     * @todo: Remove this after January 2023
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
        $paymentId = $originalArgs[0];
        $newStatus = $originalArgs[1];
        $oldStatus = $originalArgs[2];
        $successStatuses = ['publish', 'complete', 'completed'];
        if ($newStatus == $oldStatus || !in_array($newStatus, $successStatuses)) {
            return;
        }

        $payment = edd_get_payment($paymentId);

        $conditions = (array)$benchMark->setings;

        if (!$this->isMatched($conditions, $payment)) {
            return; // It's not a match
        }

        $subscriberData = Helper::prepareSubscriberData($payment);

        if (!is_email($subscriberData['email'])) {
            return;
        }
        $subscriberData['status'] = 'subscribed';

        $subscriber = FunnelHelper::createOrUpdateContact($subscriberData);

        if (!$subscriber) {
            return false;
        }

        $funnelProcessor = new FunnelProcessor();
        $funnelProcessor->startFunnelFromSequencePoint($benchMark, $subscriber, [], [
            'benchmark_value'    => intval($payment->total * 100), // converted to cents
            'benchmark_currency' => $payment->currency,
        ]);
    }

    private function isMatched($conditions, $order)
    {
        $purchaseType = Arr::get($conditions, 'purchase_type');
        return Helper::isPurchaseTypeMatch($order, $purchaseType) && Helper::isPurchaseTypeMatch($order, $purchaseType);
    }
}
