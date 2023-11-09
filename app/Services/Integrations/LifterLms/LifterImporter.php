<?php

namespace FluentCampaign\App\Services\Integrations\LifterLms;

use FluentCampaign\App\Services\Integrations\BaseImporter;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\Tag;
use FluentCrm\Framework\Support\Arr;

class LifterImporter extends BaseImporter
{
    public function __construct()
    {
        $this->importKey = 'lifterlms';
        parent::__construct();
    }

    private function getPluginName()
    {
        return 'LifterLMS';
    }

    public function getInfo()
    {
        return [
            'label'    => $this->getPluginName(),
            'logo'     => fluentCrmMix('images/lifterlms.png'),
            'disabled' => false
        ];
    }

    public function processUserDriver($config, $request)
    {
        $summary = $request->get('summary');

        if ($summary) {
            $config = $request->get('config');


            $type = Arr::get($config, 'import_type');

            if ($type == 'member_groups') {
                $groupIds = [];

                foreach ($config['member_groups_maps'] as $map) {
                    $groupIds[] = absint($map['field_key']);
                }
                $groupIds = array_filter(array_unique($groupIds));
                $selectedUsers = $this->getUserIdsByResourceIds($groupIds, 5, 0);

            } else if ($type == 'course_types') {
                $courseIds = [];
                foreach ($config['course_types_maps'] as $map) {
                    $courseIds[] = $map['field_key'];
                }
                $courseIds = array_filter(array_unique($courseIds));
                $selectedUsers = $this->getUserIdsByResourceIds($courseIds, 5, 0);
            } else {
                $selectedUsers['total'] = 0;
            }

            if (!$selectedUsers['total']) {
                return new \WP_Error('not_found', 'Sorry no users found based on your filter');
            }

            $userQuery = new \WP_User_Query([
                'include' => Arr::get($selectedUsers, 'user_ids'),
                'fields'  => ['ID', 'display_name', 'user_email'],
            ]);

            $users = $userQuery->get_results();
            $total = $selectedUsers['total'];

            $formattedUsers = [];

            foreach ($users as $user) {
                $formattedUsers[] = [
                    'name'  => $user->display_name,
                    'email' => $user->user_email
                ];
            }

            return [
                'import_info' => [
                    'subscribers'       => $formattedUsers,
                    'total'             => $total,
                    'has_list_config'   => true,
                    'has_status_config' => true,
                    'has_update_config' => true,
                    'has_silent_config' => true
                ]
            ];
        }

        $courseTypesMaps = [];
        $courses = Helper::getCourses();

        foreach ($courses as $course) {
            $courseTypesMaps[$course['id']] = [
                'label' => $course['title']
            ];
        }

        $groups = Helper::getMemberships();

        $groupTypeMaps = [];

        foreach ($groups as $group) {
            $groupTypeMaps[$group['id']] = [
                'label' => $group['title']
            ];
        }

        $tags = Tag::orderBy('title', 'ASC')->get();

        return [
            'config' => [
                'import_type'        => 'course_type',
                'course_types_maps'  => [
                    [
                        'field_key'   => '',
                        'field_value' => ''
                    ]
                ],
                'member_groups_maps' => [
                    [
                        'field_key'   => '',
                        'field_value' => ''
                    ]
                ]
            ],
            'fields' => [
                'import_type'        => [
                    'label'   => __('Import by', 'fluentcampaign-pro'),
                    'help'    => __('Please select import by Membership or course enrollment', 'fluentcampaign-pro'),
                    'type'    => 'input-radio',
                    'options' => [
                        [
                            'id'    => 'course_types',
                            'label' => __('Import By Courses', 'fluentcampaign-pro')
                        ],
                        [
                            'id'    => 'member_groups',
                            'label' => __('Import By Memberships', 'fluentcampaign-pro')
                        ]
                    ]
                ],
                'course_types_maps'  => [
                    'label'              => __('Please map your Courses and associate FluentCRM Tags', 'fluentcampaign-pro'),
                    'type'               => 'form-many-drop-down-mapper',
                    'local_label'        => sprintf(__('Select %s Course', 'fluentcampaign-pro'), $this->getPluginName()),
                    'remote_label'       => __('Select FluentCRM Tag that will be applied', 'fluentcampaign-pro'),
                    'local_placeholder'  => sprintf(__('Select %s Course', 'fluentcampaign-pro'), $this->getPluginName()),
                    'remote_placeholder' => __('Select FluentCRM Tag', 'fluentcampaign-pro'),
                    'fields'             => $courseTypesMaps,
                    'value_options'      => $tags,
                    'dependency'         => [
                        'depends_on' => 'import_type',
                        'operator'   => '=',
                        'value'      => 'course_types'
                    ]
                ],
                'member_groups_maps' => [
                    'label'              => __('Please map your LearnDash Group and associate FluentCRM Tags', 'fluentcampaign-pro'),
                    'type'               => 'form-many-drop-down-mapper',
                    'local_label'        => sprintf(__('Select %s Group', 'fluentcampaign-pro'), $this->getPluginName()),
                    'remote_label'       => __('Select FluentCRM Tag that will be applied', 'fluentcampaign-pro'),
                    'local_placeholder'  => sprintf(__('Select %s Membership', 'fluentcampaign-pro'), $this->getPluginName()),
                    'remote_placeholder' => __('Select FluentCRM Tag', 'fluentcampaign-pro'),
                    'fields'             => $groupTypeMaps,
                    'value_options'      => $tags,
                    'dependency'         => [
                        'depends_on' => 'import_type',
                        'operator'   => '=',
                        'value'      => 'member_groups'
                    ]
                ]
            ],
            'labels' => [
                'step_2' => __('Next [Review Data]', 'fluentcampaign-pro'),
                'step_3' => sprintf(__('Import %s Students Now', 'fluentcampaign-pro'), $this->getPluginName())
            ]
        ];
    }

