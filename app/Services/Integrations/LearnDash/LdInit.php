<?php

namespace FluentCampaign\App\Services\Integrations\LearnDash;

use FluentCrm\App\Models\Tag;
use FluentCrm\App\Services\Html\TableBuilder;
use FluentCrm\Framework\Support\Arr;

class LdInit
{
    public function init()
    {
        new \FluentCampaign\App\Services\Integrations\LearnDash\CourseEnrollTrigger();
        new \FluentCampaign\App\Services\Integrations\LearnDash\CourseLeaveTrigger();
        new \FluentCampaign\App\Services\Integrations\LearnDash\LessonCompletedTrigger();
        new \FluentCampaign\App\Services\Integrations\LearnDash\TopicCompletedTrigger();
        new \FluentCampaign\App\Services\Integrations\LearnDash\CourseCompletedTrigger();
        new \FluentCampaign\App\Services\Integrations\LearnDash\GroupEnrollTrigger();
        new \FluentCampaign\App\Services\Integrations\LearnDash\LearnDashImporter();

        // Course Actions
        new \FluentCampaign\App\Services\Integrations\LearnDash\AddToCourseAction();
        new \FluentCampaign\App\Services\Integrations\LearnDash\RemoveFromCourseAction();
        new \FluentCampaign\App\Services\Integrations\LearnDash\AddToGroupAction();
        new \FluentCampaign\App\Services\Integrations\LearnDash\RemoveFromGroupAction();

        // push profile section
        add_filter('fluentcrm_profile_sections', array($this, 'pushCoursesOnProfile'));

        add_filter('fluencrm_profile_section_ld_profile_courses', array($this, 'pushCoursesContent'), 10, 2);

        if (!apply_filters('fluentcrm_disable_integration_metaboxes', false, 'learndash')) {
            add_filter('learndash_settings_fields', array($this, 'addCourseGroupsFields'), 10, 2);

            add_action('save_post_sfwd-courses', array($this, 'saveCourseMetaBox'));

            add_action('learndash_update_course_access', array($this, 'maybeCourseEnrolledTags'), 20, 4);
            add_action('learndash_course_completed', array($this, 'maybeCourseCompletedTags'), 20);

            /*
             * Groups specific actions
             */
            add_action('save_post_groups', array($this, 'saveGroupMetaBox'));
            add_action('ld_added_group_access', array($this, 'maybeGroupEnrolledTags'), 10, 2);
            add_action('ld_removed_group_access', array($this, 'maybeGroupLeaveTagRemove'), 10, 2);
        }

        (new DeepIntegration())->init();
        (new LdSmartCodes())->init();

        add_action('learndash_update_course_access', function ($user_id, $course_id, $course_access_list, $remove) {
            if ($remove) {
                do_action('simulated_learndash_update_course_removed', $user_id, $course_id, $course_access_list, $remove);
            } else {
                do_action('simulated_learndash_update_course_added', $user_id, $course_id, $course_access_list, $remove);
            }
        }, 10, 4);

        add_filter('fluent_crm/subscriber_info_widgets', array($this, 'pushSubscriberInfoWidget'), 10, 2);
    }

    public function pushCoursesOnProfile($sections)
    {
        $sections['ld_profile_courses'] = [
            'name'    => 'fluentcrm_profile_extended',
            'title'   => __('Courses', 'fluentcampaign-pro'),
            'handler' => 'route',
            'query'   => [
                'handler' => 'ld_profile_courses'
            ]
        ];

        return $sections;
    }

    public function pushCoursesContent($content, $subscriber)
    {
        $content['heading'] = __('LearnDash Courses', 'fluentcampaign-pro');

        $userId = $subscriber->user_id;

        if (!$userId) {
            $content['content_html'] = '<p>' . __('No enrolled courses found for this contact', 'fluentcampaign-pro') . '</p>';
            return $content;
        }


        $courses = learndash_user_get_enrolled_courses($userId);


        if (empty($courses)) {
            $content['content_html'] = '<p>' . __('No enrolled courses found for this contact', 'fluentcampaign-pro') . '</p>';
            return $content;
        }

        $enrolledCourses = get_posts([
            'post_status'    => 'publish',
            'post_type'      => 'sfwd-courses',
            'posts_per_page' => 100,
            'post__in'       => $courses,
        ]);

        $tableBuilder = new TableBuilder();
        foreach ($enrolledCourses as $course) {
            $completedAt = get_user_meta($userId, 'course_completed_' . $course->ID, true);
            $startAt = get_user_meta($userId, 'course_' . $course->ID . '_access_from', true);
            $completedSteps = '2';
            $tableBuilder->addRow([
                'id'           => $course->ID,
                'title'        => $course->post_title,
                'status'       => learndash_course_status($course->ID, $userId, false),
                'completed_at' => ($completedAt) ? gmdate('Y-m-d H:i', $completedAt) : '',
                'started_at'   => ($startAt) ? gmdate('Y-m-d H:i', $startAt) : ''
            ]);
        }

        $tableBuilder->setHeader([
            'id'           => __('ID', 'fluentcampaign-pro'),
            'title'        => __('Course Name', 'fluentcampaign-pro'),
            'started_at'   => __('Started At', 'fluentcampaign-pro'),
            'status'       => __('Status', 'fluentcampaign-pro'),
            'completed_at' => __('Completed At', 'fluentcampaign-pro')
        ]);

        $content['content_html'] = $tableBuilder->getHtml();
        return $content;
    }

