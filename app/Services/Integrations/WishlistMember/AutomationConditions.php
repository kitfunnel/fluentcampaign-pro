<?php

namespace FluentCampaign\App\Services\Integrations\WishlistMember;

use FluentCrm\App\Services\Libs\ConditionAssessor;

class AutomationConditions
{
    public function init()
    {
        add_filter('fluentcrm_automation_condition_groups', array($this, 'addAutomationConditions'), 10, 1);
        add_filter('fluentcrm_automation_conditions_assess_wishlist', array($this, 'assessAutomationConditions'), 10, 3);
        add_filter('fluentcrm_ajax_options_product_selector_wishlist', array($this, 'getMemberships'), 10, 1);
    }

    public function addAutomationConditions($groups)
    {
        $groups['wishlist'] = [
            'label'    => __('Wishlist Member', 'fluentcampaign-pro'),
            'value'    => 'wishlist',
            'children' => [
                [
                    'value'             => 'in_membership',
                    'label'             => __('Membership Level', 'fluentcampaign-pro'),
                    'type'              => 'selections',
                    'component'         => 'product_selector',
                    'option_key'        => 'wishlist_membership_levels',
                    'is_multiple'       => true,
                    'is_singular_value' => true
                ],
            ],
        ];
        return $groups;
    }

    public function assessAutomationConditions($result, $conditions, $subscriber)
    {
        $userId = $subscriber->getWpUserId();
        $inputValues = [];
        if (!$userId) {
            $inputValues['in_membership'] = [];
        } else {
            $levels = (array)wlmapi_get_member_levels($userId);
            $levelIds = [];

            foreach ($levels as $level) {
                if (in_array('Active', $level->Status)) {
                    $levelIds[] = $level->Level_ID;
                }
            }
            $inputValues['in_membership'] = $levelIds;
        }

        if (!ConditionAssessor::matchAllConditions($conditions, $inputValues)) {
            return false;
        }

        return $result;
    }

    public function getMemberships($memberships)
    {
        return Helper::getMembershipLevels();
    }
}
