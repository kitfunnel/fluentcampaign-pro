<?php

namespace FluentCampaign\App\Services\Funnel\Conditions;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\App\Services\Libs\ConditionAssessor;
use FluentCrm\Framework\Support\Arr;

class FunnelCondition
{
    protected $priority = 10;

    protected $name = 'funnel_condition';

    public function register()
    {
        add_filter('fluentcrm_funnel_blocks', array($this, 'pushBlock'), $this->priority, 2);
        add_filter('fluentcrm_funnel_block_fields', array($this, 'pushBlockFields'), $this->priority, 2);
        add_action('fluentcrm_funnel_sequence_handle_funnel_condition', array($this, 'handle'), 10, 4);
    }

    public function pushBlock($blocks, $funnel)
    {
        $blocks[$this->name] = [
            'type'             => 'conditional',
            'title'            => __('Check Condition', 'fluentcampaign-pro'),
            'description'      => __('Check If the contact match specific data properties', 'fluentcampaign-pro'),
            'icon'             => 'fc-icon-conditions',//fluentCrmMix('images/funnel_icons/has_contact_property.svg'),
            'settings'         => [
                'conditions' => [[]]
            ],
            'reload_on_insert' => true
        ];
        return $blocks;
    }

    public function pushBlockFields($allFields, $funnel)
    {
        $allFields[$this->name] = [
            'title'     => __('Check Condition', 'fluentcampaign-pro'),
            'sub_title' => __('Check If the contact match specific data properties', 'fluentcampaign-pro'),
            'fields'    => [
                'conditions' => [
                    'type'        => 'condition_block_groups',
                    'label'       => __('Specify Matching Conditions', 'fluentcampaign-pro'),
                    'inline_help' => __('Specify which contact properties need to matched. Based on the conditions it will run yes blocks or no blocks', 'fluentcampaign-pro'),
                    'labels'      => [
                        'match_type_all_label' => __('True if all conditions match', 'fluentcampaign-pro'),
                        'match_type_any_label' => __('True if any of the conditions match', 'fluentcampaign-pro'),
                        'data_key_label'       => __('Contact Data', 'fluentcampaign-pro'),
                        'condition_label'      => __('Condition', 'fluentcampaign-pro'),
                        'data_value_label'     => __('Match Value', 'fluentcampaign-pro')
                    ],
                    'groups'      => $this->getConditionGroups($funnel),
                    'add_label'   => __('Add Condition to check your contact\'s properties', 'fluentcampaign-pro'),
                ]
            ]
        ];
        return $allFields;
    }

