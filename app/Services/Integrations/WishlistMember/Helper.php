<?php

namespace FluentCampaign\App\Services\Integrations\WishlistMember;

use FluentCrm\Framework\Support\Arr;

class Helper
{
    public static function getMembershipLevels()
    {
        $levels = \wlmapi_get_levels();
        $formattedLevels = [];
        foreach (Arr::get($levels, 'levels.level') as $level) {
            $formattedLevels[] = [
                'id'    => strval($level['id']),
                'title' => $level['name']
            ];
        }

        return $formattedLevels;
    }

    public static function getUserLevels($userId)
    {
        $enrollments = fluentCrmDb()->table('wlm_userlevels')
            ->select(['level_id'])
            ->where('user_id', $userId)
            ->get();

        $levelIds = [];

        foreach ($enrollments as $enrollment) {
            $levelIds[] = $enrollment->level_id;
        }

        return $levelIds;

    }
}
