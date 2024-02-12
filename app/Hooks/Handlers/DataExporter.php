<?php

namespace FluentCampaign\App\Hooks\Handlers;

use FluentCampaign\App\Models\Sequence;
use FluentCampaign\App\Models\SequenceMail;
use FluentCrm\App\Models\Company;
use FluentCrm\App\Models\CompanyNote;
use FluentCrm\App\Models\Meta;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\SubscriberNote;
use FluentCrm\App\Models\Template;
use FluentCrm\App\Services\ContactsQuery;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\PermissionManager;
use FluentCrm\Framework\Support\Arr;
use FluentCrm\Framework\Support\Str;

class DataExporter
{
    private $request;

    public function exportContacts()
    {
        $this->verifyRequest('fcrm_manage_contacts_export');

        $this->request = $request = FluentCrm('request');

        $columns = $request->get('columns');
        $customFields = $request->get('custom_fields', []);
        $with = [];
        if (in_array('tags', $columns)) {
            $with[] = 'tags';
        }

        if (in_array('lists', $columns)) {
            $with[] = 'lists';
        }

        if (in_array('companies', $columns)) {
            $with[] = 'companies';
        }

        if (in_array('primary_company', $columns)) {
            $with[] = 'company';
        }

        $filterType = $this->request->get('filter_type', 'simple');

        if ($filterType == 'advanced') {
            $queryArgs = [
                'filter_type'        => 'advanced',
                'filters_groups_raw' => $this->request->get('advanced_filters'),
                'search'             => $this->request->get('search', ''),
                'sort_by'            => $this->request->get('sort_by', 'id'),
                'sort_type'          => $this->request->get('sort_type', 'DESC')
            ];
        } else {
            $queryArgs = [
                'filter_type' => 'simple',
                'search'      => $this->request->get('search', ''),
                'sort_by'     => $this->request->get('sort_by', 'id'),
                'sort_type'   => $this->request->get('sort_type', 'DESC'),
                'tags'        => $this->request->get('tags', []),
                'statuses'    => $this->request->get('statuses', []),
                'lists'       => $this->request->get('lists', [])
            ];
        }

        $queryArgs['with'] = $with;

        if ($limit = $request->get('limit')) {
            $queryArgs['limit'] = intval($limit);
        }

        if ($offset = $request->get('offset')) {
            $queryArgs['offset'] = intval($offset);
        }

        $commerceColumns = $this->request->get('commerce_columns', []);

        if ($commerceColumns) {
            $queryArgs['has_commerce'] = true;
        }

        $subscribers = (new ContactsQuery($queryArgs))->get();

        $maps = $this->contactColumnMaps();
        $header = Arr::only($maps, $columns);
        $header = array_intersect($maps, $header);

        $insertHeaders = $header;
        $customHeaders = [];
        if ($customFields) {
            $allCustomFields = fluentcrm_get_custom_contact_fields();
            foreach ($allCustomFields as $field) {
                if (in_array($field['slug'], $customFields)) {
                    $insertHeaders[$field['slug']] = $field['label'];
                    $customHeaders[] = $field['slug'];
                }
            }
        }

        if ($commerceColumns) {
            foreach ($commerceColumns as $column) {
                $insertHeaders['_commerce_' . $column] = ucwords(implode(' ', explode('_', $column)));
            }
        }


        $writer = $this->getCsvWriter();
        $writer->insertOne(array_values($insertHeaders));

        $rows = [];
        foreach ($subscribers as $subscriber) {
            $row = [];
            foreach ($header as $headerKey => $column) {
                if (in_array($headerKey, ['lists', 'tags', 'companies'])) {
                    $strings = [];
                    foreach ($subscriber->{$headerKey} as $item) {
                        $field = $headerKey == 'companies' ? 'name' : 'title';
                        $strings[] = $this->sanitizeForCSV($item->{$field});
                    }
                    $row[] = implode(', ', $strings);
                } elseif ($headerKey == 'primary_company') {
                    $row[] = $subscriber->company->name;
                } else {
                    $row[] = $this->sanitizeForCSV($subscriber->{$headerKey});
                }
            }
            if ($customHeaders) {
                $customValues = $subscriber->custom_fields();
                foreach ($customHeaders as $valueKey) {
                    $value = Arr::get($customValues, $valueKey, '');
                    if (is_array($value)) {
                        $value = implode(', ', array_map([$this, 'sanitizeForCSV'], $value));
                    }
                    $row[] = $value;
                }
            }

            if ($commerceColumns) {
                foreach ($commerceColumns as $column) {
                    if ($subscriber->commerce_by_provider) {
                        $row[] = $this->sanitizeForCSV($subscriber->commerce_by_provider->{$column});
                    } else {
                        $row[] = '';
                    }
                }
            }

            $rows[] = $row;
        }

        $writer->insertAll($rows);
        $writer->output('contact-' . date('Y-m-d_H-i-s') . '.csv');
        die();
    }

