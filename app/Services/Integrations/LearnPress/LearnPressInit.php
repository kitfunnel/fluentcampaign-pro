<?php

namespace FluentCampaign\App\Services\Integrations\LearnPress;

use FluentCampaign\App\Services\MetaFormBuilder;
use FluentCrm\App\Models\Tag;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\Html\TableBuilder;

class LearnPressInit
{
    public function init()
    {
        new LearnPressImporter();

        add_filter('fluentcrm_profile_sections', array($this, 'pushCoursesOnProfile'));
        add_filter('fluencrm_profile_section_learnpress_profile_courses', array($this, 'pushCoursesContent'), 10, 2);


        if (!apply_filters('fluentcrm_disable_integration_metaboxes', false, 'learnpress')) {
            /*
            * Metaboxes
            */
            add_action('add_meta_boxes', array($this, 'addBoxes'));
            add_action('save_post_lp_course', array($this, 'saveMetaBox'));
            add_action('save_post_lp_lesson', array($this, 'saveMetaBox'));

            /*
             * Actions
             */
            add_action('learn-press/user-enrolled-course', array($this, 'courseEnrolled'), 10, 2);
            add_action('learnpress/user/course-enrolled', function ($ref, $courseId, $userId) {
                $this->courseEnrolled($courseId, $userId);
            }, 10, 3);
            add_action('learn-press/user-course-finished', array($this, 'courseFinished'), 10, 2);
            add_action('learn-press/user-completed-lesson', array($this, 'lessonCompleted'), 10, 3);
        }

    }

    public function pushCoursesOnProfile($sections)
    {
        $sections['learnpress_profile_courses'] = [
            'name'    => 'fluentcrm_profile_extended',
            'title'   => __('Courses', 'fluentcampaign-pro'),
            'handler' => 'route',
            'query'   => [
                'handler' => 'learnpress_profile_courses'
            ]
        ];

        return $sections;
    }

    public function pushCoursesContent($content, $subscriber)
    {
        $content['heading'] = __('LearnPress Courses', 'fluentcampaign-pro');

        $userId = $subscriber->user_id;

        if (!$userId) {
            $content['content_html'] = '<p>' . __('No enrolled courses found for this contact', 'fluentcampaign-pro') . '</p>';
            return $content;
        }

        $enrollments = fluentCrmDb()->table('learnpress_user_items')
            ->select(['learnpress_user_items.*', 'posts.post_title'])
            ->where('learnpress_user_items.item_type', 'lp_course')
            ->where('learnpress_user_items.user_id', $subscriber->user_id)
            ->join('posts', 'posts.ID', '=', 'learnpress_user_items.item_id')
            ->orderBy('learnpress_user_items.user_item_id', 'DESC')
            ->get();

        if (empty($enrollments)) {
            $content['content_html'] = '<p>' . __('No enrolled courses found for this contact', 'fluentcampaign-pro') . '</p>';
            return $content;
        }

        $tableBuilder = new TableBuilder();
        foreach ($enrollments as $enrollment) {
            $tableBuilder->addRow([
                'id'                => $enrollment->item_id,
                'title'             => $enrollment->post_title,
                'status'            => ucfirst($enrollment->status),
                'graduation_status' => ucfirst($enrollment->graduation),
                'started_at'        => date_i18n(get_option('date_format'), strtotime($enrollment->start_time)),
            ]);
        }

        $tableBuilder->setHeader([
            'id'                => __('Course ID', 'fluentcampaign-pro'),
            'title'             => __('Course Name', 'fluentcampaign-pro'),
            'status'            => __('Status', 'fluentcampaign-pro'),
            'graduation_status' => __('Graduation Status', 'fluentcampaign-pro'),
            'started_at'        => __('Started At', 'fluentcampaign-pro'),
        ]);

        $content['content_html'] = $tableBuilder->getHtml();
        return $content;
    }

    public function addBoxes()
    {
        add_meta_box(
            'fluentcrm_lp_course_meta', __('FluentCRM Settings', 'fluentcampaign-pro'), array($this, 'courseMetaBox',
        ), 'lp_course', 'normal', 'default');

        add_meta_box(
            'fluentcrm_lp_lesson_meta', __('FluentCRM Settings', 'fluentcampaign-pro'), array($this, 'lessonMetaBox',
        ), 'lp_lesson', 'normal', 'default');
    }

