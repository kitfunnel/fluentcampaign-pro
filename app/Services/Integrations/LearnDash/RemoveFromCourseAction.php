<?php

namespace FluentCampaign\App\Services\Integrations\LearnDash;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class RemoveFromCourseAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'learndash_remove_from_course';
        $this->priority = 20;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('LearnDash', 'fluentcampaign-pro'),
            'title'       => __('Remove From Course', 'fluentcampaign-pro'),
            'description' => __('Remove the contact from a specific LMS Course', 'fluentcampaign-pro'),
            'icon'        => 'fc-icon-learndash_complete_course',
            'settings'    => [
                'course_id'          => '',
                'skip_for_public'    => 'no',
                'send_welcome_email' => 'yes',
            ]
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Remove From Course', 'fluentcampaign-pro'),
            'sub_title' => __('Remove the contact from a specific LMS Course', 'fluentcampaign-pro'),
            'fields'    => [
                'course_id' => [
                    'type'        => 'rest_selector',
                    'option_key'  => 'post_type',
                    'sub_option_key' => 'sfwd-courses',
                    'is_multiple' => false,
                    'clearable'   => true,
                    'label'       => __('Select Course to Enroll', 'fluentcampaign-pro'),
                    'placeholder' => __('Select Course', 'fluentcampaign-pro')
                ]
            ]
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        $settings = $sequence->settings;
        $userId = $subscriber->getWpUserId();
        $courseId = Arr::get($settings, 'course_id');

        if (!$userId || !$courseId) {
            $funnelMetric->notes = __('Funnel Skipped because user/course could not be found', 'fluentcampaign-pro');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }


        if (!Helper::isInCourses([$courseId], $subscriber)) {
            $funnelMetric->notes = __('User does not have this course access', 'fluentcampaign-pro');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        $result = ld_update_course_access($userId, $courseId, true);

        if (!$result) {
            $funnelMetric->notes = __('User could not be removed from the selected course', 'fluentcampaign-pro');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        return true;
    }
}
