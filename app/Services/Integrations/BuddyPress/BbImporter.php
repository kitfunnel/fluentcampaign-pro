<?php

namespace FluentCampaign\App\Services\Integrations\BuddyPress;

use FluentCampaign\App\Services\Integrations\BaseImporter;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\Tag;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;

class BbImporter extends BaseImporter
{

    public function __construct()
    {
        $this->importKey = 'buddypress';
        parent::__construct();
    }


    private function isBoss()
    {
        return defined('BP_PLATFORM_VERSION');
    }

    private function getPluginName()
    {
        if($this->isBoss()) {
            return 'BuddyBoss';
        }

        return 'BuddyPress';
    }

    public function getInfo()
    {
        $logo = fluentCrmMix('images/buddypress.png');

        if(defined('BP_PLATFORM_VERSION')) {
            $logo = fluentCrmMix('images/buddyboss.svg');
        }

        return [
            'label'    => $this->getPluginName(),
            'logo'     => $logo,
            'disabled' => false
        ];
    }

    public function processUserDriver($config, $request)
    {
        $summary = $request->get('summary');

        if ($summary) {
            $config = $request->get('config');

            $type = Arr::get($config, 'import_type');

            if ($type == 'bp-groups') {
                $groupIds = [];
                foreach ($config['member_groups_maps'] as $map) {
                    $groupIds[] = absint($map['field_key']);
                }
                $groupIds = array_filter(array_unique($groupIds));
                $selectedUsers = $this->getUserIdsByGroupIds($groupIds, 5, 0);
            } else if ($type == 'member_type') {
                $memberTypeSlugs = [];
                foreach ($config['member_type_maps'] as $map) {
                    $memberTypeSlugs[] = $map['field_key'];
                }
                $memberTypeSlugs = array_filter(array_unique($memberTypeSlugs));
                $selectedUsers = $this->getUserIdsByMemberTypeSlugs($memberTypeSlugs, 5, 0);
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

        $memberTypeMaps = [];
        $typeTerms = bp_get_member_types( array(), 'objects' );

        foreach ($typeTerms as $type) {
            $memberTypeMaps[esc_attr( $type->name )] = [
                'label' => $type->labels['singular_name']
            ];
        }

        $groupTypeMaps = [];

        if (class_exists('\BP_Groups_Group')) {
            $groups = \BP_Groups_Group::get(array(
                'type'        => 'alphabetical',
                'per_page'    => 199,
                'show_hidden' => true
            ));

            foreach ($groups['groups'] as $group) {
                $groupTypeMaps[$group->id] = [
                    'label' => $group->name
                ];
            }
        }

        $tags = Tag::get();

        return [
            'config' => [
                'import_type'        => 'member_type',
                'member_type_maps'   => [
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
                    'label'   => sprintf(__('Select %s Import Type', 'fluentcampaign-pro'), $this->getPluginName()),
                    'help'    => __('Please select Member Type or Member group that you want to import', 'fluentcampaign-pro'),
                    'type'    => 'input-radio',
                    'options' => [
                        [
                            'id'    => 'member_type',
                            'label' => __('Import By Member Type', 'fluentcampaign-pro')
                        ],
                        [
                            'id'    => 'bp-groups',
                            'label' => __('Import By Member Groups', 'fluentcampaign-pro')
                        ]
                    ]
                ],
                'member_type_maps'   => [
                    'label'              => __('Please map your Member Type and associate FluentCRM Tags', 'fluentcampaign-pro'),
                    'type'               => 'form-many-drop-down-mapper',
                    'local_label'        => sprintf(__('Select %s Member Type', 'fluentcampaign-pro'), $this->getPluginName()),
                    'remote_label'       => __('Select FluentCRM Tag that will be applied', 'fluentcampaign-pro'),
                    'local_placeholder'  => sprintf(__('Select %s Member Type', 'fluentcampaign-pro'), $this->getPluginName()),
                    'remote_placeholder' => __('Select FluentCRM Tag', 'fluentcampaign-pro'),
                    'fields'             => $memberTypeMaps,
                    'value_options'      => $tags,
                    'dependency'         => [
                        'depends_on' => 'import_type',
                        'operator'   => '=',
                        'value'      => 'member_type'
                    ]
                ],
                'member_groups_maps' => [
                    'label'              => __('Please map your Group and associate FluentCRM Tags', 'fluentcampaign-pro'),
                    'type'               => 'form-many-drop-down-mapper',
                    'local_label'        => sprintf(__('Select %s Group', 'fluentcampaign-pro'), $this->getPluginName()),
                    'remote_label'       => 'Select FluentCRM Tag that will be applied',
                    'local_placeholder'  => sprintf(__('Select %s Group', 'fluentcampaign-pro'), $this->getPluginName()),
                    'remote_placeholder' => __('Select FluentCRM Tag', 'fluentcampaign-pro'),
                    'fields'             => $groupTypeMaps,
                    'value_options'      => $tags,
                    'dependency'         => [
                        'depends_on' => 'import_type',
                        'operator'   => '=',
                        'value'      => 'bp-groups'
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

        if ($type == 'member_type') {
            return $this->importByMemberTypes($config, $page);
        } else if($type == 'bp-groups') {
            return $this->importByMemberGroups($config, $page);
        }

        return new \WP_Error('not_found', 'Invalid Request');

    }

    private function getUserIdsByGroupIds($groupIds, $limit, $offset)
    {
        $total = fluentCrmDb()->table('bp_groups_members')
            ->whereIn('group_id', $groupIds)
            ->distinct()
            ->count('user_id');

        $users = fluentCrmDb()->table('bp_groups_members')
            ->select(['user_id'])
            ->groupBy('user_id')
            ->whereIn('group_id', $groupIds)
            ->limit($limit)
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

    private function getUserIdsByMemberTypeSlugs($typeSlugs, $limit, $offset)
    {
        $taxName = bp_get_member_type_tax_name();

        $termIds = [];
        foreach ($typeSlugs as $slug) {
            $type = bp_get_term_by( 'slug', $slug,  $taxName);
            $termIds[] = $type->term_id;
        }

        $userIds = bp_get_objects_in_term($termIds, $taxName);

        if(is_wp_error($userIds)) {
            return [
                'user_ids' => [],
                'total' => 0
            ];
        }

        return [
            'user_ids' => array_slice($userIds, $offset, $limit),
            'total'    => count($userIds)
        ];
    }

    protected function importByMemberTypes($config, $page)
    {
        $inputs = Arr::only($config, [
           'lists','update', 'new_status', 'double_optin_email', 'import_silently'
        ]);

        if (Arr::get($inputs, 'import_silently') == 'yes') {
            if(!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
                define('FLUENTCRM_DISABLE_TAG_LIST_EVENTS', true);
            }
        }

        $sendDoubleOptin = Arr::get($inputs, 'double_optin_email') == 'yes';

        $memberTypeMaps = [];
        foreach ($config['member_type_maps'] as $map) {
            if (!absint($map['field_value']) || !$map['field_key']) {
                continue;
            }

            $typeSlug = $map['field_key'];
            if (!isset($memberTypeMaps[$typeSlug])) {
                $memberTypeMaps[$typeSlug] = [];
            }
            $memberTypeMaps[$typeSlug][] = absint($map['field_value']);
        }

        $limit = 100;
        $offset = ($page - 1) * $limit;

        $typeSlugs = array_keys($memberTypeMaps);
        $userMaps = $this->getUserIdsByMemberTypeSlugs($typeSlugs, $limit, $offset);

        $userIds = $userMaps['user_ids'];

        foreach ($userIds as $userId) {
            // Create user data
            $subscriberData = Helper::getWPMapUserInfo($userId);
            $subscriberData['source'] = 'buddypress';

            $inTypes = $this->getTypeSlugsByUserId($userId);

            $tagIds = [];

            foreach ($inTypes as $inType) {
                if(!empty($memberTypeMaps[$inType])) {
                    $tagIds = array_merge($tagIds, $memberTypeMaps[$inType]);
                }
            }

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
           'lists','update', 'new_status', 'double_optin_email', 'import_silently'
        ]);

        if (Arr::get($inputs, 'import_silently') == 'yes') {
            if(!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
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
        $userMaps = $this->getUserIdsByGroupIds($groupIds, $limit, $offset);

        $userIds = $userMaps['user_ids'];

        foreach ($userIds as $userId) {
            // Create user data
            $subscriberData = Helper::getWPMapUserInfo($userId);
            $subscriberData['source'] = 'buddypress';

            $inGroups = $this->getGroupIdsByUserId($userId);
            $tagIds = [];

            foreach ($inGroups as $inGroup) {
                if(!empty($memberGroupsMaps[$inGroup])) {
                    $tagIds = array_merge($tagIds, $memberGroupsMaps[$inGroup]);
                }
            }

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

    private function getTypeSlugsByUserId($userId)
    {
        $types = (array) bp_get_member_type($userId, false);

        return array_unique($types);
    }

    private function getGroupIdsByUserId($userId)
    {
        $groups = fluentCrmDb()->table('bp_groups_members')
            ->where('user_id', $userId)
            ->select('group_id')
            ->get();

        $groupIds = [];

        foreach ($groups as $group) {
            $groupIds[] = $group->group_id;
        }

        return array_unique($groupIds);
    }
}
