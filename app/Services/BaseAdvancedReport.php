<?php

namespace FluentCampaign\App\Services;

use FluentCrm\App\Services\ReportingHelperTrait;
use FluentCrm\Framework\Support\Arr;

abstract class BaseAdvancedReport
{
    use ReportingHelperTrait;

    protected static $daily = 'P1D';
    protected static $weekly = 'P1W';
    protected static $monthly = 'P1M';

    public $provider;

    /**
     * @param array $filters
     * @return mixed
     */
    abstract public function getReports($filters = []);

    /**
     * @param $type
     * @param array $filters
     * @return mixed
     */
    abstract public function getReport($type, $filters = []);

    /**
     * @param array $filters
     * @return array
     */
    public function getCustomersGrowth($filters = [])
    {
        $params = $this->getReportParams($filters);

        $compareItems = false;
        if ($params['compare_range']) {
            $compareItems = $this->getCustomersByRange($params['compare_range']);
        }

        $currentItems = $this->getCustomersByRange($params['current_range']);
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
     * @param $range
     * @return array
     */
    protected function getCustomersByRange($range)
    {
        $frequency = $this->getFrequency($range[0], $range[1]);

        $selects = [
            fluentCrmDb()->raw('COUNT(*) as count'),
            fluentCrmDb()->raw('DATE(created_at) as date'),
        ];

        $format = 'Y-m-d';
        if ($frequency == self::$monthly) {
            $selects[] = fluentCrmDb()->raw('YEAR(created_at) AS year');
            $selects[] = fluentCrmDb()->raw('MONTH(created_at) AS month');
            $format = 'M Y';
        }

        $query = fluentCrmDb()->table('fc_contact_relations')
            ->select($selects)
            ->where('provider', $this->provider)
            ->whereBetween('created_at', [$range[0]->format('Y-m-d 00:00:01'), $range[1]->format('Y-m-d 23:59:59')])
            ->orderBy('date', 'ASC');

        if ($frequency == self::$monthly) {
            $query->groupBy('year', 'month');
        } else {
            $query->groupBy('date');
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

    /**
     * @param array $filters
     * @return array
     */
    public function getProductGrowth($filters = [])
    {
        $params = $this->getReportParams($filters);

        $subType = Arr::get($filters, 'sub_type', 'all');

        $itemTypes = [];

        if ($subType == 'recurring_revenue') {
            $itemTypes = ['renewal_signup', 'renewal'];
        } else if ($subType == 'subscription_renew') {
            $itemTypes = ['renewal'];
        } else if ($subType == 'lifetime_items') {
            $itemTypes = [Arr::get($filters, 'lifetime_type', 'product')];
        } else if ($subType == 'renewal_signup') {
            $itemTypes = ['renewal_signup'];
        }

        $compareItems = false;
        if ($params['compare_range']) {
            $compareItems = $this->getRevenueByRange($params['compare_range'], $params['product_id'], $itemTypes);
        }

        $currentItems = $this->getRevenueByRange($params['current_range'], $params['product_id'], $itemTypes);

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
            ],
            'sub_type'      => $subType,
            'item_types'    => $itemTypes
        ];
    }


    /**
     * @param array $filters
     * @return array
     */
    public function getProductGrowthCounts($filters = [])
    {
        $params = $this->getReportParams($filters);

        $subType = Arr::get($filters, 'sub_type', 'all');

        $itemTypes = [];

        if ($subType == 'recurring_revenue') {
            $itemTypes = ['renewal_signup', 'renewal'];
        } else if ($subType == 'subscription_renew') {
            $itemTypes = ['renewal'];
        } else if ($subType == 'lifetime_items') {
            $itemTypes = [Arr::get($filters, 'lifetime_type', 'product')];
        } else if ($subType == 'renewal_signup') {
            $itemTypes = ['renewal_signup'];
        } else if ($subType == 'by_item_types') {
            $itemTypes = $subType = Arr::get($filters, 'item_types', []);
        }

        $compareItems = false;
        if ($params['compare_range']) {
            $compareItems = $this->getItemCountsByRange($params['compare_range'], $params['product_id'], $itemTypes);
        }

        $currentItems = $this->getItemCountsByRange($params['current_range'], $params['product_id'], $itemTypes);

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
            ],
            'sub_type'      => $subType,
            'item_types'    => $itemTypes
        ];
    }

    /**
     * @param $range
     * @param int | false $productId
     * @param array $itemTypes
     * @return array
     */
    protected function getRevenueByRange($range, $productId = false, $itemTypes = [])
    {
        $frequency = $this->getFrequency($range[0], $range[1]);

        $selects = [
            fluentCrmDb()->raw('SUM(item_value) as revenue'),
            fluentCrmDb()->raw('COUNT(*) as count'),
            fluentCrmDb()->raw('DATE(created_at) as date'),
        ];

        $format = 'Y-m-d';
        if ($frequency == self::$monthly) {
            $selects[] = fluentCrmDb()->raw('YEAR(created_at) AS year');
            $selects[] = fluentCrmDb()->raw('MONTH(created_at) AS month');
            $format = 'M Y';
        }

        $query = fluentCrmDb()->table('fc_contact_relation_items')
            ->select($selects)
            ->where('provider', $this->provider)
            ->whereBetween('created_at', [$range[0]->format('Y-m-d 00:00:01'), $range[1]->format('Y-m-d 23:59:59')])
            ->when($productId, function ($query) use ($productId) {
                return $query->where('item_id', $productId);
            })
            ->orderBy('date', 'ASC');

        if ($frequency == self::$monthly) {
            $query->groupBy('year', 'month');
        } else {
            $query->groupBy('date');
        }

        if ($itemTypes) {
            $query->whereIn('item_type', $itemTypes);
        }

        $items = $query->get();

        $period = $this->makeDatePeriod(
            $range[0],
            $range[1],
            $frequency
        );

        $periodRange = $this->getDateRangeArray($period);

        foreach ($items as $item) {
            $periodRange[date($format, strtotime($item->date))] = $item->revenue;
        }

        return $periodRange;
    }

    /**
     * @param $range
     * @param int | false $productId
     * @param array $itemTypes
     * @return array
     */
    protected function getItemCountsByRange($range, $productId = false, $itemTypes = [])
    {
        $frequency = $this->getFrequency($range[0], $range[1]);

        $selects = [
            fluentCrmDb()->raw('COUNT(*) as count'),
            fluentCrmDb()->raw('DATE(created_at) as date'),
        ];

        $format = 'Y-m-d';
        if ($frequency == self::$monthly) {
            $selects[] = fluentCrmDb()->raw('YEAR(created_at) AS year');
            $selects[] = fluentCrmDb()->raw('MONTH(created_at) AS month');
            $format = 'M Y';
        }

        $query = fluentCrmDb()->table('fc_contact_relation_items')
            ->select($selects)
            ->where('provider', $this->provider)
            ->whereBetween('created_at', [$range[0]->format('Y-m-d 00:00:01'), $range[1]->format('Y-m-d 23:59:59')])
            ->when($productId, function ($query) use ($productId) {
                return $query->where('item_id', $productId);
            })
            ->orderBy('date', 'ASC');

        if ($frequency == self::$monthly) {
            $query->groupBy('year', 'month');
        } else {
            $query->groupBy('date');
        }

        if ($itemTypes) {
            $query->whereIn('item_type', $itemTypes);
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

    /**
     * @param array $filters
     * @return array
     */
    public function getTopProductsByPost($filters = [])
    {
        $dateRange = array_filter(Arr::get($filters, 'date_range', []));

        $page = Arr::get($filters, 'page', 1);
        $limit = Arr::get($filters, 'per_page', 5);
        $offset = ($page - 1) * $limit;

        global $wpdb;

        $topProducts = fluentCrmDb()->table('fc_contact_relation_items')
            ->select([
                'fc_contact_relation_items.item_id',
                fluentCrmDb()->raw('SUM(' . $wpdb->prefix . 'fc_contact_relation_items.item_value) as revenue'),
                fluentCrmDb()->raw('COUNT(*) as count'),
                'posts.post_title'
            ])
            ->leftJoin('posts', 'posts.ID', '=', 'fc_contact_relation_items.item_id')
            ->groupBy('fc_contact_relation_items.item_id')
            ->orderBy('revenue', 'DESC');

        if (count($dateRange) === 2) {
            $dateRange[0] .= ' 00:00:01';
            $dateRange[1] .= ' 23:59:59';
            $topProducts = $topProducts->whereBetween('created_at', $dateRange);
        }

        $topProducts = $topProducts->limit($limit)
            ->offset($offset)
            ->where('fc_contact_relation_items.provider', $this->provider)
            ->get();

        return $topProducts;
    }

    /**
     * @param $from
     * @param $to
     * @return string
     */
    protected function getFrequency($from, $to)
    {
        $numDays = $to->diff($from)->format("%a");

        if ($numDays > 91) {
            return static::$monthly;
        }

        return static::$daily;
    }

    /**
     * @param string $type
     * @param array $compareRange
     * @param array $currentRange
     * @return array|\DateTime[]|false
     * @throws \Exception
     */
    protected function getCompareRange($type, $compareDate, $currentRange)
    {
        $diffDays = $currentRange[1]->diff($currentRange[0])->format("%a");
        $diffTimestamps = $diffDays * 86400;

        if ($type == 'previous_period') {
            return [
                new \DateTime(date('Y-m-d 00:00:01', $currentRange[0]->getTimestamp() - ($diffDays + 1) * 86400)),
                new \DateTime(date('Y-m-d 23:59:59', $currentRange[0]->getTimestamp() - 86400))
            ];
        } else if ($type == 'previous_month') {
            $fromDate = date('Y-m-d 00:00:01', strtotime($currentRange[0]->format('Y-m-d') . ' -1 month'));
            return [
                new \DateTime($fromDate),
                new \DateTime(date('Y-m-d 23:59:59', strtotime($fromDate) + $diffTimestamps))
            ];
        } else if ($type == 'previous_quarter') {
            $fromDate = date('Y-m-d 00:00:01', strtotime($currentRange[0]->format('Y-m-d') . ' -3 months'));
            return [
                new \DateTime($fromDate),
                new \DateTime(date('Y-m-d 23:59:59', strtotime($fromDate) + $diffTimestamps))
            ];
        } else if ($type == 'previous_year') {
            $fromDate = date('Y-m-d 00:00:01', strtotime($currentRange[0]->format('Y-m-d') . ' -12 months'));
            return [
                new \DateTime($fromDate),
                new \DateTime(date('Y-m-d 23:59:59', strtotime($fromDate) + $diffTimestamps))
            ];
        } else if ($type == 'custom' && $compareDate) {
            $fromDate = $compareDate . ' 00:00:01';
            return [
                new \DateTime($fromDate),
                new \DateTime(date('Y-m-d 23:59:59', strtotime($fromDate) + $diffTimestamps))
            ];
        } else {
            return false;
        }
    }

    /**
     * @param array $filters
     * @return array
     * @throws \Exception
     */
    protected function getReportParams($filters)
    {
        $productId = Arr::get($filters, 'item_id', '');
        $currentRange = Arr::get($filters, 'date_range', []);
        $compareDate = Arr::get($filters, 'compare_date', []);
        $compareRangeType = Arr::get($filters, 'compare_type', 'previous_period');

        if (!$currentRange || count(array_filter($currentRange)) < 2) {
            $currentRange = [
                new \DateTime(date('Y-m-d 00:00:01', strtotime('-1 months'))),
                new \DateTime(date('Y-m-d 23:59:59'))
            ];
        } else {
            $currentRange = [
                new \DateTime($currentRange[0] . ' 00:00:01'),
                new \DateTime($currentRange[1] . ' 23:59:59')
            ];
        }

        $compareRange = $this->getCompareRange($compareRangeType, $compareDate, $currentRange);

        return [
            'product_id'    => $productId,
            'current_range' => $currentRange,
            'compare_range' => $compareRange
        ];

    }


    protected function formatDataSet($items, $title, $compareRange, $type = 'primary')
    {
        $data = [
            'range'           => [
                $compareRange[0]->format('Y-m-d'),
                $compareRange[1]->format('Y-m-d')
            ],
            'data'            => $items,
            'label'           => $title,
            'id'              => sanitize_title($title, $type, 'display'),
            'backgroundColor' => 'rgba(81, 52, 178, 0.5)',
            'borderColor'     => '#b175eb',
            'fill'            => true
        ];

        if ($type != 'primary') {
            $data['backgroundColor'] = 'rgba(128, 128, 128, 0.9)';
            $data['borderColor'] = 'gray';
        }

        return $data;

    }

}
