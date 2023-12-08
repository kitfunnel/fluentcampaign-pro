<?php

namespace FluentCampaign\App\Services\Funnel\Triggers;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class UserLoginTrigger extends BaseTrigger
{

    public function __construct()
    {
        $this->triggerName = 'wp_login';
        $this->priority = 20;
        $this->actionArgNum = 2;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('WordPress Triggers', 'fluentcampaign-pro'),
            'label'       => __('User Login', 'fluentcampaign-pro'),
            'description' => __('This Funnel will be initiated when a user login to your site', 'fluentcampaign-pro'),
	        'icon'        => 'fc-icon-wp_new_user_signup',
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('User Login', 'fluentcampaign-pro'),
            'sub_title' => __('This Funnel will be initiated when a user login to your site', 'fluentcampaign-pro'),
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

    public function getFunnelSettingsDefaults()
    {
        return [
            'subscription_status' => 'subscribed'
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'update_type'  => [
                'type'    => 'radio',
                'label'   => __('If Contact Already Exist?', 'fluentcampaign-pro'),
                'help'    => __('Please specify what will happen if the subscriber already exist in the database', 'fluentcampaign-pro'),
                'options' => FunnelHelper::getUpdateOptions()
            ],
            'user_roles'   => [
                'type'        => 'multi-select',
                'is_multiple' => true,
                'label'       => __('Targeted User Roles', 'fluentcampaign-pro'),
                'help'        => __('Select which roles registration will run this automation Funnel', 'fluentcampaign-pro'),
                'placeholder' => __('Select Roles', 'fluentcampaign-pro'),
                'options'     => FunnelHelper::getUserRoles(),
                'inline_help' => __('Leave blank to run for all user roles', 'fluentcampaign-pro')
            ],
            'run_multiple'       => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart the Automation Multiple times for a contact for this event. (Only enable if you want to restart automation for the same contact)', 'fluentcampaign-pro'),
                'inline_help' => __('If you enable, then it will restart the automation for a contact if the contact already in the automation. Otherwise, It will just skip if already exist', 'fluentcampaign-pro')
            ]
        ];
    }

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'update_type'  => 'update', // skip_all_actions, skip_update_if_exist
            'user_roles'   => [],
            'run_multiple'       => 'yes'
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $user = $originalArgs[1];

        if(!$this->isProcessable($funnel, $user)) {
            return false;
        }

        $subscriberData = FunnelHelper::prepareUserData($user);

        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);
        $subscriberData['status'] = $subscriberData['subscription_status'];
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id' => $user->ID
        ]);
    }

    private function isProcessable($funnel, $user)
    {
        $conditions = $funnel->conditions;
        // check update_type
        $updateType = Arr::get($conditions, 'update_type');

        $subscriber = FunnelHelper::getSubscriber($user->user_email);
        if ($updateType == 'skip_all_if_exist' && $subscriber) {
            return false;
        }

        // check user roles
        if ($roles = Arr::get($conditions, 'user_roles', [])) {
            $userRoles = array_values($user->roles);
            if (!array_intersect($userRoles, $roles)) {
				// user roles not matched so return false
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
