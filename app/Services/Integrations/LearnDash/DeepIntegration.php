<?php

namespace FluentCampaign\App\Services\Integrations\LearnDash;

use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCampaign\App\Services\Commerce\ContactRelationItemsModel;
use FluentCampaign\App\Services\Commerce\ContactRelationModel;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\Framework\Support\Arr;

class DeepIntegration
{
    protected $integrationkey = 'learndash';

    public function init()
    {
        add_filter('fluentcrm_advanced_filter_options', array($this, 'addAdvancedFilterOptions'), 10, 1);
        add_filter('fluentcrm_deep_integration_providers', array($this, 'addDeepIntegrationProvider'), 10, 1);
        add_filter('fluentcrm_deep_integration_sync_' . $this->integrationkey, array($this, 'syncLdStudents'), 10, 2);
        add_filter('fluentcrm_deep_integration_save_' . $this->integrationkey, array($this, 'saveSettings'), 10, 2);
        add_action('fluentcrm_contacts_filter_' . $this->integrationkey, array($this, 'addAdvancedFilter'), 10, 2);
        add_filter('fluentcrm_ajax_options_product_selector_' . $this->integrationkey, array($this, 'getCourseOptions'), 10, 3);
        add_filter('fluentcrm_ajax_options_product_selector_' . $this->integrationkey . '_groups', array($this, 'getGroupOptions'), 10, 3);

        add_action('learndash_course_completed', array($this, 'handleCourseCompleted'), 9, 1);
        add_action('learndash_update_course_access', array($this, 'handleUpdateCourseAccess'), 9, 4);

        add_action('ld_removed_group_access', array($this, 'handleGroupUpdateAccess'), 9, 2);
        add_action('ld_added_group_access', array($this, 'handleGroupUpdateAccess'), 9, 2);

        add_filter('fluent_crm/advanced_report_providers', function ($providers) {
            $providers['learndash'] = [
                'title' => __('LearnDash', 'fluentcampaign-pro')
            ];
            return $providers;
        }, 10, 1);

        add_filter('fluentcrm_advanced_filter_suggestions', function ($suggestions) {
            if (!Commerce::isEnabled('learndash')) {
                $suggestions[] = [
                    'title'    => __('Sync LearnDash Students to FluentCRM to segment them by their enrollment, membership groups data.', 'fluentcampaign-pro'),
                    'btn_text' => __('View Settings', 'fluentcampaign-pro'),
                    'provider' => 'learndash',
                    'btn_url'  => admin_url('admin.php?page=fluentcrm-admin#/settings/integration_settings?selected_integration=learndash')
                ];
            }

            return $suggestions;
        });

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
        return Subscriber::providerQueryBuilder($query, $filters, $this->integrationkey);
    }

    public function addAdvancedFilterOptions($groups)
    {
        $membershipGroups = Helper::getGroups();
        $formattedGroups = [];
        foreach ($membershipGroups as $group) {
            $formattedGroups[strval($group['id'])] = $group['title'];
        }

        $disabled = !Commerce::isEnabled($this->integrationkey);

        $items = [
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
                'label'        => __('Enrollment Groups', 'fluentcampaign-pro'),
                'type'         => 'selections',
                'cacheable'    => true,
                'component'    => 'product_selector',
                'is_multiple'  => true,
                'extended_key' => 'groups',
                'disabled'     => $disabled,
                'options'      => $formattedGroups
            ],
            [
                'value'       => 'purchased_categories',
                'label'       => __('Enrollment Categories', 'fluentcampaign-pro'),
                'type'        => 'selections',
                'component'   => 'tax_selector',
                'taxonomy'    => 'ld_course_category',
                'is_multiple' => true,
                'disabled'    => $disabled
            ],
            [
                'value'       => 'purchased_tags',
                'label'       => __('Enrollment Tags', 'fluentcampaign-pro'),
                'type'        => 'selections',
                'component'   => 'tax_selector',
                'taxonomy'    => 'ld_course_tag',
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
        ];

        if ($disabled) {
            $items = array_merge([[
                'value'    => 'last_order_date',
                'label'    => __('Sync Required From Settings', 'fluentcampaign-pro'),
                'type'     => 'dates',
                'disabled' => true
            ]], $items);
        }

        $groups[$this->integrationkey] = [
            'label'    => __('LearnDash', 'fluentcampaign-pro'),
            'value'    => $this->integrationkey,
            'children' => $items
        ];

        return $groups;
    }

