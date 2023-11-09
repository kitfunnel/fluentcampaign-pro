<?php

namespace FluentCampaign\App\Services\Integrations\TutorLms;

use FluentCrm\App\Services\Funnel\FunnelHelper;

class Helper
{
    public static function getCourses()
    {
        $courses = get_posts(array(
            'post_type'   => 'courses',
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

    public static function getLessons()
    {
        $lessons = get_posts(array(
            'post_type'   => 'lesson',
            'numberposts' => -1
        ));

        $formattedLessons = [];
        foreach ($lessons as $lesson) {
            $formattedLessons[] = [
                'id'    => strval($lesson->ID),
                'title' => $lesson->post_title
            ];
        }

        return $formattedLessons;
    }

    public static function isInCourses($courseIds, $subscriber)
    {
        if (!$courseIds) {
            return false;
        }

        $userId = $subscriber->getWpUserId();
        if (!$userId) {
            return false;
        }

        $course = fluentCrmDb()->table('posts')
            ->where('post_type', 'tutor_enrolled')
            ->whereIn('post_parent', $courseIds)
            ->where('post_author', $userId)
            ->first();

        if ($course) {
            return true;
        }

        return false;
    }

    public static function isCoursesCompleted($courseIds, $subscriber)
    {
        if (!$courseIds) {
            return false;
        }

        $userId = $subscriber->getWpUserId();
        if (!$userId) {
            return false;
        }

        foreach ($courseIds as $courseId) {
            if (tutor_utils()->is_completed_course($courseId, $userId)) {
                return true;
            }
        }

        return false;
    }

    public static function getUserCourses($userId)
    {
        $courses = fluentCrmDb()->table('posts')
            ->select(['post_parent'])
            ->where('post_type', 'tutor_enrolled')
            ->where('post_author', $userId)
            ->get();

        $courseIds = [];
        foreach ($courses as $course) {
            $courseIds[] = $course->post_parent;
        }

        return $courseIds;
    }

    public static function createContactFromTutor($userId, $tags = [])
    {
        $subscriberData = FunnelHelper::prepareUserData($userId);
        if (empty($subscriberData['email'])) {
            return false;
        }

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);

        if (!$subscriber) {
            $subscriberData['source'] = __('TutorLMS', 'fluentcampaign-pro');
            $subscriber = FunnelHelper::createOrUpdateContact($subscriberData);
        }

        if(!$subscriber) {
            return false;
        }

        if ($tags) {
            $subscriber->attachTags($tags);
        }

        return $subscriber;
    }

    public static function getTriggerSource($triggerName)
    {
        $maps = [
            'tutor_course_complete_after' => 'course',
            'tutor_after_enrolled'     => 'course'
        ];

        return isset($maps[$triggerName]) ? $maps[$triggerName] : false;

    }
}
