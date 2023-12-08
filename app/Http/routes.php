<?php

/**
 * @var $router FluentCrm\Framework\Http\Router
 */

/*
 * Email Sequences Route
 */
$router->prefix('sequences')->withPolicy('FluentCampaign\App\Http\Policies\SequencePolicy')->group(function ($router) {

    $router->get('/', 'FluentCampaign\App\Http\Controllers\SequenceController@sequences');
    $router->post('/', 'FluentCampaign\App\Http\Controllers\SequenceController@create');

    $router->get('subscriber/{subscriber_id}/sequences', 'FluentCampaign\App\Http\Controllers\SequenceController@subscriberSequences')->int('subscriber_id');

    $router->post('do-bulk-action', 'FluentCampaign\App\Http\Controllers\SequenceController@handleBulkAction');

    $router->get('{id}', 'FluentCampaign\App\Http\Controllers\SequenceController@sequence')->int('id');
    $router->put('{id}', 'FluentCampaign\App\Http\Controllers\SequenceController@update')->int('id');
    $router->post('{id}/duplicate', 'FluentCampaign\App\Http\Controllers\SequenceController@duplicate')->int('id');
    $router->delete('{id}', 'FluentCampaign\App\Http\Controllers\SequenceController@delete')->int('id');

    /*
     * @todo: Use this route in the december Update
     */
    $router->post('sequence-email-update-create', 'FluentCampaign\App\Http\Controllers\SequenceMailController@routeFallBackSequenceEmailCreateUpdate');

    $router->get('{id}/email/{email_id}', 'FluentCampaign\App\Http\Controllers\SequenceMailController@get')->int('id')->int('email_id');
    $router->post('{id}/email', 'FluentCampaign\App\Http\Controllers\SequenceMailController@create')->int('id');
    $router->post('{id}/email/duplicate', 'FluentCampaign\App\Http\Controllers\SequenceMailController@duplicate')->int('id');
    $router->put('{id}/email/{email_id}', 'FluentCampaign\App\Http\Controllers\SequenceMailController@update')->int('id')->int('sequence_id');
    $router->delete('{id}/email/{email_id}', 'FluentCampaign\App\Http\Controllers\SequenceMailController@delete')->int('id')->int('sequence_id');

    $router->get('{id}/subscribers', 'FluentCampaign\App\Http\Controllers\SequenceController@getSubscribers')->int('id');
    $router->post('{id}/subscribers', 'FluentCampaign\App\Http\Controllers\SequenceController@subscribe')->int('id');
    $router->delete('{id}/subscribers', 'FluentCampaign\App\Http\Controllers\SequenceController@deleteSubscribes')->int('id');

});


$router->prefix('recurring-campaigns')->withPolicy('FluentCampaign\App\Http\Policies\SequencePolicy')->group(function ($router) {
    $router->get('/', 'FluentCampaign\App\Http\Controllers\RecurringCampaignController@getCampaigns');
    $router->post('/', 'FluentCampaign\App\Http\Controllers\RecurringCampaignController@createCampaign');
    $router->post('/update-campaign-data', 'FluentCampaign\App\Http\Controllers\RecurringCampaignController@updateCampaignData');

    $router->get('{campaign_id}', 'FluentCampaign\App\Http\Controllers\RecurringCampaignController@getCampaign')->int('campaign_id');
    $router->post('{campaign_id}/change-status', 'FluentCampaign\App\Http\Controllers\RecurringCampaignController@changeStatus')->int('campaign_id');
    $router->post('{campaign_id}/update-settings', 'FluentCampaign\App\Http\Controllers\RecurringCampaignController@updateCampaignSettings')->int('campaign_id');
    $router->post('{campaign_id}/duplicate', 'FluentCampaign\App\Http\Controllers\RecurringCampaignController@duplicate')->int('campaign_id');
    $router->get('{campaign_id}/emails', 'FluentCampaign\App\Http\Controllers\RecurringCampaignController@getEmails')->int('campaign_id');

    $router->get('{campaign_id}/emails/{email_id}', 'FluentCampaign\App\Http\Controllers\RecurringCampaignController@getEmail')->int('campaign_id')->int('email_id');
    $router->put('{campaign_id}/emails/{email_id}', 'FluentCampaign\App\Http\Controllers\RecurringCampaignController@patchCampaignEmail')->int('campaign_id')->int('email_id');
    $router->post('{campaign_id}/emails/update-email', 'FluentCampaign\App\Http\Controllers\RecurringCampaignController@updateCampaignEmail')->int('campaign_id');


    $router->post('/delete-bulk', 'FluentCampaign\App\Http\Controllers\RecurringCampaignController@deleteBulk');

});

