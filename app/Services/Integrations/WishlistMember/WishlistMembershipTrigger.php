<?php

namespace FluentCampaign\App\Services\Integrations\WishlistMember;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class WishlistMembershipTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'wishlistmember_add_user_levels';
        $this->priority = 12;
        $this->actionArgNum = 2;
        parent::__construct();
    }
    
    public function getTrigger()
    {
        return [
            'category'    => __('Wishlist Member', 'fluentcampaign-pro'),
            'label'       => __('Membership Enrolled', 'fluentcampaign-pro'),
	        'icon'        => 'fc-icon-wishlist',
            'description' => __('This funnel runs when a member is added to a membership level', 'fluentcampaign-pro')
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
            'title'     => __('A member added to a membership level', 'fluentcampaign-pro'),
            'sub_title' => __('This funnel will start when a member is added to a level', 'fluentcampaign-pro'),
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
        $userId = intval($originalArgs[0]);
        $levelIds = $originalArgs[1];

        if(empty($levelIds) || !$userId) {
            return;
        }

        $subscriberData = FunnelHelper::prepareUserData($userId);

        $subscriberData['source'] = __('Wishlist Member', 'fluentcampaign-pro');

        if (empty($subscriberData['email'])) {
            return;
        }

        $willProcess = $this->isProcessable($funnel, $levelIds, $subscriberData);

        $willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs);
        if (!$willProcess) {
            return;
        }

        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);

        $subscriberData['status'] = $subscriberData['subscription_status'];
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id' => $levelIds[0]
        ]);

    }

    private function isProcessable($funnel, $membershipIds, $subscriberData)
    {
        $conditions = $funnel->conditions;
        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);

        // check the products ids
        if ($conditions['membership_ids']) {
            if(!array_intersect($membershipIds, $conditions['membership_ids'])) {
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
