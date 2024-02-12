<?php

namespace FluentCampaign\App\Http\Controllers;

use FluentCampaign\App\Migration\SmartLinksMigrator;
use FluentCampaign\App\Models\SmartLink;
use FluentCrm\App\Http\Controllers\Controller;
use FluentCrm\Framework\Support\Arr;
use FluentCrm\Framework\Request\Request;

class SmartLinksController extends Controller
{
    public function getLinks(Request $request)
    {
        if($this->isDisabled()) {
            return $this->sendError([
                'status' => 'disabled'
            ]);
        }

        $order = $request->get('order') ?: 'desc';
        $orderBy = $request->get('orderBy') ?: 'id';
        $search = $request->get('search');

        $actionLinks = SmartLink::orderBy($orderBy, ($order == 'ascending' ? 'asc' : 'desc'))
            ->when($search, function ($query) use ($search) {
                $query->where('title', 'LIKE', "%$search%");
                $query->orWhere('target_url', 'LIKE', "%$search%");
                $query->orWhere('notes', 'LIKE', "%$search%");
                return $query;
            })
            ->paginate();

        foreach ($actionLinks as $actionLink) {
            $actionLink->detach_actions = [
                'tags' => isset($actionLink->actions['remove_tags']) ? $actionLink->actions['remove_tags'] : [],
                'lists' => isset($actionLink->actions['remove_lists']) ? $actionLink->actions['remove_lists'] : [],
            ];

            $actionLink->auto_login = isset($actionLink->actions['auto_login']) ? $actionLink->actions['auto_login'] : 'no';
        }

        return [
            'action_links' => $actionLinks
        ];
    }

    public function activate()
    {
        SmartLinksMigrator::migrate(true);
        return [
            'message' => __('SmartLinks module has been successfully activated', 'fluentcampaign-pro')
        ];
    }

    public function createLink(Request $request)
    {
        $link = $request->get('link');
        $this->validate($link, [
            'title' => 'required',
            'target_url' => 'required|url'
        ]);

        $link['actions']['remove_tags'] = Arr::get($link, 'detach_actions.tags', []);
        $link['actions']['remove_lists'] = Arr::get($link, 'detach_actions.lists', []);
        $link['actions']['auto_login'] = Arr::get($link, 'auto_login', 'no');

        $createdLink = SmartLink::create($link);

        return [
            'link' => $createdLink,
            'message' => __('SmartLink has be created', 'fluentcampaign-pro')
        ];

    }

    public function update(Request $request, $id)
    {
        $link = $request->get('link');
        $this->validate($link, [
            'title' => 'required',
            'target_url' => 'required|url'
        ]);

        $link['actions']['remove_tags'] = Arr::get($link, 'detach_actions.tags', []);
        $link['actions']['remove_lists'] = Arr::get($link, 'detach_actions.lists', []);
        $link['actions']['auto_login'] = Arr::get($link, 'auto_login', 'no');

        $existing = SmartLink::findOrFail($id);

        $existing->fill($link)->save();

        return [
            'link' => $existing,
            'message' => __('SmartLink has be updated', 'fluentcampaign-pro')
        ];
    }

    public function delete(Request $request, $id)
    {
        SmartLink::where('id', $id)->delete();

        return [
            'message' => __('Selected Smart Link has been deleted', 'fluentcampaign-pro')
        ];
    }

    private function isDisabled()
    {
        global $wpdb;
        $table_name = $wpdb->prefix.'fc_smart_links';
        $query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) );

        if ( ! $wpdb->get_var( $query ) == $table_name ) {
            return true;
        }

        return false;
    }
}