/*
 * Dynamic Segments
 */
$router->prefix('dynamic-segments')->withPolicy('FluentCampaign\App\Http\Policies\DynamicSegmentPolicy')->group(function ($router) {

    $router->get('/', '\FluentCampaign\App\Http\Controllers\DynamicSegmentController@index');
    $router->post('/', '\FluentCampaign\App\Http\Controllers\DynamicSegmentController@createCustomSegment');
    $router->post('estimated-contacts', '\FluentCampaign\App\Http\Controllers\DynamicSegmentController@getEstimatedContacts');
    $router->put('{id}', '\FluentCampaign\App\Http\Controllers\DynamicSegmentController@updateCustomSegment')->int('id');

    $router->delete('{id}', '\FluentCampaign\App\Http\Controllers\DynamicSegmentController@deleteCustomSegment');
    $router->post('/duplicate/{id}', '\FluentCampaign\App\Http\Controllers\DynamicSegmentController@duplicate')->int('id');

    $router->get('{slug}/subscribers/{id}', '\FluentCampaign\App\Http\Controllers\DynamicSegmentController@getSegment')->alphaNumDash('slug')->int('id');
    $router->get('custom-fields', '\FluentCampaign\App\Http\Controllers\DynamicSegmentController@getCustomFields');

});

/*
 * Dynamic Segments
 */
$router->prefix('campaigns-pro')->withPolicy('FluentCrm\App\Http\Policies\CampaignPolicy')->group(function ($router) {

    $router->post('{id}/resend-failed-emails', '\FluentCampaign\App\Http\Controllers\CampaignsProController@resendFailedEmails');
    $router->post('{id}/resend-emails', '\FluentCampaign\App\Http\Controllers\CampaignsProController@resendEmails');
    $router->post('{id}/tag-actions', '\FluentCampaign\App\Http\Controllers\CampaignsProController@doTagActions');

    $router->get('posts', 'FluentCampaign\App\Http\Controllers\DynamicPostDataController@getPosts');
    $router->get('products', 'FluentCampaign\App\Http\Controllers\DynamicPostDataController@getProducts');

});

/*
 * Action Links
 */
$router->prefix('smart-links')->withPolicy('FluentCampaign\App\Http\Policies\SmartLinksPolicy')->group(function ($router) {

    $router->get('/', '\FluentCampaign\App\Http\Controllers\SmartLinksController@getLinks');
    $router->post('/', '\FluentCampaign\App\Http\Controllers\SmartLinksController@createLink');
    $router->post('activate', '\FluentCampaign\App\Http\Controllers\SmartLinksController@activate');
    $router->put('{id}', '\FluentCampaign\App\Http\Controllers\SmartLinksController@update');
    $router->delete('{id}', '\FluentCampaign\App\Http\Controllers\SmartLinksController@delete');

});

/*
 * Dynamic Segments
 */
$router->prefix('campaign-pro-settings')->withPolicy('FluentCrm\App\Http\Policies\SettingsPolicy')->group(function ($router) {

    $router->get('license', '\FluentCampaign\App\Http\Controllers\LicenseController@getStatus');
    $router->post('license', '\FluentCampaign\App\Http\Controllers\LicenseController@saveLicense');
    $router->delete('license', '\FluentCampaign\App\Http\Controllers\LicenseController@deactivateLicense');

    $router->get('managers', '\FluentCampaign\App\Http\Controllers\ManagerController@getManagers');
    $router->post('managers', '\FluentCampaign\App\Http\Controllers\ManagerController@addManager');
    $router->put('managers/{id}', '\FluentCampaign\App\Http\Controllers\ManagerController@updateManager')->int('id');
    $router->delete('managers/{id}', '\FluentCampaign\App\Http\Controllers\ManagerController@deleteManager')->int('id');

    $router->post('import_funnel', '\FluentCampaign\App\Http\Controllers\FunnelImporter@import');

});

$router->prefix('commerce-reports')->withPolicy('FluentCrm\App\Http\Policies\SettingsPolicy')->group(function ($router) {
    $router->get('/{provider}', '\FluentCampaign\App\Http\Controllers\CommerceReportsController@getReports')->alphaNumDash('provider');
    $router->get('/{provider}/report', '\FluentCampaign\App\Http\Controllers\CommerceReportsController@getReport')->alphaNumDash('provider');
});

