<?php

namespace FluentCampaign\App\Services\Integrations\LifterLms;

use FluentCrm\App\Models\Tag;
use FluentCrm\App\Services\Html\TableBuilder;

class LifterInit
{
    public function init()
    {
        new \FluentCampaign\App\Services\Integrations\LifterLms\CourseEnrollTrigger();
        new \FluentCampaign\App\Services\Integrations\LifterLms\MembershipEnrollTrigger();
        new \FluentCampaign\App\Services\Integrations\LifterLms\LessonCompletedTrigger();
        new \FluentCampaign\App\Services\Integrations\LifterLms\CourseCompletedTrigger();
        new \FluentCampaign\App\Services\Integrations\LifterLms\LifterImporter();

        // Actions
        new \FluentCampaign\App\Services\Integrations\LifterLms\AddToCourseAction();
        new \FluentCampaign\App\Services\Integrations\LifterLms\RemoveFromCourseAction();
        new \FluentCampaign\App\Services\Integrations\LifterLms\AddToMembershipAction();
        new \FluentCampaign\App\Services\Integrations\LifterLms\RemoveFromMembershipAction();

        // push profile section
        add_filter('fluentcrm_profile_sections', array($this, 'pushCoursesOnProfile'));

        add_filter('fluencrm_profile_section_lifter_profile_courses', array($this, 'pushCoursesContent'), 10, 2);


        if (!apply_filters('fluentcrm_disable_integration_metaboxes', false, 'lifterlms')) {
            /*
             * Course
             */
            add_filter('llms_metabox_fields_lifterlms_course_options', array($this, 'addCourseMetaBox'));
            add_action('llms_metabox_after_save_lifterlms-course-options', array($this, 'saveCourseMetaBoxData'), 20, 1);
            add_action('llms_user_enrolled_in_course', array($this, 'maybeCourseEnrolledTags'), 50, 2);
            add_action('lifterlms_course_completed', array($this, 'maybeCourseCompletedTags'), 50, 2);
            add_action('llms_user_removed_from_course', array($this, 'maybeRemoveCourseTagsTags'), 50, 2);

            // lesson
            add_filter( 'llms_metabox_fields_lifterlms_lesson', array( $this, 'addLessonMetaBox' ) );
            add_action('llms_metabox_after_save_lifterlms-lesson', array($this, 'saveLessonMetaBoxData'), 20, 1);
            add_action( 'lifterlms_lesson_completed', array( $this, 'maybeLessonCompletedTags' ), 10, 2 );

            // Membership
            add_filter('llms_metabox_fields_lifterlms_membership', array($this, 'addMembershipMetaBox'));
            add_action('llms_metabox_after_save_lifterlms-membership', array($this, 'saveMembershipMetaBoxData'), 20, 1);
            add_action( 'llms_user_added_to_membership_level', array( $this, 'maybeMembershipTags' ), 50, 2 );
            add_action( 'llms_user_removed_from_membership_level', array( $this, 'maybeRemoveMembershipTags' ), 50, 2 );
        }

        (new DeepIntegration())->init();
        (new LifterSmartCodes())->init();

        add_filter('fluent_crm/subscriber_info_widgets', array($this, 'pushSubscriberInfoWidget'), 10, 2);

    }


    public function pushCoursesOnProfile($sections)
    {
        $sections['lifter_contact_courses'] = [
            'name'    => 'fluentcrm_profile_extended',
            'title'   => __('Courses', 'fluentcampaign-pro'),
            'handler' => 'route',
            'query'   => [
                'handler' => 'lifter_profile_courses'
            ]
        ];

        return $sections;
    }