    public function exportNotes()
    {
        $this->verifyRequest('fcrm_manage_contacts_export');
        $this->request = FluentCrm('request');

        $contactId = $this->request->get('subscriber_id');

        $routePrefix = $this->request->get('route_prefix', 'subscribers');

        $fileName = $contactId . '-contact-notes-' . date('Y-m-d_H-i') . '.csv';
        if ($routePrefix == 'companies') {
            $notes = CompanyNote::where('subscriber_id', $contactId)
                ->orderBy('id', 'DESC')
                ->get();
            $fileName = $contactId . '-company-notes-' . date('Y-m-d_H-i') . '.csv';
        } else {
            $notes = SubscriberNote::where('subscriber_id', $contactId)
                ->orderBy('id', 'DESC')
                ->get();
        }

        $writer = $this->getCsvWriter();
        $writer->insertOne([
            'Id',
            'Title',
            'Description',
            'Type',
            'Created At'
        ]);

        $rows = [];
        foreach ($notes as $note) {
            $rows[] = [
                $note->id,
                $this->sanitizeForCSV($note->title),
                $this->sanitizeForCSV($note->description),
                $note->type,
                $note->created_at
            ];
        }

        $writer->insertAll($rows);
        $writer->output($fileName);
        die();
    }

    public function exportCompanies()
    {
        $this->verifyRequest('fcrm_manage_contacts_export');

        $request = FluentCrm('request');

        if(!Helper::isExperimentalEnabled('company_module')) {
            die('Company Module is not enabled');
        }

        $companies = Company::with(['owner'])
            ->searchBy($request->getSafe('search'))
            ->get();


        $mainProps = [
            'id',
            'name',
            'industry',
            'description',
            'logo',
            'type',
            'email',
            'phone',
            'address_line_1',
            'address_line_2',
            'postal_code',
            'city',
            'state',
            'country',
            'employees_number',
            'linkedin_url',
            'facebook_url',
            'twitter_url',
            'website',
            'created_at',
            'updated_at'
        ];


        $header = $mainProps;
        $header[] = 'owner_email';
        $header[] = 'owner_name';

        $writer = $this->getCsvWriter();
        $writer->insertOne($header);

        $rows = [];
        foreach ($companies as $company) {

            $row = [];

            foreach ($mainProps as $mainProp) {
                $row[] = $this->sanitizeForCSV($company->{$mainProp});
            }
            $row[] = $company->owner ? $company->owner->email : '';
            $row[] = $company->owner ? $this->sanitizeForCSV($company->owner->full_name) : '';
            $rows[] = $row;
        }

        $writer->insertAll($rows);
        $writer->output('companies_' . date('Y-m-d_H:i') . '.csv');
        die();
    }

