<?php

namespace FluentCampaign\App\Http\Controllers;

use FluentCampaign\App\Services\DynamicSegments\CustomSegment;
use FluentCrm\App\Http\Controllers\Controller;
use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Meta;
use FluentCrm\App\Models\Tag;
use FluentCrm\Framework\Request\Request;

class DynamicSegmentController extends Controller
{
    public function index()
    {
        $segments = apply_filters('fluentcrm_dynamic_segments', []);

        return $this->sendSuccess([
            'dynamic_segments' => $segments
        ]);
    }

    public function getSegment(Request $request, $slug, $id = 0)
    {
        $segment = apply_filters('fluentcrm_dynamic_segment_' . $slug, null, $id, [
            'subscribers' => false,
            'paginate'    => false,
            'model'       => true
        ]);

        if(!$segment) {
            return false;
        }

        $model = $segment['model'];

        $model->when($this->request->has('sort_by'), function ($query) {
            return $query->orderBy($this->request->get('sort_by'), $this->request->get('sort_type'));
        });

        $subscribers = $model->with(['tags', 'lists']);

        if (!empty($request->get('search'))) {
            $subscribers = $subscribers->where('first_name', 'LIKE', '%'.$request->get('search').'%');
        }

        if ($request->get('has_commerce')) {
            $commerceProvider = apply_filters('fluentcrm_commerce_provider', '');
            if ($commerceProvider) {
                $subscribers->with(['commerce_by_provider' => function ($query) use ($commerceProvider) {
                    $query->where('provider', $commerceProvider);
                }]);
            }
        }

        $subscribers = $subscribers->paginate();

        if ($this->request->get('custom_fields') == 'true') {
            // we have to include custom fields
            foreach ($subscribers as $subscriber) {
                $subscriber->custom_fields = $subscriber->custom_fields();
            }
        }

        return $this->sendSuccess([
            'segment'     => $segment,
            'subscribers' => $subscribers
        ]);
    }

    public function getCustomFields(Request $request)
    {
        $textOperators = [
            '='        => __('Equal', 'fluentcampaign-pro'),
            '!='       => __('Not Equal', 'fluentcampaign-pro'),
            'LIKE'     => __('Contains', 'fluentcampaign-pro'),
            'NOT LIKE' => __('Not Contains', 'fluentcampaign-pro')
        ];
        $selectOptions = [
            'whereIn'    => __('In', 'fluentcampaign-pro'),
            'whereNotIn' => __('Not In', 'fluentcampaign-pro')
        ];
        $dateOperators = [
            '>=' => __('Within', 'fluentcampaign-pro'),
            '<=' => __('Before', 'fluentcampaign-pro'),
        ];
        $subscriptionOptions = [];
        foreach (fluentcrm_subscriber_statuses() as $option) {
            $subscriptionOptions[$option] = ucfirst($option);
        }

        $fields = [
            [
                'type'    => 'condition_blocks',
                'key'     => 'conditions',
                'heading' => __('Conditions', 'fluentcampaign-pro'),
                'label'   => __('Select conditions which will define this segment. All Conditions will be applied to filter', 'fluentcampaign-pro'),
                'fields'  => [
                    'email'         => [
                        'type'      => 'text',
                        'label'     => __('Contact Email', 'fluentcampaign-pro'),
                        'operators' => $textOperators,
                        'value'     => ''
                    ],
                    'first_name'    => [
                        'type'      => 'text',
                        'label'     => __('First Name', 'fluentcampaign-pro'),
                        'operators' => $textOperators,
                        'value'     => ''
                    ],
                    'last_name'     => [
                        'type'      => 'text',
                        'label'     => __('Last Name', 'fluentcampaign-pro'),
                        'operators' => $textOperators,
                        'value'     => ''
                    ],
                    'city'          => [
                        'type'      => 'text',
                        'label'     => __('City', 'fluentcampaign-pro'),
                        'operators' => $textOperators,
                        'value'     => ''
                    ],
                    'state'         => [
                        'type'      => 'text',
                        'label'     => __('State', 'fluentcampaign-pro'),
                        'operators' => $textOperators,
                        'value'     => ''
                    ],
                    'country'       => [
                        'type'        => 'option-selector',
                        'option_key'  => 'countries',
                        'is_multiple' => true,
                        'label'       => __('Country', 'fluentcampaign-pro'),
                        'operators'   => $selectOptions,
                        'value'       => []
                    ],
                    'status'        => [
                        'type'        => 'select',
                        'is_multiple' => true,
                        'label'       => __('Subscription Status', 'fluentcampaign-pro'),
                        'operators'   => $selectOptions,
                        'value'       => [],
                        'options'     => $subscriptionOptions
                    ],
                    'source'        => [
                        'type'      => 'text',
                        'label'     => __('Source', 'fluentcampaign-pro'),
                        'operators' => $textOperators,
                        'value'     => ''
                    ],
                    'created_at'    => [
                        'type'      => 'days_ago',
                        'label'     => __('Created at', 'fluentcampaign-pro'),
                        'operators' => $dateOperators
                    ],
                    'last_activity' => [
                        'type'        => 'days_ago',
                        'label'       => __('Last Contact Activity', 'fluentcampaign-pro'),
                        'description' => __('Activity on your site login, email link click or various other activities', 'fluentcampaign-pro'),
                        'operators'   => $dateOperators
                    ],
                    'tags'          => [
                        'type'        => 'select',
                        'is_multiple' => true,
                        'label'       => __('Tags', 'fluentcampaign-pro'),
                        'operators'   => [
                            'whereIn'    => __('In', 'fluentcampaign-pro'),
                            'whereNotIn' => __('Not In', 'fluentcampaign-pro')
                        ],
                        'value'       => [],
                        'options'     => $this->getTagOptions()
                    ],
                    'lists'         => [
                        'type'        => 'select',
                        'is_multiple' => true,
                        'label'       => __('Lists', 'fluentcampaign-pro'),
                        'operators'   => [
                            'whereIn'    => __('In', 'fluentcampaign-pro'),
                            'whereNotIn' => __('Not In', 'fluentcampaign-pro')
                        ],
                        'value'       => [],
                        'options'     => $this->getListOptions()
                    ]
                ]
            ],
            [
                'type'    => 'activities_blocks',
                'key'     => 'email_activities',
                'heading' => __('Filter By Email Activities', 'fluentcampaign-pro'),
                'label'   => __('Filter your contacts by from email open or email link click metrics. Leave the values blank for not applying', 'fluentcampaign-pro'),
                'fields'  => [
                    'status'                    => [
                        'type'  => 'yes_no_check',
                        'label' => __('Enable Last Email Activity Filter', 'fluentcampaign-pro')
                    ],
                    'last_email_open'           => [
                        'type'        => 'days_ago_with_operator',
                        'label'       => __('Last Email Open', 'fluentcampaign-pro'),
                        'options'     => $dateOperators,
                        'inline_help' => __('Keep days 0/Blank for disable', 'fluentcampaign-pro')
                    ],
                    'last_email_link_click'     => [
                        'type'        => 'days_ago_with_operator',
                        'label'       => __('Last Email Link Clicked', 'fluentcampaign-pro'),
                        'options'     => $dateOperators,
                        'inline_help' => __('Keep days 0/Blank for disable', 'fluentcampaign-pro')
                    ],
                    'last_email_activity_match' => [
                        'heading' => __('Match Type', 'fluentcampaign-pro'),
                        'label'   => __('Should Match Both Open & Click Condition?', 'fluentcampaign-pro'),
                        'options' => [
                            'match_all' => __('Match Both Open and Click Condition', 'fluentcampaign-pro'),
                            'match_any' => __('Match Any One Condition', 'fluentcampaign-pro')
                        ]
                    ]
                ]
            ]
        ];

        $settingsDefaults = [
            'conditions'       => [
                [
                    'field'    => '',
                    'operator' => '',
                    'value'    => ''
                ]
            ],
            'condition_match'  => 'match_all',
            'email_activities' => [
                'status'                    => 'no',
                'last_email_open'           => [
                    'value'    => 0,
                    'operator' => '>='
                ],
                'last_email_link_click'     => [
                    'value'    => 0,
                    'operator' => '>='
                ],
                'last_email_activity_match' => 'match_any'
            ]
        ];

        return $this->sendSuccess([
            'fields'            => $fields,
            'settings_defaults' => $settingsDefaults
        ]);
    }

