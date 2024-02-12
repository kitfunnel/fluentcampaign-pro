<?php

namespace FluentCampaign\App\Services\Integrations\LearnDash;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class LessonCompletedTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'learndash_lesson_completed';
        $this->priority = 20;
        $this->actionArgNum = 1;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('LearnDash', 'fluentcampaign-pro'),
            'label'       => __('Lesson Completed', 'fluentcampaign-pro'),
	        'icon'        => 'fc-icon-learndash_complete_lesson',
            'description' => __('This funnel runs a student completes a lesson', 'fluentcampaign-pro')
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
            'title'     => __('Completes a Lesson', 'fluentcampaign-pro'),
            'sub_title' => __('This Funnel will start when a student completes a lesson', 'fluentcampaign-pro'),
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
                    'info'       => '<b>'.__('An Automated double-optin email will be sent for new subscribers', 'fluentcampaign-pro').'</b>',
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
            'update_type' => 'update', // skip_all_actions, skip_update_if_exist
            'course_id'   => '',
            'lesson_id'   => []
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'update_type' => [
                'type'    => 'radio',
                'label'   => __('If Contact Already Exist?', 'fluentcampaign-pro'),
                'help'    => __('Please specify what will happen if the subscriber already exist in the database', 'fluentcampaign-pro'),
                'options' => FunnelHelper::getUpdateOptions()
            ],
            'course_id'   => [
                'type'        => 'reload_rest_selector',
                'option_key'  => 'post_type',
                'sub_option_key' => 'sfwd-courses',
                'label'       => __('Target Course', 'fluentcampaign-pro'),
                'help'        => __('Select Course to find out Lesson', 'fluentcampaign-pro'),
                'inline_help' => __('You must select a course', 'fluentcampaign-pro')
            ],
            'lesson_ids'  => [
                'type'        => 'multi-select',
                'multiple'    => true,
                'label'       => __('Target Lesson', 'fluentcampaign-pro'),
                'help'        => __('Select Lesson to find out Topic', 'fluentcampaign-pro'),
                'options'     => Helper::getLessonsByCourse($funnel->conditions['course_id']),
                'inline_help' => __('Leave empty to target any lesson of this course', 'fluentcampaign-pro')
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
        $data = $originalArgs[0];

        $subscriberData = FunnelHelper::prepareUserData($data['user']);

        $subscriberData['source'] = __('LearnDash', 'fluentcampaign-pro');

        if (empty($subscriberData['email']) || !is_email($subscriberData['email'])) {
            return;
        }

        $lessonId = $data['lesson']->ID;
        $courseId = $data['course']->ID;
        $willProcess = $this->isProcessable($funnel, $courseId, $lessonId, $subscriberData);

        Helper::startProcessing($this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs, $lessonId);
    }

    private function isProcessable($funnel, $courseId, $lessonId, $subscriberData)
    {
        $conditions = $funnel->conditions;

        if (Arr::get($conditions, 'course_id') != $courseId) {
            return false;
        }

        // check the products ids
        if ($conditions['lesson_ids']) {
            if(!in_array($lessonId, $conditions['lesson_ids'])) {
                return false;
            }
        }

        // check update_type
        $updateType = Arr::get($conditions, 'update_type');

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);
        if ($subscriber && $updateType == 'skip_all_if_exist') {
            return false;
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
