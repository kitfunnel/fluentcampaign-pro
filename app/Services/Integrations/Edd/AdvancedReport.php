<?php

namespace FluentCampaign\App\Services\Integrations\Edd;

use FluentCampaign\App\Services\BaseAdvancedReport;
use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCrm\App\Services\ReportingHelperTrait;
use FluentCrm\Framework\Support\Arr;

class AdvancedReport extends BaseAdvancedReport
{
    use ReportingHelperTrait;

    public function __construct()
    {
        $this->provider = 'edd';
    }

    /**
     * @param array $filters
     * @return array
     */
    public function getReports($filters = [])
    {
        if (!$this->haveAccess()) {
            return [];
        }

        $enabled = Commerce::isEnabled('edd');
        $supports = [
            'product_growth'   => [
                'title'       => __('Gross Volume', 'fluentcampaign-pro'),
                'is_money'    => true,
                'has_product' => true
            ],
            'customers_growth' => [
                'title' => __('Customer Growth', 'fluentcampaign-pro')
            ]
        ];

        if (defined('EDD_RECURRING_VERSION')) {
            $supports['product_growth']['sub_types'] = [
                'all'                => [
                    'label' => __('All', 'fluentcampaign-pro')
                ],
                'recurring_revenue'  => [
                    'label' => __('Subscription Revenue (All)', 'fluentcampaign-pro')
                ],
                'renewal_signup'     => [
                    'label' => __('Subscriptions (New)', 'fluentcampaign-pro')
                ],
                'subscription_renew' => [
                    'label' => __('Recurring (renew only)', 'fluentcampaign-pro')
                ],
                'lifetime_items'     => [
                    'label' => __('Onetime Items', 'fluentcampaign-pro')
                ]
            ];

            $supports['subscriptions_report'] = [
                'title'       => __('Subscriptions', 'fluentcampaign-pro'),
                'has_product' => true,
                'is_money'    => false,
                'sub_types'   => [
                    'all'     => [
                        'label' => __('Signups', 'fluentcampaign-pro')
                    ],
                    'renews'  => [
                        'label' => __('Renews', 'fluentcampaign-pro')
                    ],
                    'expires' => [
                        'label' => __('Expired', 'fluentcampaign-pro')
                    ]
                ]
            ];
        }


        if($enabled && Arr::get($filters, 'top_products_only')) {
            return [
                'top_products' => $this->getTopProductsByPost($filters)
            ];
        }

        $overview = [
            'enabled'       => $enabled,
            'title'         => __('Easy Digital Downloads - Advanced Reports', 'fluentcampaign-pro'),
            'supports'      => $supports,
            'currency_sign' => edd_currency_symbol(),
            'has_top_products_filter' => true
        ];

        if (!$enabled) {
            $overview['enable_instruction'] = __('Please enable data sync first from FluentCRM', 'fluentcampaign-pro').' <b><a href="' . admin_url('admin.php?page=fluentcrm-admin#/settings/integration_settings?selected_integration=edd') . '">'.__('Settings -> Integrations Settings -> Edd', 'fluentcampaign-pro').'</a></b> '.__('to view in details edd reports', 'fluentcampaign-pro');
        } else {
            $counts = fluentCrmDb()->table('fc_contact_relations')
                ->where('provider', 'edd')
                ->select([
                    fluentCrmDb()->raw('SUM(total_order_count) as total_orders'),
                    fluentCrmDb()->raw('SUM(total_order_value) as total_order_value'),
                    fluentCrmDb()->raw('COUNT(*) as customer_total')
                ])
                ->first();

            $overview['store_average'] = Commerce::getStoreAverage('edd');

            $overview['widgets'] = [
                'total_revenue'         => [
                    'value'    => $counts->total_order_value,
                    'label'    => __('Total Revenue', 'fluentcampaign-pro'),
                    'is_money' => true
                ],
                'total_orders'          => [
                    'value' => $counts->total_orders,
                    'label' => __('Total Orders', 'fluentcampaign-pro')
                ],
                'total_customers'       => [
                    'value' => $counts->customer_total,
                    'label' => __('Total Customers', 'fluentcampaign-pro')
                ],
                'revenue_per_customers' => [
                    'value'    => ($counts->customer_total) ? $counts->total_order_value / $counts->customer_total : 0,
                    'label'    => __('Average Revenue Per Customer', 'fluentcampaign-pro'),
                    'is_money' => true
                ]
            ];

            if (defined('EDD_RECURRING_VERSION')) {

                $activeSubscriptions = fluentCrmDb()->table('edd_subscriptions')
                    ->where('status', 'active')
                    ->count();

                if ($activeSubscriptions) {
                    $overview['widgets']['active_subscriptions'] = [
                        'value' => $activeSubscriptions,
                        'label' => 'Active Subscriptions'
                    ];
                }

                $cancelledSubscriptions = fluentCrmDb()->table('edd_subscriptions')
                    ->where('status', 'cancelled')
                    ->count();
                if ($cancelledSubscriptions) {
                    $overview['widgets']['canclled_subscriptions'] = [
                        'value' => $cancelledSubscriptions,
                        'label' => 'Cancelled Subscriptions'
                    ];
                }
            }

            if (defined('EDD_SL_VERSION')) {
                $overview['widgets']['license_count'] = [
                    'value' => fluentCrmDb()->table('edd_license_activations')->count(),
                    'label' => __('Total Activated Sites (Licenses)', 'fluentcampaign-pro')
                ];
                $overview['widgets']['active_licenses'] = [
                    'value' => fluentCrmDb()->table('edd_licenses')->whereIn('status', ['active', 'disabled'])->count(),
                    'label' => __('Active Licenses', 'fluentcampaign-pro')
                ];

                $overview['widgets']['expired_licenses'] = [
                    'value' => fluentCrmDb()->table('edd_licenses')->where('status', 'expired')->count(),
                    'label' => __('Expired Licenses', 'fluentcampaign-pro')
                ];
            }
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
        if (!$this->haveAccess()) {
            return [];
        }

        if ($type == 'product_growth') {
            return $this->getProductGrowth($filters);
        } else if ($type == 'customers_growth') {
            return $this->getCustomersGrowth($filters);
        } else if ($type == 'subscriptions_report') {
            return $this->getSubscriptionsReport($filters);
        }
    }

    /**
     * @param array $filters
     * @return array
     */
    public function getSubscriptionsReport($filters = [])
    {
        if (!$this->haveAccess()) {
            return [];
        }

        $params = $this->getReportParams($filters);

        $subType = Arr::get($filters, 'sub_type', 'all');

        $compareItems = false;
        $statuses = [];
        $dateColumn = 'created';
        if ($subType == 'expires') {
            $statuses = ['expired', 'cancelled'];
            $dateColumn = 'expiration';
        } else if ($subType == 'renews') {
            if ($params['compare_range']) {
                $compareItems = $this->getItemCountsByRange($params['compare_range'], $params['product_id'], ['renewal']);
            }

            $currentItems = $this->getItemCountsByRange($params['current_range'], $params['product_id'], ['renewal']);

            $dataSets = [
                $this->formatDataSet($currentItems, __('Current Range', 'fluentcampaign-pro'), $params['current_range'])
            ];

            if ($compareItems) {
                $dataSets[] = $this->formatDataSet($compareItems, __('Compare Range', 'fluentcampaign-pro'), $params['compare_range'], 'compare');
            }

            return [
                'data_sets'     => $dataSets,
                'compare_range' => $params['compare_range'],
                'current_range' => [
                    $params['current_range'][0]->format('Y-m-d'),
                    $params['current_range'][1]->format('Y-m-d')
                ]
            ];
        }

        if ($params['compare_range']) {
            $compareItems = $this->getSubscriptionsByRange($params['compare_range'], $params['product_id'], $statuses, $dateColumn);
        }

        $currentItems = $this->getSubscriptionsByRange($params['current_range'], $params['product_id'], $statuses, $dateColumn);
        $dataSets = [
            $this->formatDataSet($currentItems, __('Current Range', 'fluentcampaign-pro'), $params['current_range'])
        ];

        if ($compareItems) {
            $dataSets[] = $this->formatDataSet($compareItems, __('Compare Range', 'fluentcampaign-pro'), $params['compare_range'], 'compare');
        }

        return [
            'data_sets'     => $dataSets,
            'compare_range' => $params['compare_range'],
            'current_range' => [
                $params['current_range'][0]->format('Y-m-d'),
                $params['current_range'][1]->format('Y-m-d')
            ]
        ];

    }

    /**
     * @param array $range
     * @param int|false $productId
     * @param array $statuses
     * @param string $dateColumn
     * @return array
     */
    protected function getSubscriptionsByRange($range, $productId = false, $statuses = [], $dateColumn = 'created')
    {
        $frequency = $this->getFrequency($range[0], $range[1]);

        $selects = [
            fluentCrmDb()->raw('COUNT(*) as count'),
            fluentCrmDb()->raw('DATE(' . $dateColumn . ') as date'),
        ];

        $format = 'Y-m-d';
        if ($frequency == self::$monthly) {
            $selects[] = fluentCrmDb()->raw('YEAR(' . $dateColumn . ') AS year');
            $selects[] = fluentCrmDb()->raw('MONTH(' . $dateColumn . ') AS month');
            $format = 'M Y';
        }

        $query = fluentCrmDb()->table('edd_subscriptions')
            ->select($selects)
            ->whereBetween($dateColumn, [$range[0]->format('Y-m-d 00:00:01'), $range[1]->format('Y-m-d 23:59:59')])
            ->orderBy('date', 'ASC');

        if ($frequency == self::$monthly) {
            $query->groupBy('year', 'month');
        } else {
            $query->groupBy('date');
        }

        if ($statuses) {
            $query->whereIn('status', $statuses);
        }

        if ($productId) {
            $query->where('product_id', $productId);
        }

        $items = $query->get();

        $period = $this->makeDatePeriod(
            $range[0],
            $range[1],
            $frequency
        );

        $periodRange = $this->getDateRangeArray($period);

        foreach ($items as $item) {
            $periodRange[date($format, strtotime($item->date))] = $item->count;
        }

        return $periodRange;
    }

    private function haveAccess()
    {
        return apply_filters('fluent_crm/user_can_view_edd_report', current_user_can('view_shop_sensitive_data'));
    }
}
