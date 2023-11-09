<?php

namespace FluentCampaign\App\Services\Integrations\LifterLms;

use FluentCrm\App\Services\Funnel\FunnelHelper;

class Helper
{
    public static function getCourses()
    {
        $courses = get_posts(array(
            'post_type'   => 'course',
            'numberposts' => -1
        ));

        $formattedCourses = [];
        foreach ($courses as $course) {
            $formattedCourses[] = [
                'id'    => strval($course->ID),
                'title' => $course->post_title
            ];
        }

        return $formattedCourses;
    }

    public static function getMemberships()
    {
        $courses = get_posts(array(
            'post_type'   => 'llms_membership',
            'numberposts' => -1
        ));

        $formattedCourses = [];
        foreach ($courses as $course) {
            $formattedCourses[] = [
                'id'    => strval($course->ID),
                'title' => $course->post_title
            ];
        }

        return $formattedCourses;
    }

    public static function getStudentAddress($userId)
    {
        $address = [
            'address_line_1' => get_user_meta($userId, 'llms_billing_address_1', true),
            'address_line_2' => get_user_meta($userId, 'llms_billing_address_2', true),
            'postal_code'    => get_user_meta($userId, 'llms_billing_zip', true),
            'city'           => get_user_meta($userId, 'llms_billing_city', true),
            'state'          => get_user_meta($userId, 'llms_billing_state', true),
            'country'        => get_user_meta($userId, 'llms_billing_country', true),
        ];

        return array_filter($address);
    }

    public static function getLessonsByCourseGroup()
    {
        $courses = get_posts(array(
            'post_type'   => 'course',
            'numberposts' => -1
        ));

        $groups = [];
        foreach ($courses as $course) {
            $group = [
                'title'   => $course->post_title,
                'slug'    => $course->post_name . '_' . $course->ID,
                'options' => []
            ];

            $lmsCourse = llms_get_post($course->ID);

            $lessons = $lmsCourse->get_lessons('posts');

            foreach ($lessons as $lesson) {
                $group['options'][] = [
                    'id'    => strval($lesson->ID),
                    'title' => $lesson->post_title
                ];
            }
            $groups[] = $group;
        }
        return $groups;
    }

    public static function isInCourses($courseIds, $subscriber, $requireCompleted = false)
    {
        if (!$courseIds) {
            return false;
        }

        $userId = $subscriber->getWpUserId();

        if (!$userId) {
            return false;
        }

        $student = llms_get_student($userId);

        if (!$student) {
            return false;
        }

        if ($requireCompleted) {
            $courses = $student->get_completed_courses([
                'limit'  => 10000,
                'status' => 'enrolled'
            ]);
        } else {
            $courses = $student->get_courses([
                'limit'  => 10000,
                'status' => 'enrolled'
            ]);
        }

        if (!$courses || empty($courses['results'])) {
            return false;
        }


        return !!array_intersect($courses['results'], $courseIds);

    }

    public static function getUserMemberships($userId)
    {
        $student = llms_get_student($userId);
        if (!$student) {
            return [];
        }

        $memberships = $student->get_memberships([
            'limit'  => 10000,
            'status' => 'enrolled'
        ]);

        if (!$memberships || empty($memberships['results'])) {
            return [];
        }

        return $memberships['results'];
    }

    public static function getUserCourses($userId)
    {
        $student = llms_get_student($userId);
        if (!$student) {
            return [];
        }

        $courses = $student->get_courses([
            'limit'  => 10000,
            'status' => 'enrolled'
        ]);

        if (!$courses || empty($courses['results'])) {
            return [];
        }

        return $courses['results'];
    }

    public static function isInActiveMembership($membershipIds, $subscriber)
    {
        if (!$membershipIds) {
            return false;
        }

        $userId = $subscriber->getWpUserId();
        if (!$userId) {
            return false;
        }

        $memberships = self::getUserMemberships($userId);

        return !!array_intersect($memberships, $membershipIds);
    }

    public static function createContactFromLifter($userId, $tags = [], $lists = [])
    {
        $subscriberData = FunnelHelper::prepareUserData($userId);
        if (empty($subscriberData['email']) || !is_email($subscriberData['email'])) {
            return false;
        }

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);

        if (!$subscriber) {
            $subscriberData['source'] = __('LifterLMS', 'fluentcampaign-pro');
            $subscriberData = array_merge($subscriberData, Helper::getStudentAddress($userId));
            $subscriber = FunnelHelper::createOrUpdateContact($subscriberData);
        }

        if(!$subscriber) {
            return false;
        }

        if ($tags) {
            $subscriber->attachTags($tags);
        }

        if ($lists) {
            $subscriber->attachLists($lists);
        }

        return $subscriber;
    }

    public static function getTagsCatsByCourseIds($courseIds)
    {
        $items = fluentCrmDb()->table('term_relationships')
            ->select(['term_taxonomy.term_id'])
            ->whereIn('term_relationships.object_id', $courseIds)
            ->join('term_taxonomy', 'term_taxonomy.term_taxonomy_id', '=', 'term_relationships.term_taxonomy_id')
            ->whereIn('term_taxonomy.taxonomy', ['course_tag', 'course_cat'])
            ->get();

        $formattedItems = [];

        foreach ($items as $item) {
            $formattedItems[] = $item->term_id;
        }

        return array_values(array_unique($formattedItems));
    }

    public static function getTriggerSource($triggerName)
    {
        $maps = [
            'lifterlms_course_completed'          => 'course',
            'llms_user_enrolled_in_course'        => 'course',
            'lifterlms_lesson_completed'          => 'lesson',
            'llms_user_added_to_membership_level' => 'membership'
        ];

        return isset($maps[$triggerName]) ? $maps[$triggerName] : false;
    }
}
