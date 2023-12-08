<?php

namespace FluentCampaign\App\Services\Integrations\BuddyPress;

use FluentCrm\App\Services\Html\TableBuilder;

class BBInit
{
    public function init()
    {
        (new Group())->init();
        (new BBMemberType())->init();

        new BbImporter();

        add_filter('fluentcrm_profile_sections', array($this, 'pushCoursesOnProfile'));
        add_filter('fluencrm_profile_section_buddypress_profile', array($this, 'pushGroupsContent'), 10, 2);

        add_filter('fluent_crm/subscriber_info_widgets', array($this, 'pushSubscriberInfoWidget'), 10, 2);

    }


    public function pushCoursesOnProfile($sections)
    {

        if (!function_exists('bp_is_active') || !bp_is_active('groups')) {
            return $sections;
        }

        $title = __('BuddyPress Groups', 'fluentcampaign-pro');

        if (defined('BP_PLATFORM_VERSION')) {
            $title = __('BuddyBoss Groups', 'fluentcampaign-pro');
        }

        $sections['buddypress_profile'] = [
            'name'    => 'fluentcrm_profile_extended',
            'title'   => $title,
            'handler' => 'route',
            'query'   => [
                'handler' => 'buddypress_profile'
            ]
        ];

        return $sections;
    }

    public function pushGroupsContent($content, $subscriber)
    {

        $title = __('BuddyPress Groups', 'fluentcampaign-pro');

        if (defined('BP_PLATFORM_VERSION')) {
            $title = __('BuddyBoss Groups', 'fluentcampaign-pro');
        }

        $content['heading'] = $title;

        $userId = $subscriber->user_id;

        if (!bp_is_active('groups')) {
            $content['content_html'] = '<p>Groups is not activated</p>';
            return $content;
        }

        if (!$userId) {
            $content['content_html'] = '<p>' . esc_html__('No groups found for this contact', 'fluentcampaign-pro') . '</p>';
            return $content;
        }

        $groups = fluentCrmDb()->table('bp_groups_members')
            ->select(['bp_groups_members.*', 'bp_groups.name'])
            ->where('bp_groups_members.user_id', $subscriber->user_id)
            ->join('bp_groups', 'bp_groups.id', '=', 'bp_groups_members.group_id')
            ->orderBy('bp_groups_members.id', 'DESC')
            ->get();

        if (empty($groups)) {
            $content['content_html'] = '<p>' . esc_html__('No groups found for this contact', 'fluentcampaign-pro') . '</p>';
            return $content;
        }

        $tableBuilder = new TableBuilder();
        foreach ($groups as $group) {
            $memberType = __('Member', 'fluentcampaign-pro');

            if ($group->is_banned) {
                $memberType = __('Banned', 'fluentcampaign-pro');
            } else if ($group->is_admin) {
                $memberType = __('Admin', 'fluentcampaign-pro');
            } else if ($group->is_mod) {
                $memberType = __('Moderator', 'fluentcampaign-pro');
            }

            $tableBuilder->addRow([
                'id'           => $group->group_id,
                'group'        => $group->name,
                'type'         => $memberType,
                'is_confirmed' => ($group->is_confirmed) ? __('Confirmed', 'fluentcampaign-pro') : __('Not Confirmed', 'fluentcampaign-pro'),
                'last_update'  => date_i18n(get_option('date_format'), strtotime($group->date_modified)),
            ]);
        }

        $tableBuilder->setHeader([
            'id'           => __('Group ID', 'fluentcampaign-pro'),
            'group'        => __('Group Name', 'fluentcampaign-pro'),
            'type'         => __('Type', 'fluentcampaign-pro'),
            'is_confirmed' => __('Confirmation Status', 'fluentcampaign-pro'),
            'last_update'  => __('Last Update', 'fluentcampaign-pro'),
        ]);

        $content['content_html'] = $tableBuilder->getHtml();
        return $content;
    }

    public function pushSubscriberInfoWidget($widgets, $subscriber)
    {
        if (!function_exists('bp_is_active') || !bp_is_active('groups')) {
            return $widgets;
        }

        if(!$subscriber->user_id) {
            return $widgets;
        }


        $groups = fluentCrmDb()->table('bp_groups_members')
            ->select(['bp_groups_members.*', 'bp_groups.name'])
            ->where('bp_groups_members.user_id', $subscriber->user_id)
            ->join('bp_groups', 'bp_groups.id', '=', 'bp_groups_members.group_id')
            ->orderBy('bp_groups_members.id', 'DESC')
            ->get();

        if(!$groups) {
            return $widgets;
        }

        $html = '<ul class="fc_full_listed">';

        foreach ($groups as $group) {
            $memberType = __('Member', 'fluentcampaign-pro');

            if ($group->is_banned) {
                $memberType = __('Banned', 'fluentcampaign-pro');
            } else if ($group->is_admin) {
                $memberType = __('Admin', 'fluentcampaign-pro');
            } else if ($group->is_mod) {
                $memberType = __('Moderator', 'fluentcampaign-pro');
            }

            $html .= '<li>'.$group->name.' <span class="el-tag el-tag--mini el-tag--light">'.$memberType.'</span></li>';
        }

        $html .= '</ul>';

        $widgets[] = [
            'title' => __('Community Groups', 'fluentcampaign-pro'),
            'content' => $html
        ];

        return $widgets;

    }

}
