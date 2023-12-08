<?php

namespace FluentCampaign\App\Services\Integrations\TutorLms;

class AutomationConditions
{
    public function init()
    {
        add_filter('fluentcrm_automation_condition_groups', array($this, 'addAutomationConditions'), 10, 1);
        add_filter('fluentcrm_automation_conditions_assess_tutorlms', array($this, 'assessAutomationConditions'), 10, 3);
        add_filter('fluentcrm_ajax_options_product_selector_tutorlms', array($this, 'getCourses'), 10, 1);
    }

    public function addAutomationConditions($groups)
    {
        $groups['tutorlms'] = [
            'label'    => __('TutorLMS', 'fluentcampaign-pro'),
            'value'    => 'tutorlms',
            'children' => [
                [
                    'value'             => 'is_in_course',
                    'label'             => __('Course Enrollment', 'fluentcampaign-pro'),
                    'type'              => 'selections',
                    'component'         => 'product_selector',
                    'is_multiple'       => true,
                    'is_singular_value' => true
                ],
                [
                    'value'             => 'is_course_completed',
                    'label'             => __('Course Completed', 'fluentcampaign-pro'),
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
        foreach ($conditions as $condition) {
            $operator = $condition['operator'];
            $courses = $condition['data_value'];
            $datKey = $condition['data_key'];

            if ($datKey == 'is_in_course') {
                $isInCourse = Helper::isInCourses($courses, $subscriber);
                if (($operator == 'in' && !$isInCourse) || ($operator == 'not_in' && $isInCourse) ) {
                    return false;
                }
            } else if($datKey == 'is_course_completed') {
                $isComplete = Helper::isCoursesCompleted($courses, $subscriber);
                if (($operator == 'in' && !$isComplete) || ($operator == 'not_in' && $isComplete) ) {
                    return false;
                }
            }
        }

        return $result;
    }

    public function getCourses($memberships)
    {
        return Helper::getCourses();
    }
}
