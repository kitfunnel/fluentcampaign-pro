<?php

namespace FluentCampaign\App\Services\Funnel\Actions;

use FluentCrm\App\Models\FunnelMetric;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Libs\Parser\Parser;
use FluentCrm\Framework\Support\Arr;

class HTTPSendDataAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'http_send_data';
        $this->priority = 99;
        parent::__construct();

        add_action('fluent_run_http_send_data_request_process', array($this, 'runRequestProcess'));
    }

    public function getBlock()
    {
        return [
            'category'    => __('CRM', 'fluentcampaign-pro'),
            'title'       => __('Outgoing Webhook', 'fluentcampaign-pro'),
            'description' => __('Send Data to external server via GET or POST Method', 'fluentcampaign-pro'),
            'icon'        => 'fc-icon-webhooks',
            'settings'    => [
                'sending_method'    => 'POST',
                'run_on_background' => 'yes',
                'remote_url'        => '',
                'body_data_type'    => 'subscriber_data',
                'request_format'    => 'json',
                'body_data_values'  => [
                    [
                        'data_key'   => '',
                        'data_value' => ''
                    ]
                ],
                'header_type'       => 'no_headers',
                'header_data'       => [
                    [
                        'data_key'   => '',
                        'data_value' => ''
                    ]
                ]
            ]
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Send Data to External Server', 'fluentcampaign-pro'),
            'sub_title' => __('Send Data to external server via GET or POST Method', 'fluentcampaign-pro'),
            'fields'    => [
                'sending_method'    => [
                    'type'    => 'radio',
                    'label'   => __('Data Send Method', 'fluentcampaign-pro'),
                    'options' => [
                        [
                            'id'    => 'POST',
                            'title' => __('POST Method', 'fluentcampaign-pro')
                        ],
                        [
                            'id'    => 'GET',
                            'title' => __('GET Method', 'fluentcampaign-pro')
                        ]
                    ]
                ],
                'remote_url'        => [
                    'type'        => 'input-text',
                    'data-type'   => 'url',
                    'placeholder' => __('Remote URL', 'fluentcampaign-pro'),
                    'label'       => __('Remote URL', 'fluentcampaign-pro'),
                    'help'        => __('Please provide valid URL in where you want to send the data', 'fluentcampaign-pro')
                ],
                'request_format'    => [
                    'type'    => 'radio',
                    'label'   => __('Request Format', 'fluentcampaign-pro'),
                    'options' => [
                        [
                            'id'    => 'json',
                            'title' => __('Send as JSON format', 'fluentcampaign-pro')
                        ],
                        [
                            'id'    => 'form',
                            'title' => __('Send as Form Method', 'fluentcampaign-pro')
                        ]
                    ]
                ],
                'body_data_type'    => [
                    'type'    => 'radio',
                    'label'   => __('Request Body', 'fluentcampaign-pro'),
                    'options' => [
                        [
                            'id'    => 'subscriber_data',
                            'title' => __('Full Subscriber Data (Raw)', 'fluentcampaign-pro')
                        ],
                        [
                            'id'    => 'custom_data',
                            'title' => __('Custom Data', 'fluentcampaign-pro')
                        ]
                    ]
                ],
                'body_data_values'  => [
                    'label'                  => __('Request Body Data', 'fluentcampaign-pro'),
                    'type'                   => 'text-value-multi-properties',
                    'data_key_label'         => __('Data Key', 'fluentcampaign-pro'),
                    'data_value_label'       => __('Data Value', 'fluentcampaign-pro'),
                    'data_value_placeholder' => 'key',
                    'data_key_placeholder'   => 'value',
                    'help'                   => __('Please map the data for custom sending data type', 'fluentcampaign-pro'),
                    'value_input_type'       => 'text-popper',
                    'dependency'             => [
                        'depends_on' => 'body_data_type',
                        'operator'   => '=',
                        'value'      => 'custom_data'
                    ]
                ],
                'header_type'       => [
                    'type'    => 'radio',
                    'label'   => __('Request Header', 'fluentcampaign-pro'),
                    'options' => [
                        [
                            'id'    => 'no_headers',
                            'title' => __('No Headers', 'fluentcampaign-pro')
                        ],
                        [
                            'id'    => 'with_headers',
                            'title' => __('With Headers', 'fluentcampaign-pro')
                        ]
                    ]
                ],
                'header_data'       => [
                    'label'                  => __('Request Headers Data', 'fluentcampaign-pro'),
                    'type'                   => 'text-value-multi-properties',
                    'data_key_label'         => __('Header Key', 'fluentcampaign-pro'),
                    'data_value_label'       => __('Header Value', 'fluentcampaign-pro'),
                    'data_value_placeholder' => 'key',
                    'data_key_placeholder'   => 'value',
                    'help'                   => __('Please map the data for request headers', 'fluentcampaign-pro'),
                    'value_input_type'       => 'input-text',
                    'dependency'             => [
                        'depends_on' => 'header_type',
                        'operator'   => '=',
                        'value'      => 'with_headers'
                    ]
                ],
                'run_on_background' => [
                    'type'        => 'yes_no_check',
                    'label'       => '',
                    'check_label' => __('Send Data as Background Process. (You may enable this if you have lots of tasks)', 'fluentcampaign-pro')
                ],
            ]
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        // Renew the subscriber
        $subscriber = Subscriber::find($subscriber->id);

        if(!$subscriber) {
            return false;
        }

        $settings = $sequence->settings;
        $remoteUrl = $settings['remote_url'];
        if (!$remoteUrl || filter_var($remoteUrl, FILTER_VALIDATE_URL) === FALSE) {
            $funnelMetric->notes = __('Funnel Skipped because provided url is not valid', 'fluentcampaign-pro');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        $body = [];
        if ($settings['body_data_type'] == 'subscriber_data') {
            $body = $subscriber->toArray();
            $body['custom_field'] = $subscriber->custom_fields();
        } else {
            // We have to loop the data
            foreach ($settings['body_data_values'] as $item) {
                if (empty($item['data_key']) || empty($item['data_value'])) {
                    continue;
                }
                $body[$item['data_key']] = Parser::parse($item['data_value'], $subscriber);
            }
        }

        if (!$body) {
            $funnelMetric->notes = __('No valid body data found', 'fluentcampaign-pro');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        $body = apply_filters('fluent_crm/http_webhook_body', $body, $sequence, $subscriber);

        $headers = [];
        if ($settings['header_type'] == 'with_headers') {
            foreach ($settings['header_data'] as $item) {
                if (empty($item['data_key']) || empty($item['data_value'])) {
                    continue;
                }
                $headers[$item['data_key']] = Parser::parse($item['data_value'], $subscriber);
            }
        }

        $sendingMethod = $settings['sending_method'];

        $runOnBackground = $settings['run_on_background'] == 'yes';

        $isJson = 'no';
        if ($settings['request_format'] == 'json' && $sendingMethod == 'POST') {
            $isJson = 'yes';
            $headers['Content-Type'] = 'application/json; charset=utf-8';
        }

        if ($sendingMethod == 'GET') {
            $remoteUrl = add_query_arg($body, $remoteUrl);
        }

        $data = [
            'payload'       => [
                'body'      => ($sendingMethod == 'POST') ? $body : null,
                'method'    => $sendingMethod,
                'headers'   => $headers,
                'sslverify' => apply_filters('ff_webhook_ssl_verify', false)
            ],
            'remote_url'    => $remoteUrl,
            'funnel_sub_id' => $funnelSubscriberId,
            'sequence_id'   => $sequence->id,
            'metric_id'     => $funnelMetric->id,
            'is_json'       => $isJson
        ];

        if ($runOnBackground) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'processing');
            fluentcrm_queue_on_background('fluent_run_http_send_data_request_process', $data);
            return true;
        }

        return $this->runRequestProcess($data);
    }

    public function runRequestProcess($data)
    {
        if ($data['is_json'] == 'yes') {
            $data['payload']['body'] = json_encode($data['payload']['body']);
        }

        $response = wp_remote_request($data['remote_url'], $data['payload']);

        if (is_wp_error($response)) {
            $code = Arr::get($response, 'response.code');
            $message = $response->get_error_message() . ', with response code: ' . $code . ' - ' . (int)$response->get_error_code();
            FunnelMetric::where('id', $data['metric_id'])
                ->update(['notes' => $message]);
            FunnelHelper::changeFunnelSubSequenceStatus($data['funnel_sub_id'], $data['sequence_id'], 'skipped');
            return false;
        }

        $responseBody = wp_remote_retrieve_body($response);
        if (is_array($responseBody)) {
            $responseBody = json_encode($responseBody);
        }

        if (is_string($responseBody)) {
            if (strlen($responseBody) > 2000) {
                $responseBody = substr($responseBody, 0, 2000) . '....';
            }

            FunnelMetric::where('id', $data['metric_id'])
                ->update(['notes' => $responseBody]);
        }

        FunnelHelper::changeFunnelSubSequenceStatus($data['funnel_sub_id'], $data['sequence_id']);

        return true;
    }
}
