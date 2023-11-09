<?php

namespace FluentCampaign\App\Services\Integrations\TutorLms;

use FluentCampaign\App\Services\MetaFormBuilder;
use FluentCrm\App\Models\Tag;
use FluentCrm\App\Services\Html\TableBuilder;

class TutorLmsInit
{
    public function init()
    {
        new CourseEnrollTrigger();
        new CourseCompletedTrigger();
        new LessonCompletedTrigger();

        new TutorImporter();
        (new DeepIntegration())->init();
        (new AutomationConditions())->init();

        // Course Actions
        new AddToCourseAction();
        new RemoveFromCourseAction();

        // push profile section
        add_filter('fluentcrm_profile_sections', array($this, 'pushCoursesOnProfile'));
        add_filter('fluencrm_profile_section_tutor_profile_courses', array($this, 'pushCoursesContent'), 10, 2);
        
        if (!apply_filters('fluentcrm_disable_integration_metaboxes', false, 'tutorlms')) {
            /*
            * Course metabox and settings
            */
            add_action('add_meta_boxes', array($this, 'addCourseMetaBox'), 10);
            add_action( 'save_post_courses', array( $this, 'saveCourseMetaBox' ) );

            add_action('tutor_course_complete_after', array($this, 'maybeCourseCompletedTags'), 50, 2);
            add_action('tutor_after_enrolled', array($this, 'maybeCourseEnrolledTags'), 50, 2);
        }

        add_filter('fluentcrm_ajax_options_product_selector_tutorlms', array($this, 'getCourseOptions'), 10, 3);

        (new TutorShortCodes())->init();

        add_filter('fluent_crm/subscriber_info_widgets', array($this, 'pushSubscriberInfoWidget'), 10, 2);

    }

    public function pushCoursesOnProfile($sections)
    {
        $sections['tutor_profile_courses'] = [
            'name'    => 'fluentcrm_profile_extended',
            'title'   => __('Courses', 'fluentcampaign-pro'),
            'handler' => 'route',
            'query'   => [
                'handler' => 'tutor_profile_courses'
            ]
        ];

        return $sections;
    }

    public function pushCoursesContent($content, $subscriber)
    {
        $content['heading'] = __('TutorLMS Courses', 'fluentcampaign-pro');

        $userId = $subscriber->user_id;

        if (!$userId) {
            $content['content_html'] = '<p>'.__('No enrolled courses found for this contact', 'fluentcampaign-pro').'</p>';
            return $content;
        }

        $courseIds = tutor_utils()->get_enrolled_courses_ids_by_user($userId);

        if (empty($courseIds)) {
            $content['content_html'] = '<p>'.__('No enrolled courses found for this contact', 'fluentcampaign-pro').'</p>';
            return $content;
        }

        $enrolledCourses = get_posts([
            'post_type'      => tutor()->course_post_type,
            'posts_per_page' => 100,
            'post__in'       => $courseIds,
        ]);

        $tableBuilder = new TableBuilder();
        foreach ($enrolledCourses as $course) {
            $enrolled = fluentCrmDb()->table('posts')
                ->where('post_parent', $course->ID)
                ->where('post_author', $userId)
                ->where('post_type', 'tutor_enrolled')
                ->first();

            $completed_count = tutor_utils()->get_course_completed_percent($course->ID, $userId);

            $tableBuilder->addRow([
                'id'         => $course->ID,
                'title'      => $course->post_title,
                'progress'   => $completed_count . '%',
                'started_at' => date_i18n(get_option('date_format'), strtotime($enrolled->post_date)),
            ]);
        }

        $tableBuilder->setHeader([
            'id'         => __('ID', 'fluentcampaign-pro'),
            'title'      => __('Course Name', 'fluentcampaign-pro'),
            'started_at' => __('Started At', 'fluentcampaign-pro'),
            'progress'   => __('Progress', 'fluentcampaign-pro')
        ]);

        $content['content_html'] = $tableBuilder->getHtml();
        return $content;
    }

