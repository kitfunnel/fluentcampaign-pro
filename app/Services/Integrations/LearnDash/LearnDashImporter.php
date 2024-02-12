<?php

namespace FluentCampaign\App\Services\Integrations\LearnDash;

use FluentCampaign\App\Services\Integrations\BaseImporter;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\Tag;
use FluentCrm\Framework\Support\Arr;

class LearnDashImporter extends BaseImporter
{

    public function __construct()
    {
        $this->importKey = 'learndash';
        parent::__construct();
    }

    private function getPluginName()
    {
        return 'Learndash';
    }


    public function getInfo()
    {
        return [
            'label'    => $this->getPluginName(),
            'logo'     => fluentCrmMix('images/learndash.png'),
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
                $selectedUsers = $this->getUserIdsByGroupIds($groupIds, 5, 0);

            } else if ($type == 'course_types') {
                $courseIds = [];
                foreach ($config['course_types_maps'] as $map) {
                    $courseIds[] = $map['field_key'];
                }
                $courseIds = array_filter(array_unique($courseIds));
                $selectedUsers = $this->getUserIdsByCourseIds($courseIds, 5, 0);
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

        $groups = Helper::getGroups();

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
                    'help'    => __('Please select import by group or course enrollment', 'fluentcampaign-pro'),
                    'type'    => 'input-radio',
                    'options' => [
                        [
                            'id'    => 'course_types',
                            'label' => __('Import By Courses', 'fluentcampaign-pro')
                        ],
                        [
                            'id'    => 'member_groups',
                            'label' => __('Import By Member Groups', 'fluentcampaign-pro')
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
                    'local_placeholder'  => sprintf(__('Select %s Group', 'fluentcampaign-pro'), $this->getPluginName()),
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

    private function getUserIdsByGroupIds($groupIds, $limit, $offset)
    {
        if (!$groupIds) {
            return [
                'user_ids' => [],
                'total'    => 0
            ];
        }

        $keys = [];

        foreach ($groupIds as $groupId) {
            $keys[] = 'learndash_group_users_' . $groupId;
        }

        $query = fluentCrmDb()->table('usermeta')
            ->select(['user_id'])
            ->groupBy('user_id')
            ->whereIn('meta_key', $keys);

        $total = count(fluentCrmDb()->table('usermeta')
            ->select(['user_id'])
            ->groupBy('user_id')
            ->whereIn('meta_key', $keys)->get());

        $users = $query->limit($limit)
            ->offset($offset)
            ->get();

        $userIds = [];

        foreach ($users as $user) {
            $userIds[] = $user->user_id;
        }

        return [
            'user_ids' => $userIds,
            'total'    => $total
        ];
    }

    private function getUserIdsByCourseIds($courseIds, $limit, $offset)
    {
        if (!$courseIds) {
            return [
                'user_ids' => [],
                'total'    => 0
            ];
        }

        $keys = [];

        foreach ($courseIds as $courseId) {
            $keys[] = 'course_' . $courseId . '_access_from';
        }

        $query = fluentCrmDb()->table('usermeta')
            ->select(['user_id'])
            ->groupBy('user_id')
            ->whereIn('meta_key', $keys);


        $total = count(fluentCrmDb()->table('usermeta')
            ->select(['user_id'])
            ->groupBy('user_id')
            ->whereIn('meta_key', $keys)->get());

        $users = $query->limit($limit)
            ->offset($offset)
            ->get();

        $userIds = [];

        foreach ($users as $user) {
            $userIds[] = $user->user_id;
        }

        return [
            'user_ids' => $userIds,
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

        $userMaps = $this->getUserIdsByCourseIds($courseIds, $limit, $offset);

        $userIds = $userMaps['user_ids'];

        foreach ($userIds as $userId) {
            // Create user data
            $subscriberData = \FluentCrm\App\Services\Helper::getWPMapUserInfo($userId);
            $subscriberData['source'] = 'learndash';

            $inCourses = $this->getCourseIdsByUserId($userId);

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
                Arr::get($inputs, 'lists', []),
                Arr::get($inputs, 'update', 'yes'),
                Arr::get($inputs, 'new_status', 'subscribed'),
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
            if (!absint($map['field_value']) && !absint($map['field_key'])) {
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
        $userMaps = $this->getUserIdsByGroupIds($groupIds, $limit, $offset);

        $userIds = $userMaps['user_ids'];

        foreach ($userIds as $userId) {
            // Create user data
            $subscriberData = \FluentCrm\App\Services\Helper::getWPMapUserInfo($userId);
            $subscriberData['source'] = 'learndash';

            $inGroups = $this->getGroupIdsByUserId($userId);
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
                Arr::get($inputs, 'lists', []),
                Arr::get($inputs, 'update'),
                Arr::get($inputs, 'new_status', 'subscribed'),
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

    private function getCourseIdsByUserId($userId)
    {
        $courseIds = learndash_get_user_courses_from_meta($userId);
        $course_ids_groups = learndash_get_user_groups_courses_ids($userId);
        if (!empty($course_ids_groups)) {
            $courseIds = array_merge($courseIds, $course_ids_groups);
        }

        return array_unique($courseIds);
    }

    private function getGroupIdsByUserId($userId)
    {
        return learndash_get_users_group_ids($userId);
    }
}
