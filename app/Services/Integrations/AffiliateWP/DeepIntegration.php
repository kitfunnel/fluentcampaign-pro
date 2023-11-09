<?php

namespace FluentCampaign\App\Services\Integrations\AffiliateWP;

use FluentCampaign\App\Services\Integrations\BaseImporter;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\Libs\ConditionAssessor;
use FluentCrm\Framework\Support\Arr;

class DeepIntegration extends BaseImporter
{
    public function __construct()
    {
        $this->importKey = 'afiliatewp';
        parent::__construct();

        $this->registerHooks();

    }

    private function registerHooks()
    {
        add_filter('fluentcrm_contacts_filter_aff_wp', array($this, 'addAdvancedFilter'), 10, 2);
        add_filter('fluentcrm_advanced_filter_options', array($this, 'addAdvancedFilterOptions'), 10, 1);
        add_filter('fluent_crm/smartcode_group_callback_aff_wp', array($this, 'parseSmartcode'), 10, 4);
        add_filter('fluent_crm/extended_smart_codes', array($this, 'pushGeneralCodes'));

        // Automation
        add_filter('fluentcrm_automation_condition_groups', array($this, 'addAdvancedFilterOptions'), 10, 1);
        add_filter('fluentcrm_automation_conditions_assess_aff_wp', array($this, 'assessFunnelConditions'), 10, 3);
    }

    /**
     * @param \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder $query
     * @param array $filters
     * @return \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder
     */
    public function addAdvancedFilter($query, $filters)
    {
        foreach ($filters as $filter) {
            $query = $this->applyFilter($query, $filter);
        }

        return $query;
    }

    public function addAdvancedFilterOptions($groups)
    {
        $disabled = false;

        $groups['aff_wp'] = [
            'label'    => __('AffiliateWP', 'fluentcampaign-pro'),
            'value'    => 'aff_wp',
            'children' => [
                [
                    'value'   => 'is_affiliate',
                    'label'   => __('Is Affiliate', 'fluentcampaign-pro'),
                    'type'    => 'single_assert_option',
                    'options' => [
                        'yes' => __('Yes', 'fluentcampaign-pro'),
                        'no'  => __('No', 'fluentcampaign-pro')
                    ],
                ],
                [
                    'value'    => 'affiliate_id',
                    'label'    => __('Affiliate ID', 'fluentcampaign-pro'),
                    'type'     => 'numeric',
                    'disabled' => $disabled
                ],
                [
                    'value'    => 'referrals',
                    'label'    => __('Total Referrals', 'fluentcampaign-pro'),
                    'type'     => 'numeric',
                    'disabled' => $disabled
                ],
                [
                    'value'   => 'status',
                    'label'   => __('Status', 'fluentcampaign-pro'),
                    'type'    => 'single_assert_option',
                    'options' => [
                        'active'   => __('Active', 'fluentcampaign-pro'),
                        'inactive' => __('Inactive', 'fluentcampaign-pro'),
                        'pending'  => __('Pending', 'fluentcampaign-pro')
                    ],
                ],
                [
                    'value'    => 'earnings',
                    'label'    => __('Earnings', 'fluentcampaign-pro'),
                    'type'     => 'numeric',
                    'disabled' => $disabled
                ],
                [
                    'value'    => 'unpaid_earnings',
                    'label'    => __('Unpaid Earnings', 'fluentcampaign-pro'),
                    'type'     => 'numeric',
                    'disabled' => $disabled
                ],
                [
                    'value'    => 'date_registered',
                    'label'    => __('Registration Date', 'fluentcampaign-pro'),
                    'type'     => 'dates',
                    'disabled' => $disabled
                ],
                [
                    'value'    => 'last_payment_date',
                    'label'    => __('Last Payout Date', 'fluentcampaign-pro'),
                    'type'     => 'dates',
                    'disabled' => $disabled
                ]
            ]
        ];

        return $groups;
    }

