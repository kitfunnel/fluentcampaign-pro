<?php

namespace FluentCampaign\App\Services\Integrations\LearnDash;

use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCampaign\App\Services\Commerce\ContactRelationItemsModel;
use FluentCrm\App\Models\FunnelSubscriber;

class LdSmartCodes
{
    public function init()
    {
        add_filter('fluent_crm/smartcode_group_callback_ld', array($this, 'parseLdCodes'), 10, 4);
        add_filter('fluent_crm/extended_smart_codes', array($this, 'pushGeneralCodes'));
        add_filter('fluent_crm_funnel_context_smart_codes', array($this, 'pushContextCodes'), 14, 2);
    }

    public function parseLdCodes($code, $valueKey, $defaultValue, $subscriber)
    {
        $userId = $subscriber->getWpUserId();

        if (!$userId) {
            return $defaultValue;
        }

        /*
         * General Student Items
         */
        switch ($valueKey) {
            case 'courses':
                $courses = ld_get_mycourses($userId);
                $coursesNames = array();
                if (!empty($courses) && is_array($courses)) {
                    foreach ($courses as $course_id) {
                        $coursesNames[] = get_the_title($course_id);
                    }
                }

                return implode(', ', $coursesNames);
            case 'courses_link':
                $courses = ld_get_mycourses($userId);
                $coursesNames = array();
                if (!empty($courses) && is_array($courses)) {
                    foreach ($courses as $course_id) {
                        $coursesNames[] = [
                            'title'     => get_the_title($course_id),
                            'permalink' => get_the_permalink($course_id)
                        ];
                    }
                }

                if (!$coursesNames) {
                    return $defaultValue;
                }

                $html = '<ul class="ld_courses">';
                foreach ($coursesNames as $coursesName) {
                    $html .= '<li><a href="' . $coursesName['permalink'] . '">' . $coursesName['title'] . '</a>';
                }
                $html .= '</ul>';
                return $html;
            case 'groups':
                $groups = learndash_get_users_group_ids($userId);
                $groupNames = array();
                if (!empty($groups) && is_array($groups)) {
                    foreach ($groups as $group) {
                        $groupNames[] = get_the_title($group);
                    }
                }

                return implode(', ', $groupNames);
            case 'groups_link':
                $groups = learndash_get_users_group_ids($userId);
                $groupNames = array();
                if (!empty($groups) && is_array($groups)) {
                    foreach ($groups as $group) {
                        $groupNames[] = [
                            'title'     => get_the_title($group),
                            'permalink' => get_the_permalink($group),
                        ];
                    }
                }

                if (!$groupNames) {
                    return $defaultValue;
                }

                $html = '<ul class="ld_groups">';
                foreach ($groupNames as $groupName) {
                    $html .= '<li><a href="' . $groupName['permalink'] . '">' . $groupName['title'] . '</a>';
                }
                $html .= '</ul>';
                return $html;
        }

        /*
         * Contextual Course / Groups Related SmartCodes
         */
        $triggerSource = false;
        $triggerId = false;

        if (!empty($subscriber->funnel_subscriber_id)) {
            $funnelSub = FunnelSubscriber::where('id', $subscriber->funnel_subscriber_id)->first();
            if ($funnelSub) {
                $triggerSource = Helper::getTriggerSource($funnelSub->source_trigger_name);
                $triggerId = $funnelSub->source_ref_id;
            }
        }

        if ($triggerSource == 'course' && !in_array($valueKey, ['course_name', 'course_href', 'course_name_linked'])) {
            $triggerId = false;
        } else if ($triggerSource == 'group' && !in_array($valueKey, ['group_name', 'group_href', 'group_name_linked'])) {
            $triggerId = false;
        }

        if (!$triggerId && !Commerce::isEnabled('learndash')) {
            return $defaultValue;
        }

        if (!$triggerId && Commerce::isEnabled('learndash')) {
            $courseItems = ['course_name', 'course_href', 'course_name_linked'];
            if (in_array($valueKey, $courseItems)) {
                $itemType = 'course';
            } else if (in_array($valueKey, ['group_name', 'group_href', 'group_name_linked'])) {
                $itemType = 'membership';
            } else {
                return $defaultValue;
            }

            $item = ContactRelationItemsModel::provider('learndash')
                ->where('subscriber_id', $subscriber->id)
                ->where('item_type', $itemType)
                ->orderBy('created_at', 'DESC')
                ->first();

            if ($item) {
                $triggerId = $item->item_id;
            }
        }

        if (!$triggerId) {
            return $defaultValue;
        }

        switch ($valueKey) {
            case 'course_name':
            case 'group_name':
            case 'topic_name':
            case 'lesson_name':
                return get_the_title($triggerId);
            case 'course_href':
            case 'group_href':
            case 'topic_href':
            case 'lesson_href':
                return get_the_permalink($triggerId);
            case 'course_name_linked':
            case 'group_name_linked':
            case 'topic_name_linked':
            case 'lesson_name_linked':
                $title = get_the_title($triggerId);
                if ($title) {
                    return '<a href="' . get_the_permalink($triggerId) . '">' . $title . '</a>';
                }
                return $defaultValue;
        }

    }

