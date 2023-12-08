<?php

namespace FluentCampaign\App\Services\Integrations\TutorLms;

use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCampaign\App\Services\Commerce\ContactRelationItemsModel;
use FluentCampaign\App\Services\Commerce\ContactRelationModel;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\Framework\Support\Arr;

class DeepIntegration
{
    protected $integrationkey = 'tutorlms';

    public function init()
    {
        /*
         * Advanced Reporting and Syncing
         */
        add_filter('fluentcrm_deep_integration_providers', array($this, 'addDeepIntegrationProvider'), 10, 1);
        add_filter('fluentcrm_deep_integration_sync_' . $this->integrationkey, array($this, 'syncLdStudents'), 10, 2);
        add_filter('fluentcrm_deep_integration_save_' . $this->integrationkey, array($this, 'saveSettings'), 10, 2);

        add_filter('fluent_crm/advanced_report_providers', function ($providers) {
            $providers['tutorlms'] = [
                'title' => __('TutorLMS', 'fluentcampaign-pro')
            ];
            return $providers;
        }, 10, 1);


        /*
         * Advanced Filters
         */
        add_filter('fluentcrm_advanced_filter_options', array($this, 'addAdvancedFilterOptions'), 10, 1);
        add_action('fluentcrm_contacts_filter_' . $this->integrationkey, array($this, 'addAdvancedFilter'), 10, 2);

        add_filter('fluentcrm_advanced_filter_suggestions', function ($suggestions) {
            if (!Commerce::isEnabled($this->integrationkey)) {
                $suggestions[] = [
                    'title'    => __('Sync TutorLMS Students to FluentCRM to segment them by their courses data.', 'fluentcampaign-pro'),
                    'btn_text' => __('View Settings', 'fluentcampaign-pro'),
                    'provider' => 'tutorlms',
                    'btn_url'  => admin_url('admin.php?page=fluentcrm-admin#/settings/integration_settings?selected_integration=tutorlms')
                ];
            }

            return $suggestions;
        });

        // Need to sync the course enrollment/remove status
        $hooks = [
            'tutor_after_enrolled',
            'tutor_course_complete_after',
            'tutor_after_enrollment_deleted',
            'tutor_after_enrollment_cancelled'
        ];

        foreach ($hooks as $hook) {
            add_action($hook, function ($courseId, $userId) use ($hook) {

                if (!Commerce::isEnabled('tutorlms')) {
                    return;
                }

                $this->syncEnrollmentCanged($userId, $courseId, $hook);
            }, 10, 2);
        }
    }

    public function addDeepIntegrationProvider($providers)
    {
        $providers['tutorlms'] = [
            'title'       => __('TutorLMS', 'fluentcampaign-pro'),
            'sub_title'   => __('With TutorLMS deep integration with FluentCRM, you easily segment your students by their enrollment, course dates and target your students more efficiently.', 'fluentcampaign-pro'),
            'sync_title'  => __('TutorLMS students are not synced with FluentCRM yet.', 'fluentcampaign-pro'),
            'sync_desc'   => __('To sync and enable deep integration with TutorLMS students with FluentCRM, please configure and enable sync.', 'fluentcampaign-pro'),
            'sync_button' => __('Sync TutorLMS Students', 'fluentcampaign-pro'),
            'settings'    => $this->getSyncSettings()
        ];

        return $providers;
    }

    public function getSyncSettings()
    {
        $defaults = [
            'tags'           => [],
            'lists'          => [],
            'contact_status' => 'subscribed'
        ];

        $settings = fluentcrm_get_option('_tutorlms_sync_settings', []);

        $settings = wp_parse_args($settings, $defaults);

        $settings['is_enabled'] = Commerce::isEnabled('tutorlms');

        $settings['tags'] = array_map('intval', $settings['tags']);
        $settings['lists'] = array_map('intval', $settings['lists']);

        return $settings;
    }

    public function saveSettings($returnData, $config)
    {
        $tags = Arr::get($config, 'tags', []);
        $lists = Arr::get($config, 'lists', []);
        $contactStatus = Arr::get($config, 'contact_status', 'subscribed');

        $settings = [
            'tags'           => $tags,
            'lists'          => $lists,
            'contact_status' => $contactStatus
        ];

        if (Arr::get($config, 'action') == 'disable') {
            Commerce::disableModule($this->integrationkey);
            $settings['disabled_at'] = current_time('mysql');
        }

        fluentcrm_update_option('_tutorlms_sync_settings', $settings);

        return [
            'message'  => __('Settings have been saved', 'fluentcampaign-pro'),
            'settings' => $this->getSyncSettings()
        ];
    }

