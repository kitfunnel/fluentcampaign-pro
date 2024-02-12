<?php

namespace FluentCampaign\App\Services\Integrations\PMPro;

class Helper
{
    public static function getMembershipLevels()
    {
        $levels = \pmpro_getAllLevels(true, false);
        $formattedLevels = [];
        foreach ($levels as $level) {
            $formattedLevels[] = [
                'id' => strval($level->id),
                'title' => $level->name
            ];
        }

        return $formattedLevels;
    }

    public static function getUserLevels($userId, $status = 'active')
    {
        $levels = fluentCrmDb()->table('pmpro_memberships_users')
            ->select(['membership_id'])
            ->where('user_id', $userId)
            ->where('status', $status)
            ->get();

        $levelIds = [];

        foreach ($levels as $level) {
            $levelIds[] = $level->membership_id;
        }

        return array_unique($levelIds);
    }
}
