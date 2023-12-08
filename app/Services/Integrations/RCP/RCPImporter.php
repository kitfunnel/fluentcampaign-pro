<?php

namespace FluentCampaign\App\Services\Integrations\RCP;

use FluentCampaign\App\Services\Integrations\BaseImporter;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\Tag;
use FluentCrm\Framework\Support\Arr;

class RCPImporter extends BaseImporter
{
    public function __construct()
    {
        $this->importKey = 'rcp';
        parent::__construct();
    }

    private function getPluginName()
    {
        return 'Restrict Content Pro';
    }


    public function getInfo()
    {
        return [
            'label'    => $this->getPluginName(),
            'logo'     => fluentCrmMix('images/rcp.png'),
            'disabled' => false
        ];
    }

    public function processUserDriver($config, $request)
    {
        $summary = $request->get('summary');

        if ($summary) {
            $config = $request->get('config');

            $type = Arr::get($config, 'import_type');

            if ($type == 'membership_level') {
                $levelIds = [];
                foreach ($config['level_type_maps'] as $map) {
                    $levelIds[] = $map['field_key'];
                }
                $levelIds = array_filter(array_unique($levelIds));
                $selectedUsers = $this->getUserIdsByResourceIds($levelIds, 5, 0);
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

        $levels = Helper::getMembershipLevels();

        $levelMaps = [];

        foreach ($levels as $level) {
            $levelMaps[$level['id']] = [
                'label' => $level['title']
            ];
        }

        $tags = Tag::orderBy('title', 'ASC')->get();

        return [
            'config' => [
                'import_type'     => 'membership_level',
                'level_type_maps' => [
                    [
                        'field_key'   => '',
                        'field_value' => ''
                    ]
                ]
            ],
            'fields' => [
                'import_type'     => [
                    'label'   => __('Import by', 'fluentcampaign-pro'),
                    'help'    => __('Please select import by Membership Level', 'fluentcampaign-pro'),
                    'type'    => 'input-radio',
                    'options' => [
                        [
                            'id'    => 'membership_level',
                            'label' => __('Import By Membership Level', 'fluentcampaign-pro')
                        ]
                    ]
                ],
                'level_type_maps' => [
                    'label'                 => __('Please map your Membership Levels and associate FluentCRM Tags', 'fluentcampaign-pro'),
                    'type'                  => 'form-many-drop-down-mapper',
                    'local_label'           => sprintf(__('Select %s Membership', 'fluentcampaign-pro'), $this->getPluginName()),
                    'remote_label'          => __('Select FluentCRM Tag that will be applied', 'fluentcampaign-pro'),
                    'local_placeholder'     => sprintf(__('Select %s Membership', 'fluentcampaign-pro'), $this->getPluginName()),
                    'remote_placeholder'    => __('Select FluentCRM Tag', 'fluentcampaign-pro'),
                    'fields'                => $levelMaps,
                    'value_option_selector' => [
                        'option_key' => 'tags',
                        'creatable'  => true
                    ],
                    'dependency'            => [
                        'depends_on' => 'import_type',
                        'operator'   => '=',
                        'value'      => 'membership_level'
                    ]
                ]
            ],
            'labels' => [
                'step_2' => __('Next [Review Data]', 'fluentcampaign-pro'),
                'step_3' => sprintf(__('Import %s Members Now', 'fluentcampaign-pro'), $this->getPluginName())
            ]
        ];
    }

    public function importData($returnData, $config, $page)
    {
        $type = Arr::get($config, 'import_type');

        if ($type == 'membership_level') {
            return $this->importByMembershipLevels($config, $page);
        }

        return new \WP_Error('not_found', 'Invalid Request');

    }

    private function getUserIdsByResourceIds($levelIds, $limit, $offset)
    {
        if (!$levelIds) {
            return [
                'user_ids' => [],
                'total'    => 0
            ];
        }

        $userIds = [];

        $enrollments = fluentCrmDb()->table('rcp_memberships')
            ->select(['user_id'])
            ->groupBy('user_id')
            ->where('object_type', 'membership')
            ->where('status', 'active')
            ->whereIn('object_id', $levelIds);

        $total = fluentCrmDb()->table('rcp_memberships')
            ->where('object_type', 'membership')
            ->where('status', 'active')
            ->whereIn('object_id', $levelIds)
            ->distinct()
            ->count('user_id');

        $enrollments = $enrollments->limit($limit)
            ->offset($offset)
            ->get();

        foreach ($enrollments as $enrollment) {
            $userIds[] = $enrollment->user_id;
        }

        return [
            'user_ids' => $userIds,
            'total'    => $total
        ];
    }

    protected function importByMembershipLevels($config, $page)
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

        $membershipMaps = [];
        foreach ($config['level_type_maps'] as $map) {
            if (!absint($map['field_value']) || !$map['field_key']) {
                continue;
            }

            $typeSlug = $map['field_key'];
            if (!isset($membershipMaps[$typeSlug])) {
                $membershipMaps[$typeSlug] = [];
            }
            $membershipMaps[$typeSlug][] = absint($map['field_value']);
        }

        $limit = 100;
        $offset = ($page - 1) * $limit;

        $membershipIds = array_keys($membershipMaps);

        $userMaps = $this->getUserIdsByResourceIds($membershipIds, $limit, $offset);

        $userIds = $userMaps['user_ids'];

        foreach ($userIds as $userId) {
            // Create user data
            $subscriberData = \FluentCrm\App\Services\Helper::getWPMapUserInfo($userId);
            $subscriberData['source'] = 'rcp';

            $inLevels = Helper::getUserLevels($userId);

            $tagIds = [];

            foreach ($inLevels as $inLevel) {
                if (!empty($membershipMaps[$inLevel])) {
                    $tagIds = array_merge($tagIds, $membershipMaps[$inLevel]);
                }
            }

            $tagIds = array_unique($tagIds);

            if (!$tagIds) {
                continue;
            }

            $lists = Arr::get($inputs, 'lists', []);

            Subscriber::import(
                [$subscriberData],
                $tagIds,
                $lists,
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
}
