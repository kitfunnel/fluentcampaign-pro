<?php

namespace FluentCampaign\App\Services\Integrations\LearnPress;

use FluentCampaign\App\Services\Integrations\BaseImporter;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\Tag;
use FluentCrm\Framework\Support\Arr;

class LearnPressImporter extends BaseImporter
{
    public function __construct()
    {
        $this->importKey = 'learnpress';
        parent::__construct();
    }


    private function getPluginName()
    {
        return 'LearnPress';
    }


    public function getInfo()
    {
        return [
            'label'    => $this->getPluginName(),
            'logo'     => fluentCrmMix('images/learnpress.png'),
            'disabled' => false
        ];
    }

    public function processUserDriver($config, $request)
    {
        $summary = $request->get('summary');

        if ($summary) {
            $config = $request->get('config');

            $type = Arr::get($config, 'import_type');

            if ($type == 'course_type') {
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

        $courses = $this->getCourses();

        $tags = Tag::orderBy('title', 'ASC')->get();

        return [
            'config' => [
                'import_type'        => 'course_type',
                'course_types_maps'  => [
                    [
                        'field_key'   => '',
                        'field_value' => ''
                    ]
                ]
            ],
            'fields' => [
                'import_type'        => [
                    'label'   => __('Import by', 'fluentcampaign-pro'),
                    'help'    => __('Please select import by course enrollment', 'fluentcampaign-pro'),
                    'type'    => 'input-radio',
                    'options' => [
                        [
                            'id'    => 'course_type',
                            'label' => 'Import By Courses'
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
                    'fields'             => $courses,
                    'value_options'      => $tags,
                    'dependency'         => [
                        'depends_on' => 'import_type',
                        'operator'   => '=',
                        'value'      => 'course_type'
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

        if ($type == 'course_type') {
            return $this->importByMemberCourses($config, $page);
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

        $enrollments = fluentCrmDb()->table('learnpress_user_items')
            ->select(['user_id'])
            ->where('item_type', 'lp_course')
            ->where('status', $status)
            ->whereIn('item_id', $courseIds)
            ->groupBy('user_id');

        $total = fluentCrmDb()->table('learnpress_user_items')
            ->where('item_type', 'lp_course')
            ->where('status', $status)
            ->whereIn('item_id', $courseIds)
            ->distinct()
            ->count('user_id');

        $enrollments = $enrollments->limit($limit)
            ->offset($offset)
            ->get();

        foreach ($enrollments as $enrollment)
        {
            $courseUsers[] = $enrollment->user_id;
        }

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
            $subscriberData['source'] = 'learnpress';

            $inCourses = $this->getUserCourses($userId);

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

    protected function getCourses()
    {
        $courses = get_posts(array(
            'post_type' => 'lp_course',
            'numberposts' => -1
        ));

        $formattedCourses = [];
        foreach ($courses as $course) {
            $formattedCourses[strval($course->ID)] = [
                'label' => $course->post_title
            ];
        }

        return $formattedCourses;
    }

    protected function getUserCourses($userId, $status = 'enrolled')
    {
        $enrollments = fluentCrmDb()->table('learnpress_user_items')
            ->select(['item_id'])
            ->where('item_type', 'lp_course')
            ->where('status', $status)
            ->where('user_id', $userId)
            ->groupBy('item_id')
            ->get();

        $courses = [];

        foreach ($enrollments as $enrollment) {
            $courses[] = $enrollment->item_id;
        }

        return array_unique($courses);

    }
}
