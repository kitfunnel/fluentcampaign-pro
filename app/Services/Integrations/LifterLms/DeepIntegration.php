<?php

namespace FluentCampaign\App\Services\Integrations\LifterLms;

use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCampaign\App\Services\Commerce\ContactRelationItemsModel;
use FluentCampaign\App\Services\Commerce\ContactRelationModel;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\Framework\Support\Arr;

class DeepIntegration
{

    protected $integrationkey = 'lifterlms';

    public function init()
    {
        add_filter('fluentcrm_advanced_filter_options', array($this, 'addAdvancedFilterOptions'), 10, 1);
        add_filter('fluentcrm_deep_integration_providers', array($this, 'addDeepIntegrationProvider'), 10, 1);

        add_filter('fluentcrm_deep_integration_sync_lifterlms', array($this, 'syncLifterStudents'), 10, 2);
        add_filter('fluentcrm_deep_integration_save_lifterlms', array($this, 'saveSettings'), 10, 2);

        add_action('fluentcrm_contacts_filter_lifterlms', array($this, 'addAdvancedFilter'), 10, 2);

        add_filter('fluentcrm_ajax_options_product_selector_lifterlms', array($this, 'getCourseOptions'), 10, 3);
        add_filter('fluentcrm_ajax_options_product_selector_lifterlms_groups', array($this, 'getMembershipOptions'), 10, 3);

        $syncHooks = [
            'llms_user_enrollment_deleted',
            'llms_user_enrolled_in_course',
            'llms_user_removed_from_course',
            'lifterlms_course_completed',
            'llms_user_added_to_membership_level',
            'llms_user_removed_from_membership_level'
        ];

        foreach ($syncHooks as $hook) {
            add_action($hook, function ($userId, $courseId) use ($hook) {
                $this->maybeReSyncStudent($userId, $courseId, $hook);
            }, 10, 2);
        }

        add_filter('fluentcrm_advanced_filter_suggestions', function ($suggestions) {
            if (!Commerce::isEnabled('lifterlms')) {
                $suggestions[] = [
                    'title'    => __('Sync LifterLMS Students to FluentCRM to segment them by their courses data.', 'fluentcampaign-pro'),
                    'btn_text' => __('View Settings', 'fluentcampaign-pro'),
                    'provider' => 'lifterlms',
                    'btn_url'  => admin_url('admin.php?page=fluentcrm-admin#/settings/integration_settings?selected_integration=lifterlms')
                ];
            }

            return $suggestions;
        });

        add_filter('fluent_crm/advanced_report_providers', function ($providers) {
            $providers['lifterlms'] = [
                'title' => __('LifterLMS', 'fluentcampaign-pro')
            ];
            return $providers;
        }, 10, 1);

        // Automation
        (new AutomationConditions())->init();
    }

    /**
     * @param \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder $query
     * @param array $filters
     * @return \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder
     */
    public function addAdvancedFilter($query, $filters)
    {
        return Subscriber::providerQueryBuilder($query, $filters, 'lifterlms');
    }