    public function addCourseGroupsFields($fields, $metabox_key)
    {
        if ($metabox_key == 'learndash-course-access-settings') {
            global $post;

            if (empty($post) || empty($post->ID)) {
                return $fields;
            }

            $tagSettings = wp_parse_args(get_post_meta($post->ID, '_fluentcrm_settings', true), [
                'enrolled_tags'  => [],
                'completed_tags' => []
            ]);

            $formattedTags = [];
            foreach (Tag::get() as $tag) {
                $formattedTags[$tag->id . ' '] = $tag->title; //  WE NEED A SPACE not sure why they could not handle integer as value
            }

            $fields['fcrm_enrolled_tags'] = [
                'name'      => 'fcrm_enrolled_tags',
                'label'     => __('[FluentCRM] Apply Tags on course enrollment', 'fluentcampaign-pro'),
                'type'      => 'multiselect',
                'multiple'  => true,
                'help_text' => __('Selected tags will be applied to the contact on course enrollment', 'fluentcampaign-pro'),
                'options'   => $formattedTags,
                'value'     => (array)$tagSettings['enrolled_tags'],
                'default'   => [],
            ];

            $fields['fcrm_completed_tags'] = [
                'name'          => 'fcrm_completed_tags',
                'label'         => __('[FluentCRM] Apply Tags on course completion', 'fluentcampaign-pro'),
                'type'          => 'multiselect',
                'multiple'      => true,
                'select_option' => __('Select Tags', 'fluentcampaign-pro'),
                'help_text'     => __('Selected tags will be applied to the contact on course completion', 'fluentcampaign-pro'),
                'options'       => $formattedTags,
                'value'         => (array)$tagSettings['completed_tags'],
                'default'       => [],
            ];

        } else if ($metabox_key == 'learndash-group-access-settings') {
            global $post;

            if (empty($post) || empty($post->ID)) {
                return $fields;
            }

            $tagSettings = wp_parse_args(get_post_meta($post->ID, '_fluentcrm_settings', true), [
                'fcrm_enrolled_tags'   => [],
                'fcrm_remove_on_leave' => 'no'
            ]);

            $formattedTags = [];
            foreach (Tag::get() as $tag) {
                $formattedTags[$tag->id . ' '] = $tag->title; //  WE NEED A SPACE not sure why they could not handle integer as value
            }

            $fields['fcrm_enrolled_tags'] = [
                'name'      => 'fcrm_enrolled_tags',
                'label'     => __('[FluentCRM] Apply Tags on group enrollment', 'fluentcampaign-pro'),
                'type'      => 'multiselect',
                'multiple'  => true,
                'help_text' => __('Selected tags will be applied to the contact on group enrollment', 'fluentcampaign-pro'),
                'options'   => $formattedTags,
                'value'     => (array)$tagSettings['fcrm_enrolled_tags'],
                'default'   => [],
            ];

            $fields['fcrm_remove_on_leave'] = [
                'name'      => 'fcrm_remove_on_leave',
                'label'     => __('[FluentCRM] Remove Tags on group leave', 'fluentcampaign-pro'),
                'type'      => 'checkbox-switch',
                'options'   => array(
                    'yes' => __('selected contact tags will be removed when user leave this group', 'fluentcampaign-pro'),
                    'no'  => '',
                ),
                'help_text' => __('selected contact tags (defined in previous field) will be removed when user leave this group', 'fluentcampaign-pro'),
                'value'     => $tagSettings['fcrm_remove_on_leave'],
                'default'   => '',
            ];
        }

        return $fields;

    }