    public function pushCoursesContent($content, $subscriber)
    {
        $content['heading'] = __('LifterLMS Courses', 'fluentcampaign-pro');

        $student_id = $subscriber->user_id;

        if (!$student_id) {
            $content['content_html'] = '<p>'.__('No enrolled courses found for this contact', 'fluentcampaign-pro').'</p>';
            return $content;
        }


        if (!llms_current_user_can('view_lifterlms_reports', $student_id)) {
            $content['content_html'] = '<p>'.__("You do not have permission to access this student\'s reports", "fluentcampaign-pro").'</p>';
            return $content;
        }

        $student = llms_get_student($student_id);

        if (!$student) {
            $content['content_html'] = '<p>'.__('No enrolled courses found for this contact', 'fluentcampaign-pro').'</p>';
            return $content;
        }

        $courses = $student->get_courses();

        if (empty($courses['results'])) {
            $content['content_html'] = '<p>'.__('No enrolled courses found for this contact', 'fluentcampaign-pro').'</p>';
            return $content;
        }

        $enrolledCourses = get_posts([
            'post_status'    => 'publish',
            'post_type'      => 'course',
            'posts_per_page' => 100,
            'post__in'       => $courses['results'],
        ]);

        $tableBuilder = new TableBuilder();
        foreach ($enrolledCourses as $course) {
            $tableBuilder->addRow([
                'id'              => $course->ID,
                'title'           => $course->post_title,
                'enrollment_date' => $student->get_enrollment_date($course->ID, 'enrolled'),
                'status'          => llms_get_enrollment_status_name($student->get_enrollment_status($course->ID)),
                'grade'           => $student->get_grade($course->ID),
                'progress'        => $student->get_progress($course->ID, 'course') . '%',
                'completed_at'    => $student->get_completion_date($course->ID)
            ]);
        }

        $tableBuilder->setHeader([
            'id'              => __('ID', 'fluentcampaign-pro'),
            'title'           => __('Course Name', 'fluentcampaign-pro'),
            'enrollment_date' => __('Enrolled At', 'fluentcampaign-pro'),
            'status'          => __('Status', 'fluentcampaign-pro'),
            'grade'           => __('Grade', 'fluentcampaign-pro'),
            'progress'        => __('Progress', 'fluentcampaign-pro'),
            'completed_at'    => __('Completed At', 'fluentcampaign-pro')
        ]);

        $content['content_html'] = $tableBuilder->getHtml();
        return $content;
    }

    public function addCourseMetaBox($metabox)
    {
        global $post;
        if ($post->post_type != 'course') {
            return $metabox;
        }

        $formattedTags = [];
        foreach (Tag::get() as $tag) {
            $formattedTags[] = [
                'key'   => $tag->id,
                'title' => $tag->title
            ];
        }

        $tagSettings = wp_parse_args(get_post_meta($post->ID, '_fluentcrm_settings', true), [
            'enrolled_tags'  => [],
            'completed_tags' => [],
            'remove_tags' => false
        ]);

        $metabox['fluentcrm'] = array(
            'title'  => __('FluentCRM', 'fluentcampaign-pro'),
            'fields' => array(
                [
                    'class'           => 'select4',
                    'data_attributes' => array(
                        'placeholder' => __('Select Tags', 'fluentcampaign-pro'),
                    ),
                    'desc'            => __('Selected tags will be applied to the contact on course enrollment.', 'fluentcampaign-pro'),
                    'id'              => '_fluentcrm_settings[enrolled_tags]',
                    'label'           => __('Apply Tags on course enrollment', 'fluentcampaign-pro'),
                    'multi'           => true,
                    'type'            => 'select',
                    'value'           => $formattedTags,
                    'selected'        => $tagSettings['enrolled_tags'],
                ],
                [
                    'class'           => 'select4',
                    'data_attributes' => array(
                        'placeholder' => __('Select Tags', 'fluentcampaign-pro'),
                    ),
                    'desc'            => __('Selected tags will be applied to the contact on course completion.', 'fluentcampaign-pro'),
                    'id'              => '_fluentcrm_settings[completed_tags]',
                    'label'           => __('Apply Tags on course completion', 'fluentcampaign-pro'),
                    'multi'           => true,
                    'type'            => 'select',
                    'value'           => $formattedTags,
                    'selected'        => $tagSettings['completed_tags'],
                ],
                [
                    'type'       => 'checkbox',
                    'label'      => __( 'Remove Tags', 'fluentcampaign-pro' ),
                    'desc'       => __( 'Automatically remove tags defined in "Apply Tags" if course enrollment is cancelled.', 'fluentcampaign-pro' ),
                    'id'              => '_fluentcrm_settings[remove_tags]',
                    'class'      => '',
                    'value'      => 'yes',
                    'desc_class' => 'd-3of4 t-3of4 m-1of2',
                    'default'   => (!empty($tagSettings['remove_tags'])) ? 'yes' : ''
                ]
            ),
        );

        return $metabox;
    }