    public function addDeepIntegrationProvider($providers)
    {
        $providers['learndash'] = [
            'title'       => __('LearnDash', 'fluentcampaign-pro'),
            'sub_title'   => __('With LearnDash deep integration with FluentCRM, you easily segment your students by their enrollment, course dates and target your students more efficiently.', 'fluentcampaign-pro'),
            'sync_title'  => __('LearnDash students are not synced with FluentCRM yet.', 'fluentcampaign-pro'),
            'sync_desc'   => __('To sync and enable deep integration with LearnDash students with FluentCRM, please configure and enable sync.', 'fluentcampaign-pro'),
            'sync_button' => __('Sync LearnDash Students', 'fluentcampaign-pro'),
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

        $settings = fluentcrm_get_option('_learndash_sync_settings', []);

        $settings = wp_parse_args($settings, $defaults);

        $settings['is_enabled'] = Commerce::isEnabled('learndash');

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

        fluentcrm_update_option('_learndash_sync_settings', $settings);

        return [
            'message'  => __('Settings have been saved', 'fluentcampaign-pro'),
            'settings' => $this->getSyncSettings()
        ];
    }

    public function enable()
    {
        $settings = $this->getSyncSettings();
        if (!$settings['is_enabled']) {
            $settings['is_enabled'] = true;
            fluentcrm_update_option('_learndash_sync_settings', $settings);
        }
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

        fluentcrm_update_option('_learndash_sync_settings', $settings);

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
            fluentcrm_update_option('_learndash_student_sync_count', 0);
            $runTime = 5;
        }

        $run = true;

        while ($run) {
            $offset = intval(fluentcrm_get_option('_learndash_student_sync_count', 0));

            $students = fluentCrmDb()->table('usermeta')
                ->select([
                    fluentCrmDb()->raw('DISTINCT(user_id) as student_user_id'),
                ])
                ->where(function ($query) {
                    $query->where('meta_key', 'LIKE', 'learndash_group_users_%')
                        ->orWhere('meta_key', 'LIKE', 'course_%_access_from');
                })
                ->groupBy('user_id')
                ->offset($offset)
                ->limit(10)
                ->orderBy('user_id', 'ASC')
                ->get();

            if ($students) {
                foreach ($students as $student) {
                    $this->syncStudent($student->student_user_id, $contactStatus, $inputs['tags'], $inputs['lists'], $sendDoubleOptin);
                    $lastStudentId = $student->student_user_id;

                    fluentcrm_update_option('_learndash_student_sync_count', $offset + 1);
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
        $total = fluentCrmDb()->table('usermeta')
            ->where(function ($query) {
                $query->where('meta_key', 'LIKE', 'learndash_group_users_%')
                    ->orWhere('meta_key', 'LIKE', 'course_%_access_from');
            })
            ->count(fluentCrmDb()->raw('DISTINCT user_id'));

        $completedCount = fluentcrm_get_option('_learndash_student_sync_count', 0);

        $hasMore = $total > $completedCount;

        if (!$hasMore) {
            Commerce::enableModule('learndash');
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

        $contactData = array_merge($contactData, Helper::getStudentAddress($userId));
        $contactData = array_filter($contactData);

        $subscriber = FluentCrmApi('contacts')->getContact($contactData['email']);

        if ($subscriber) {
            $subscriber->fill($contactData)->save();
        } else {
            $contactData['source'] = 'learndash';
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
        $courseTimeStamps = [];

        foreach ($courses as $course) {
            $courseIds[] = $course['item_id'];
            $courseTimeStamps[] = strtotime($course['created_at']);
        }

        $relationData = [
            'subscriber_id'     => $subscriber->id,
            'provider'          => 'learndash',
            'provider_id'       => $userId,
            'created_at'        => date('Y-m-d H:i:s', min($courseTimeStamps)),
            'total_order_count' => count($courses),
            'first_order_date'  => date('Y-m-d H:i:s', min($courseTimeStamps)),
            'last_order_date'   => date('Y-m-d H:i:s', max($courseTimeStamps))
        ];

        $contactRelation = ContactRelationModel::updateOrCreate([
            'subscriber_id' => $subscriber->id,
            'provider'      => 'learndash'
        ], $relationData);

        if (!$contactRelation) {
            return false;
        }

        $groups = $this->getUserGroups($userId);

        if ($groups) {
            $courses = array_merge($courses, $groups);
        }

        $contactRelation->syncItems($courses, false, true);

        return [
            'relation'   => $contactRelation,
            'subscriber' => $subscriber
        ];
    }

    public function getUserCourses($userId)
    {
        $courseIds = learndash_user_get_enrolled_courses($userId);

        if (!$courseIds) {
            return [];
        }

        $courses = [];

        foreach ($courseIds as $courseId) {
            $status = 'enrolled';
            $completed_on = get_user_meta($userId, 'course_completed_' . $courseId, true);
            if ($completed_on) {
                $status = 'completed';
            }

            $startDate = ld_course_access_from($courseId, $userId);
            if (!$startDate) {
                $startDate = learndash_user_group_enrolled_to_course_from($userId, $courseId);
            }

            if ($startDate) {
                $startDate = time();
            }

            $itemData = [
                'origin_id'  => $userId,
                'item_id'    => $courseId,
                'item_type'  => 'course',
                'provider'   => 'learndash',
                'status'     => $status,
                'created_at' => date('Y-m-d H:i:s', $startDate)
            ];

            if ($completed_on) {
                $itemData['updated_at'] = date('Y-m-d H:i:s', $completed_on);
            }

            $courses[] = $itemData;
        }

        return $courses;
    }

    public function getUserGroups($userId)
    {
        $groupIds = learndash_get_users_group_ids($userId);

        $formattedGroups = [];
        foreach ($groupIds as $groupId) {
            $startedOn = learndash_get_user_group_started_timestamp($groupId, $userId);

            if (!$startedOn) {
                $startedOn = time();
            }

            $formattedGroups[] = [
                'origin_id'  => $userId,
                'item_id'    => $groupId,
                'item_type'  => 'membership',
                'provider'   => 'learndash',
                'status'     => 'enrolled',
                'created_at' => date('Y-m-d H:i:s', $startedOn)
            ];
        }

        return $formattedGroups;
    }

    public function getCourseOptions($courses, $search, $includeIds)
    {
        return Helper::getCourses();
    }

    public function getGroupOptions($courses, $search, $includeIds)
    {
        return Helper::getGroups();
    }

    public function handleCourseCompleted($data)
    {
        if (!Commerce::isEnabled($this->integrationkey)) {
            return false;
        }

        $user = $data['user'];

        if (!$user) {
            return false;
        }

        $course = $data['course'];

        $item = ContactRelationItemsModel::provider($this->integrationkey)
            ->where('subscriber_id', $user->ID)
            ->where('item_id', $course->ID)
            ->first();

        if (!$item) {
            return false;
        }

        $item->status = 'completed';
        if (!empty($data['completed_time'])) {
            $item->updated_at = date('Y-m-d H:i:s', $data['completed_time']);
        }

        $item->save();

        return true;

    }

    public function handleUpdateCourseAccess($user_id, $course_id, $course_access_list, $remove)
    {
        if (!Commerce::isEnabled($this->integrationkey)) {
            return false;
        }
        
        $settings = $this->getSyncSettings();
        $this->syncStudent($user_id, $settings['contact_status'], $settings['tags'], $settings['lists'], !$remove, $remove);
        return true;
    }

    public function handleGroupUpdateAccess($user_id, $group_id)
    {
        if (!Commerce::isEnabled($this->integrationkey)) {
            return false;
        }

        $settings = $this->getSyncSettings();
        $this->syncStudent($user_id, $settings['contact_status'], $settings['tags'], $settings['lists'], true, true);
    }
}
