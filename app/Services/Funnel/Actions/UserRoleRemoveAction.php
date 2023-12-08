<?php

namespace FluentCampaign\App\Services\Funnel\Actions;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;

class UserRoleRemoveAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'remove_user_role';
        $this->priority = 100;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category'    => __('WordPress', 'fluentcampaign-pro'),
            'title'       => __('Remove WP User Role', 'fluentcampaign-pro'),
            'description' => __('Remove the Selected Role of User', 'fluentcampaign-pro'),
            'icon'        => 'fc-icon-tag_removed',
            'settings'    => [
                'role' => null
            ]
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Remove the Selected Role of User', 'fluentcampaign-pro'),
            'sub_title' => __('Select Role that you want to remove from targeted Contact', 'fluentcampaign-pro'),
            'fields'    => [
                'role'      => [
                    'type'    => 'select',
                    'label'   => __('User Role', 'fluentcampaign-pro'),
                    'options' => $this->getUserRoles(),
                ],
                'role_info' => [
                    'type' => 'html',
                    'info' => '<p><b>' . __('Only if user is not Administrator Role then the selected role will be applied. After removing the role, if user does not have any role then subscriber role will be added.', 'fluentcampaign-pro') . '</b></p>',
                ]
            ]
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        if (empty($sequence->settings['role'])) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $role = $sequence->settings['role'];

        $user = $subscriber->getWpUser();
        if (!$user) {

            $funnelMetric->status = 'skipped';
            $funnelMetric->notes = 'Skipped because no user found';
            $funnelMetric->save();

            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $userRoles = array_values($user->roles);

        if (!in_array($role, $userRoles)) {
            $funnelMetric->status = 'skipped';
            $funnelMetric->notes = 'Skipped because, user does not have the selected role';
            $funnelMetric->save();

            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        if (in_array('administrator', $userRoles)) {
            $funnelMetric->status = 'skipped';
            $funnelMetric->notes = 'Role can not be removed because user have administrator role';
            $funnelMetric->save();

            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $user->remove_role($role);

        if (empty($user->roles)) {
            $user->add_role('subscriber');
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
