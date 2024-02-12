<?php

namespace FluentCampaign\App\Services\Funnel\Conditions;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\Libs\ConditionAssessor;
use FluentCrm\Framework\Support\Arr;

class FunnelConditionHelper
{
    public static function formatConditionGroups($conditions)
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
                'property'    => $property,
                'value'       => $filterItem['value'],
                'operator'    => $filterItem['operator'],
                'extra_value' => Arr::get($filterItem, 'extra_value'),
                'data_key'    => $property,
                'data_value'  => $filterItem['value'],
            ];
        }

        return $groups;
    }

    public static function assessSegmentConditions($conditions, $subscriber)
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

    public static function assessActivities($conditions, $subscriber)
    {
        $formattedInputs = [];
        foreach ($conditions as $condition) {
            $prop = $condition['data_key'];
            $formattedInputs[$prop] = $subscriber->lastActivityDate($prop);
        }

        return ConditionAssessor::matchAllConditions($conditions, $formattedInputs);
    }

    public static function assessEventTrackingConditions($conditions, $subscriber)
    {
        if (!Helper::isExperimentalEnabled('event_tracking')) {
            return false;
        }

        $hasSubscriber = Subscriber::where('id', $subscriber->id)->where(function ($q) use ($conditions) {
            do_action_ref_array('fluentcrm_contacts_filter_event_tracking', [&$q, $conditions]);
        })->first();

        return (bool)$hasSubscriber;
    }
}
