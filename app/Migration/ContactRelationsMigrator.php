<?php

namespace FluentCampaign\App\Migration;

class ContactRelationsMigrator
{
    /**
     * On-Demand Action Links Migrator.
     *
     * @param bool $isForced
     * @return void
     */
    public static function migrate($isForced = false)
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'fc_contact_relations';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table || $isForced) {
            $sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `subscriber_id` BIGINT UNSIGNED NOT NULL,
                `provider` VARCHAR(100) NOT NULL,
                `provider_id` BIGINT UNSIGNED NULL,
                `first_order_date` TIMESTAMP NULL,
                `last_order_date` TIMESTAMP NULL,
                `total_order_count` INT(10) NULL DEFAULT 0,
                `total_order_value` DECIMAL (10, 2) NULL DEFAULT 0,
                `status` VARCHAR(100) NULL,
                `commerce_taxonomies` LONGTEXT NULL,
                `commerce_coupons` LONGTEXT NULL,
                `meta_col_1` MEDIUMTEXT NULL,
                `meta_col_2` MEDIUMTEXT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                 KEY `subscriber_id` (`subscriber_id`),
                 KEY `provider` (`provider`),
                 KEY `provider_id` (`provider_id`)
            ) $charsetCollate;";
            dbDelta($sql);
        }
    }
}
