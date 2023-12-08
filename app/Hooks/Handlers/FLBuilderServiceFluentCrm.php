<?php

namespace FluentCampaign\App\Hooks\Handlers;

use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Tag;

final class FLBuilderServiceFluentCrm extends \FLBuilderService
{

    /**
     * The ID for this service.
     *
     * @since 1.5.4
     * @var string $id
     */
    public $id = 'fluentcrm';

    /**
     * Test the API connection.
     *
     * @param array $fields
     * @return array{
     * @type bool|string $error The error message or false if no error.
     * @type array $data An array of data used to make the connection.
     * }
     * @since 1.5.4
     */
    public function connect($fields = array())
    {
        $response = array(
            'error' => false,
            'data'  => array(),
        );

        return $response;
    }

    /**
     * Renders the markup for the connection settings.
     *
     * @return string The connection settings markup.
     * @since 1.5.4
     */
    public function render_connect_settings()
    {
        return '';
    }

    /**
     * Render the markup for service specific fields.
     *
     * @param string $account The name of the saved account.
     * @param object $settings Saved module settings.
     * @return array {
     * @type bool|string $error The error message or false if no error.
     * @type string $html The field markup.
     * }
     * @since 1.5.4
     */
    public function render_fields($account, $settings)
    {
        $response = array(
            'error' => false,
            'html'  => '',
        );

        $response['html'] = self::render_list_field($settings);
        return $response;
    }

    /**
     * Render markup for the list field.
     *
     * @param array $lists List data from the API.
     * @param object $settings Saved module settings.
     * @return string The markup for the list field.
     * @access private
     * @since 1.5.4
     */
    private function render_list_field($settings)
    {
        ob_start();

        $tags = Tag::orderBy('title', 'ASC')->get();

        $tagsOptions = [];
        foreach ($tags as $tag) {
            $tagsOptions[strval($tag->id)] = $tag->title;
        }

        $lists = Lists::orderBy('title', 'ASC')->get();
        $listOptions = [
            '0' => __('Select a list', 'fluentform'),
        ];
        foreach ($lists as $list) {
            $listOptions[strval($list->id)] = $list->title;
        }

        \FLBuilder::render_settings_field('apply_list', array(
            'row_class'    => 'fl-builder-service-field-row',
            'class'        => 'fl-builder-service-list-select',
            'type'         => 'select',
            'label'        => __('Add To List', 'fl-builder'),
            'multi-select' => false,
            'options'      => $listOptions,
        ), $settings);

        \FLBuilder::render_settings_field('apply_tag', array(
            'row_class'    => 'fl-builder-service-field-row',
            'class'        => 'fl-builder-service-list-select',
            'type'         => 'select',
            'label'        => __('Apply Tag', 'fl-builder'),
            'multi-select' => true,
            'options'      => $tagsOptions,
        ), $settings);

        $statuses = [];

        foreach (fluentcrm_subscriber_statuses() as $status) {
            $statuses[$status] = ucfirst($status);
        }

        \FLBuilder::render_settings_field('contact_status', array(
            'row_class'    => 'fl-builder-service-field-row',
            'class'        => 'fl-builder-service-list-select',
            'type'         => 'select',
            'label'        => __('Contact Status', 'fl-builder'),
            'multi-select' => false,
            'options'      => $statuses,
        ), $settings);


        return ob_get_clean();
    }

    protected function is_active()
    {
        return defined('FLUENTCRM');
    }

    /**
     * Subscribe an email address to FluentCRM.
     *
     * @param object $settings A module settings object.
     * @param string $email The email to subscribe.
     * @param string $name Optional. The full name of the person subscribing.
     * @return array {
     * @type bool|string $error The error message or false if no error.
     * }
     * @since 1.5.4
     */
    public function subscribe($settings, $email, $name = false)
    {
        $response = array(
            'error' => false,
        );

        if (!is_email($email)) {
            return array(
                'error' => __('Email Address is not valid', 'fluentcampaign-pro'),
            );
        }

        $contactData = [
            'email'     => $email,
            'full_name' => $name
        ];

        if (!empty($settings->apply_tag)) {
            $contactData['tags'] = $settings->apply_tag;
        }

        if (!empty($settings->apply_list)) {
            $contactData['lists'] = [$settings->apply_list];
        }

        if (!empty($settings->contact_status)) {
            $contactData['status'] = $settings->contact_status;
        }


        $contact = FluentCrmApi('contacts')->createOrUpdate($contactData);

        if ($contact && $contact->status == 'pending') {
            $contact->sendDoubleOptinEmail();
        }

        return $response;
    }
}
