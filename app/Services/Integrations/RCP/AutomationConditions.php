<?php

namespace FluentCampaign\App\Services\Integrations\RCP;

use FluentCrm\App\Services\Libs\ConditionAssessor;

class AutomationConditions
{
    public function init()
    {
        add_filter('fluentcrm_automation_condition_groups', array($this, 'addAutomationConditions'), 10, 1);
        add_filter('fluentcrm_automation_conditions_assess_rcp', array($this, 'assessAutomationConditions'), 10, 3);
        add_filter('fluentcrm_ajax_options_product_selector_rcp', array($this, 'getMemberships'), 10, 1);
    }

    public function addAutomationConditions($groups)
    {
        $groups['rcp'] = [
            'label'    => __('RCP', 'fluentcampaign-pro'),
            'value'    => 'rcp',
            'children' => [
                [
                    'value'             => 'in_membership',
                    'label'             => __('Membership Level', 'fluentcampaign-pro'),
                    'type'              => 'selections',
                    'component'         => 'product_selector',
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
            $inputValues['in_membership'] = $this->getUserLevelIds($userId);
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

    public function getUserLevelIds($userId)
    {

        $customer = rcp_get_customer_by_user_id($userId);
        $levels = $customer->get_memberships([
            'status' => 'active'
        ]);

        if(!$levels) {
            return [];
        }

        $levelIds = [];
        foreach ($levels as $level) {
            $levelIds[] = $level->get_id();
        }

        return $levelIds;
    }
}
