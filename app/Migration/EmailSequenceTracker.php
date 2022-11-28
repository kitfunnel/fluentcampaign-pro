<?php

namespace FluentCampaign\App\Migration;

class EmailSequenceTracker
{
    /**
     * Migrate the table.
     *
     * @param bool $isForced
     * @return void
     */
    public static function migrate($isForced = true)
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix .'fc_sequence_tracker';

        $indexPrefix = $wpdb->prefix .'fc_index_';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table || $isForced) {
            $sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `campaign_id` BIGINT UNSIGNED NULL,
                `last_sequence_id` BIGINT UNSIGNED NULL,
                `subscriber_id` BIGINT UNSIGNED NULL,
                `next_sequence_id` BIGINT UNSIGNED NULL,
                `status` VARCHAR(50) DEFAULT 'active',
                `type` VARCHAR(50) DEFAULT 'sequence_tracker',
                `last_executed_time` TIMESTAMP NULL,
                `next_execution_time` TIMESTAMP NULL,
                `notes` TEXT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                INDEX `{$indexPrefix}_esequence_idx` (`campaign_id` DESC),
                INDEX `{$indexPrefix}_esequence_subscriber_idx` (`subscriber_id` ASC),
                KEY `status` (`status`),
                KEY `type` (`type`),
                KEY `last_sequence_id` (`last_sequence_id`),
                KEY `next_execution_time` (`next_execution_time`)
            ) $charsetCollate;";
            dbDelta($sql);
        } else {

            $indexes = $wpdb->get_results("SHOW INDEX FROM $table");
            $indexedColumns = [];
            foreach ($indexes as $index) {
                $indexedColumns[] = $index->Column_name;
            }

            if(!in_array('status', $indexedColumns)) {
                $indexSql = "ALTER TABLE {$table} ADD INDEX `status` (`status`),
                        ADD INDEX `type` (`type`),
                        ADD INDEX `last_sequence_id` (`last_sequence_id`),
                        ADD INDEX `next_execution_time` (`next_execution_time`);";

                $wpdb->query($indexSql);
            }
        }
    }
}
