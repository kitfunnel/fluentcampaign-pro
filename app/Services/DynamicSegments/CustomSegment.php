<?php

namespace FluentCampaign\App\Services\DynamicSegments;

use FluentCrm\App\Models\Meta;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\ContactsQuery;
use FluentCrm\Framework\Support\Arr;

class CustomSegment extends BaseSegment
{

    public $slug = 'custom_segment';

    public $priority = 100;

    public function register()
    {
        add_filter('fluentcrm_dynamic_segments', function ($segments) {
            if ($customSegments = $this->getSegments()) {
                $segments = array_merge($segments, $customSegments);
            }
            return $segments;
        }, $this->priority);

        add_filter('fluentcrm_dynamic_segment_' . $this->slug, array($this, 'getSegmentDetails'), 10, 3);
    }

    public function getSegments()
    {
        $segments = Meta::where('object_type', $this->slug)
            ->orderBy('id', 'ASC')
            ->get();

        $formattedSegments = [];
        foreach ($segments as $segment) {
            $settings = $segment->value;
            $settings = wp_parse_args($settings, $this->getInfo());
            $settings['id'] = $segment->id;
            $formattedSegments[] = $settings;
        }
        return $formattedSegments;
    }

    public function getInfo()
    {
        return [
            'slug'        => $this->slug,
            'is_system'   => false,
            'subtitle'    => __('Custom Segments with custom filters on Subscriber data', 'fluentcampaign-pro'),
            'description' => __('This is a custom segment and contacts are filter based your provided filters on real time data.', 'fluentcampaign-pro')
        ];
    }

    public function getCount()
    {
        $model = $this->getModel();
        return $model->count();
    }

    public function getSegmentDetails($segment, $id, $config)
    {
        $item = Meta::where('id', $id)->where('object_type', 'custom_segment')->first();

        if (!$item) {
            return [];
        }

        $segment = $item->value;

        if (!isset($segment['filters'])) {
            $segment['filters'] = $this->getSegmentFilters($segment, $item);
        }

        $segment['sort_by'] = Arr::get($config, 'sort_by');
        $segment['sort_type'] = Arr::get($config, 'sort_type');

        $segment = wp_parse_args($segment, $this->getInfo());

        $model = $this->getModel($segment);

        $segment['id'] = $item->id;

        if (Arr::get($config, 'contact_count')) {
            $segment['contact_count'] = $model->count();
        }

        if (Arr::get($config, 'model')) {
            $segment['model'] = $model;
        }

        if (Arr::get($config, 'subscribers')) {
            $segment['subscribers'] = $this->getSubscribers($config, $segment);
        }

        return $segment;
    }

    private function getContactCount($segment)
    {
        $model = $this->getModel($segment);
        return $model->count();
    }

    public function getModel($segment = [])
    {
        $filters = $this->getSegmentFilters($segment);

        $args = [
            'filter_type' => 'advanced',
            'with'               => [],
            'filters_groups_raw' => $filters
        ];
        if (!empty($segment['sort_by'])) {
            $args['sort_by'] = $segment['sort_by'];
            $args['sort_type'] = $segment['sort_type'];
        }

        $contactQuery = new ContactsQuery($args);

        return $contactQuery->getModel();
    }

    protected function getSegmentFilters($segment, $item = false)
    {
        if (isset($segment['filters'])) {
            return $segment['filters'];
        }

        $conditions = Arr::get($segment, 'settings.conditions', []);
        $operatorMaps = [
            '='          => '=',
            '!='         => '!=',
            'LIKE'       => 'contains',
            'NOT LIKE'   => 'not_contains',
            'whereIn'    => 'in',
            'whereNotIn' => 'not_in',
            '>='         => 'days_within',
            '<='         => 'days_before'
        ];
        $contactFields = ['email', 'first_name', 'last_name', 'city', 'state', 'country', 'source', 'created_at', 'last_activity'];
        $segmentFields = ['tags', 'lists', 'status'];

        $formattedGroups = [];
        foreach ($conditions as $condition) {
            if (in_array($condition['field'], $contactFields)) {
                $formattedGroups[] = [
                    'operator' => Arr::get($operatorMaps, $condition['operator'], 'contains'),
                    'source'   => ['subscriber', $condition['field']],
                    'value'    => $condition['value']
                ];
            } else if (in_array($condition['field'], $segmentFields)) {
                $formattedGroups[] = [
                    'operator' => Arr::get($operatorMaps, $condition['operator'], 'contains'),
                    'source'   => ['segment', $condition['field']],
                    'value'    => $condition['value']
                ];
            }
        }

        $conditionGroups = [$formattedGroups];

        if (Arr::get($segment, 'settings.email_activities.status') == 'yes') {
            $emailActivities = Arr::get($segment, 'settings.email_activities', []);

            $activityFilters = [];
            // Handle Email Open
            if (Arr::get($emailActivities, 'last_email_open.value')) {
                $operator = 'days_within';
                if (Arr::get($emailActivities, 'last_email_open.operator') == '<=') {
                    $operator = 'days_before';
                }
                $activityFilters[] = [
                    'operator' => $operator,
                    'source'   => ['activities', 'email_opened'],
                    'value'    => intval(Arr::get($emailActivities, 'last_email_open.value'))
                ];
            }

            // Handle Link Click
            if (Arr::get($emailActivities, 'last_email_link_click.value')) {
                $operator = 'days_within';
                if (Arr::get($emailActivities, 'last_email_link_click.operator') == '<=') {
                    $operator = 'days_before';
                }
                $activityFilters[] = [
                    'operator' => $operator,
                    'source'   => ['activities', 'email_link_clicked'],
                    'value'    => intval(Arr::get($emailActivities, 'last_email_link_click.value'))
                ];
            }

            $isOr = Arr::get($emailActivities, 'last_email_activity_match') == 'match_any';

            if (count($activityFilters) < 2 || !$isOr) {
                $conditionGroups[0] = array_merge($conditionGroups[0], $activityFilters);
            } else {
                $conditionGroups = [$conditionGroups[0], $conditionGroups[0]];
                $conditionGroups[0][] = $activityFilters[0];
                $conditionGroups[1][] = $activityFilters[1];
            }
        }

        if ($item) {
            unset($segment['settings']);
            $segment['filters'] = $conditionGroups;
            $item->value = $segment;
            $item->save();
        }

        return $conditionGroups;
    }
}
