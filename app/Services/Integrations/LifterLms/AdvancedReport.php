<?php

namespace FluentCampaign\App\Services\Integrations\LifterLms;

use FluentCampaign\App\Services\BaseAdvancedReport;
use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCampaign\App\Services\Commerce\ContactRelationItemsModel;
use FluentCrm\App\Services\ReportingHelperTrait;
use FluentCrm\Framework\Support\Arr;

class AdvancedReport extends BaseAdvancedReport
{
    use ReportingHelperTrait;

    public function __construct()
    {
        $this->provider = 'lifterlms';
    }

    /**
     * @param array $filters
     * @return array
     */
    public function getReports($filters = [])
    {
        $enabled = Commerce::isEnabled($this->provider);

        if($enabled && Arr::get($filters, 'top_products_only')) {
            return [
                'top_products' => $this->getTopProductsByPost($filters)
            ];
        }

        $supports = [
            'product_growth'   => [
                'title'       => __('Enrollments', 'fluentcampaign-pro'),
                'has_product' => true,
                'sub_types' => [
                    'all' => [
                        'label' => __('All', 'fluentcampaign-pro')
                    ],
                    'courses' => [
                        'label' => __('Courses', 'fluentcampaign-pro')
                    ],
                    'memberships' => [
                        'label' => __('Memberships', 'fluentcampaign-pro')
                    ]
                ]
            ],
            'customers_growth' => [
                'title' => __('Students Growth', 'fluentcampaign-pro')
            ]
        ];

        $overview = [
            'enabled'       => $enabled,
            'title'         => __('LifterLMS - Advanced Reports', 'fluentcampaign-pro'),
            'supports'      => $supports,
            'has_top_products_filter' => true
        ];

        if (!$enabled) {
            $overview['enable_instruction'] = __('Please enable data sync first from FluentCRM', 'fluentcampaign-pro').' <b><a href="'.admin_url('admin.php?page=fluentcrm-admin#/settings/integration_settings?selected_integration=lifterlms').'">'.__('Settings -> Integrations Settings -> LifterLMS', 'fluentcampaign-pro').'</a></b> '.__('to view in details LifterLMS reports', 'fluentcampaign-pro');
        } else {
            $overview['widgets'] = [
                'total_students'         => [
                    'value'    => fluentCrmDb()->table('fc_contact_relations')->where('provider', $this->provider)->count(),
                    'label'    => __('Total Students', 'fluentcampaign-pro'),
                    'is_int' => true
                ],
                'total_enrollments'       => [
                    'value' => ContactRelationItemsModel::provider($this->provider)->where('item_type', 'course')->count(),
                    'label' => __('Total Course Enrollments', 'fluentcampaign-pro'),
                    'is_int' => true
                ],
                'total_membership_enrollments'       => [
                    'value' => ContactRelationItemsModel::provider($this->provider)->where('item_type', 'membership')->count(),
                    'label' => __('Total Membership Enrollments', 'fluentcampaign-pro'),
                    'is_int' => true
                ]
            ];
        }

        if (in_array('top_products', Arr::get($filters, 'with', [])) && $enabled) {
            $overview['top_products'] = $this->getTopProductsByPost();
        }

        return $overview;
    }

    /**
     * @param string $type
     * @param array $filters
     * @return array|void
     */
    public function getReport($type, $filters = [])
    {
        if ($type == 'product_growth') {

            $subType = Arr::get($filters, 'sub_type', 'all');

            if($subType == 'courses') {
                $filters['sub_type'] = 'by_item_types';
                $filters['item_types'] = ['course'];
            } else if($subType == 'memberships') {
                $filters['sub_type'] = 'by_item_types';
                $filters['item_types'] = ['membership'];
            }

            return $this->getProductGrowthCounts($filters);
        } else if ($type == 'customers_growth') {
            return $this->getCustomersGrowth($filters);
        }
    }
}
