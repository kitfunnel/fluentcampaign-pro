<?php

namespace FluentCampaign\App\Services\Integrations\Edd;

use FluentCrm\App\Services\Funnel\BaseBenchMark;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class EddSubscriptionActiveBenchmark extends BaseBenchMark
{
    public function __construct()
    {
        $this->triggerName = 'edd_subscription_status_change';
        $this->actionArgNum = 3;
        $this->priority = 20;
        
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'title'       => __('[EDD] Recurring Subscription Active', 'fluentcampaign-pro'),
            'description' => __('This will run once a subscription gets active', 'fluentcampaign-pro'),
            'icon' => 'el-icon-check',
            'settings'    => [
                'product_ids'        => [],
                'type'               => 'required',
                'can_enter' => 'yes'
            ]
        ];
    }

    public function getDefaultSettings()
    {
        return [
            'product_ids'        => [],
            'type'               => 'required',
            'can_enter' => 'yes'
        ];
    }

    public function getBlockFields($funnel)
    {
        return [
            'title'     => __('EDD Recurring Subscription Active', 'fluentcampaign-pro'),
            'sub_title' => __('This will run once a subscription gets active', 'fluentcampaign-pro'),
            'fields'    => [
                'product_ids'        => [
                    'type'        => 'rest_selector',
                    'option_key'  => 'edd_products',
                    'is_multiple' => true,
                    'label'       => __('Target Products', 'fluentcampaign-pro'),
                    'help'        => __('Select for which products this goal will run', 'fluentcampaign-pro'),
                    'inline_help' => __('Keep it blank to run to any product purchase', 'fluentcampaign-pro')
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
        $oldStatus = $originalArgs[0];
        $newStatus = $originalArgs[1];
        if ($newStatus != 'active' || $newStatus == $oldStatus) {
            return false;
        }

        $subscription = $originalArgs[2];

        $conditions = (array) $benchMark->setings;

        $customer = $subscription->customer;

        if(!$customer || !$customer->user_id) {
            return false;
        }

        if (!empty($conditions['product_ids'])) {
            if (!in_array($subscription->product_id, $conditions['product_ids'])) {
                return false;
            }
        }

        $subscriberData = FunnelHelper::prepareUserData($customer->user_id);
        $subscriberData['source'] = 'edd';

        if (!is_email($subscriberData['email'])) {
            return false;
        }
        $subscriberData['status'] = 'subscribed';

        $subscriber = FunnelHelper::createOrUpdateContact($subscriberData);

        if(!$subscriber) {
            return false;
        }

        $funnelProcessor = new FunnelProcessor();
        $funnelProcessor->startFunnelFromSequencePoint($benchMark, $subscriber, []);
    }

}
