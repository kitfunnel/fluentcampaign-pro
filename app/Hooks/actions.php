<?php
/**
 * @var \FluentCrm\Framework\Foundation\Application $app
 */


(new \FluentCampaign\App\Hooks\Handlers\IntegrationHandler())->init();
(new \FluentCampaign\App\Hooks\Handlers\CampaignArchiveFront())->register();
(new \FluentCampaign\App\Hooks\Handlers\VisualEmailBuilderHandler())->register();
(new \FluentCampaign\App\Hooks\Handlers\ExtendedSmartCodesHandler())->register();

add_action('init', function () {
    (new \FluentCampaign\App\Hooks\Handlers\DynamicSegment())->init();
}, 1);

/*
 * Cleanup actions
 */
$app->addAction('fluentcrm_sequence_email_deleted', 'FluentCampaign\App\Hooks\Handlers\Cleanup@deleteCampaignAssets', 10, 1);
$app->addAction('fluentcrm_sequence_deleted', 'FluentCampaign\App\Hooks\Handlers\Cleanup@deleteSequenceAssets', 10, 1);
$app->addAction('fluentcrm_after_subscribers_deleted', 'FluentCampaign\App\Hooks\Handlers\Cleanup@deleteCommerceItems', 10, 1);

// fluentcrm_scheduled_hourly_tasks
$app->addAction('fluentcrm_scheduled_hourly_tasks', 'FluentCampaign\App\Hooks\Handlers\EmailScheduleHandler@handle');
$app->addAction('fluentcrm_scheduled_maybe_regular_tasks', 'FluentCampaign\App\Hooks\Handlers\EmailScheduleHandler@handle');

$app->addAction('wp_ajax_fluentcrm_export_contacts', 'FluentCampaign\App\Hooks\Handlers\DataExporter@exportContacts');
$app->addAction('wp_ajax_fluentcrm_export_companies', 'FluentCampaign\App\Hooks\Handlers\DataExporter@exportCompanies');
$app->addAction('wp_ajax_fluentcrm_import_funnel', 'FluentCampaign\App\Hooks\Handlers\DataExporter@importFunnel');
$app->addAction('wp_ajax_fluentcrm_import_sequence', 'FluentCampaign\App\Hooks\Handlers\DataExporter@importEmailSequence');
$app->addAction('wp_ajax_fluentcrm_export_notes', 'FluentCampaign\App\Hooks\Handlers\DataExporter@exportNotes');
$app->addAction('wp_ajax_fluentcrm_export_sequence', 'FluentCampaign\App\Hooks\Handlers\DataExporter@exportEmailSequence');
$app->addAction('wp_ajax_fluentcrm_export_template', 'FluentCampaign\App\Hooks\Handlers\DataExporter@exportEmailTemplate');
$app->addAction('wp_ajax_fluentcrm_import_template', 'FluentCampaign\App\Hooks\Handlers\DataExporter@importEmailTemplate');

$app->addAction('fluentcrm_smartlink_clicked', 'FluentCampaign\App\Hooks\Handlers\SmartLinkHandler@handleClick', 10, 1);
$app->addAction('fluentcrm_smartlink_clicked_direct', 'FluentCampaign\App\Hooks\Handlers\SmartLinkHandler@handleClick', 10, 2);

$app->addAction('set_user_role', 'FluentCampaign\App\Hooks\Handlers\IntegrationHandler@maybeAutoAlterTags', 11, 2);
$app->addAction('add_user_role', 'FluentCampaign\App\Hooks\Handlers\IntegrationHandler@maybeAutoAlterTags', 11, 2);

$app->addAction('fluent_crm/recurring_mail_created_as_draft', 'FluentCampaign\App\Hooks\Handlers\RecurringCampaignHandler@draftMailCreated', 10, 2);

$app->addAction('wp_ajax_fluent_crm_export_dynamic_segment', 'FluentCampaign\App\Hooks\Handlers\DataExporter@exportDynamicSegment');
$app->addAction('wp_ajax_fluent_crm_import_dynamic_segment', 'FluentCampaign\App\Hooks\Handlers\DataExporter@importDynamicSegment');
