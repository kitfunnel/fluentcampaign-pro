<?php

namespace FluentCampaign\App\Services\Integrations\RCP;

class Helper
{
    public static function getMembershipLevels()
    {
        $memberships = \rcp_get_membership_levels(array('number' => 999));

        $formattedLevels = [];
        foreach ($memberships as $membership) {
            $formattedLevels[] = [
                'id'    => strval($membership->id),
                'title' => $membership->name
            ];
        }

        return $formattedLevels;
    }

    public static function getUserLevels($userId)
    {

        if(!$userId) {
            return [];
        }

        $customer = \rcp_get_customer_by_user_id($userId);

        if (!$customer) {
            return [];
        }

        $levels = $customer->get_memberships([
            'status' => 'active'
        ]);

        if (!$levels) {
            return [];
        }

        $levelIds = [];

        foreach ($levels as $level) {
            $levelIds[] = $level->get_object_id();
        }

        return array_unique($levelIds);
    }
}