    public function addAdvancedFilterOptions($groups)
    {
        $disabled = !Commerce::isEnabled('lifterlms');

        $groups['lifterlms'] = [
            'label'    => __('LifterLMS', 'fluentcampaign-pro'),
            'value'    => 'lifterlms',
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
                    'value'        => 'purchased_groups',
                    'label'        => __('Enrollment Memberships', 'fluentcampaign-pro'),
                    'type'         => 'selections',
                    'component'    => 'product_selector',
                    'cacheable'    => true,
                    'extended_key' => 'groups',
                    'is_multiple'  => true,
                    'disabled'     => $disabled
                ],
                [
                    'value'       => 'purchased_categories',
                    'label'       => __('Enrollment Categories', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'tax_selector',
                    'taxonomy'    => 'course_cat',
                    'is_multiple' => true,
                    'disabled'    => $disabled
                ],
                [
                    'value'       => 'purchased_tags',
                    'label'       => __('Enrollment Tags', 'fluentcampaign-pro'),
                    'type'        => 'selections',
                    'component'   => 'tax_selector',
                    'taxonomy'    => 'course_tag',
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

    public function addDeepIntegrationProvider($providers)
    {
        $providers['lifterlms'] = [
            'title'       => __('LifterLMS', 'fluentcampaign-pro'),
            'sub_title'   => __('With LifterLMS deep integration with FluentCRM, you easily segment your students by their enrollment, course dates and target your students more efficiently.', 'fluentcampaign-pro'),
            'sync_title'  => __('LifterLMS students are not synced with FluentCRM yet.', 'fluentcampaign-pro'),
            'sync_desc'   => __('To sync and enable deep integration with LifterLMS students with FluentCRM, please configure and enable sync.', 'fluentcampaign-pro'),
            'sync_button' => __('Sync LifterLMS Students', 'fluentcampaign-pro'),
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

        $settings = fluentcrm_get_option('_lifterlms_sync_settings', []);

        $settings = wp_parse_args($settings, $defaults);

        $settings['is_enabled'] = Commerce::isEnabled('lifterlms');

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

        fluentcrm_update_option('_lifterlms_sync_settings', $settings);

        return [
            'message'  => 'Settings have been saved',
            'settings' => $this->getSyncSettings()
        ];
    }

    public function enable()
    {
        $settings = $this->getSyncSettings();
        if (!$settings['is_enabled']) {
            $settings['is_enabled'] = true;
            fluentcrm_update_option('_lifterlms_sync_settings', $settings);
        }
    }

    public function syncLifterStudents($returnData, $config)
    {
        $tags = Arr::get($config, 'tags', []);
        $lists = Arr::get($config, 'lists', []);
        $contactStatus = Arr::get($config, 'contact_status', 'subscribed');

        $settings = [
            'tags'           => $tags,
            'lists'          => $lists,
            'contact_status' => $contactStatus
        ];

        fluentcrm_update_option('_lifterlms_sync_settings', $settings);

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

        $runTime = 30;
        if ($page == 1) {
            if (!Commerce::isMigrated(true)) {
                Commerce::migrate();
            } else {
                Commerce::resetModuleData($this->integrationkey);
            }

            fluentcrm_update_option('_lifterlms_student_sync_count', 0);
            $runTime = 5;
        }

        $run = true;

        $lastStudentId = 0;

        while ($run) {
            $offset = intval(fluentcrm_get_option('_lifterlms_student_sync_count', 0));
            $students = fluentCrmDb()->table('lifterlms_user_postmeta')
                ->select([
                    fluentCrmDb()->raw('DISTINCT(user_id) as student_user_id'),
                ])
                ->where(function ($query) {
                    $query->where('meta_key', '_status')
                        ->where('meta_value', 'enrolled');
                })
                ->orderBy('student_user_id', 'ASC')
                ->offset($offset)
                ->limit(50)
                ->get();

            if ($students) {
                foreach ($students as $student) {
                    $this->syncStudent($student->student_user_id, $contactStatus, $inputs['tags'], $inputs['lists'], $sendDoubleOptin);
                    $lastStudentId = $student->student_user_id;
                    fluentcrm_update_option('_lifterlms_student_sync_count', $offset + 1);
                    $offset += 1;

                    if (time() - $startTime > $runTime) {
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
        $total = fluentCrmDb()->table('lifterlms_user_postmeta')
            ->where(function ($query) {
                $query->where('meta_key', '_status')
                    ->where('meta_value', 'enrolled');
            })
            ->count(fluentCrmDb()->raw('DISTINCT user_id'));

        $completedCount = fluentcrm_get_option('_lifterlms_student_sync_count', 0);

        $hasMore = $total > $completedCount;

        if (!$hasMore) {
            Commerce::enableModule('lifterlms');
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
                        ->provider('lifterlms')
                        ->delete();
                    ContactRelationModel::provider('lifterlms')
                        ->where('subscriber_id', $subscriber->id)
                        ->delete();
                }
            }
            return false;
        }

        $contactData = \FluentCrm\App\Services\Helper::getWPMapUserInfo($user);

        $contactData = array_merge($contactData, Helper::getStudentAddress($userId));
        $contactData = array_filter($contactData);

        $subscriber = FluentCrmApi('contacts')->getContact($contactData['email']);

        if ($subscriber) {
            $subscriber->fill($contactData)->save();
        } else {
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

        $courseIds = [];

        foreach ($courses as $course) {
            $courseIds[] = $course['item_id'];
        }

        $firstCourse = reset($courses);
        $lastCourse = end($courses);
        $relationData = [
            'subscriber_id'     => $subscriber->id,
            'provider'          => 'lifterlms',
            'provider_id'       => $userId,
            'created_at'        => $firstCourse['created_at'],
            'total_order_count' => count($courses),
            'first_order_date'  => $firstCourse['created_at'],
            'last_order_date'   => $lastCourse['created_at']
        ];

        $contactRelation = ContactRelationModel::updateOrCreate([
            'subscriber_id' => $subscriber->id,
            'provider'      => 'lifterlms'
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
        $studentCourses = fluentCrmDb()->table('lifterlms_user_postmeta')
            ->where(function ($query) {
                $query->where('meta_key', '_status')
                    ->where('meta_value', 'enrolled');
            })
            ->where('user_id', $userId)
            ->orderBy('meta_id', 'ASC')
            ->get();

        if (!$studentCourses) {
            return [];
        }

        $completedCourses = fluentCrmDb()->table('lifterlms_user_postmeta')
            ->where(function ($query) {
                $query->where('meta_key', '_is_complete')
                    ->where('meta_value', 'yes');
            })
            ->where('user_id', $userId)
            ->get();

        $completedCourseMaps = [];
        foreach ($completedCourses as $completedCourse) {
            $completedCourseMaps[$completedCourse->post_id] = [
                'completed_date' => $completedCourse->updated_date
            ];
        }

        $courses = [];

        foreach ($studentCourses as $studentCourse) {
            $status = 'enrolled';
            $completedDate = '';
            if (isset($completedCourseMaps[$studentCourse->post_id])) {
                $status = 'completed';
                $completedDate = $completedCourseMaps[$studentCourse->post_id]['completed_date'];
            }

            $type = (get_post_type($studentCourse->post_id) == 'course') ? 'course' : 'membership';

            $itemData = [
                'origin_id'  => $studentCourse->meta_id,
                'item_id'    => $studentCourse->post_id,
                'item_type'  => $type,
                'provider'   => 'lifterlms',
                'status'     => $status,
                'created_at' => $studentCourse->updated_date,
            ];

            if ($completedDate) {
                $itemData['updated_at'] = $completedDate;
            }

            $courses[] = $itemData;
        }

        return $courses;
    }

    public function getCourseOptions($courses, $search, $includeIds)
    {
        return Helper::getCourses();
    }

    public function getMembershipOptions($courses, $search, $includeIds)
    {
        return Helper::getMemberships();
    }

    public function maybeReSyncStudent($userId, $courseId, $hook)
    {
        if (!Commerce::isEnabled('lifterlms')) {
            return false;
        }

        $settings = $this->getSyncSettings();
        if ($hook == 'llms_user_added_to_membership_level') {
            return $this->syncStudent($userId, $settings['contact_status'], $settings['tags'], $settings['lists'], $settings['contact_status'] == 'pending');
        } else if ($hook == 'llms_user_removed_from_membership_level') {
            $contactRelation = $this->getContactRelation($userId);
            if (!$contactRelation) {
                return false;
            }
            $this->syncStudent($userId, $settings['contact_status'], [], [], false, true);
            return true;

        } else if ($hook == 'llms_user_enrollment_deleted' || $hook == 'llms_user_removed_from_course') {
            $contactRelation = $this->getContactRelation($userId);
            if (!$contactRelation) {
                return false;
            }

            ContactRelationItemsModel::where('relation_id', $contactRelation->id)
                ->where('item_id', $courseId)
                ->delete();

            $contactRelation->recalculate(['first_order_date', 'last_order_date', 'total_order_count'], 'course', []);
            return true;
        } else {
            $this->syncStudent($userId, $settings['contact_status'], $settings['tags'], $settings['lists'], $settings['contact_status'] == 'pending');
        }
    }

    protected function getContactRelation($userId)
    {
        $subscriber = fluentCrmApi('contacts')->getContactByUserRef($userId);
        if (!$subscriber) {
            return false;
        }

        $contactRelation = ContactRelationModel::where('subscriber_id', $subscriber->id)
            ->where('provider', 'lifterlms')
            ->first();

        return $contactRelation;
    }
}
