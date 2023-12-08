<?php

namespace FluentCampaign\App\Http\Controllers;

use FluentCrm\App\Http\Controllers\Controller;
use FluentCrm\Framework\Request\Request;

class CommerceReportsController extends Controller
{
    public function getReports(Request $request, $provider)
    {
        $report = $this->getProviderClass($provider);

        if (!$report) {
            return $this->sendError([
                'message' => sprintf('Provider Class %s is not found', $provider)
            ]);
        }

        return [
            'report' => $report->getReports($request->all())
        ];
    }

    public function getReport(Request $request, $provider)
    {
        $report = $this->getProviderClass($provider);

        if (!$report) {
            return $this->sendError([
                'message' => sprintf('Provider Class %s is not found', $provider)
            ]);
        }

        return $report->getReport($request->get('report_type'), $request->all());
    }

    public function getTopProducts(Request $request, $provider)
    {

    }

    public function getCustomersGrowth(Request $request, $provider)
    {

    }

    public function getProductGrowth(Request $request, $provider)
    {
        $report = $this->getProviderClass($provider);

        if (!$report) {
            return $this->sendError([
                'message' => sprintf('Provider Class %s is not found', $provider)
            ]);
        }

        return $report->getProductGrowth($request->all());
    }

    protected function getProviderClass($provider)
    {
        if ($provider == 'edd') {
            return new \FluentCampaign\App\Services\Integrations\Edd\AdvancedReport();
        } else if ($provider == 'woo') {
            return new \FluentCampaign\App\Services\Integrations\WooCommerce\AdvancedReport();
        } else if ($provider == 'learndash') {
            return new \FluentCampaign\App\Services\Integrations\LearnDash\AdvancedReport();
        } else if ($provider == 'lifterlms') {
            return new \FluentCampaign\App\Services\Integrations\LifterLms\AdvancedReport();
        } else if ($provider == 'tutorlms') {
            return new \FluentCampaign\App\Services\Integrations\TutorLms\AdvancedReport();
        } else if ($provider == 'crm') {
            return new \FluentCampaign\App\Services\Integrations\CRM\AdvancedReport();
        }
        
        return apply_filters("fluentcrm_advanced_reports_provider_{$provider}", false);
    }

}
