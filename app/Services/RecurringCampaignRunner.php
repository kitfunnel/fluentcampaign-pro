<?php

namespace FluentCampaign\App\Services;

use FluentCrm\Framework\Support\Arr;
use FluentCampaign\App\Models\RecurringCampaign;

class RecurringCampaignRunner
{

    public static function getNextScheduledAt($schedulingSettings, $cutSeconds = 1800)
    {
        $type = Arr::get($schedulingSettings, 'type');

        $validTypes = ['daily', 'weekly', 'monthly'];

        if (!in_array($type, $validTypes)) {
            return NULL;
        }

        $time = Arr::get($schedulingSettings, 'time', '00:00') . ':00';

        $currentTimeStamp = current_time('timestamp');

        switch ($type) {
            case 'daily':
                $nextDateTime = date('Y-m-d', $currentTimeStamp) . ' ' . $time;
                if ((strtotime($nextDateTime) - $currentTimeStamp) > $cutSeconds) {
                    return $nextDateTime;
                }
                // let's make it to next day
                return date('Y-m-d H:i:s', strtotime($nextDateTime) + 86400);

            case 'weekly':
                $selectedDay = strtolower(Arr::get($schedulingSettings, 'day', 'mon'));
                $currentDay = strtolower(date('D', $currentTimeStamp));

                if ($selectedDay == $currentDay) {
                    // it's today
                    $nextDateTime = date('Y-m-d', $currentTimeStamp) . ' ' . $time;

                    if (strtotime($nextDateTime) - $currentTimeStamp > $cutSeconds) {
                        return $nextDateTime; // it's after the 30 minutes
                    }
                }

                return date('Y-m-d', strtotime('next ' . $selectedDay, $currentTimeStamp)) . ' ' . $time;

            case 'monthly':
                $selectedDay = Arr::get($schedulingSettings, 'day', '1');
                $currentDay = date('j');

                if ($selectedDay == $currentDay) {
                    // it's today
                    $nextDateTime = date('Y-m-d', $currentTimeStamp) . ' ' . $time;
                    if (strtotime($nextDateTime) - $currentTimeStamp > $cutSeconds) {
                        return $nextDateTime; // it's after the cut seconds
                    }
                } else if ($currentDay < $selectedDay) {
                    // add 0 if single digit
                    $selectedDay = str_pad($selectedDay, 2, '0', STR_PAD_LEFT);
                    return date('Y-m-'.$selectedDay, $currentTimeStamp) . ' ' . $time;
                }

                $nextMonth = strtotime("first day of next month", $currentTimeStamp);
                $advanceDay = absint($selectedDay - 1);

                return date('Y-m-d', $nextMonth + ($advanceDay * 86400)) . ' ' . $time;
        }

        return NULL;
    }

    public static function setCalculatedScheduledAt()
    {
        $nextItem = RecurringCampaign::orderBy('scheduled_at', 'ASC')
            ->where('status', 'active')
            ->whereNotNull('scheduled_at')
            ->first();

        if (!$nextItem) {
            update_option('_fc_next_recurring_campaign', false, 'no');
            return;
        }

        update_option('_fc_next_recurring_campaign', strtotime($nextItem->scheduled_at), 'no');
    }

    public static function assesCondition($condition)
    {
        if ($condition['object_type'] === 'cpt') {
            $exist = fluentcrmDb()->table('posts')
                ->where('post_type', $condition['object_name'])
                ->where('post_status', 'publish')
                ->where('post_date', '>', date('Y-m-d H:i:s', current_time('timestamp') - 2100 - (int)$condition['compare_value'] * 86400))
                ->first();

            if ($exist) {
                return true;
            }
        }

        return false;
    }
}
