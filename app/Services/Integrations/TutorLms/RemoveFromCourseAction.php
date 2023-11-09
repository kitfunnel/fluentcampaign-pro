<?php

namespace FluentCampaign\App\Services\Integrations\TutorLms;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class RemoveFromCourseAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'fcrm_turor_remove_from_course';
        $this->priority = 20;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('TutorLMS', 'fluentcampaign-pro'),
            'title'       => __('Remove From a Course', 'fluentcampaign-pro'),
            'description' => __('Remove the contact from a specific LMS Course', 'fluentcampaign-pro'),
            'icon'        => 'fc-icon-remove_from_course_lms',
            'settings'    => [
                'course_id' => ''
            ]
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Remove From a Course', 'fluentcampaign-pro'),
            'sub_title' => __('Remove the contact from a specific LMS Course', 'fluentcampaign-pro'),
            'fields'    => [
                'course_id' => [
                    'type'        => 'rest_selector',
                    'option_key'  => 'product_selector_tutorlms',
                    'is_multiple' => false,
                    'clearable'   => true,
                    'label'       => __('Select a course that you want to remove from', 'fluentcampaign-pro'),
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

        if (!$userId) {
            $funnelMetric->notes = __('Funnel Skipped because user could not be found', 'fluentcampaign-pro');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }
        tutor_utils()->cancel_course_enrol($courseId, $userId, 'delete');

        return true;
    }
}
