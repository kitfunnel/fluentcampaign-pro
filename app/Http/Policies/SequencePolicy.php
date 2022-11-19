<?php

namespace FluentCampaign\App\Http\Policies;

use FluentCrm\App\Http\Policies\BasePolicy;
use FluentCrm\Framework\Request\Request;

class SequencePolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param  \FluentCrm\Framework\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        if($request->method() == 'GET') {
            return $this->currentUserCan('fcrm_read_emails');
        }

        return $this->currentUserCan('fcrm_manage_emails');
    }

    public function delete(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_email_delete');
    }

    public function deleteSubscribes(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_email_delete');
    }
}
