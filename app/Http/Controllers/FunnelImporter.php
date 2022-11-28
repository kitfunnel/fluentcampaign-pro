<?php

namespace FluentCampaign\App\Http\Controllers;

use FluentCrm\App\Http\Controllers\Controller;
use FluentCrm\Framework\Request\Request;

class FunnelImporter extends Controller
{
    public function import(Request $request)
    {
        // Validate the file
        $file = $this->request->get('file');
        print_r($file);
        die();
    }
}
