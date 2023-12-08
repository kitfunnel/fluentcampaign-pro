<?php

namespace FluentCampaign\App\Services\Integrations\LearnDash;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class AddToCourseAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'learndash_add_to_course';
        $this->priority = 20;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('LearnDash', 'fluentcampaign-pro'),
            'title'       => __('Enroll To Course', 'fluentcampaign-pro'),
            'description' => __('Enroll the contact to a specific LMS Course', 'fluentcampaign-pro'),
            'icon'        => 'fc-icon-learndash_enroll_course',
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
            'title'     => __('Enroll To a Course', 'fluentcampaign-pro'),
            'sub_title' => __('Enroll the contact to a specific LMS Course', 'fluentcampaign-pro'),
            'fields'    => [
                'course_id'          => [
                    'type'        => 'rest_selector',
                    'option_key'  => 'post_type',
                    'sub_option_key' => 'sfwd-courses',
                    'is_multiple' => false,
                    'clearable'   => true,
                    'label'       => __('Select Course to Enroll', 'fluentcampaign-pro'),
                    'placeholder' => __('Select Course', 'fluentcampaign-pro')
                ],
                'skip_for_public'    => [
                    'type'        => 'yes_no_check',
                    'check_label' => __('Do not enroll the course if contact is not an existing WordPress User', 'fluentcampaign-pro'),
                ],
                'send_welcome_email' => [
                    'type'        => 'yes_no_check',
                    'check_label' => __('Send default WordPress Welcome Email for new WordPress users', 'fluentcampaign-pro'),
                    'dependency'  => [
                        'depends_on' => 'skip_for_public',
                        'operator'   => '=',
                        'value'      => 'no',
                    ]
                ],
                'html'               => [
                    'type'       => 'html',
                    'info'       => __('WordPress user will be created if no user found with the contact\'s email address', 'fluentcampaign-pro'),
                    'dependency' => [
                        'depends_on' => 'skip_for_public',
                        'operator'   => '=',
                        'value'      => 'no',
                    ]
                ]
            ]
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        $settings = $sequence->settings;
        $userId = $subscriber->getWpUserId();

        if (!$userId && $settings['skip_for_public'] == 'yes') {
            $funnelMetric->notes = __('Funnel Skipped because user could not be found', 'fluentcampaign-pro');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        $courseId = Arr::get($settings, 'course_id');

        if (!$courseId) {
            $funnelMetric->notes = __('Funnel Skipped because no course found', 'fluentcampaign-pro');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        if (!$userId) {
            // If no user found then let's create a user
            $welcomeEmail = Arr::get($settings, 'send_welcome_email') == 'yes';
            $userId = FunnelHelper::createWpUserFromSubscriber($subscriber, $welcomeEmail);

            if (is_wp_error($userId)) {
                $funnelMetric->notes = $userId->get_error_message();
                $funnelMetric->save();
                FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
                return false;
            }
        }

        if (Helper::isInCourses([$courseId], $subscriber)) {
            $funnelMetric->notes = __('User already in the course', 'fluentcampaign-pro');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        $result = ld_update_course_access($userId, $courseId);

        if (!$result) {
            $funnelMetric->notes = __('User could not be enrolled to the selected course', 'fluentcampaign-pro');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        return true;
    }

}