    public function importFunnel()
    {
        $this->verifyRequest('fcrm_write_funnels');
        $this->request = FluentCrm('request');
        $files = $this->request->files();
        $file = $files['file'];
        $content = file_get_contents($file);
        $funnel = json_decode($content, true);


        if (empty($funnel['type']) || $funnel['type'] != 'funnels') {
            wp_send_json([
                'message' => __('The provided JSON file is not valid', 'fluentcampaign-pro')
            ], 423);
        }

        $funnelTrigger = $funnel['trigger_name'];
        $triggers = apply_filters('fluentcrm_funnel_triggers', []);

        $funnel['title'] .= ' (Imported @ ' . current_time('mysql') . ')';

        if (!isset($triggers[$funnelTrigger])) {
            wp_send_json([
                'message'  => __('The trigger defined in the JSON file is not available on your site.', 'fluentcampaign-pro'),
                'requires' => [
                    'Trigger Name Required: ' . $funnelTrigger
                ]
            ], 423);
        }

        $sequences = $funnel['sequences'];
        $formattedSequences = [];

        $blocks = apply_filters('fluentcrm_funnel_blocks', [], (object)$funnel);
        foreach ($sequences as $sequence) {
            $actionName = $sequence['action_name'];

            if ($sequence['type'] == 'conditional') {
                $sequence = (object)$sequence;
                $sequence = (array)FunnelHelper::migrateConditionSequence($sequence, true);
                $actionName = $sequence['action_name'];
            }

            if (!isset($blocks[$actionName])) {
                wp_send_json([
                    'message'  => __('The Block Action defined in the JSON file is not available on your site.', 'fluentcampaign-pro'),
                    'requires' => [
                        'Missing Action: ' . $actionName
                    ],
                    'sequence' => $sequence
                ], 423);
            }

            $formattedSequences[] = $sequence;
        }

        unset($funnel['sequences']);

        $data = [
            'funnel'           => $funnel,
            'blocks'           => $blocks,
            'block_fields'     => apply_filters('fluentcrm_funnel_block_fields', [], (object)$funnel),
            'funnel_sequences' => $formattedSequences
        ];
        wp_send_json($data, 200);
    }

    public function importEmailSequence()
    {
        $this->verifyRequest('fcrm_manage_emails');
        $this->request = FluentCrm('request');
        $files = $this->request->files();
        $file = $files['file'];
        $content = file_get_contents($file);
        $jsonArray = json_decode($content, true);

        $sequence = Arr::get($jsonArray, 'sequence', []);


        if (empty($sequence['type']) || $sequence['type'] != 'email_sequence') {
            wp_send_json([
                'message' => __('The provided JSON file is not valid. sequence key is required in the JSON File', 'fluentcampaign-pro')
            ], 423);
        }

        $emails = Arr::get($jsonArray, 'emails', []);

        if (empty($emails)) {
            wp_send_json([
                'message' => __('The provided JSON file is not valid. No valid email sequence found', 'fluentcampaign-pro')
            ], 423);
        }

        $sequenceData = Arr::only($sequence, (new Sequence())->getFillable());

        $sequenceData['title'] = '[imported] ' . $sequenceData['title'];

        $createdSequence = Sequence::create($sequenceData);

        if (!$createdSequence) {
            wp_send_json([
                'message' => 'Failed to import'
            ], 423);
        }


        foreach ($emails as $email) {
            $emailData = Arr::only($email, [
                'title',
                'type',
                'available_urls',
                'status',
                'template_id',
                'email_subject',
                'email_pre_header',
                'email_body',
                'delay',
                'utm_status',
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'utm_term',
                'utm_content',
                'design_template',
                'scheduled_at',
                'settings'
            ]);

            $emailData['template_id'] = (int)$emailData['template_id'];

            $emailData = array_filter($emailData);

            $emailData['parent_id'] = $createdSequence->id;

            SequenceMail::create($emailData);
        }

        wp_send_json([
            'message'  => __('Sequence has been successfully imported', 'fluent-campaign-pro'),
            'sequence' => $createdSequence
        ]);

    }

    public function exportEmailSequence()
    {
        $this->verifyRequest('fcrm_manage_emails');
        $this->request = FluentCrm('request');

        $sequenceId = $this->request->get('sequence_id');

        if (!$sequenceId) {
            die('Please provide sequence_id');
        }

        $data = [];
        $sequence = Sequence::findOrFail($sequenceId);

        $data['sequence'] = $sequence;
        $data['emails'] = SequenceMail::where('parent_id', $sequence->id)
            ->orderBy('delay', 'ASC')
            ->get();

        header('Content-disposition: attachment; filename=' . sanitize_title($sequence->title, 'sequence', 'display') . '-' . $sequence->id . '.json');
        header('Content-type: application/json');
        echo wp_json_encode($data);
        exit();
    }