    public function saveCourseMetaBox($postId)
    {
        if (empty($_POST['post_ID']) || $_POST['post_ID'] != $postId || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        if (!empty($_POST['learndash-course-access-settings'])) {
            $data = [
                'enrolled_tags'  => [],
                'completed_tags' => []
            ];

            if (!empty($_POST['learndash-course-access-settings']['fcrm_enrolled_tags'])) {
                $data['enrolled_tags'] = $_POST['learndash-course-access-settings']['fcrm_enrolled_tags'];
                unset($_POST['learndash-course-access-settings']['fcrm_enrolled_tags']);
            }

            if (!empty($_POST['learndash-course-access-settings']['fcrm_completed_tags'])) {
                $data['completed_tags'] = $_POST['learndash-course-access-settings']['fcrm_completed_tags'];
                unset($_POST['learndash-course-access-settings']['fcrm_completed_tags']);
            }

            update_post_meta($postId, '_fluentcrm_settings', $data);
        }

    }

    public function saveGroupMetaBox($postId)
    {
        if (empty($_POST['post_ID']) || !isset($_POST['post_ID']) || $_POST['post_ID'] != $postId || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !isset($_POST['post_type']) || $_POST['post_type'] != 'groups') {
            return;
        }

        if (!empty($_POST['learndash-group-access-settings'])) {

            $settings = Arr::only($_POST['learndash-group-access-settings'], ['fcrm_enrolled_tags', 'fcrm_remove_on_leave']);

            $settings = wp_parse_args($settings, [
                'fcrm_enrolled_tags'   => [],
                'fcrm_remove_on_leave' => ''
            ]);

            if (empty($settings['fcrm_enrolled_tags'])) {
                $settings['fcrm_enrolled_tags'] = [];
            }

            update_post_meta($postId, '_fluentcrm_settings', $settings);
        }
    }

    public function maybeCourseEnrolledTags($userId, $courseId, $accessList = [], $isRemoved = false)
    {

        $settings = get_post_meta($courseId, '_fluentcrm_settings', true);
        if (!$settings || empty($settings['enrolled_tags']) || !is_array($settings['enrolled_tags'])) {
            return false;
        }

        $tags = array_map(function ($tagId) {
            return intval($tagId);
        }, $settings['enrolled_tags']);

        $tags = array_filter($tags);
        if (!$tags) {
            return false;
        }

        Helper::createContactFromLd($userId, $tags);
        return true;
    }

    public function maybeGroupEnrolledTags($userId, $groupId)
    {

        $settings = get_post_meta($groupId, '_fluentcrm_settings', true);
        if (!$settings || empty($settings['fcrm_enrolled_tags']) || !is_array($settings['fcrm_enrolled_tags'])) {
            return false;
        }

        $tags = array_map(function ($tagId) {
            return intval($tagId);
        }, $settings['fcrm_enrolled_tags']);

        $tags = array_filter($tags);
        if (!$tags) {
            return false;
        }

        Helper::createContactFromLd($userId, $tags);
        return true;
    }

    public function maybeGroupLeaveTagRemove($userId, $groupId)
    {
        $settings = get_post_meta($groupId, '_fluentcrm_settings', true);
        if (!$settings || empty($settings['fcrm_enrolled_tags']) || !is_array($settings['fcrm_enrolled_tags']) || Arr::get($settings, 'fcrm_remove_on_leave') != 'yes') {
            return false;
        }

        $tagsToRemove = array_map(function ($tagId) {
            return (int)$tagId;
        }, $settings['fcrm_enrolled_tags']);

        $tagsToRemove = array_filter($tagsToRemove);
        if (!$tagsToRemove) {
            return false;
        }

        $contact = FluentCrmApi('contacts')->getContactByUserRef($userId);

        if ($contact) {
            $contact->detachTags($tagsToRemove);
            return true;
        }

        return false;
    }

    public function maybeCourseCompletedTags($data)
    {
        $settings = get_post_meta($data['course']->ID, '_fluentcrm_settings', true);
        if (!$settings || empty($settings['completed_tags']) || !is_array($settings['completed_tags'])) {
            return false;
        }

        $tags = array_map(function ($tagId) {
            return intval($tagId);
        }, $settings['completed_tags']);

        $tags = array_filter($tags);
        if (!$tags) {
            return false;
        }

        Helper::createContactFromLd($data['user'], $settings['completed_tags']);
        return true;
    }

    public function pushSubscriberInfoWidget($widgets, $subscriber)
    {
        if(!$subscriber->user_id) {
            return $widgets;
        }

        $courses = learndash_user_get_enrolled_courses($subscriber->user_id);

        if (empty($courses)) {
            return $widgets;
        }

        $enrolledCourses = get_posts([
            'post_status'    => 'publish',
            'post_type'      => 'sfwd-courses',
            'posts_per_page' => 100,
            'post__in'       => $courses,
        ]);

        if(!$enrolledCourses) {
            return $widgets;
        }

        $html = '<ul class="fc_full_listed">';

        foreach ($enrolledCourses as $course) {
            $html .= '<li><a target="_blank" rel="noopener" href="'.get_permalink($course->ID).'">'.$course->post_title.' <span class="el-tag el-tag--mini el-tag--light">'.learndash_course_status($course->ID, $subscriber->user_id, false).'</span></a></li>';
        }
        $html .= '</ul>';

        $widgets[] = [
            'title' => 'Course Enrollments',
            'content' => $html
        ];

        return $widgets;
    }
}
