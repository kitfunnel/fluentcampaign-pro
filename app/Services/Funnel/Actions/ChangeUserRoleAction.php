<?php

namespace FluentCampaign\App\Services\Funnel\Actions;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;

class ChangeUserRoleAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'fcrm_change_user_role';
        $this->priority = 99;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('WordPress', 'fluentcampaign-pro'),
            'title'       => __('Change WP User Role', 'fluentcampaign-pro'),
            'description' => __('If user exist with the contact email then you can change user role', 'fluentcampaign-pro'),
            'icon'        => 'fc-icon-wp_user_role',//fluentCrmMix('images/funnel_icons/wp_user_role.svg'),
            'settings'    => [
                'user_role' => '',
                'replace'   => 'yes'
            ]
        ];
    }

    public function getBlockFields()
    {
        $userRoles = $this->getUserRoles();

        return [
            'title'     => __('Change User Role', 'fluentcampaign-pro'),
            'sub_title' => __('Change connected user role', 'fluentcampaign-pro'),
            'fields'    => [
                'user_role'                    => [
                    'type'    => 'select',
                    'label'   => __('User Role', 'fluentcampaign-pro'),
                    'options' => $userRoles,
                    'inline_help' => __('Selected Role will be applied if there has a user with contact\'s email address', 'fluentcampaign-pro')
                ],
                'replace'       => [
                    'type'        => 'yes_no_check',
                    'label'       => __('Replace Existing Role', 'fluentcampaign-pro'),
                    'check_label' => __('Replace user role ', 'fluentcampaign-pro'),
                    'inline_help' => __('If you disable this then it will append the selected role with existing roles.', 'fluentcampaign-pro')
                ]
            ]
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        $user = get_user_by('email', $subscriber->email);
        if (!$user) {
            $funnelMetric->notes = __('Funnel Skipped because no user found with the email address', 'fluentcampaign-pro');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        $settings = $sequence->settings;
        $userRole = $settings['user_role'];

        if($userRole == 'administrator') {
            $funnelMetric->notes = __('Funnel Skipped because administrator user role can not be set for security reason', 'fluentcampaign-pro');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        if(!$userRole) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        if (!function_exists('get_editable_roles')) {
            require_once(ABSPATH . '/wp-admin/includes/user.php');
        }

        $roles = \get_editable_roles();

        if(empty($roles[$userRole])) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        $willReplace = $settings['replace'] == 'yes';

        if($willReplace) {
            $user->set_role($userRole);
        } else {
            $user->add_role($userRole);
        }
    }

    public function getUserRoles($keyed = false)
    {
        if (!function_exists('get_editable_roles')) {
            require_once(ABSPATH . '/wp-admin/includes/user.php');
        }

        $roles = \get_editable_roles();

        $formattedRoles = [];
        foreach ($roles as $roleKey => $role) {

            if($roleKey == 'administrator') {
                continue;
            }

            if ($keyed) {
                $formattedRoles[$roleKey] = $role['name'];
            } else {
                $formattedRoles[] = [
                    'id'    => $roleKey,
                    'title' => $role['name']
                ];
            }

        }
        return $formattedRoles;
    }


}