    public function importData($returnData, $config, $page)
    {
        $type = Arr::get($config, 'import_type');

        if ($type == 'course_types') {
            return $this->importByMemberCourses($config, $page);
        } else if ($type == 'member_groups') {
            return $this->importByMemberGroups($config, $page);
        }

        return new \WP_Error('not_found', 'Invalid Request');
    }


    private function getUserIdsByResourceIds($courseIds, $limit, $offset, $status = 'enrolled')
    {
        if (!$courseIds) {
            return [
                'user_ids' => [],
                'total'    => 0
            ];
        }

        $courseUsers = [];

        foreach ($courseIds as $courseId) {
            $courseUsers = array_merge($this->getUserIdsByResourseId($courseId, $status), $courseUsers);
        }

        $courseUsers = array_unique($courseUsers);

        $total = count($courseUsers);

        $courseUsers = array_slice($courseUsers, $offset, $limit);

        return [
            'user_ids' => $courseUsers,
            'total'    => $total
        ];
    }

    protected function importByMemberCourses($config, $page)
    {
        $inputs = Arr::only($config, [
            'lists', 'update', 'new_status', 'double_optin_email', 'import_silently'
        ]);

        if (Arr::get($inputs, 'import_silently') == 'yes') {
            if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
                define('FLUENTCRM_DISABLE_TAG_LIST_EVENTS', true);
            }
        }

        $sendDoubleOptin = Arr::get($inputs, 'double_optin_email') == 'yes';

        $courseTypesMaps = [];
        foreach ($config['course_types_maps'] as $map) {
            if (!absint($map['field_value']) || !$map['field_key']) {
                continue;
            }

            $typeSlug = $map['field_key'];
            if (!isset($courseTypesMaps[$typeSlug])) {
                $courseTypesMaps[$typeSlug] = [];
            }
            $courseTypesMaps[$typeSlug][] = absint($map['field_value']);
        }

        $limit = 100;
        $offset = ($page - 1) * $limit;

        $courseIds = array_keys($courseTypesMaps);

        $userMaps = $this->getUserIdsByResourceIds($courseIds, $limit, $offset);

        $userIds = $userMaps['user_ids'];

