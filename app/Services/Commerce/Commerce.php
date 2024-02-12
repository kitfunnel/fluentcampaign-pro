<?php

namespace FluentCampaign\App\Services\Commerce;

use FluentCrm\Framework\Support\Arr;

class Commerce
{
    public static function isMigrated($checkDb = false)
    {
        if ($checkDb) {
            global $wpdb;
            $result = $wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->prefix . "fc_contact_relations'");
            return $result;
        }

        $status = self::getEnabledModules();
        return $status['migrated'] == 'yes';
    }

    public static function isEnabled($module)
    {
        $settings = self::getEnabledModules();
        return in_array($module, Arr::get($settings, 'modules', []));
    }

    public static function enableModule($module)
    {
        $modules = self::getEnabledModules(false);
        $modules['modules'][] = $module;
        $modules['modules'] = array_unique($modules['modules']);
        $modules['migrated'] = 'yes';

        update_option('_fluentcrm_commerce_modules', $modules);
        return self::getEnabledModules(false);
    }

    public static function disableModule($module)
    {
        $modules = self::getEnabledModules(false);

        if (($key = array_search($module, $modules['modules'])) !== false) {
            unset($modules['modules'][$key]);
        }

        $modules['modules'] = array_unique($modules['modules']);
        $modules['migrated'] = 'yes';

        update_option('_fluentcrm_commerce_modules', $modules);
        return self::getEnabledModules(false);
    }

    public static function getEnabledModules($cached = true)
    {
        static $status;

        if ($status && $cached) {
            return $status;
        }

        $modules = get_option('_fluentcrm_commerce_modules', []);
        if (!$modules) {
            update_option('_fluentcrm_commerce_modules', [
                'migrated' => 'no',
                'modules'  => []
            ]);
            $status = [
                'migrated' => 'no',
                'modules'  => []
            ];
        } else {
            $status = $modules;
        }

        return $status;
    }

    public static function migrate()
    {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        \FluentCampaign\App\Migration\ContactRelationsMigrator::migrate(true);
        \FluentCampaign\App\Migration\ContactRelationItemsMigrator::migrate(true);

        update_option('_fluentcrm_commerce_modules', [
            'migrated' => 'yes',
            'enabled'  => []
        ]);

        self::getEnabledModules(false);
    }

    public static function getRangeFromPeriod($period)
    {
        if (!$period) {
            return false;
        }

        $startFrom = false;
        $dateTo = false;

        if (is_array($period) && count($period) == 2) {
            return $period;
        } else if ($period) {
            if ($period == 'ytd') {
                $startFrom = date('Y-01-01 00:00:01');
                $dateTo = date('Y-m-d 23:59:59');
            } else if ($period == 'q1') {
                $startFrom = date('Y-01-01 00:00:01');
                $dateTo = date('Y-03-31 23:59:59');
            } else if ($period == 'q2') {
                $startFrom = date('Y-04-01 00:00:01');
                $dateTo = date('Y-06-30 23:59:59');
            } else if ($period == 'q3') {
                $startFrom = date('Y-07-01 00:00:01');
                $dateTo = date('Y-09-30 23:59:59');
            } else if ($period == 'q4') {
                $startFrom = date('Y-10-01 00:00:01');
                $dateTo = date('Y-12-31 23:59:59');
            } else if ($period == 'last_year') {
                $startFrom = date((date('Y') - 1) . '-01-01 00:00:01');
                $dateTo = date((date('Y') - 1) . '-m-d 23:59:59');
            }
        }

        if ($startFrom && $dateTo) {
            return [$startFrom, $dateTo];
        }

        return false;
    }

    public static function getPercentChangeHtml($value, $refValue)
    {
        if (!$refValue || !$value) {
            return '';
        }
        $change = $value - $refValue;
        $percentChange = absint(ceil($change / $refValue * 100));
        if ($change >= 0) {
            return '<span class="el-icon-caret-top fc_positive fc_change_ref">' . $percentChange . '%' . '</span>';
        } else {
            return '<span class="el-icon-caret-bottom fc_negative fc_change_ref">' . $percentChange . '%' . '</span>';
        }
    }

    public static function cacheStoreAverage($provider)
    {
        $average = fluentCrmDb()->table('fc_contact_relations')
            ->where('provider', $provider)
            ->select([
                fluentCrmDb()->raw('SUM(total_order_count) as total_orders'),
                fluentCrmDb()->raw('SUM(total_order_value) as total_order_value'),
                fluentCrmDb()->raw('COUNT(*) as customer_total')
            ])
            ->first();

        $aov = 0;
        $aoc = 0;

        if ($average && $average->total_orders) {
            $aov = $average->total_order_value / $average->total_orders;
            $aoc = $average->total_orders / $average->customer_total;
        }

        $data = [
            'aov' => $aov,
            'aoc' => $aoc
        ];

        fluentcrm_update_option('_' . $provider . '_store_average', $data);
    }

    public static function getStoreAverage($provider)
    {
        return fluentcrm_get_option('_' . $provider . '_store_average', [
            'aov' => 0,
            'aoc' => 0
        ]);
    }

    public static function getCommerceProvider($defaultProvider = '')
    {
        static $provider;

        if ($provider) {
            return $provider;
        }

        if (defined('WC_PLUGIN_FILE') && self::isEnabled('woo')) {
            $provider = 'woo';
        } else if (self::isEnabled('edd') && defined('EDD_VERSION')) {
            $provider = 'edd';
        } else {
            $provider = $defaultProvider;
        }

        return $provider;
    }

    public static function getDefaultCurrencySign($defaultSign = '')
    {
        static $sign;

        if ($sign) {
            return $sign;
        }

        $provider = self::getCommerceProvider();
        if (!$provider) {
            return $defaultSign;
        }

        if ($provider == 'woo') {
            $sign = get_woocommerce_currency_symbol();
        } else if ($provider == 'edd') {
            $sign = edd_currency_symbol();
        } else {
            $sign = $defaultSign;
        }

        return $sign;
    }

    public static function isOrderSyncedCache($key)
    {
        static $cached = [];

        if (isset($cached[$key])) {
            return true;
        }

        $cached[$key] = true;

        return false;
    }

    public static function resetModuleData($module)
    {
        if (!self::isEnabled($module)) {
            return false;
        }

        ContactRelationModel::provider($module)->delete();
        ContactRelationItemsModel::provider($module)->delete();

        if (!ContactRelationModel::first()) {
            global $wpdb;
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}fc_contact_relations");
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}fc_contact_relation_items");
        }

        return true;
    }

    public static function getLifetimeValue($value, $contact)
    {
        $provider = self::getCommerceProvider();

        if (!$provider) {
            return $value;
        }

        $relation = ContactRelationModel::provider($provider)
            ->where('subscriber_id', $contact->id)
            ->first();

        if ($relation) {
            return $relation->total_order_value;
        }

        return $value;
    }
}
