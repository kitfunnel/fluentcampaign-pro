<?php

namespace FluentCampaign\App\Services\Integrations\Edd;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class EddRecurringExpired extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'edd_subscription_status_change';
        $this->priority = 10;
        $this->actionArgNum = 3;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('Easy Digital Downloads', 'fluentcampaign-pro'),
            'label'       => __('Recurring Subscription Expired', 'fluentcampaign-pro'),
            'description' => __('This funnel will start when a recurring subscription gets expired', 'fluentcampaign-pro'),
            'icon'        => 'el-icon-circle-close'
        ];
    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'subscription_status' => 'subscribed'
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('Recurring Subscription Expired', 'fluentcampaign-pro'),
            'sub_title' => __('This Funnel will start once a Recurring Subscription status changed to expired', 'fluentcampaign-pro'),
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

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'update_type'  => 'update', // skip_all_actions, skip_update_if_exist
            'product_ids'  => [],
            'run_multiple' => 'no'
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'update_type'  => [
                'type'    => 'radio',
                'label'   => __('If Contact Exist?', 'fluentcampaign-pro'),
                'help'    => __('Please specify what will happen if the subscriber already exist in the database', 'fluentcampaign-pro'),
                'options' => FunnelHelper::getUpdateOptions()
            ],
            'product_ids'  => [
                'type'        => 'rest_selector',
                'option_key'  => 'edd_products',
                'is_multiple' => true,
                'label'       => __('Target Products', 'fluentcampaign-pro'),
                'help'        => __('Select for which products this automation will run', 'fluentcampaign-pro'),
                'inline_help' => __('Keep it blank to run to any product purchase', 'fluentcampaign-pro')
            ],
            'run_multiple' => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart the Automation Multiple times for a contact for this event. (Only enable if you want to restart automation for the same contact)', 'fluentcampaign-pro'),
                'inline_help' => __('If you enable, then it will restart the automation for a contact if the contact already in the automation. Otherwise, It will just skip if already exist', 'fluentcampaign-pro')
            ],
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $oldStatus = $originalArgs[0];
        $newStatus = $originalArgs[1];

        if ($oldStatus == $newStatus || $newStatus != 'expired') {
            return false;
        }

        $subscription = $originalArgs[2];


        $customer = $subscription->customer;

        if (!$customer || is_wp_error($customer) || !$customer->user_id) {
            return false;
        }

        $subscriberData = FunnelHelper::prepareUserData($customer->user_id);
        $subscriberData['source'] = 'edd';

        if (empty($subscriberData['email'])) {
            return false;
        }

        // check if the customer already have a active or trialling subscription
        $exist = fluentCrmDb()->table('edd_subscriptions')
            ->where('customer_id', $subscription->customer_id)
            ->whereIn('status', ['active', 'trialling'])
            ->where('product_id', $subscription->product_id)
            ->first();

        if($exist) {
            return false; // Customer already have an active subscription of this product
        }

        $willProcess = $this->isProcessable($funnel, $subscription, $subscriberData);


        $willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs);

        if (!$willProcess) {
            return false;
        }

        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);

        $subscriberData['status'] = $subscriberData['subscription_status'];
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $subscription->id
        ]);
    }

    private function isProcessable($funnel, $subscription, $subscriberData)
    {
        $conditions = (array)$funnel->conditions;

        // check update_type
        $updateType = Arr::get($conditions, 'update_type');

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);
        if ($subscriber && $updateType == 'skip_all_if_exist') {
            return false;
        }

        if (!empty($conditions['product_ids'])) {
            if (!in_array($subscription->product_id, $conditions['product_ids'])) {
                return false;
            }
        }

        // check run_only_one
        if ($subscriber && FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id)) {
            $multipleRun = Arr::get($conditions, 'run_multiple') == 'yes';
            if ($multipleRun) {
                FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
            }
            return $multipleRun;
        }

        return true;
    }
}