    public function createCustomSegment(Request $request)
    {
        $segment = \json_decode($request->get('segment'), true);

        if (empty($segment['title'])) {
            return $this->sendError([
                'message' => __('Please provide segment title', 'fluentcampaign-pro')
            ]);
        }

        $segmentData = [
            'object_type' => 'custom_segment',
            'key'         => 'custom_segment',
            'value'       => $segment,
            'updated_at'  => fluentCrmTimestamp()
        ];

        $segmentData['created_at'] = fluentCrmTimestamp();
        $inserted = Meta::create($segmentData);
        $segment['id'] = $inserted->id;
        $segment['slug'] = 'custom_segment';

        return $this->sendSuccess([
            'message' => __('Segment has been created', 'fluentcampaign-pro'),
            'segment' => $segment
        ]);
    }

    public function updateCustomSegment(Request $request, $segmentId)
    {
        $segment = \json_decode($request->get('segment'), true);

        if (empty($segment['title'])) {
            return $this->sendError([
                'message' => __('Please provide segment title', 'fluentcampaign-pro')
            ]);
        }

        unset($segment['id']);

        $segmentData = [
            'object_type' => 'custom_segment',
            'key'         => 'custom_segment',
            'value'       => maybe_serialize($segment),
            'updated_at'  => fluentCrmTimestamp()
        ];

        Meta::where('id', $segmentId)
            ->where('object_type', 'custom_segment')
            ->update($segmentData);
        $segment['id'] = $segmentId;

        return $this->sendSuccess([
            'message' => __('Segment has been updated', 'fluentcampaign-pro'),
            'segment' => $segment
        ]);
    }

    public function deleteCustomSegment(Request $request, $segmentId)
    {
        if (!$segmentId) {
            return $this->sendError([
                'message' => __('Sorry! No segment found', 'fluentcampaign-pro')
            ]);
        }

        Meta::where('object_type', 'custom_segment')
            ->where('id', $segmentId)
            ->delete();

        return $this->sendSuccess([
            'message' => __('Selected segment has been deleted', 'fluentcampaign-pro')
        ]);
    }

    public function getTagOptions()
    {
        $tags = Tag::get();
        $formattedTags = [];
        foreach ($tags as $tag) {
            $formattedTags[strval($tag->id)] = $tag->title;
        }

        return $formattedTags;
    }

    public function getListOptions()
    {
        $lists = Lists::get();
        $formattedLists = [];
        foreach ($lists as $list) {
            $formattedLists[strval($list->id)] = $list->title;
        }

        return $formattedLists;
    }

    public function getEstimatedContacts(Request $request)
    {
        $filters = $request->get('filters');
        $customSegmentModel = (new CustomSegment())->getModel(['filters' => $filters]);

        return [
            'count' => $customSegmentModel->count()
        ];
    }
}
