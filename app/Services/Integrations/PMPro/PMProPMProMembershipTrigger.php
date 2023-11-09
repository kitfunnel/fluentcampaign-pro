<?php

namespace FluentCampaign\App\Services\Integrations\PMPro;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class PMProPMProMembershipTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'pmpro_after_change_membership_level';
        $this->priority = 11;
        $this->actionArgNum = 3;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('Paid Membership Pro', 'fluentcampaign-pro'),
            'label'       => __('Membership Level assignment of a User', 'fluentcampaign-pro'),
	        'icon'        => 'fc-icon-paid_membership_pro_user_level',
            'description' => __('This funnel will start when a user is assigned to specified membership levels', 'fluentcampaign-pro')
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
            'title'     => __('Enrollment in a Membership Level in PMPro', 'fluentcampaign-pro'),
            'sub_title' => __('This funnel will start when an user is enrolled in Membership Levels', 'fluentcampaign-pro'),
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
            'update_type'   => 'update', // skip_all_actions, skip_update_if_exist
            'membership_ids'    => [],
            'run_multiple' => 'no'
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'update_type'   => [
                'type'    => 'radio',
                'label'   => __('If Contact Already Exist?', 'fluentcampaign-pro'),
                'help'    => __('Please specify what will happen if the subscriber already exist in the database', 'fluentcampaign-pro'),
                'options' => FunnelHelper::getUpdateOptions()
            ],
            'membership_ids'    => [
                'type'        => 'multi-select',
                'label'       => __('Target Membership Levels', 'fluentcampaign-pro'),
                'help'        => __('Select for which Membership Levels this automation will run', 'fluentcampaign-pro'),
                'options'     => Helper::getMembershipLevels(),
                'inline_help' => __('Keep it blank to run to any Level Enrollment', 'fluentcampaign-pro')
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
        $levelId = intval($originalArgs[0]);
        $userId = $originalArgs[1];

        if(empty($levelId)) {
            return;
        }

        $subscriberData = FunnelHelper::prepareUserData($userId);

        $subscriberData['source'] = 'PMPro';

        if (empty($subscriberData['email'])) {
            return;
        }

        $willProcess = $this->isProcessable($funnel, $levelId, $subscriberData);


        $willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs);
        if (!$willProcess) {
            return;
        }

        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);

        $subscriberData['status'] = $subscriberData['subscription_status'];
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id' => $levelId
        ]);

    }

    private function isProcessable($funnel, $membershipId, $subscriberData)
    {
        $conditions = $funnel->conditions;
        // check update_type
        $updateType = Arr::get($conditions, 'update_type');

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);
        if ($subscriber && $updateType == 'skip_all_if_exist') {
            return false;
        }

        // check the products ids
        if ($conditions['membership_ids']) {
            if( !in_array($membershipId, $conditions['membership_ids']) ) {
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
}