    private function applyFilter($query, $filter)
    {
        $key = Arr::get($filter, 'property', '');
        $value = Arr::get($filter, 'value', '');
        $operator = Arr::get($filter, 'operator', '');

        if ($value === '' || !$key || !$operator) {
            return $query;
        }

        if ($key == 'is_affiliate') {
            if ($value == 'yes') {
                return $query->has('affiliate_wp');
            } else {
                return $query->doesntHave('affiliate_wp');
            }
        }

        $affProperties = ['affiliate_id', 'status', 'referrals', 'earnings', 'unpaid_earnings'];

        if (in_array($key, $affProperties)) {
            return $query->whereHas('affiliate_wp', function ($q) use ($key, $value, $operator) {
                return $q->where($key, $operator, $value);
            });
        }

        if ($key == 'date_registered') {
            $filter = Subscriber::filterParser($filter);
            return $query->whereHas('affiliate_wp', function ($q) use ($filter) {
                if ($filter['operator'] == 'BETWEEN') {
                    return $q->whereBetween('date_registered', $filter['value']);
                } else {
                    return $q->where('date_registered', $filter['operator'], $filter['value']);
                }
            });
        }

        if ($key == 'last_payment_date') {
            $filter = Subscriber::filterParser($filter);

            return $query->whereHas('affiliate_wp', function ($q) use ($filter) {
                $subQ = $q->join('affiliate_wp_payouts', 'affiliate_wp_payouts.affiliate_id', '=', 'affiliate_wp_affiliates.affiliate_id');
                //->where('affiliate_wp_payouts.status', 'paid');
                if ($filter['operator'] == 'BETWEEN') {
                    return $subQ->whereBetween('date', $filter['value']);
                } else {
                    return $subQ->where('date', $filter['operator'], $filter['value']);
                }
            });
        }

        return $query;
    }

    public function getInfo()
    {
        return [
            'label'    => __('AffiliateWP', 'fluentcampaign-pro'),
            'logo'     => fluentCrmMix('images/affiliatewp.svg'),
            'disabled' => false
        ];
    }

    public function processUserDriver($config, $request)
    {
        $summary = $request->get('summary');

        if ($summary) {
            $users = fluentCrmDb()->table('affiliate_wp_affiliates')
                ->join('users', 'users.ID', '=', 'affiliate_wp_affiliates.user_id')
                ->select(['users.user_email', 'users.display_name'])
                ->limit(5)
                ->get();

            $formattedUsers = [];
            foreach ($users as $user) {
                $formattedUsers[] = [
                    'name'  => $user->display_name,
                    'email' => $user->user_email
                ];
            }

            return [
                'import_info' => [
                    'subscribers'       => $formattedUsers,
                    'total'             => AffiliateWPModel::count(),
                    'has_tag_config'    => true,
                    'has_list_config'   => true,
                    'has_status_config' => true,
                    'has_update_config' => false,
                    'has_silent_config' => true
                ]
            ];
        }

        $importType = 'affiliate_sync';

        $importTitle = __('Sync AffiliateWP Affiliates Now', 'fluentcampaign-pro');

        $configFields = [
            'config' => [
                'import_type' => $importType
            ],
            'fields' => [
                'sync_import_html' => [
                    'type'    => 'html-viewer',
                    'heading' => __('Affiliates Sync', 'fluentcampaign-pro'),
                    'info'    => __('You can sync all your Affiliates into FluentCRM. After this sync you can segment your contacts easily', 'fluentcampaign-pro')
                ]
            ],
            'labels' => [
                'step_2' => __('Next [Review Data]', 'fluentcampaign-pro'),
                'step_3' => $importTitle
            ]
        ];

        return $configFields;
    }

    public function importData($returnData, $config, $page)
    {
        $inputs = Arr::only($config, [
            'lists', 'tags', 'status', 'double_optin_email', 'import_silently'
        ]);

        $inputs = wp_parse_args($inputs, [
            'lists'              => [],
            'tags'               => [],
            'new_status'         => 'subscribed',
            'double_optin_email' => 'no',
            'import_silently'    => 'yes'
        ]);

        if (Arr::get($inputs, 'import_silently') == 'yes') {
            if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
                define('FLUENTCRM_DISABLE_TAG_LIST_EVENTS', true);
            }
        }