    public function exportDynamicSegment()
    {
        $this->verifyRequest('fcrm_manage_contact_cats');
        $this->request = FluentCrm('request');

        $segmentId = $this->request->get('segment_id');

        if (!$segmentId) {
            die('Please provide segment_id');
        }

        $segment = Meta::where('id', $segmentId)->where('object_type', 'custom_segment')->first();
        $title = '';
        if ($segment) {
            $title = $segment->value['title'];
        }

        header('Content-disposition: attachment; filename=' . sanitize_title($title, 'dynamic-segment', 'display') . '-' . $segment->id . '.json');
        header('Content-type: application/json');
        echo wp_json_encode($segment);
        exit();
    }

    public function importDynamicSegment()
    {
        $this->verifyRequest('fcrm_manage_contact_cats');
        $this->request = FluentCrm('request');
        $files = $this->request->files();
        $file = $files['file'];
        $content = file_get_contents($file);
        $segment = json_decode($content, true);
        unset($segment['id']);

        if (empty($segment['object_type']) || $segment['object_type'] != 'custom_segment') {
            wp_send_json([
                'message' => __('The provided JSON file is not valid. object type is required in the JSON File', 'fluentcampaign-pro')
            ], 423);
        }

        $title = '[imported] ' . $segment['value']['title'];
        $segment['value']['title'] = $title;

        $createdSegment = Meta::create($segment);

        if (!$createdSegment) {
            wp_send_json([
                'message' => 'Failed to import'
            ], 423);
        }

        wp_send_json([
            'message'  => __('Segment has been successfully imported', 'fluent-campaign-pro'),
            'segment' => $createdSegment
        ]);

    }

    private function contactColumnMaps()
    {
        return [
            'id'                  => __('ID', 'fluentcampaign-pro'),
            'user_id'             => __('User ID', 'fluentcampaign-pro'),
            'prefix'              => __('Title', 'fluentcampaign-pro'),
            'first_name'          => __('First Name', 'fluentcampaign-pro'),
            'last_name'           => __('Last Name', 'fluentcampaign-pro'),
            'email'               => __('Email', 'fluentcampaign-pro'),
            'timezone'            => __('Timezone', 'fluentcampaign-pro'),
            'address_line_1'      => __('Address Line 1', 'fluentcampaign-pro'),
            'address_line_2'      => __('Address Line 2', 'fluentcampaign-pro'),
            'postal_code'         => __('Postal Code', 'fluentcampaign-pro'),
            'city'                => __('City', 'fluentcampaign-pro'),
            'state'               => __('State', 'fluentcampaign-pro'),
            'country'             => __('Country', 'fluentcampaign-pro'),
            'ip'                  => __('IP Address', 'fluentcampaign-pro'),
            'phone'               => __('Phone', 'fluentcampaign-pro'),
            'status'              => __('Status', 'fluentcampaign-pro'),
            'contact_type'        => __('Contact Type', 'fluentcampaign-pro'),
            'source'              => __('Source', 'fluentcampaign-pro'),
            'date_of_birth'       => __('Date Of Birth', 'fluentcampaign-pro'),
            'last_activity'       => __('Last Activity', 'fluentcampaign-pro'),
            'created_at'          => __('Created At', 'fluentcampaign-pro'),
            'updated_at'          => __('Updated At', 'fluentcampaign-pro'),
            'lists'               => __('Lists', 'fluentcampaign-pro'),
            'tags'                => __('Tags', 'fluentcampaign-pro'),
            'companies'           => __('Companies', 'fluentcampaign-pro'),
            'primary_company'     => __('Primary Company', 'fluentcampaign-pro'),
        ];
    }