        foreach ($userIds as $userId) {
            // Create user data
            $subscriberData = \FluentCrm\App\Services\Helper::getWPMapUserInfo($userId);
            $subscriberData['source'] = 'lifterlms';
            $subscriberData = array_merge($subscriberData, Helper::getStudentAddress($userId));

            $inCourses = Helper::getUserCourses($userId);

            $tagIds = [];

            foreach ($inCourses as $inCourse) {
                if (!empty($courseTypesMaps[$inCourse])) {
                    $tagIds = array_merge($tagIds, $courseTypesMaps[$inCourse]);
                }
            }

            $tagIds = array_unique($tagIds);

            Subscriber::import(
                [$subscriberData],
                $tagIds,
                $inputs['lists'],
                $inputs['update'],
                $inputs['new_status'],
                $sendDoubleOptin
            );
        }

        return [
            'page_total'   => ceil($userMaps['total'] / $limit),
            'record_total' => $userMaps['total'],
            'has_more'     => $userMaps['total'] > ($page * $limit),
            'current_page' => $page,
            'next_page'    => $page + 1
        ];
        
    }

    protected function importByMemberGroups($config, $page)
    {
        $inputs = Arr::only($config, [
            'lists', 'update', 'new_status', 'double_optin_email', 'import_silently'
        ]);

        if (Arr::get($inputs, 'import_silently') == 'yes') {
            if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
                define('FLUENTCRM_DISABLE_TAG_LIST_EVENTS', true);
            }
        }

        $sendDoubleOptin = Arr::get($inputs, 'double_optin_email') == 'yes';

        $memberGroupsMaps = [];
        foreach ($config['member_groups_maps'] as $map) {
            if (!absint($map['field_value']) || !absint($map['field_key'])) {
                continue;
            }

            $groupId = absint($map['field_key']);
            if (!isset($memberGroupsMaps[$groupId])) {
                $memberGroupsMaps[$groupId] = [];
            }
            $memberGroupsMaps[$groupId][] = absint($map['field_value']);
        }

        $limit = 100;
        $offset = ($page - 1) * $limit;

        $groupIds = array_keys($memberGroupsMaps);
        $userMaps = $this->getUserIdsByResourceIds($groupIds, $limit, $offset);

        $userIds = $userMaps['user_ids'];

        foreach ($userIds as $userId) {
            // Create user data
            $subscriberData = \FluentCrm\App\Services\Helper::getWPMapUserInfo($userId);
            $subscriberData['source'] = 'lifterlms';
            $subscriberData = array_merge($subscriberData, Helper::getStudentAddress($userId));

            $inGroups = Helper::getUserMemberships($userId);
            $tagIds = [];

            foreach ($inGroups as $inGroup) {
                if (!empty($memberGroupsMaps[$inGroup])) {
                    $tagIds = array_merge($tagIds, $memberGroupsMaps[$inGroup]);
                }
            }

            $tagIds = array_unique($tagIds);

            Subscriber::import(
                [$subscriberData],
                $tagIds,
                $inputs['lists'],
                $inputs['update'],
                $inputs['new_status'],
                $sendDoubleOptin
            );
        }

        return [
            'page_total'   => ceil($userMaps['total'] / $limit),
            'record_total' => $userMaps['total'],
            'has_more'     => $userMaps['total'] > ($page * $limit),
            'current_page' => $page,
            'next_page'    => $page + 1
        ];

    }


    protected function getUserIdsByResourseId($resourceId, $status = 'enrolled')
    {
        global $wpdb;
        $users = $wpdb->get_results("SELECT u.ID as id, (
SELECT meta_value
FROM wp_lifterlms_user_postmeta
WHERE meta_key = '_status'
AND user_id = id
AND post_id = $resourceId
ORDER BY updated_date DESC
LIMIT 1 ) AS status
FROM wp_users as u
HAVING status IS NOT NULL
AND status = '{$status}'");

        $userIds = [];
        foreach ($users as $user) {
            $userIds[] = $user->id;
        }

        return $userIds;
    }
}
