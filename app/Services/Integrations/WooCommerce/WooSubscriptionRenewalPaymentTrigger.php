<?php

namespace FluentCampaign\App\Services\Integrations\WooCommerce;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class WooSubscriptionRenewalPaymentTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'woocommerce_subscription_renewal_payment_complete';
        $this->priority = 22;
        $this->actionArgNum = 1;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'ribbon'      => 'subscription',
            'category'    => __('WooCommerce', 'fluentcampaign-pro'),
            'label'       => __('Renewal Payment Received', 'fluentcampaign-pro'),
            'icon'        => 'fc-icon-woo_order_complete',
            'description' => __('This funnel will start when a recurring payment received for a subscription', 'fluentcampaign-pro')
        ];
    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'subscription_status' => 'subscribed'
        ];
    }

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'product_ids'  => [],
            'run_multiple' => 'no'
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'product_ids'  => [
                'type'               => 'rest_selector',
                'option_key'         => 'woo_products',
                'sub_option_key'     => ['subscription', 'variable-subscription'],
                'experimental_cache' => true,
                'is_multiple'        => true,
                'label'              => __('Target Products', 'fluentcampaign-pro'),
                'help'               => __('Select for which subscription products this automation will run', 'fluentcampaign-pro'),
                'inline_help'        => __('Keep it blank to run to any subscription renewal payment', 'fluentcampaign-pro')
            ],
            'run_multiple' => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart the Automation Multiple times for a contact for this event. (Only enable if you want to restart automation for the same contact)', 'fluentcampaign-pro'),
                'inline_help' => __('If you enable, then it will restart the automation for a contact if the contact already in the automation. Otherwise, It will just skip if already exist', 'fluentcampaign-pro')
            ]
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('WooCommerce Renewal Payment received', 'fluentcampaign-pro'),
            'sub_title' => __('This Automation will start when a recurring payment received  for a subscription', 'fluentcampaign-pro'),
            'fields'    => [
                'subscription_status'      => [
                    'type'        => 'option_selectors',
                    'option_key'  => 'editable_statuses',
                    'is_multiple' => false,
                    'label'       => __('Contact Status', 'fluentcampaign-pro'),
                    'placeholder' => __('Select Status', 'fluentcampaign-pro')
                ],
                'subscription_status_info' => [
                    'type'       => 'html',
                    'info'       => '<b>' . __('An Automated double-optin email will be sent for new subscribers', 'fluentcampaign-pro') . '</b>',
                    'dependency' => [
                        'depends_on' => 'subscription_status',
                        'operator'   => '=',
                        'value'      => 'pending'
                    ]
                ]
            ]
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $subscription = $originalArgs[0];

        $userData = FunnelHelper::prepareUserData($subscription->get_user_id());

        if(!$userData) {
            return false;
        }

        if(!$this->isProcessable($subscription, $funnel, $userData)) {
            return false;
        }

        $subscriberData = wp_parse_args($userData, $funnel->settings);

        $subscriberData['status'] = (!empty($subscriberData['subscription_status'])) ? $subscriberData['subscription_status'] : 'subscribed';
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $subscription->get_id()
        ]);
    }

    /**
     * @param $subscription \WC_Subscription
     * @param $funnel
     * @param $userData
     * @return bool
     */
    private function isProcessable($subscription, $funnel, $userData)
    {
        $conditions = (array)$funnel->conditions;

        $productIds = [];
        /*
         * User may have other subscription active
         */
        foreach ($subscription->get_items() as $line_item) {
            $productIds[] = $line_item->get_product_id();
        }

        if(!$productIds) {
            return false;
        }

        if ($conditions['product_ids']) {
            if (!array_intersect($productIds, $conditions['product_ids'])) {
                return false; //  Not in the target products
            }
        }

        $subscriber = FunnelHelper::getSubscriber($userData['email']);

        // check run_only_one
        if ($subscriber) {
            $funnelSub = FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id);
            if ($funnelSub) {
                $multipleRun = Arr::get($conditions, 'run_multiple') == 'yes';
                if ($multipleRun) {
                    if ($funnelSub->source_ref_id == $subscription->get_id()) {
                        return false;
                    }
                    FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
                }

                return $multipleRun;
            }
        }

        return true;
    }
}