    public function addMembershipMetaBox($metabox)
    {
        global $post;
        if ($post->post_type != 'llms_membership') {
            return $metabox;
        }

        $formattedTags = [];
        foreach (Tag::get() as $tag) {
            $formattedTags[] = [
                'key'   => $tag->id,
                'title' => $tag->title
            ];
        }

        $tagSettings = wp_parse_args(get_post_meta($post->ID, '_fluentcrm_settings', true), [
            'enrolled_tags'  => [],
            'remove_tags' => false
        ]);

        $isChecked = '';

        if(!empty($tagSettings['remove_tags'])) {
            $isChecked = 'yes';
        }

        $metabox['fluentcrm'] = array(
            'title'  => __('FluentCRM', 'fluentcampaign-pro'),
            'fields' => array(
                [
                    'class'           => 'select4',
                    'data_attributes' => array(
                        'placeholder' => __('Select Tags', 'fluentcampaign-pro'),
                    ),
                    'desc'            => __('Selected tags will be applied to the contact on membership enrollment.', 'fluentcampaign-pro'),
                    'id'              => '_fluentcrm_settings[enrolled_tags]',
                    'label'           => __('Apply Tags on Membership enrollment', 'fluentcampaign-pro'),
                    'multi'           => true,
                    'type'            => 'select',
                    'value'           => $formattedTags,
                    'selected'        => $tagSettings['enrolled_tags'],
                ],
                array(
                    'type'       => 'checkbox',
                    'label'      => __( 'Remove Tags', 'fluentcampaign-pro' ),
                    'desc'       => __( 'Automatically remove tags defined in "Apply Tags" if membership is cancelled.', 'fluentcampaign-pro' ),
                    'id'              => '_fluentcrm_settings[remove_tags]',
                    'class'      => '',
                    'value'      => 'yes',
                    'desc_class' => 'd-3of4 t-3of4 m-1of2',
                    'default'   => $isChecked
                )
            ),
        );

        return $metabox;
    }

    public function saveMembershipMetaBoxData($postId)
    {
        // Return early during quick saves and ajax requests.
        if ( isset( $_POST['action'] ) && 'inline-save' === $_POST['action'] ) {
            return false;
        } elseif ( llms_is_ajax() ) {
            return false;
        }

        $settings = [
            'enrolled_tags'  => [],
            'remove_tags' => false
        ];

        if (isset($_POST['_fluentcrm_settings'])) {
            $settings = $_REQUEST['_fluentcrm_settings'];
        }

        update_post_meta($postId, '_fluentcrm_settings', $settings);
        return $settings;
    }

    public function maybeMembershipTags($userId, $membershipId)
    {
        $settings = get_post_meta($membershipId, '_fluentcrm_settings', true);
        if(!$settings || empty($settings['enrolled_tags'])) {
            return false;
        }

        $tags = array_filter(array_map('absint', $settings['enrolled_tags']));

        if(!$tags) {
            return false;
        }

        Helper::createContactFromLifter($userId, $tags);

    }

    public function maybeRemoveMembershipTags($userId, $membershipId)
    {
        $settings = get_post_meta($membershipId, '_fluentcrm_settings', true);
        if(!$settings || empty($settings['enrolled_tags']) || empty($settings['remove_tags'])) {
            return false;
        }

        $tags = array_filter(array_map('absint', $settings['enrolled_tags']));

        if(!$tags) {
            return false;
        }

        $contact = FluentCrmApi('contacts')->getContactByUserRef($userId);

        if($contact) {
            $contact->detachTags($tags);
        }

    }

    public function maybeRemoveCourseTagsTags($userId, $courseId)
    {
        $settings = get_post_meta($courseId, '_fluentcrm_settings', true);
        if(!$settings || empty($settings['enrolled_tags']) || empty($settings['remove_tags'])) {
            return false;
        }

        $tags = array_filter(array_map('absint', $settings['enrolled_tags']));

        if(!$tags) {
            return false;
        }

        $contact = FluentCrmApi('contacts')->getContactByUserRef($userId);

        if($contact) {
            $contact->detachTags($tags);
        }

    }

