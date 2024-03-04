<?php

namespace FluentCampaign\App\Services\Integrations\TutorLms;

use FluentCampaign\App\Services\Commerce\ContactRelationItemsModel;
use FluentCrm\App\Models\FunnelSubscriber;

class TutorShortCodes
{
    public function init()
    {
        add_filter('fluent_crm/smartcode_group_callback_tl', array($this, 'parseTutorCodes'), 10, 4);
        add_filter('fluent_crm/extended_smart_codes', array($this, 'pushGeneralCodes'));
        add_filter('fluent_crm_funnel_context_smart_codes', array($this, 'pushContextCodes'), 14, 2);
    }

    public function parseTutorCodes($code, $valueKey, $defaultValue, $subscriber)
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
                $courses = tutor_utils()->get_enrolled_courses_ids_by_user($userId);
                $coursesNames = array();
                if (!empty($courses) && is_array($courses)) {
                    foreach ($courses as $course_id) {
                        $coursesNames[] = get_the_title($course_id);
                    }
                }

                return implode(', ', $coursesNames);
            case 'courses_link':
                $courses = tutor_utils()->get_enrolled_courses_ids_by_user($userId);
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

                $html = '<ul class="tutor_courses">';
                foreach ($coursesNames as $coursesName) {
                    $html .= '<li><a href="' . $coursesName['permalink'] . '">' . $coursesName['title'] . '</a>';
                }
                $html .= '</ul>';
                return $html;
        }


        /*
         * Contextual Course / Topic Related SmartCodes
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

        $courseItems = ['course_name', 'course_href', 'course_name_linked'];
        if ($triggerSource == 'course' && !in_array($valueKey, $courseItems)) {
            $triggerId = false;
        }

        if (!$triggerId) {
            return $defaultValue;
        }

        if (!$triggerId) {
            if (in_array($valueKey, $courseItems)) {
                $itemType = 'course';
            } else {
                return $defaultValue;
            }

            $item = ContactRelationItemsModel::provider('tutorlms')
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
                return get_the_title($triggerId);
            case 'course_href':
                return get_the_permalink($triggerId);
            case 'course_name_linked':
                $title = get_the_title($triggerId);
                if ($title) {
                    return '<a href="' . get_the_permalink($triggerId) . '">' . $title . '</a>';
                }
                return $defaultValue;
        }
    }

    public function pushGeneralCodes($codes)
    {
        $codes['tutorlms'] = [
            'key'        => 'tutorlms',
            'title'      => 'TutorLMS',
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
                'key'        => 'tl_course',
                'title'      => 'Enrolled Course',
                'shortcodes' => $this->getSmartCodes($triggerSource)
            ];
            return $codes;
        }

        return $codes;
    }


    private function getSmartCodes($context = '')
    {
         $generalCodes = [
            '{{tl.courses}}'      => 'Enrolled Course Names (Comma Separated)',
            '{{tl.courses_link}}' => 'Enrolled Course with links (list)'
        ];


        if (!$context) {
            return $generalCodes;
        }

        $courseContext = [
            '{{tl.course_name}}'        => 'Current Course Title',
            '{{tl.course_name_linked}}' => 'Current Course Title with Hyperlink',
            '##tl.course_href##'        => 'HTTP Link of the current course'
        ];

        if ($context == 'all') {
            return array_merge($generalCodes, $courseContext);
        } else if ($context == 'course') {
            return $courseContext;
        }
        return [];
    }
}