        $sendDoubleOptin = Arr::get($inputs, 'double_optin_email') == 'yes';
        $contactStatus = Arr::get($inputs, 'status', 'subscribed');

        $startTime = time();

        $runTime = 30;
        if ($page == 1) {
            fluentcrm_update_option('_affwp_sync_count', 0);
            $runTime = 5;
        }

        $run = true;

        while ($run) {
            $offset = fluentcrm_get_option('_affwp_sync_count', 0);
            $affiliates = fluentCrmDb()->table('affiliate_wp_affiliates')
                ->limit(10)
                ->offset($offset)
                ->orderBy('affiliate_id', 'ASC')
                ->get();

            if ($affiliates) {
                foreach ($affiliates as $affiliate) {
                    $subscribers = Helper::getWPMapUserInfo($affiliate->user_id);

                    Subscriber::import(
                        [$subscribers],
                        Arr::get($inputs, 'tags', []),
                        Arr::get($inputs, 'lists', []),
                        true,
                        $contactStatus,
                        $sendDoubleOptin
                    );

                    fluentcrm_update_option('_affwp_sync_count', $offset + 1);
                    if (time() - $startTime > $runTime) {
                        return $this->getSyncStatus();
                    }
                }
            } else {
                $run = false;
            }
        }

        return $this->getSyncStatus();
    }

    private function getSyncStatus()
    {
        $total = fluentCrmDb()->table('affiliate_wp_affiliates')->count();
        $completedCount = fluentcrm_get_option('_affwp_sync_count', 0);

        $hasMore = $total > $completedCount;

        return [
            'page_total'   => $total,
            'record_total' => $total,
            'has_more'     => $hasMore,
            'current_page' => $completedCount,
            'next_page'    => $completedCount + 1,
            'reload_page'  => !$hasMore
        ];
    }

    public function pushGeneralCodes($codes)
    {
        $codes['aff_wp'] = [
            'key'        => 'aff_wp',
            'title'      => 'AffiliateWP',
            'shortcodes' => [
                '{{aff_wp.affiliate_id}}'       => 'Affiliate ID',
                '{{aff_wp.status}}'             => 'Status',
                '{{aff_wp.earnings}}'           => 'Total Earning',
                '{{aff_wp.unpaid_earnings}}'    => 'Unpaid Earnings',
                '{{aff_wp.referrals}}'          => 'Referrals',
                '{{aff_wp.visits}}'             => 'Visits',
                '{{aff_wp.date_registered}}'    => 'Date Registered',
                '{{aff_wp.payment_email}}'      => 'Payment Email',
                '{{aff_wp.last_payout_amount}}' => 'Last payout Amount',
                '{{aff_wp.last_payout_date}}'   => 'Last Payout Date'
            ]
        ];

        return $codes;
    }

    public function parseSmartCode($value, $valueKey, $defaultValue, $subscriber)
    {
        $userId = $subscriber->user_id;

        if (!$userId) {
            $user = $subscriber->getWpUser();
            if (!$user) {
                return $defaultValue;
            }
            $userId = $user->ID;
        }

        $affiliate = AffiliateWPModel::where('user_id', $userId)->first();

        if (!$affiliate) {
            return $defaultValue;
        }

        return $affiliate->getAffPropValue($valueKey, $defaultValue);
    }

    public function assessFunnelConditions($result, $conditions, $subscriber)
    {
        $user = $subscriber->getWpUser();
        $affiliate = false;
        $inputs = [
            'is_affiliate' => 'no'
        ];

        if ($user) {
            $affiliate = AffiliateWPModel::where('user_id', $user->ID)->first();
            if ($affiliate) {
                $inputs['is_affiliate'] = 'yes';
            }
        }

        foreach ($conditions as $condition) {
            $prop = $condition['data_key'];
            if ($prop == 'is_affiliate') {
                $inputs[$prop] = ($affiliate) ? 'yes' : 'no';
            } else {
                $inputs[$prop] = ($affiliate) ? $affiliate->getAffPropValue($prop, '') : '';
            }
        }

        return ConditionAssessor::matchAllConditions($conditions, $inputs);
    }
}
