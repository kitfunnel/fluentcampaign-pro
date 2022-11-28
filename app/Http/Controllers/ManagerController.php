<?php

namespace FluentCampaign\App\Http\Controllers;

use FluentCrm\App\Http\Controllers\Controller;
use FluentCrm\App\Services\PermissionManager;
use FluentCrm\Framework\Support\Arr;
use FluentCrm\Framework\Request\Request;

class ManagerController extends Controller
{
    public function getManagers(Request $request)
    {
        $limit = $request->get('per_page', 10);
        $page = $request->get('page', 1);

        $query = new \WP_User_Query( array(
            'meta_key' => '_fcrm_has_role',
            'meta_value' => 1,
            'meta_compare' => '=',
            'number' => $limit,
            'paged' => $page
        ) );

        $managers = [];

        foreach ($query->get_results() as $user)
        {
            $managers[] = [
                'id' => $user->ID,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->user_email,
                'permissions' => PermissionManager::getUserPermissions($user)
            ];
        }

        return [
            'managers' => [
                'data' => $managers,
                'total' => $query->get_total()
            ],
            'permissions' => PermissionManager::getReadablePermissions()
        ];

    }

    public function addManager(Request $request)
    {
        $manager = $request->get('manager');
        $this->validate($manager, [
            'permissions' => 'required',
            'email' => 'required|email'
        ]);

        $email = Arr::get($manager, 'email');

        $user = get_user_by('email', $email);

        if(!$user) {
            return $this->sendError([
               'message' => __('Associate user could not be found with this email', 'fluentcampaign-pro')
            ]);
        }

        $permissions = Arr::get($manager, 'permissions', []);

        $allPermissions = PermissionManager::getReadablePermissions();
        foreach ($permissions as $permission) {
            $depends = Arr::get($allPermissions, $permission.'.depends', []);
            if($depends && !array_intersect($permissions, $depends)) {
                return $this->sendError([
                    'message' => $permission.' require permissions: '.implode(', ', $depends)
                ]);
            }
        }


        PermissionManager::attachPermissions($user, $permissions);

        update_user_meta($user->id, '_fcrm_has_role', 1);

        return $this->sendSuccess([
            'message' => __('Manager has been added', 'fluentcampaign-pro')
        ]);
    }

    public function updateManager(Request $request, $id)
    {
        $manager = $request->get('manager');

        $this->validate($manager, [
            'permissions' => 'required',
            'email' => 'required|email'
        ]);

        $email = Arr::get($manager, 'email');

        $user = get_user_by('email', $email);

        if(!$user) {
            return $this->sendError([
                'message' => __('Associate user could not be found with this email', 'fluentcampaign-pro')
            ]);
        }

        $permissions = Arr::get($manager, 'permissions', []);

        $allPermissions = PermissionManager::getReadablePermissions();
        foreach ($permissions as $permission) {
            $depends = Arr::get($allPermissions, $permission.'.depends', []);
            if($depends && !array_intersect($permissions, $depends)) {
                return $this->sendError([
                    'message' => $permission.' require permissions: '.implode(', ', $depends)
                ]);
            }
        }

        PermissionManager::attachPermissions($user, $permissions);

        update_user_meta($user->id, '_fcrm_has_role', 1);

        return $this->sendSuccess([
            'message' => __('Manager has been updated', 'fluentcampaign-pro')
        ]);

    }

    public function deleteManager(Request $request, $id)
    {
        $user = get_user_by('ID', $id);

        if(!$user) {
            return $this->sendError([
                'message' => __('Associate user could not be found', 'fluentcampaign-pro')
            ]);
        }

        PermissionManager::attachPermissions($user, []);

        delete_user_meta($user->id, '_fcrm_has_role');

        return $this->sendSuccess([
            'message' => __('Manager has been removed', 'fluentcampaign-pro')
        ]);

    }
}
