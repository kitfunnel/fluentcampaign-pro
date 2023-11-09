<?php

namespace FluentCampaign\App\Services\Integrations\WooCommerce;

use FluentCampaign\App\Services\BaseAdvancedReport;
use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCrm\App\Services\ReportingHelperTrait;
use FluentCrm\Framework\Support\Arr;

class AdvancedReport extends BaseAdvancedReport
{
    use ReportingHelperTrait;

    public function __construct()
    {
        $this->provider = 'woo';
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

        $enabled = Commerce::isEnabled('woo');

        if($enabled && Arr::get($filters, 'top_products_only')) {
            return [
                'top_products' => $this->getTopProductsByPost($filters)
            ];
        }

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

        if (defined('WCS_INIT_TIMESTAMP')) {
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
                'title'       => 'Subscriptions',
                'has_product' => true,
                'is_money'    => false,
                'sub_types'   => [
                    'all'    => [
                        'label' => __('Signups', 'fluentcampaign-pro')
                    ],
                    'renews' => [
                        'label' => __('Renews', 'fluentcampaign-pro')
                    ]
                ]
            ];
        }

        $overview = [
            'enabled'       => $enabled,
            'title'         => __('WooCommerce - Advanced Reports', 'fluentcampaign-pro'),
            'title_info' => __('Individual Product Sales values are excluded Tax & Shipping amounts', 'fluentcampaign-pro'),
            'supports'      => $supports,
            'currency_sign' => get_woocommerce_currency_symbol(),
            'has_top_products_filter' => true
        ];

        if (!$enabled) {
            $overview['enable_instruction'] = __('Please enable data sync first from FluentCRM', 'fluentcampaign-pro').' <b><a href="' . admin_url('admin.php?page=fluentcrm-admin#/settings/integration_settings?selected_integration=woo') . '">'.__('Settings -> Integrations Settings -> WooCommerce', 'fluentcampaign-pro').'</a></b> '.__('to view in details WooCommerce reports', 'fluentcampaign-pro');
        } else {
            $counts = fluentCrmDb()->table('fc_contact_relations')
                ->where('provider', $this->provider)
                ->select([
                    fluentCrmDb()->raw('SUM(total_order_count) as total_orders'),
                    fluentCrmDb()->raw('SUM(total_order_value) as total_order_value'),
                    fluentCrmDb()->raw('COUNT(*) as customer_total')
                ])
                ->first();

            $overview['store_average'] = Commerce::getStoreAverage($this->provider);

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
            $filters['lifetime_type'] = 'line_item';
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
        $params = $this->getReportParams($filters);
        $subType = Arr::get($filters, 'sub_type', 'all');

        $compareItems = false;

        $statuses = [];

        if ($subType == 'expires') {
            $statuses = ['wc-expired', 'wc-cancelled'];
            if ($params['compare_range']) {
                $compareItems = $this->getSubscriptionsEndByRange($params['compare_range'], $statuses);
            }

            $currentItems = $this->getSubscriptionsEndByRange($params['current_range'], $statuses);

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
        } else if ($subType == 'renews') {
            $statuses = ['renewal'];
        } else {
            $statuses = ['renewal_signup'];
        }

        if ($params['compare_range']) {
            $compareItems = $this->getItemCountsByRange($params['compare_range'], $params['product_id'], $statuses);
        }

        $currentItems = $this->getItemCountsByRange($params['current_range'], $params['product_id'], $statuses);
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
    protected function getSubscriptionsEndByRange($range, $statuses = [])
    {
        $frequency = $this->getFrequency($range[0], $range[1]);

        global $wpdb;
        $dateColumn = $wpdb->prefix . 'postmeta.meta_value';

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

        $query = fluentCrmDb()->table('posts')
            ->select($selects)
            ->where('posts.post_type', 'shop_subscription')
            ->join('postmeta', 'postmeta.post_id', '=', 'posts.ID')
            ->where('postmeta.meta_key', '_schedule_end');

        if ($frequency == self::$monthly) {
            $query->groupBy('year', 'month');
        } else {
            $query->groupBy('date');
        }

        if ($statuses) {
            $query->whereIn('posts.post_status', $statuses);
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
        return apply_filters('fluent_crm/user_can_view_woo_report', current_user_can('view_woocommerce_reports'));
    }

}
