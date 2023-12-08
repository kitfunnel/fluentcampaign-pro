<?php

namespace FluentCampaign\App\Services\Integrations\LearnDash;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class RemoveFromGroupAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'learndash_remove_from_group';
        $this->priority = 20;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('LearnDash', 'fluentcampaign-pro'),
            'title'       => __('Remove From Group', 'fluentcampaign-pro'),
            'description' => __('Remove the contact from a specific LMS Group', 'fluentcampaign-pro'),
            'icon'        => 'fc-icon-learndash_course_group',
            'settings'    => [
                'group_id'           => ''
            ]
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Remove From a Group', 'fluentcampaign-pro'),
            'sub_title' => __('Remove the contact from a specific LMS Group', 'fluentcampaign-pro'),
            'fields'    => [
                'group_id'           => [
                    'type'        => 'rest_selector',
                    'option_key'  => 'post_type',
                    'sub_option_key' => 'groups',
                    'is_multiple' => false,
                    'clearable'   => true,
                    'label'       => __('Select LearnDash Group to un-enroll contact', 'fluentcampaign-pro'),
                    'placeholder' => __('Select LearnDash Group', 'fluentcampaign-pro')
                ]
            ]
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        $settings = $sequence->settings;
        $userId = $subscriber->getWpUserId();
        $groupId = Arr::get($settings, 'group_id');

        if (!$userId || !$groupId) {
            $funnelMetric->notes = __('Funnel Skipped because user/group could not be found', 'fluentcampaign-pro');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        if (get_user_meta($userId, 'learndash_group_users_' . $groupId)) {
            ld_update_group_access($userId, $groupId, true);
            return true;
        }

        return false;
    }

}
