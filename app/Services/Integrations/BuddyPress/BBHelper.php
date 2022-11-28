<?php

namespace FluentCampaign\App\Services\Integrations\BuddyPress;

class BBHelper
{
    public static function getGroupSettings($groupId)
    {
        $defaults = [
            'attach_tags' => [],
            'remove_tag' => ''
        ];

        $settings = groups_get_groupmeta($groupId, '_fcrm_config', true);

        if(!$settings) {
            $settings = $defaults;
        } else {
            $settings = wp_parse_args($settings, $defaults);
        }

        return $settings;
    }
}