    public function getConditionGroups($funnel)
    {
        $groups = [
            'subscriber' => [
                'label'    => __('Contact', 'fluentcampaign-pro'),
                'value'    => 'subscriber',
                'children' => [
                    [
                        'label' => __('First Name', 'fluentcampaign-pro'),
                        'value' => 'first_name',
                        'type'  => 'nullable_text'
                    ],
                    [
                        'label' => __('Last Name', 'fluentcampaign-pro'),
                        'value' => 'last_name',
                        'type'  => 'nullable_text'
                    ],
                    [
                        'label' => __('Email', 'fluentcampaign-pro'),
                        'value' => 'email',
                        'type'  => 'extended_text'
                    ],
                    [
                        'label' => __('Address Line 1', 'fluentcampaign-pro'),
                        'value' => 'address_line_1',
                        'type'  => 'nullable_text'
                    ],
                    [
                        'label' => __('Address Line 2', 'fluentcampaign-pro'),
                        'value' => 'address_line_2',
                        'type'  => 'nullable_text'
                    ],
                    [
                        'label' => __('City', 'fluentcampaign-pro'),
                        'value' => 'city',
                        'type'  => 'nullable_text'
                    ],
                    [
                        'label' => __('State', 'fluentcampaign-pro'),
                        'value' => 'state',
                        'type'  => 'nullable_text'
                    ],
                    [
                        'label' => __('Postal Code', 'fluentcampaign-pro'),
                        'value' => 'postal_code',
                        'type'  => 'nullable_text'
                    ],
                    [
                        'label'             => __('Country', 'fluentcampaign-pro'),
                        'value'             => 'country',
                        'type'              => 'selections',
                        'component'         => 'options_selector',
                        'option_key'        => 'countries',
                        'is_multiple'       => true,
                        'is_singular_value' => true
                    ],
                    [
                        'label' => __('Phone', 'fluentcampaign-pro'),
                        'value' => 'phone',
                        'type'  => 'nullable_text'
                    ],
                    [
                        'label' => __('WP User ID', 'fluentcampaign-pro'),
                        'value' => 'user_id',
                        'type'  => 'numeric',
                    ],
                    [
                        'label'             => __('Type', 'fluentcampaign-pro'),
                        'value'             => 'contact_type',
                        'type'              => 'selections',
                        'component'         => 'options_selector',
                        'option_key'        => 'contact_types',
                        'is_multiple'       => false,
                        'is_singular_value' => true
                    ],
                    [
                        'label'       => __('Name Prefix (Title)', 'fluentcampaign-pro'),
                        'value'       => 'prefix',
                        'type'        => 'selections',
                        'options'     => \FluentCrm\App\Services\Helper::getContactPrefixes(true),
                        'is_multiple' => true,
                        'is_only_in'  => true
                    ],
                    [
                        'label' => __('Last Activity', 'fluentcampaign-pro'),
                        'value' => 'last_activity',
                        'type'  => 'dates',
                    ],
                    [
                        'label' => __('Created At', 'fluentcampaign-pro'),
                        'value' => 'created_at',
                        'type'  => 'dates',
                    ]
                ],
            ],
            'segment'    => [
                'label'    => __('Contact Segment', 'fluentcampaign-pro'),
                'value'    => 'segment',
                'children' => [
                    [
                        'label'       => __('Tags', 'fluentcampaign-pro'),
                        'value'       => 'tags',
                        'type'        => 'selections',
                        'component'   => 'options_selector',
                        'option_key'  => 'tags',
                        'is_multiple' => true,
                    ],
                    [
                        'label'       => __('Lists', 'fluentcampaign-pro'),
                        'value'       => 'lists',
                        'type'        => 'selections',
                        'component'   => 'options_selector',
                        'option_key'  => 'lists',
                        'is_multiple' => true,
                    ],
                    [
                        'label'             => __('WP User Role', 'fluentcampaign-pro'),
                        'value'             => 'user_role',
                        'type'              => 'selections',
                        'is_singular_value' => true,
                        'options'           => FunnelHelper::getUserRoles(true),
                        'is_multiple'       => true,
                    ]
                ],
            ],
            'activities' => [
                'label'    => __('Contact Activities', 'fluentcampaign-pro'),
                'value'    => 'activities',
                'children' => [
                    [
                        'label' => __('Last Email Sent', 'fluentcampaign-pro'),
                        'value' => 'email_sent',
                        'type'  => 'dates',
                    ],
                    [
                        'label' => __('Last Email Clicked', 'fluentcampaign-pro'),
                        'value' => 'email_link_clicked',
                        'type'  => 'dates',
                    ],
                    [
                        'label' => __('Last Email Open (approximately)', 'fluentcampaign-pro'),
                        'value' => 'email_opened',
                        'type'  => 'dates',
                    ]
                ]
            ]
        ];

        if ($customFields = fluentcrm_get_custom_contact_fields()) {
            // form data for custom fields in groups
            $children = [];
            foreach ($customFields as $field) {
                $item = [
                    'label' => $field['label'],
                    'value' => $field['slug'],
                    'type'  => $field['type'],
                ];

                if ($item['type'] == 'number') {
                    $item['type'] = 'numeric';
                } else if ($item['type'] == 'date') {
                    $item['type'] = 'dates';
                    $item['date_type'] = 'date';
                    $item['value_format'] = 'yyyy-MM-dd';
                } else if($item['type'] == 'date_time') {
                    $item['type'] = 'dates';
                    $item['has_time'] = 'yes';
                    $item['date_type'] = 'datetime';
                    $item['value_format'] = 'yyyy-MM-dd HH:mm:ss';
                } else if (isset($field['options'])) {
                    $item['type'] = 'selections';
                    $options = $field['options'];
                    $formattedOptions = [];
                    foreach ($options as $option) {
                        $formattedOptions[$option] = $option;
                    }
                    $item['options'] = $formattedOptions;
                    $isMultiple = in_array($field['type'], ['checkbox', 'select-multi']);
                    $item['is_multiple'] = $isMultiple;
                    if ($isMultiple) {
                        $item['is_singular_value'] = true;
                    }
                } else {
                    $item['type'] = 'extended_text';
                }

                $children[] = $item;

            }

            $groups['custom_fields'] = [
                'label'    => __('Custom Fields', 'fluentcampaign-pro'),
                'value'    => 'custom_fields',
                'children' => $children
            ];
        }

        $groups = apply_filters('fluentcrm_automation_condition_groups', $groups, $funnel);

        $otherConditions = apply_filters('fluentcrm_automation_custom_conditions', [], $funnel);

        if (!empty($otherConditions)) {
            $groups['other'] = [
                'label'    => __('Other', 'fluentcampaign-pro'),
                'value'    => 'other',
                'children' => $otherConditions
            ];
        }

        return array_values($groups);
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        // we are renewing the subscriber's data, because previous step may change the data
        $subscriber = Subscriber::find($subscriber->id);

        if (!$subscriber) {
            return false;
        }

        $conditions = Arr::get($sequence->settings, 'conditions', []);

        $isYes = $this->assessConditionGroups($conditions, $subscriber, $sequence, $funnelSubscriberId);

        (new FunnelProcessor())->initChildSequences($sequence, $isYes, $subscriber, $funnelSubscriberId, $funnelMetric);
    }

