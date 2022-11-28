<?php

namespace FluentCampaign\App\Http\Policies;

use FluentCrm\App\Http\Policies\BasePolicy;
use FluentCrm\Framework\Request\Request;

class DynamicSegmentPolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param  \FluentCrm\Framework\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_contact_cats');
    }


    /**
     * Check user permission for delete custom segment
     * @param  \FluentCrm\Framework\Request\Request $request
     * @return Boolean
     */
    public function deleteCustomSegment(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_contact_cats_delete');
    }
}