    public function courseMetaBox($post)
    {
        $settings = [
            'enroll_tags'    => [],
            'completed_tags' => []
        ];

        $crmSettings = get_post_meta($post->ID, '_fcrm_settings', true);
        if ($crmSettings) {
            $settings = wp_parse_args($crmSettings, $settings);
        }

        $tags = Tag::orderBy('title', 'ASC')->get();
        ?>
        <table class="form-table">
            <tr>
                <th><label
                        for="fluentcrm_add_enroll_tags"><?php _e('Apply Tags on Course Enrollment', 'fluentcampaign-pro'); ?></label>
                </th>
                <td>
                    <input type="hidden" value="yes" name="_has_fcrm"/>
                    <select id="fluentcrm_add_enroll_tags"
                            placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>" style="width:100%;"
                            class="fc_multi_select"
                            name="_fcrm[enroll_tags][]" multiple="multiple">
                        <?php foreach ($tags as $tag): ?>
                            <option
                                value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['enroll_tags'])) {
                                echo 'selected';
                            } ?> ><?php echo $tag->title; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span
                        class="description"><?php esc_html_e('Apply these tags on course enrollment', 'fluentcampaign-pro'); ?></span>
                </td>
            </tr>
            <tr>
                <th><label
                        for="fluentcrm_add_completed_tags"><?php _e('Apply Tags on Course Completion', 'fluentcampaign-pro'); ?></label>
                </th>
                <td>
                    <select id="fluentcrm_add_completed_tags"
                            placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>" style="width:100%;"
                            class="fc_multi_select"
                            name="_fcrm[completed_tags][]" multiple="multiple">
                        <?php foreach ($tags as $tag): ?>
                            <option
                                value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['completed_tags'])) {
                                echo 'selected';
                            } ?> ><?php echo $tag->title; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span
                        class="description"><?php esc_html_e('Apply these tags on course completion', 'fluentcampaign-pro'); ?></span>
                </td>
            </tr>
        </table>
        <?php
        (new MetaFormBuilder())->initMultiSelect('.fc_multi_select', 'Select Tags');
    }

    public function lessonMetaBox($post)
    {
        $settings = [
            'completed_tags' => []
        ];

        $crmSettings = get_post_meta($post->ID, '_fcrm_settings', true);
        if ($crmSettings) {
            $settings = wp_parse_args($crmSettings, $settings);
        }

        $tags = Tag::orderBy('title', 'ASC')->get();
        ?>
        <table class="form-table">
            <tr>
                <th><label
                        for="fluentcrm_add_completed_tags"><?php _e('Apply Tags on Lesson Completion', 'fluentcampaign-pro'); ?></label>
                </th>
                <td>
                    <input type="hidden" value="yes" name="_has_fcrm"/>
                    <select id="fluentcrm_add_completed_tags"
                            placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>" style="width:100%;"
                            class="fc_multi_select"
                            name="_fcrm[completed_tags][]" multiple="multiple">
                        <?php foreach ($tags as $tag): ?>
                            <option
                                value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['completed_tags'])) {
                                echo 'selected';
                            } ?> ><?php echo $tag->title; ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>
        <?php
        (new MetaFormBuilder())->initMultiSelect('.fc_multi_select', 'Select Tags');
    }

    public function saveMetaBox($post_id)
    {
        if (!isset($_POST['_has_fcrm']) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || $_POST['post_type'] == 'revision') {
            return;
        }

        if (isset($_POST['_fcrm'])) {
            $data = wp_unslash($_POST['_fcrm']);
        } else {
            $data = array();
        }

        update_post_meta($post_id, '_fcrm_settings', $data);
    }

    public function courseEnrolled($course_id, $user_id)
    {
        $settings = get_post_meta($course_id, '_fcrm_settings', true);

        if (!$settings || empty($settings['enroll_tags'])) {
            return false;
        }

        $contact = Helper::getWPMapUserInfo($user_id);

        $contact['tags'] = $settings['enroll_tags'];

        FunnelHelper::createOrUpdateContact($contact);

        return true;
    }

    public function courseFinished($courseId, $userId)
    {
        $settings = get_post_meta($courseId, '_fcrm_settings', true);

        if (!$settings || empty($settings['completed_tags'])) {
            return false;
        }

        $contact = Helper::getWPMapUserInfo($userId);

        $contact['tags'] = $settings['completed_tags'];

        FunnelHelper::createOrUpdateContact($contact);

        return true;
    }

    public function lessonCompleted($lessonId, $result, $userId)
    {
        $settings = get_post_meta($lessonId, '_fcrm_settings', true);

        if (!$settings || empty($settings['completed_tags'])) {
            return false;
        }

        $contact = Helper::getWPMapUserInfo($userId);

        $contact['tags'] = $settings['completed_tags'];

        FunnelHelper::createOrUpdateContact($contact);

        return true;
    }
}
