<?php

namespace FluentCampaign\App\Http\Policies;

use FluentCrm\App\Http\Policies\BasePolicy;
use FluentCrm\Framework\Request\Request;

class SmartLinksPolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param  \FluentCrm\Framework\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        if($request->method() == 'GET') {
            return $this->currentUserCan('fcrm_manage_settings') || $this->currentUserCan('fcrm_manage_contacts');
        }

        return $this->currentUserCan('fcrm_manage_settings');
    }
}
