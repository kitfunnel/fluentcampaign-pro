<?php

namespace FluentCampaign\App\Services\Integrations\LifterLms;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class LessonCompletedTrigger extends BaseTrigger
{
    public function __construct()
    {
        if (!defined('LLMS_PLUGIN_FILE')) {
            return;
        }

        $this->triggerName = 'lifterlms_lesson_completed';
        $this->priority = 12;
        $this->actionArgNum = 2;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('LifterLMS', 'fluentcampaign-pro'),
            'label'       => __('Lesson Completed', 'fluentcampaign-pro'),
	        'icon'        => 'fc-icon-lifter_lms_complete_lession-t2',
            'description' => __('This funnel runs when a student completes a lesson', 'fluentcampaign-pro')
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
            'title'     => __('Student completes a Lesson in LifterLMS', 'fluentcampaign-pro'),
            'sub_title' => __('This Funnel will start when a student completes a lesson', 'fluentcampaign-pro'),
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
            'lesson_ids'    => []
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
            'lesson_ids'    => [
                'type'        => 'grouped-select',
                'label'       => __('Target Lessons', 'fluentcampaign-pro'),
                'help'        => __('Select for which Lessons this automation will run', 'fluentcampaign-pro'),
                'options'     => Helper::getLessonsByCourseGroup(),
                'is_multiple' => true,
                'inline_help' => __('Keep it blank to run to any Lesson', 'fluentcampaign-pro')
            ],
            'run_multiple'       => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart the Automation Multiple times for a contact for this event. (Only enable if you want to restart automation for the same contact)', 'fluentcampaign-pro'),
                'inline_help' => __('If you enable, then it will restart the automation for a contact if the contact already in the automation. Otherwise, It will just skip if already exist', 'fluentcampaign-pro')
            ]
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $userId = $originalArgs[0];
        $lessonId = $originalArgs[1];

        $subscriberData = FunnelHelper::prepareUserData($userId);

        $subscriberData['source'] = __('LifterLMS', 'fluentcampaign-pro');

        $subscriberData = array_merge($subscriberData, Helper::getStudentAddress($userId));

        if (empty($subscriberData['email'])) {
            return;
        }

        $willProcess = $this->isProcessable($funnel, $lessonId, $subscriberData);

        $willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs);
        if (!$willProcess) {
            return;
        }

        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);

        $subscriberData['status'] = $subscriberData['subscription_status'];
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id' => $lessonId
        ]);

    }

    private function isProcessable($funnel, $lessonId, $subscriberData)
    {
        $conditions = $funnel->conditions;
        // check update_type
        $updateType = Arr::get($conditions, 'update_type');

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);
        if ($subscriber && $updateType == 'skip_all_if_exist') {
            return false;
        }

        // check the products ids
        if ($conditions['lesson_ids']) {
            return in_array($lessonId, $conditions['lesson_ids']);
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
