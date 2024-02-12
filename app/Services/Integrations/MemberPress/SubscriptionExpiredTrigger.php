<?php

namespace FluentCampaign\App\Services\Integrations\MemberPress;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class SubscriptionExpiredTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'mepr-event-transaction-expired';
        $this->priority = 20;
        $this->actionArgNum = 1;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('MemberPress', 'fluentcampaign-pro'),
            'label'       => __('Subscription Expired', 'fluentcampaign-pro'),
            'description' => __('This funnel runs when a subscription expires', 'fluentcampaign-pro')
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
            'title'     => __('A Subscription expired', 'fluentcampaign-pro'),
            'sub_title' => __('This funnel will start when a subscription has been expired', 'fluentcampaign-pro'),
            'fields'    => [
                'subscription_status'      => [
                    'type'        => 'option_selectors',
                    'option_key'  => 'editable_statuses',
                    'is_multiple' => false,
                    'label'       => __('Subscription Status', 'fluentcampaign-pro'),
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
            'membership_ids' => [],
            'run_multiple'   => 'no'
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'membership_ids' => [
                'type'        => 'multi-select',
                'label'       => __('Target Membership Levels', 'fluentcampaign-pro'),
                'help'        => __('Select for which Membership Levels this automation will run', 'fluentcampaign-pro'),
                'options'     => $this->getMembershipLevels(),
                'inline_help' => __('Keep it blank to run to any subscription cancellation', 'fluentcampaign-pro')
            ],
            'run_multiple'   => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart the Automation Multiple times for a contact for this event. (Only enable if you want to restart automation for the same contact)', 'fluentcampaign-pro'),
                'inline_help' => __('If you enable, then it will restart the automation for a contact if the contact already in the automation. Otherwise, It will just skip if already exist', 'fluentcampaign-pro')
            ]
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $event = $originalArgs[0];

        $txn = $event->get_data();

        $subscription = $txn->subscription();

        if (strtotime($txn->expires_at) <= time() && (empty($subscription) || $subscription->is_expired())) {

            $productId = $txn->product_id;
            $userId = $txn->user_id;

            if (!$productId || !$userId) {
                return;
            }

            $subscriberData = FunnelHelper::prepareUserData($userId);

            $subscriberData['source'] = __('MemberPress', 'fluentcampaign-pro');

            if (empty($subscriberData['email'])) {
                return;
            }

            $willProcess = $this->isProcessable($funnel, $productId, $subscriberData);

            $willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs);

            if (!$willProcess) {
                return;
            }

            $subscriberData = wp_parse_args($subscriberData, $funnel->settings);

            $subscriberData['status'] = $subscriberData['subscription_status'];
            unset($subscriberData['subscription_status']);

            (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
                'source_trigger_name' => $this->triggerName,
                'source_ref_id'       => $productId
            ]);
        }
    }

    private function isProcessable($funnel, $membershipId, $subscriberData)
    {
        $conditions = $funnel->conditions;
        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);

        // check the products ids
        if ($conditions['membership_ids']) {
            if (!in_array($membershipId, $conditions['membership_ids'])) {
                return false;
            }
        }

        // check run_only_one
        if ($subscriber && FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id)) {
            $multipleRun = Arr::get($conditions, 'run_multiple') == 'yes';
            if ($multipleRun) {
                FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
            } else {
                return false;
            }
        }

        return true;
    }

    private function getMembershipLevels()
    {
        $levels = \MeprCptModel::all('MeprProduct');
        $formattedLevels = [];
        foreach ($levels as $level) {
            $formattedLevels[] = [
                'id'    => strval($level->ID),
                'title' => $level->post_title
            ];
        }

        return $formattedLevels;
    }

}
