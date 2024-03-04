<?php


namespace FluentCampaign\App\Services\Integrations\LifterLms;

use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCampaign\App\Services\Commerce\ContactRelationItemsModel;
use FluentCrm\App\Models\FunnelSubscriber;

class LifterSmartCodes
{
    public function init()
    {
        add_filter('fluent_crm/smartcode_group_callback_lifter', array($this, 'parseLifterCodes'), 10, 4);
        add_filter('fluent_crm/extended_smart_codes', array($this, 'pushGeneralCodes'));
        add_filter('fluent_crm_funnel_context_smart_codes', array($this, 'pushContextCodes'), 12, 2);
    }

    public function pushGeneralCodes($codes)
    {
        $codes['lifterlms'] = [
            'key'        => 'lifterlms',
            'title'      => 'LifterLMS',
            'shortcodes' => $this->getSmartCodes()
        ];

        return $codes;
    }

    public function parseLifterCodes($code, $valueKey, $defaultValue, $subscriber)
    {
        $userId = $subscriber->getWpUserId();

        if (!$userId) {
            return $defaultValue;
        }

        $student = llms_get_student($userId);

        if (!$student) {
            return $defaultValue;
        }

        /*
         * General Student Items
         */
        switch ($valueKey) {
            case 'courses':
                $courses = $student->get_courses(['status' => 'enrolled', 'numberposts' => 10]);
                $coursesNames = array();
                if (!empty($courses['results']) && is_array($courses['results'])) {
                    foreach ($courses['results'] as $course_id) {
                        $coursesNames[] = get_the_title($course_id);
                    }
                }

                return implode(', ', $coursesNames);
            case 'courses_link':
                $courses = $student->get_courses(['status' => 'enrolled', 'numberposts' => 10]);
                $coursesNames = array();
                if (!empty($courses['results']) && is_array($courses['results'])) {
                    foreach ($courses['results'] as $course_id) {
                        $coursesNames[] = [
                            'title'     => get_the_title($course_id),
                            'permalink' => get_the_permalink($course_id)
                        ];
                    }
                }

                if (!$coursesNames) {
                    return $defaultValue;
                }

                $html = '<ul class="lifter_courses">';
                foreach ($coursesNames as $coursesName) {
                    $html .= '<li><a href="' . $coursesName['permalink'] . '">' . $coursesName['title'] . '</a>';
                }
                $html .= '</ul>';
                return $html;
            case 'groups':
                $groups = $student->get_memberships(['status' => 'enrolled']);
                $groupNames = array();
                if (!empty($groups['results']) && is_array($groups['results'])) {
                    foreach ($groups['results'] as $group) {
                        $groupNames[] = get_the_title($group);
                    }
                }

                return implode(', ', $groupNames);
            case 'groups_link':
                $groups = $student->get_memberships(['status' => 'enrolled']);
                $groupNames = array();
                if (!empty($groups['results']) && is_array($groups['results'])) {
                    foreach ($groups['results'] as $group) {
                        $groupNames[] = [
                            'title'     => get_the_title($group),
                            'permalink' => get_the_permalink($group),
                        ];
                    }
                }

                if (!$groupNames) {
                    return $defaultValue;
                }

                $html = '<ul class="lifter_groups">';
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
        } else if ($triggerSource == 'membership' && !in_array($valueKey, ['membership_name', 'membership_href', 'membership_name_linked'])) {
            $triggerId = false;
        }

        if (!$triggerId && !Commerce::isEnabled('lifterlms')) {
            return $defaultValue;
        }

        if (!$triggerId && Commerce::isEnabled('lifterlms')) {
            $courseItems = ['course_name', 'course_href', 'course_name_linked'];
            if (in_array($valueKey, $courseItems)) {
                $itemType = 'course';
            } else if (in_array($valueKey, ['membership_name', 'membership_href', 'membership_name_linked'])) {
                $itemType = 'membership';
            } else {
                return $defaultValue;
            }

            $item = ContactRelationItemsModel::provider('lifterlms')
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
            case 'membership_name':
            case 'topic_name':
            case 'lesson_name':
                return get_the_title($triggerId);
            case 'course_href':
            case 'membership_href':
            case 'lesson_href':
                return get_the_permalink($triggerId);
            case 'course_name_linked':
            case 'membership_name_linked':
            case 'lesson_name_linked':
                $title = get_the_title($triggerId);
                if ($title) {
                    return '<a href="' . get_the_permalink($triggerId) . '">' . $title . '</a>';
                }
                return $defaultValue;
        }

        return $defaultValue;

    }

    public function pushContextCodes($codes, $context)
    {
        $triggerSource = Helper::getTriggerSource($context);
        if (!$triggerSource) {
            return $codes;
        }

        if ($triggerSource == 'course') {
            $codes[] = [
                'key'        => 'lifter_course',
                'title'      => 'Enrolled Course',
                'shortcodes' => $this->getSmartCodes('course')
            ];
            return $codes;
        }

        if ($triggerSource == 'membership') {
            $codes[] = [
                'key'        => 'lifter_membership',
                'title'      => 'Enrolled LMS Membership',
                'shortcodes' => $this->getSmartCodes('membership')
            ];
            return $codes;
        }

        if ($triggerSource == 'lesson') {
            $codes[] = [
                'key'        => 'lifter_lesson',
                'title'      => 'Completed LMS Lesson',
                'shortcodes' => $this->getSmartCodes('lesson')
            ];
            return $codes;
        }

        return $codes;
    }

    private function getSmartCodes($withContext = '')
    {
        $generalCodes = [
            '{{lifter.courses}}'      => 'Enrolled Course Names (Comma Separated)',
            '{{lifter.courses_link}}' => 'Enrolled Course with links (list)',
            '{{lifter.groups}}'       => 'Enrolled Group Names (Comma Separated)',
            '{{lifter.groups_link}}'  => 'Enrolled Groups with Links (list)'
        ];

        if (!$withContext) {
            return $generalCodes;
        }

        $courseContext = [
            '{{lifter.course_name}}'        => 'Current Course Title',
            '{{lifter.course_name_linked}}' => 'Current Course Title with Hyperlink',
            '##lifter.course_href##'        => 'HTTP Link of the current course'
        ];

        $membershipContext = [
            '{{lifter.group_name}}'        => 'Current Membership Title',
            '{{lifter.group_name_linked}}' => 'Current Membership Title with Hyperlink',
            '##lifter.group_href##'        => 'HTTP Link of the current membership'
        ];

        $lessonContext = [
            '{{lifter.lesson_name}}'        => 'Current Lesson Title',
            '{{lifter.lesson_name_linked}}' => 'Current Lesson Title with Hyperlink',
            '##lifter.lesson_href##'        => 'HTTP Link of the current Lesson'
        ];

        if ($withContext == 'all') {
            return array_merge($generalCodes, $courseContext, $membershipContext, $lessonContext);
        } else if ($withContext == 'course') {
            return $courseContext;
        } else if ($withContext == 'membership') {
            return $membershipContext;
        } else if ($withContext == 'lesson') {
            return $lessonContext;
        }

        return [];

    }
}