    public function syncLdStudents($returnData, $config)
    {
        $tags = Arr::get($config, 'tags', []);
        $lists = Arr::get($config, 'lists', []);
        $contactStatus = Arr::get($config, 'contact_status', 'subscribed');

        $settings = [
            'tags'           => $tags,
            'lists'          => $lists,
            'contact_status' => $contactStatus
        ];

        fluentcrm_update_option('_tutorlms_sync_settings', $settings);

        $status = $this->syncStudents([
            'tags'               => $tags,
            'lists'              => $lists,
            'new_status'         => $contactStatus,
            'double_optin_email' => ($contactStatus == 'pending') ? 'yes' : 'no',
            'import_silently'    => 'yes'
        ], $config['syncing_page']);

        return [
            'syncing_status' => $status
        ];
    }

    public function syncStudents($config, $page)
    {
        $inputs = Arr::only($config, [
            'lists', 'tags', 'new_status', 'double_optin_email', 'import_silently'
        ]);

        $inputs = wp_parse_args($inputs, [
            'lists'              => [],
            'tags'               => [],
            'new_status'         => 'subscribed',
            'double_optin_email' => 'no',
            'import_silently'    => 'yes'
        ]);

        if (Arr::get($inputs, 'import_silently') == 'yes') {
            if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
                define('FLUENTCRM_DISABLE_TAG_LIST_EVENTS', true);
            }
        }

        $sendDoubleOptin = Arr::get($inputs, 'double_optin_email') == 'yes';
        $contactStatus = Arr::get($inputs, 'new_status', 'subscribed');

        $startTime = time();

        $lastStudentId = false;
        $runTime = 30;
        if ($page == 1) {
            if (!Commerce::isMigrated(true)) {
                Commerce::migrate();
            } else {
                Commerce::resetModuleData($this->integrationkey);
            }
            fluentcrm_update_option('_tutorlms_student_sync_count', 0);
            $runTime = 5;
        }

        $run = true;

        while ($run) {
            $offset = (int)fluentcrm_get_option('_tutorlms_student_sync_count', 0);

            $students = fluentCrmDb()->table('posts')
                ->where('post_type', 'tutor_enrolled')
                ->where('post_status', 'completed')
                ->orderBy('post_author', 'ASC')
                ->groupBy('post_author')
                ->select([
                    fluentCrmDb()->raw('DISTINCT(post_author) as student_user_id'),
                ])
                ->offset($offset)
                ->limit(10)
                ->get();

            if ($students) {
                foreach ($students as $student) {
                    $this->syncStudent($student->student_user_id, $contactStatus, $inputs['tags'], $inputs['lists'], $sendDoubleOptin);
                    $lastStudentId = $student->student_user_id;

                    fluentcrm_update_option('_tutorlms_student_sync_count', $offset + 1);
                    $offset += 1;

                    if ((time() - $startTime > $runTime) || fluentCrmIsMemoryExceeded()) {
                        return $this->getStudentSyncStatus($lastStudentId);
                    }
                }
            } else {
                $run = false;
            }
        }