    public function addLessonMetaBox($metabox)
    {
        global $post;

        global $post;
        if ($post->post_type != 'lesson') {
            return $metabox;
        }

        $formattedTags = [];
        foreach (Tag::get() as $tag) {
            $formattedTags[] = [
                'key'   => $tag->id,
                'title' => $tag->title
            ];
        }

        $tagSettings = wp_parse_args(get_post_meta($post->ID, '_fluentcrm_settings', true), [
            'lesson_completed_tags'  => []
        ]);

        $metabox['fluentcrm'] = array(
            'title'  => __('FluentCRM', 'fluentcampaign-pro'),
            'fields' => array(
                [
                    'class'           => 'select4',
                    'data_attributes' => array(
                        'placeholder' => __('Select Tags', 'fluentcampaign-pro'),
                    ),
                    'desc'            => __('Selected tags will be applied to the contact on lesson completed.', 'fluentcampaign-pro'),
                    'id'              => '_fluentcrm_settings[lesson_completed_tags]',
                    'label'           => __('Apply Tags on Course Completed', 'fluentcampaign-pro'),
                    'multi'           => true,
                    'type'            => 'select',
                    'value'           => $formattedTags,
                    'selected'        => $tagSettings['lesson_completed_tags'],
                ]
            ),
        );

        return $metabox;
    }

    public function saveCourseMetaBoxData($postId)
    {
        $action = llms_filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
        if ('inline-save' === $action) {
            return null;
        }

        $settings = [
            'enrolled_tags'  => [],
            'completed_tags' => [],
            'remove_tags' => false
        ];

        if (isset($_POST['_fluentcrm_settings'])) {
            $settings = $_REQUEST['_fluentcrm_settings'];
        }

        update_post_meta($postId, '_fluentcrm_settings', $settings);
        return $settings;
    }

    public function saveLessonMetaBoxData($postId)
    {
        $action = llms_filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
        if ('inline-save' === $action) {
            return null;
        }

        $settings = [
            'lesson_completed_tags'  => []
        ];

        if (isset($_POST['_fluentcrm_settings'])) {
            $settings = $_REQUEST['_fluentcrm_settings'];
        }

        update_post_meta($postId, '_fluentcrm_settings', $settings);
        return $settings;
    }

    public function maybeCourseEnrolledTags($userId, $courseId)
    {
        $settings = get_post_meta($courseId, '_fluentcrm_settings', true);
        if (!$settings || empty($settings['enrolled_tags']) || !is_array($settings['enrolled_tags'])) {
            return false;
        }

        Helper::createContactFromLifter($userId, $settings['enrolled_tags']);

        return true;
    }

    public function maybeCourseCompletedTags($userId, $courseId)
    {
        $settings = get_post_meta($courseId, '_fluentcrm_settings', true);
        if (!$settings || empty($settings['completed_tags']) || !is_array($settings['completed_tags'])) {
            return false;
        }

        Helper::createContactFromLifter($userId, $settings['completed_tags']);
        return true;
    }

    public function maybeLessonCompletedTags($userId, $courseId)
    {
        $settings = get_post_meta($courseId, '_fluentcrm_settings', true);
        if (!$settings || empty($settings['lesson_completed_tags']) || !is_array($settings['lesson_completed_tags'])) {
            return false;
        }

        Helper::createContactFromLifter($userId, $settings['lesson_completed_tags']);
        return true;
    }


    public function pushSubscriberInfoWidget($widgets, $subscriber)
    {
        if(!$subscriber->user_id) {
            return $widgets;
        }

        $student = llms_get_student($subscriber->user_id);

        if(!$student) {
            return $widgets;
        }

        $courses = $student->get_courses();

        if (empty($courses['results'])) {
            return $widgets;
        }

        $enrolledCourses = get_posts([
            'post_status'    => 'publish',
            'post_type'      => 'course',
            'posts_per_page' => 100,
            'post__in'       => $courses['results'],
        ]);

        if(!$enrolledCourses) {
            return false;
        }

        $html = '<ul class="fc_full_listed">';

        foreach ($enrolledCourses as $course) {
            $html .= '<li><a target="_blank" rel="noopener" href="'.get_permalink($course->ID).'">'.$course->post_title.' <span class="el-tag el-tag--mini el-tag--light">'.llms_get_enrollment_status_name($student->get_enrollment_status($course->ID)).'</span></a></li>';
        }
        $html .= '</ul>';

        $widgets[] = [
            'title' => 'Course Enrollments',
            'content' => $html
        ];

        return $widgets;
    }

}
