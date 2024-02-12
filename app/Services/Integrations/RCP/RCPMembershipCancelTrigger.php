<?php

namespace FluentCampaign\App\Services\Integrations\RCP;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class RCPMembershipCancelTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'rcp_membership_post_cancel';
        $this->priority = 15;
        $this->actionArgNum = 2;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('Restrict Content Pro', 'fluentcampaign-pro'),
            'label'       => __('Membership cancelled', 'fluentcampaign-pro'),
	        'icon'        => 'fc-icon-rcp_membership_cancle',
            'description' => __('This funnel runs when a membership is cancelled', 'fluentcampaign-pro')
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
            'title'     => __('Membership is cancelled', 'fluentcampaign-pro'),
            'sub_title' => __('This funnel will start when a membership has been cancelled', 'fluentcampaign-pro'),
            'fields'    => [
                'subscription_status' => [
                    'type'        => 'option_selectors',
                    'option_key'  => 'editable_statuses',
                    'is_multiple' => false,
                    'label'       => __('Subscription Status', 'fluentcampaign-pro'),
                    'placeholder' => __('Select Status', 'fluentcampaign-pro')
                ],
                'subscription_status_info' => [
                    'type' => 'html',
                    'info' => '<b>'.__('An Automated double-optin email will be sent for new subscribers', 'fluentcampaign-pro').'</b>',
                    'dependency'  => [
                        'depends_on'    => 'subscription_status',
                        'operator' => '=',
                        'value'    => 'pending'
                    ]
                ]
            ]
        ];
    }

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'membership_ids'    => [],
            'run_multiple' => 'no'
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'membership_ids'    => [
                'type'        => 'multi-select',
                'label'       => __('Target Membership Levels', 'fluentcampaign-pro'),
                'help'        => __('Select for which Membership Levels this automation will run', 'fluentcampaign-pro'),
                'options'     => Helper::getMembershipLevels(),
                'inline_help' => __('Keep it blank to run to any Level Cancellation', 'fluentcampaign-pro')
            ],
            'run_multiple' => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart the Automation Multiple times for a contact for this event. (Only enable if you want to restart automation for the same contact)', 'fluentcampaign-pro'),
                'inline_help'        => __('If you enable, then it will restart the automation for a contact if the contact already in the automation. Otherwise, It will just skip if already exist', 'fluentcampaign-pro')
            ]
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $membership = $originalArgs[1];
        $memberShipId = $membership->get_object_id();

        $userId = $membership->get_user_id();

        if(!$memberShipId || !$userId) {
            return;
        }

        $subscriberData = FunnelHelper::prepareUserData($userId);

        $subscriberData['source'] = 'RCP';

        if (empty($subscriberData['email'])) {
            return;
        }

        $willProcess = $this->isProcessable($funnel, $memberShipId, $subscriberData);

        $willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs);
        if (!$willProcess) {
            return;
        }

        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);

        $subscriberData['status'] = $subscriberData['subscription_status'];
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id' => $memberShipId
        ]);

    }

    private function isProcessable($funnel, $membershipId, $subscriberData)
    {
        $conditions = $funnel->conditions;
        // check the products ids
        if ($conditions['membership_ids']) {
            if(!in_array($membershipId, $conditions['membership_ids'])) {
                return false;
            }
        }

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);

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
}