        return $this->getStudentSyncStatus($lastStudentId);
    }

    public function getStudentSyncStatus($lastStudentId = false)
    {
        $total = fluentCrmDb()->table('posts')
            ->where('post_type', 'tutor_enrolled')
            ->where('post_status', 'completed')
            ->count(fluentCrmDb()->raw('DISTINCT post_author'));

        $completedCount = fluentcrm_get_option('_tutorlms_student_sync_count', 0);

        $hasMore = $total > $completedCount;

        if (!$hasMore) {
            Commerce::enableModule('tutorlms');
        }

        return [
            'page_total'   => $total,
            'record_total' => $total,
            'has_more'     => $hasMore,
            'current_page' => $completedCount,
            'next_page'    => $completedCount + 1,
            'reload_page'  => !$hasMore,
            'last_sync_id' => $lastStudentId
        ];
    }

    public function syncStudent($userId, $contactStatus = 'subscribed', $tags = [], $lists = [], $sendDoubleOptin = true, $forceSync = false)
    {
        $user = get_user_by('ID', $userId);
        if (!$user) {
            return false;
        }

        $courses = $this->getUserCourses($userId);

        if (!$courses) {
            if ($forceSync) {
                $subscriber = FluentCrmApi('contacts')->getContactByUserRef($userId);
                if ($subscriber) {
                    ContactRelationItemsModel::where('subscriber_id', $subscriber->id)
                        ->provider($this->integrationkey)
                        ->delete();
                    ContactRelationModel::provider($this->integrationkey)
                        ->where('subscriber_id', $subscriber->id)
                        ->delete();
                }
            }
            return false;
        }

        $contactData = \FluentCrm\App\Services\Helper::getWPMapUserInfo($user);

        $subscriber = FluentCrmApi('contacts')->getContact($contactData['email']);

        if ($subscriber) {
            $subscriber->fill($contactData)->save();
        } else {
            $contactData['source'] = 'tutorlms';
            $contactData['status'] = $contactStatus;
            $subscriber = FluentCrmApi('contacts')->createOrUpdate($contactData);
        }

        if (!$subscriber) {
            return false;
        }

        if ($contactStatus == 'pending' && $subscriber->status == 'pending' && $sendDoubleOptin) {
            $subscriber->sendDoubleOptinEmail();
        }

        if ($tags) {
            $subscriber->attachTags($tags);
        }

        if ($lists) {
            $subscriber->attachLists($lists);
        }

        $courseTimeStamps = [];

        foreach ($courses as $course) {
            $courseTimeStamps[] = strtotime($course['created_at']);
        }

        $relationData = [
            'subscriber_id'     => $subscriber->id,
            'provider'          => 'tutorlms',
            'provider_id'       => $userId,
            'created_at'        => date('Y-m-d H:i:s', min($courseTimeStamps)),
            'total_order_count' => count($courses),
            'first_order_date'  => date('Y-m-d H:i:s', min($courseTimeStamps)),
            'last_order_date'   => date('Y-m-d H:i:s', max($courseTimeStamps))
        ];

        $contactRelation = ContactRelationModel::updateOrCreate([
            'subscriber_id' => $subscriber->id,
            'provider'      => 'tutorlms'
        ], $relationData);

        if (!$contactRelation) {
            return false;
        }

        $contactRelation->syncItems($courses, false, true);

        return [
            'relation'   => $contactRelation,
            'subscriber' => $subscriber
        ];
    }

    public function getUserCourses($userId)
    {
        $enrolments = fluentCrmDb()->table('posts')
            ->where('post_type', 'tutor_enrolled')
            ->where('post_status', 'completed')
            ->where('post_author', $userId)
            ->get();

        if (!$enrolments) {
            return [];
        }

        $completedCourses = $this->getCompletedCourses($userId);

        $formattedEnrollments = [];
        foreach ($enrolments as $enrolment) {
            $formattedEnrollments[] = [
                'origin_id'  => $userId,
                'item_id'    => $enrolment->post_parent,
                'item_type'  => 'course',
                'provider'   => 'tutorlms',
                'status'     => ($completedCourses && in_array($enrolment->post_parent, $completedCourses)) ? 'completed' : 'enrolled',
                'created_at' => $enrolment->post_date,
                'updated_at' => $enrolment->post_modified
            ];
        }

        return $formattedEnrollments;
    }

    private function getCompletedCourses($userId)
    {
        $completedCourses = fluentCrmDb()->table('comments')
            ->where('comment_type', 'course_completed')
            ->where('comment_author', $userId)
            ->select(['comment_post_ID'])
            ->get();

        if (!$completedCourses) {
            return [];
        }

        // convert to plain array
        return array_map(function ($course) {
            return $course->comment_post_ID;
        }, $completedCourses);
    }

    public function syncEnrollmentCanged($userId, $courseId, $hook)
    {
        $settings = $this->getSyncSettings();
        $this->syncStudent($userId, $settings['contact_status'], $settings['tags'], $settings['lists']);
    }

    /**
     * @param \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder $query
     * @param array $filters
     * @return \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder
     */
    public function addAdvancedFilter($query, $filters)
    {
        return Subscriber::providerQueryBuilder($query, $filters, $this->integrationkey);
    }

    public function addAdvancedFilterOptions($groups)
    {
        $disabled = !Commerce::isEnabled($this->integrationkey);

        $groups[$this->integrationkey] = [
            'label'    => 'TutorLMS',
            'value'    => $this->integrationkey,
            'children' => [
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
                    'value'       => 'purchased_items',
                    'label'       => __('Enrollment Courses', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'cacheable'   => true,
                    'component'   => 'product_selector',
                    'is_multiple' => true,
                    'disabled'    => $disabled
                ],
                [
                    'value'       => 'purchased_categories',
                    'label'       => __('Enrollment Categories', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'tax_selector',
                    'taxonomy'    => 'course-category',
                    'is_multiple' => true,
                    'disabled'    => $disabled
                ],
                [
                    'value'       => 'purchased_tags',
                    'label'       => __('Enrollment Tags', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'tax_selector',
                    'taxonomy'    => 'course-tag',
                    'is_multiple' => true,
                    'disabled'    => $disabled
                ],
                [
                    'value'             => 'commerce_exist',
                    'label'             => 'Is a student?',
                    'type'              => 'selections',
                    'is_multiple'       => false,
                    'disable_values'    => true,
                    'value_description' => 'This filter will check if a contact has at least one enrolled course or not',
                    'custom_operators'  => [
                        'exist'     => 'Yes',
                        'not_exist' => 'No',
                    ],
                    'disabled'          => $disabled
                ]
            ],
        ];

        return $groups;
    }
}