    public function exportEmailTemplate()
    {
        $this->verifyRequest('fcrm_manage_email_templates');
        $templateId = (int)$_REQUEST['template_id'];

        if (!$templateId) {
            die('Please provide Template ID');
        }

        $template = Template::findOrFail($templateId);

        $editType = get_post_meta($template->ID, '_edit_type', true);
        if (!$editType) {
            $editType = 'html';
        }

        $footerSettings = false;
        if($template) {
            $footerSettings = get_post_meta($template->ID, '_footer_settings', true);
        }

        if(!$footerSettings) {
            $footerSettings = [
                'custom_footer' => 'no',
                'footer_content' => ''
            ];
        }

        $templateData = [
            'is_fc_template'  => 'yes',
            'post_title'      => $template->post_title,
            'post_content'    => $template->post_content,
            'post_excerpt'    => $template->post_excerpt,
            'email_subject'   => get_post_meta($template->ID, '_email_subject', true),
            'edit_type'       => $editType,
            'design_template' => get_post_meta($template->ID, '_design_template', true),
            'settings'        => [
                'template_config' => get_post_meta($template->ID, '_template_config', true),
                'footer_settings' => $footerSettings
            ]
        ];

        $templateData = apply_filters('fluent_crm/editing_template_data', $templateData, $template);

        header('Content-disposition: attachment; filename=Email-Template-' . sanitize_title($template->post_title, 'template', 'display') . '-' . $template->ID . '.json');
        header('Content-type: application/json');
        echo wp_json_encode($templateData);
        exit();
    }

    public function importEmailTemplate()
    {
        $this->verifyRequest('fcrm_manage_email_templates');

        $this->request = FluentCrm('request');
        $files = $this->request->files();
        $file = $files['file'];
        $content = file_get_contents($file);
        $data = json_decode($content, true);

        if (empty($data['post_title']) || empty($data['post_content']) || Arr::get($data, 'is_fc_template') != 'yes') {
            wp_send_json([
                'message'  => __('The provided JSON file is not valid.', 'fluentcampaign-pro'),
                'requires' => [
                    'File is not valid'
                ]
            ], 423);
        }

        $postData = Arr::only($data, ['post_title', 'post_content', 'post_excerpt']);

        if (empty($postData['post_title'])) {
            $postData['post_title'] = 'Imported Email Template @ ' . current_time('mysql');
        } else {
            $postData['post_title'] = sanitize_text_field($postData['post_title']);
        }

        $postData['post_title'] = '[Imported] ' . $postData['post_title'];

        if (empty($data['email_subject'])) {
            $data['email_subject'] = $data['post_title'];
        } else {
            $data['email_subject'] = sanitize_text_field($data['email_subject']);
        }

        $postData['post_modified'] = current_time('mysql');
        $postData['post_modified_gmt'] = date('Y-m-d H:i:s');
        $postData['post_date'] = current_time('mysql');
        $postData['post_date_gmt'] = date('Y-m-d H:i:s');
        $postData['post_type'] = fluentcrmTemplateCPTSlug();

        $templateId = wp_insert_post($postData);

        if (is_wp_error($templateId)) {
            wp_send_json([
                'message'  => $templateId->get_error_message(),
                'requires' => [
                    'Could not create the template'
                ]
            ], 423);
        }

        update_post_meta($templateId, '_email_subject', $data['email_subject']);
        if($editType = Arr::get($data, '_edit_type')) {
            update_post_meta($templateId, '_edit_type', $editType);
        }

        if (isset($data['design_template'])) {
            update_post_meta($templateId, '_design_template', sanitize_text_field($data['design_template']));
        }

        if (isset($data['_visual_builder_design'])) {
            update_post_meta($templateId, '_visual_builder_design', $data['_visual_builder_design']);
        }

        if (!empty($data['settings']['template_config'])) {
            update_post_meta($templateId, '_template_config', $data['settings']['template_config']);
        }

        if (!empty($data['settings']['footer_settings'])) {
            update_post_meta($templateId, '_footer_settings', $data['settings']['footer_settings']);
        }

        wp_send_json([
            'message'     => __('Templates has been successfully imported', 'fluentcampaign-pro'),
            'template_id' => $templateId
        ]);
    }

    private function verifyRequest($permission = 'fcrm_manage_contacts_export')
    {
        if (PermissionManager::currentUserCan($permission)) {
            return true;
        }

        die('You do not have permission');
    }

    private function getCsvWriter()
    {
        if (!class_exists('\League\Csv\Writer')) {
            include FLUENTCRM_PLUGIN_PATH . 'app/Services/Libs/csv/autoload.php';
        }

        return \League\Csv\Writer::createFromFileObject(new \SplTempFileObject());
    }

    private function sanitizeForCSV($content)
    {
        $formulas = ['=', '-', '+', '@', "\t", "\r"];

        if (Str::startsWith($content, $formulas)) {
            $content = "'" . $content;
        }

        return $content;
    }
}
