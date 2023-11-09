<?php

namespace FluentCampaign\App\Services\Integrations\PMPro;

use FluentCrm\App\Services\Libs\ConditionAssessor;

class AutomationConditions
{
    public function init()
    {
        add_filter('fluentcrm_automation_condition_groups', array($this, 'addAutomationConditions'), 10, 1);
        add_filter('fluentcrm_automation_conditions_assess_pmpro', array($this, 'assessAutomationConditions'), 10, 3);
        add_filter('fluentcrm_ajax_options_product_selector_pmpro', array($this, 'getMemberships'), 10, 1);
    }

    public function addAutomationConditions($groups)
    {
        $groups['pmpro'] = [
            'label'    => __('Paid Membership Pro', 'fluentcampaign-pro'),
            'value'    => 'pmpro',
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
            $levels = (array)pmpro_getMembershipLevelsForUser($userId);
            $levelIds = [];

            foreach ($levels as $level) {
                $levelIds[] = $level->id;
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
