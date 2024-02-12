<?php

namespace FluentCampaign\App\Services\Funnel\Triggers;

use FluentCampaign\App\Services\Funnel\Conditions\FunnelConditionHelper;
use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;

class TrackingEventRecordedTrigger extends BaseTrigger
{

    public function __construct()
    {
        $this->triggerName = 'fluent_crm/event_tracked';
        $this->priority = 30;
        $this->actionArgNum = 2;
        parent::__construct();
    }

    public function getTrigger()
    {
        if (!Helper::isExperimentalEnabled('event_tracking')) {
            return false;
        }

        return [
            'category'    => __('CRM', 'fluentcampaign-pro'),
            'label'       => __('Tracking Event Recorded', 'fluentcampaign-pro'),
            'description' => __('This Funnel will be initiated a tracking event has been recorded for a contact', 'fluentcampaign-pro'),
            'icon'        => 'fc-icon-wp_new_user_signup',
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('Tracking Event Recorded', 'fluentcampaign-pro'),
            'sub_title' => __('This Funnel will be initiated a tracking event has been recorded for a contact', 'fluentcampaign-pro'),
            'fields'    => [
                'event_key'                => [
                    'label'              => __('Target Event Key', 'fluentcampaign-pro'),
                    'value'              => 'event_tracking_key',
                    'type'               => 'rest_selector',
                    'option_key'         => 'event_tracking_keys',
                    'is_multiple'        => false,
                    'custom_operators'   => [
                        'in'     => 'in',
                        'not_in' => 'not in'
                    ],
                    'creatable'          => true,
                    'experimental_cache' => true,
                    'help'               => 'Select the target event key to start this automation'
                ],
                'minimum_event_count'      => [
                    'label'       => __('Minimum Occurrence Count of the selected event', 'fluentcampaign-pro'),
                    'type'        => 'input-number',
                    'placeholder' => __('Minimum Event Count', 'fluentcampaign-pro'),
                ],
                'subscription_status_info' => [
                    'type' => 'html',
                    'info' => '<p>' . __('Please note, this trigger will start if the contact is in subscribed status. Otherwise, it will skip this automation.', 'fluentcampaign-pro') . '</p>',
                ]
            ]
        ];
    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'subscription_status' => 'subscribed',
            'event_key'           => '',
            'minimum_event_count' => 1
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'value_conditions' => [
                'type'      => 'condition_block_groups',
                'label'     => __('Advanced Event Tracking Conditions (Will check all tracking event for the contact)', 'fluentcampaign-pro'),
                'labels'    => [
                    'match_type_all_label' => __('True if all conditions match', 'fluentcampaign-pro'),
                    'match_type_any_label' => __('True if any of the conditions match', 'fluentcampaign-pro'),
                    'data_key_label'       => __('Contact Data', 'fluentcampaign-pro'),
                    'condition_label'      => __('Condition', 'fluentcampaign-pro'),
                    'data_value_label'     => __('Match Value', 'fluentcampaign-pro')
                ],
                'is_open'   => true,
                'groups'    => apply_filters('fluent_crm/event_tracking_condition_groups', []),
                'add_label' => __('Add Condition to check your event tracking properties', 'fluentcampaign-pro'),
            ],
            'run_multiple'     => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart the Automation Multiple times for a contact for this event. (Only enable if you want to restart automation for the same contact)', 'fluentcampaign-pro'),
                'inline_help' => __('If you enable, then it will restart the automation for a contact if the contact already in the automation. Please note that, if the automation status is active it will not restart.', 'fluentcampaign-pro')
            ]
        ];
    }

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'value_conditions'   => [[]],
            'run_multiple'       => 'no',
            'dont_run_on_active' => 'yes'
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        if (!Helper::isExperimentalEnabled('event_tracking')) {
            return false;
        }

        if (did_action('fluent_crm/event_tracking_automation_triggered_' . $funnel->id)) {
            return false;
        }

        $subscriber = $originalArgs[1];

        if ($subscriber->status != 'subscribed') {
            return false;
        }

        $trackedEvent = $originalArgs[0];

        $settings = $funnel->settings;

        if (Arr::get($settings, 'event_key') != $trackedEvent->event_key || $trackedEvent->counter < Arr::get($settings, 'minimum_event_count', 1)) {
            return false;
        }

        if (!$this->isProcessable($funnel, $subscriber)) {
            return false;
        }

        $subscriberData[] = [
            'email' => $subscriber->email
        ];

        do_action('fluent_crm/event_tracking_automation_triggered_' . $funnel->id, $funnel);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $trackedEvent->id
        ], $subscriber);
    }

    private function isProcessable($funnel, $subscriber)
    {
        // Check if the contact is already in the automation and in active status
        $exist = FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id);
        if ($exist) {
            if ($exist->status == 'active' || Arr::get($funnel->conditions, 'run_multiple') != 'yes') {
                return false;
            }
        }

        $conditionGroups = Arr::get($funnel->conditions, 'value_conditions', []);
        $isConditionMatched = $this->assessConditionGroups($conditionGroups, $subscriber);
        if (!$isConditionMatched) {
            return false;
        }

        if ($exist) {
            FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
        }

        return true;
    }

    private function assessConditionGroups($conditionGroups, $subscriber)
    {
        $conditionGroups = array_filter($conditionGroups);
        if (empty($conditionGroups)) {
            return true;
        }

        foreach ($conditionGroups as $conditions) {
            $result = $this->assesAutomationTriggerConditions($conditions, $subscriber);
            if ($result) {
                return true;
            }
        }

        return false;
    }

    private function assesAutomationTriggerConditions($conditions, $subscriber)
    {
        $formattedGroups = FunnelConditionHelper::formatConditionGroups($conditions);

        foreach ($formattedGroups as $groupName => $group) {
            if ($groupName == 'segment') {
                if (!FunnelConditionHelper::assessSegmentConditions($group, $subscriber)) {
                    return false;
                }
            } else if ($groupName == 'event_tracking') {
                if (!FunnelConditionHelper::assessEventTrackingConditions($group, $subscriber)) {
                    return false;
                }
            }
        }

        return true;
    }
    
}
