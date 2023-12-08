<?php

namespace FluentCampaign\App\Migration;

class ContactRelationItemsMigrator
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
        $table = $wpdb->prefix . 'fc_contact_relation_items';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table || $isForced) {
            $sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `subscriber_id` BIGINT UNSIGNED NOT NULL,
                `relation_id` BIGINT UNSIGNED NOT NULL,
                `provider` VARCHAR(100) NOT NULL,
                `origin_id` BIGINT UNSIGNED NULL,
                `item_id` BIGINT UNSIGNED NOT NULL,
                `item_sub_id` BIGINT UNSIGNED NULL,
                `item_value` DECIMAL (10, 2) NULL,
                `status` VARCHAR(100) NULL,
                `item_type` VARCHAR(100) NULL,
                `meta_col` MEDIUMTEXT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL, 
                 KEY `relation_id` (`relation_id`),
                 KEY `item_id` (`item_id`),
                 KEY `provider` (`provider`),
                 KEY `subscriber_id` (`subscriber_id`),
                 KEY `status` (`status`)
            ) $charsetCollate;";
            dbDelta($sql);
        }
    }
}