    public function pushGeneralCodes($codes)
    {
        $codes['learndash'] = [
            'key'        => 'learndash',
            'title'      => 'LearnDash',
            'shortcodes' => $this->getSmartCodes()
        ];

        return $codes;
    }

    public function pushContextCodes($codes, $context)
    {
        $triggerSource = Helper::getTriggerSource($context);
        if (!$triggerSource) {
            return $codes;
        }

        if ($triggerSource == 'course') {
            $codes[] = [
                'key'        => 'ld_course',
                'title'      => 'Enrolled Course',
                'shortcodes' => $this->getSmartCodes('course')
            ];
            return $codes;
        }

        if ($triggerSource == 'group') {
            $codes[] = [
                'key'        => 'ld_membership',
                'title'      => 'Enrolled LMS Membership',
                'shortcodes' => $this->getSmartCodes('membership')
            ];
            return $codes;
        }

        if ($triggerSource == 'lesson') {
            $codes[] = [
                'key'        => 'ld_lesson',
                'title'      => 'Completed LMS Lesson',
                'shortcodes' => $this->getSmartCodes('lesson')
            ];
            return $codes;
        }

        if ($triggerSource == 'topic') {
            $codes[] = [
                'key'        => 'ld_topic',
                'title'      => 'Completed LMS Topic',
                'shortcodes' => $this->getSmartCodes('topic')
            ];
            return $codes;
        }

        return $codes;
    }

    private function getSmartCodes($context = '')
    {
        $generalCodes = [
            '{{ld.courses}}'      => 'Enrolled Course Names (Comma Separated)',
            '{{ld.courses_link}}' => 'Enrolled Course with links (list)',
            '{{ld.groups}}'       => 'Enrolled Group Names (Comma Separated)',
            '{{ld.groups_link}}'  => 'Enrolled Groups with Links (list)'
        ];

        if (!$context) {
            return $generalCodes;
        }

        $courseContext = [
            '{{ld.course_name}}'        => 'Current Course Title',
            '{{ld.course_name_linked}}' => 'Current Course Title with Hyperlink',
            '##ld.course_href##'        => 'HTTP Link of the current course'
        ];

        $membershipContext = [
            '{{ld.group_name}}'        => 'Current Membership Title',
            '{{ld.group_name_linked}}' => 'Current Membership Title with Hyperlink',
            '##ld.group_href##'        => 'HTTP Link of the current membership'
        ];

        $lessonContext = [
            '{{ld.lesson_name}}'        => 'Current Lesson Title',
            '{{ld.lesson_name_linked}}' => 'Current Lesson Title with Hyperlink',
            '##ld.lesson_href##'        => 'HTTP Link of the current Lesson'
        ];

        $topicContext = [
            '{{ld.topic_name}}'        => 'Current Topic Title',
            '{{ld.topic_name_linked}}' => 'Current Topic Title with Hyperlink',
            '##ld.topic_href##'        => 'HTTP Link of the current Topic'
        ];

        if ($context == 'all') {
            return array_merge($generalCodes, $courseContext, $membershipContext, $lessonContext);
        } else if ($context == 'course') {
            return $courseContext;
        } else if ($context == 'membership') {
            return $membershipContext;
        } else if ($context == 'lesson') {
            return $lessonContext;
        } else if ($context == 'topic') {
            return $topicContext;
        }

        return [];

    }
}
