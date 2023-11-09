<?php

namespace FluentCampaign\App\Services\Integrations\LearnDash;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class CourseEnrollTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'simulated_learndash_update_course_added';
        $this->priority = 15;
        $this->actionArgNum = 4;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('LearnDash', 'fluentcampaign-pro'),
            'label'       => __('Enrolls in a Course', 'fluentcampaign-pro'),
	        'icon'        => 'fc-icon-learndash_enroll_course',
            'description' => __('This funnel will start when a student is enrolled in a course', 'fluentcampaign-pro')
        ];
    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'lists'               => [],
            'tags'                => [],
            'subscription_status' => 'subscribed'
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('Enrolls in a Course', 'fluentcampaign-pro'),
            'sub_title' => __('This Funnel will start when a student is enrolled in a course', 'fluentcampaign-pro'),
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
            'update_type' => 'update', // skip_all_actions, skip_update_if_exist
            'course_ids'  => [],
            'run_multiple' => 'no'
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
            'course_ids'  => [
                'type'        => 'rest_selector',
                'option_key'  => 'post_type',
                'sub_option_key' => 'sfwd-courses',
                'is_multiple' => true,
                'label'       => __('Target Courses', 'fluentcampaign-pro'),
                'help'        => __('Select for which Courses this automation will run', 'fluentcampaign-pro'),
                'inline_help' => __('Keep it blank to run to any Course Enrollment', 'fluentcampaign-pro')
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
        $userId = $originalArgs[0];
        $courseId = $originalArgs[1];
        $isRemoved = $originalArgs[3];

        if ($isRemoved) {
            return false;
        }


        $subscriberData = FunnelHelper::prepareUserData($userId);

        $subscriberData['source'] = 'LearnDash';

        if (empty($subscriberData['email']) || !is_email($subscriberData['email'])) {
            return;
        }

        $willProcess = $this->isProcessable($funnel, $courseId, $subscriberData);

        Helper::startProcessing($this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs, $courseId);
    }

    private function isProcessable($funnel, $courseId, $subscriberData)
    {
        $conditions = $funnel->conditions;
        // check update_type
        $updateType = Arr::get($conditions, 'update_type');

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);
        if ($subscriber && $updateType == 'skip_all_if_exist') {
            return false;
        }

        // check the products ids
        if ($conditions['course_ids']) {
            $conditionMeta = in_array($courseId, $conditions['course_ids']);
            if(!$conditionMeta) {
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