    public function addCourseMetaBox($post_id)
    {
        add_meta_box('fcrm_course_tags', __('FluentCRM - Course Tags', 'fluentcampaign-pro'), function ($post) {

            $tagSettings = wp_parse_args(get_post_meta($post->ID, '_fluentcrm_settings', true), [
                'enrolled_tags'  => [],
                'completed_tags' => []
            ]);

            $formattedTags = [];
            foreach (Tag::get() as $tag) {
                $formattedTags[] = [
                    'key'   => $tag->id,
                    'title' => $tag->title
                ];
            }

            $formBuilder = new MetaFormBuilder();
            $formBuilder->addField([
                'class'           => 'tutor_select2',
                'data_attributes' => array(
                    'data-placeholder' => __('Select Tags', 'fluentcampaign-pro'),
                ),
                'desc'            => __('Selected tags will be applied to the contact on course enrollment.', 'fluentcampaign-pro'),
                'name'            => '_fluentcrm_settings[enrolled_tags][]',
                'label'           => __('Apply Tags on course enrollment', 'fluentcampaign-pro'),
                'multi'           => true,
                'type'            => 'select',
                'options'         => $formattedTags,
                'value'           => $tagSettings['enrolled_tags'],
            ]);

            $formBuilder->addField([
                'class'           => 'tutor_select2',
                'data_attributes' => array(
                    'data-placeholder' => __('Select Tags', 'fluentcampaign-pro'),
                ),
                'desc'            => __('Selected tags will be applied to the contact on course completion.', 'fluentcampaign-pro'),
                'name'            => '_fluentcrm_settings[completed_tags][]',
                'label'           => __('Apply Tags on course completion', 'fluentcampaign-pro'),
                'multi'           => true,
                'type'            => 'select',
                'options'         => $formattedTags,
                'value'           => $tagSettings['completed_tags'],
            ]);

            $formBuilder->renderFields();

        }, 'courses');
    }

    public function saveCourseMetaBox($postId)
    {
        $settings = [
            'enrolled_tags'  => [],
            'completed_tags' => []
        ];

        if (isset($_POST['_fluentcrm_settings'])) {
            $settings = $_REQUEST['_fluentcrm_settings'];
        }

        update_post_meta($postId, '_fluentcrm_settings', $settings);
    }

    public function maybeCourseEnrolledTags($courseId, $userId)
    {
        $settings = get_post_meta($courseId, '_fluentcrm_settings', true);
        if (!$settings || empty($settings['enrolled_tags']) || !is_array($settings['enrolled_tags'])) {
            return false;
        }
        Helper::createContactFromTutor($userId, $settings['enrolled_tags']);
        return true;
    }

    public function maybeCourseCompletedTags($courseId, $userId)
    {
        $settings = get_post_meta($courseId, '_fluentcrm_settings', true);
        if (!$settings || empty($settings['completed_tags']) || !is_array($settings['completed_tags'])) {
            return false;
        }

        Helper::createContactFromTutor($userId, $settings['completed_tags']);
        return true;
    }

    public function getCourseOptions($courses, $search, $includeIds)
    {
        return Helper::getCourses();
    }

    public function pushSubscriberInfoWidget($widgets, $subscriber)
    {
        if(!$subscriber->user_id) {
            return $widgets;
        }

        $userId = $subscriber->user_id;

        $courseIds = tutor_utils()->get_enrolled_courses_ids_by_user($userId);

        if (empty($courseIds)) {
            return $widgets;
        }

        $enrolledCourses = get_posts([
            'post_type'      => tutor()->course_post_type,
            'posts_per_page' => 100,
            'post__in'       => $courseIds,
        ]);

        if(!$enrolledCourses) {
            return $widgets;
        }

        $html = '<ul class="fc_full_listed">';
        foreach ($enrolledCourses as $course) {
            $html .= '<li><a target="_blank" rel="noopener" href="'.get_permalink($course->ID).'">'.$course->post_title.'</a></li>';
        }
        $html .= '</ul>';

        $widgets[] = [
            'title' => 'Course Enrollments',
            'content' => $html
        ];

        return $widgets;
    }

}
