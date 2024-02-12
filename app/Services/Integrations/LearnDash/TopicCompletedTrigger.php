<?php

namespace FluentCampaign\App\Services\Integrations\LearnDash;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class TopicCompletedTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'learndash_topic_completed';
        $this->priority = 20;
        $this->actionArgNum = 2;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('LearnDash', 'fluentcampaign-pro'),
            'label'       => __('Topic Completed', 'fluentcampaign-pro'),
            'description' => __('This funnel runs when a student completes a lesson topic', 'fluentcampaign-pro')
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
            'title'     => __('Completes a Topic', 'fluentcampaign-pro'),
            'sub_title' => __('This funnel will start when a user is completes a lesson topic', 'fluentcampaign-pro'),
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
            'lesson_id'   => '',
            'topic_ids'   => []
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
            'lesson_id'   => [
                'type'        => 'reload_field_selection',
                'label'       => __('Target Lesson', 'fluentcampaign-pro'),
                'help'        => __('Select Lesson to find out the available topics', 'fluentcampaign-pro'),
                'options'     => Helper::getLessonsByCourse($funnel->conditions['course_id']),
                'inline_help' => __('You must select a topic', 'fluentcampaign-pro')
            ],
            'topic_ids'   => [
                'type'        => 'multi-select',
                'label'       => __('Target Topics', 'fluentcampaign-pro'),
                'help'        => __('Select for which Topics this automation will run', 'fluentcampaign-pro'),
                'options'     => Helper::getTopicsByCourseLesson($funnel->conditions['course_id'], $funnel->conditions['lesson_id']),
                'is_multiple' => true,
                'inline_help' => __('Keep it blank to run to any Topic for that lesson', 'fluentcampaign-pro')
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
        $groupId = $originalArgs[1];

        $data = $originalArgs[0];

        $subscriberData = FunnelHelper::prepareUserData($data['user']);

        $subscriberData['source'] = __('LearnDash', 'fluentcampaign-pro');

        if (empty($subscriberData['email']) || !is_email($subscriberData['email'])) {
            return;
        }

        $lessonId = $data['lesson']->ID;
        $courseId = $data['course']->ID;
        $topicId = $data['topic']->ID;

        $willProcess = $this->isProcessable($funnel, $courseId, $lessonId, $topicId, $subscriberData);

        Helper::startProcessing($this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs, $topicId);
    }

    private function isProcessable($funnel, $courseId, $lessonId, $topicId, $subscriberData)
    {
        $conditions = $funnel->conditions;

        if (Arr::get($conditions, 'course_id') != $courseId) {
            return false;
        }

        // check the products ids
        if ($conditions['lesson_id'] != $lessonId) {
            return false;
        }

        // check the products ids
        if ($conditions['topic_ids']) {
            if (!in_array($topicId, $conditions['topic_ids'])) {
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
