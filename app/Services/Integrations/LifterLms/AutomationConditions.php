<?php

namespace FluentCampaign\App\Services\Integrations\LifterLms;

use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCrm\App\Models\Subscriber;

class AutomationConditions
{
    public function init()
    {
        add_filter('fluentcrm_automation_condition_groups', array($this, 'addAutomationConditions'), 10, 1);
        add_filter('fluentcrm_automation_conditions_assess_lifterlms', array($this, 'assessAutomationConditions'), 10, 3);
    }

    public function addAutomationConditions($groups)
    {
        $membershipGroups = Helper::getMemberships();
        $formattedGroups = [];
        foreach ($membershipGroups as $group) {
            $formattedGroups[strval($group['id'])] = $group['title'];
        }

        $disabled = !Commerce::isEnabled('lifterlms');

        $groups['lifterlms'] = [
            'label'    => ($disabled) ? __('LifterLMS (Sync Required)', 'fluentcampaign-pro') : __('LifterLMS', 'fluentcampaign-pro'),
            'value'    => 'lifterlms',
            'children' => [
                [
                    'value'       => 'purchased_items',
                    'label'       => __('Enrollment Courses', 'fluentcampaign-pro'),
                    'cacheable'   => true,
                    'type'        => 'selections',
                    'component'   => 'product_selector',
                    'is_multiple' => true,
                    'disabled'    => false
                ],
                [
                    'value'             => 'is_course_completed',
                    'label'             => __('Course Completed', 'fluentcampaign-pro'),
                    'type'              => 'selections',
                    'cacheable'         => true,
                    'component'         => 'product_selector',
                    'is_multiple'       => true,
                    'is_singular_value' => true
                ],
                [
                    'value'       => 'purchased_groups',
                    'label'       => __('Enrollment Memberships', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'options',
                    'is_multiple' => true,
                    'disabled'    => false,
                    'options'     => $formattedGroups
                ],
                [
                    'value'    => 'last_order_date',
                    'label'    => __('Last Enrollment Date', 'fluentcampaign-pro'),
                    'type'     => 'dates',
                    'disabled' => $disabled
                ],
                [
                    'value'    => 'first_order_date',
                    'label'    => __('First Enrollment Date', 'fluentcampaign-pro'),
                    'type'     => 'dates',
                    'disabled' => $disabled
                ],
                [
                    'value'       => 'purchased_categories',
                    'label'       => __('Enrollment Categories', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'tax_selector',
                    'taxonomy'    => 'course_cat',
                    'is_multiple' => true,
                    'disabled'    => $disabled
                ],
                [
                    'value'       => 'purchased_tags',
                    'label'       => __('Enrollment Tags', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'tax_selector',
                    'taxonomy'    => 'course_tag',
                    'is_multiple' => true,
                    'disabled'    => $disabled
                ]
            ]
        ];

        return $groups;
    }

    public function assessAutomationConditions($result, $conditions, $subscriber)
    {
        if (Commerce::isEnabled('lifterlms')) {
            $formattedConditions = [];

            foreach ($conditions as $condition) {

                $prop = $condition['data_key'];
                $operator = $condition['operator'];
                $value = $condition['data_value'];

                if ($prop == 'is_course_completed') {
                    $isCompleted = Helper::isInCourses($value, $subscriber, true);
                    if (($operator == 'in' && !$isCompleted) || ($operator == 'not_in' && $isCompleted)) {
                        return false;
                    }
                    continue;
                }

                $formattedConditions[] = [
                    'operator' => $operator,
                    'value'    => $value,
                    'property' => $prop,
                ];
            }

            if ($formattedConditions) {
                $hasSubscriber = Subscriber::where('id', $subscriber->id)->where(function ($q) use ($formattedConditions) {
                    do_action_ref_array('fluentcrm_contacts_filter_lifterlms', [&$q, $formattedConditions]);
                })->first();
                return !!$hasSubscriber;
            }

        } else {
            foreach ($conditions as $condition) {
                $prop = $condition['data_key'];
                $value = $condition['data_value'];
                $operator = $condition['operator'];
                if ($prop == 'purchased_items') {
                    $isPurchases = Helper::isInCourses($value, $subscriber);
                    if (($operator == 'in' && !$isPurchases) || ($operator == 'not_in' && $isPurchases)) {
                        return false;
                    }
                } else if ($prop == 'purchased_groups') {
                    $isPurchases = Helper::isInActiveMembership($value, $subscriber);
                    if (($operator == 'in' && !$isPurchases) || ($operator == 'not_in' && $isPurchases)) {
                        return false;
                    }
                } else if ($prop == 'is_course_completed') {
                    $isPurchases = Helper::isInCourses($value, $subscriber, true);
                    if (($operator == 'in' && !$isPurchases) || ($operator == 'not_in' && $isPurchases)) {
                        return false;
                    }
                }
            }
        }
        return $result;
    }
}