    protected function assessConditionGroups($conditionGroups, $subscriber, $sequence, $funnelSubscriberId)
    {
        foreach ($conditionGroups as $conditions) {
            $result = $this->assesConditions($conditions, $subscriber, $sequence, $funnelSubscriberId);
            if ($result) {
                return true;
            }
        }
        return false;
    }

    protected function assesConditions($conditions, $subscriber, $sequence, $funnelSubscriberId)
    {
        $formattedGroups = $this->formatGroup($conditions);

        foreach ($formattedGroups as $groupName => $group) {
            if ($groupName == 'subscriber') {
                $subscriberData = $subscriber->toArray();
                if (!ConditionAssessor::matchAllConditions($group, $subscriberData)) {
                    return false;
                }
            } else if ($groupName == 'custom_fields') {
                $customData = $subscriber->custom_fields();
                if (!ConditionAssessor::matchAllConditions($group, $customData)) {
                    return false;
                }
            } else if ($groupName == 'segment') {
                if (!$this->assessSegmentConditions($group, $subscriber)) {
                    return false;
                }
            } else if ($groupName == 'activities') {
                if (!$this->assessActivities($group, $subscriber)) {
                    return false;
                }
            } else if ($groupName == 'other') {
                foreach ($group as $condition) {
                    $prop = $condition['data_key'];
                    if (!apply_filters('fluentcrm_automation_custom_condition_assert_' . $prop, true, $condition, $subscriber, $sequence, $funnelSubscriberId)) {
                        return false;
                    }
                }
            } else {
                $result = apply_filters("fluentcrm_automation_conditions_assess_$groupName", true, $group, $subscriber, $sequence, $funnelSubscriberId);
                if (!$result) {
                    return false;
                }
            }
        }

        return true;
    }


    protected function formatGroup($conditions)
    {
        $groups = [];

        foreach ($conditions as $filterItem) {

            if (count($filterItem['source']) != 2 || empty($filterItem['source'][0]) || empty($filterItem['source'][1]) || empty($filterItem['operator'])) {
                continue;
            }
            $provider = $filterItem['source'][0];

            if (!isset($groups[$provider])) {
                $groups[$provider] = [];
            }

            $property = $filterItem['source'][1];

            $groups[$provider][] = [
                'data_key'   => $property,
                'operator'   => $filterItem['operator'],
                'data_value' => $filterItem['value']
            ];
        }

        return $groups;
    }


    protected function assessActivities($conditions, $subscriber)
    {
        $formattedInputs = [];
        foreach ($conditions as $condition) {
            $prop = $condition['data_key'];
            $formattedInputs[$prop] = $subscriber->lastActivityDate($prop);
        }

        return ConditionAssessor::matchAllConditions($conditions, $formattedInputs);
    }

    protected function assessSegmentConditions($conditions, $subscriber)
    {
        foreach ($conditions as $condition) {
            $prop = $condition['data_key'];
            if ($prop == 'tags') {
                $items = $subscriber->tags->pluck('id')->toArray();
            } else if ($prop == 'lists') {
                $items = $subscriber->lists->pluck('id')->toArray();
            } else if ($prop == 'user_role') {
                $items = [];
                $user = $subscriber->getWpUser();
                if ($user) {
                    $items = array_values($user->roles);
                }
            } else {
                return false;
            }

            $inputs = [];
            $inputs[$prop] = $items;

            if (!ConditionAssessor::assess($condition, $inputs)) {
                return false;
            }
        }

        return true;
    }

}
