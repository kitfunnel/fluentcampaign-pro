<?php

namespace FluentCampaign\App\Services\Integrations\Edd;

use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCampaign\App\Services\Commerce\ContactRelationItemsModel;
use FluentCampaign\App\Services\Integrations\BaseImporter;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\Framework\Support\Arr;

class EddImporter extends BaseImporter
{
    public function __construct()
    {
        $this->importKey = 'edd';
        parent::__construct();
    }

    private function getPluginName()
    {
        return 'Easy Digital Downloads';
    }

    public function getInfo()
    {
        return [
            'label'    => $this->getPluginName(),
            'logo'     => fluentCrmMix('images/edd.svg'),
            'disabled' => false
        ];
    }

    public function processUserDriver($config, $request)
    {
        $summary = $request->get('summary');

        if ($summary) {

            $config = $request->get('config');

            $type = Arr::get($config, 'import_type');

            if ($type == 'customers_sync') {
                $customersQuery = fluentCrmDb()->table('edd_customers');
                $total = $customersQuery->count();

                $formattedUsers = $customersQuery->select(['name', 'email'])->limit(5)->get();
            } else if ($type == 'product_tags') {
                $productIds = [];
                foreach ($config['product_type_maps'] as $map) {
                    $productIds[] = absint($map['field_key']);
                }

                $total = Subscriber::whereHas('contact_commerce_items', function ($query) use ($productIds) {
                    $query->whereIn('item_id', $productIds)
                        ->where('provider', 'edd');
                })->count();

                $contactsIdsCollections = ContactRelationItemsModel::provider('edd')
                    ->whereIn('item_id', $productIds)
                    ->select(['subscriber_id'])
                    ->groupBy('subscriber_id')
                    ->limit(5)
                    ->get();

                $contactIds = [];

                foreach ($contactsIdsCollections as $contactsIdsCollection) {
                    $contactIds[] = $contactsIdsCollection->subscriber_id;
                }

                $contacts = Subscriber::whereIn('id', $contactIds)->select(['id', 'first_name', 'last_name', 'email'])->get();

                $formattedUsers = [];
                foreach ($contacts as $contact) {
                    $formattedUsers[] = [
                        'name'  => $contact->full_name,
                        'email' => $contact->email
                    ];
                }

                return [
                    'import_info' => [
                        'subscribers'       => $formattedUsers,
                        'total'             => $total,
                        'has_tag_config'    => false,
                        'has_list_config'   => true,
                        'has_status_config' => false,
                        'has_update_config' => false,
                        'has_silent_config' => true
                    ]
                ];
            }

            return [
                'import_info' => [
                    'subscribers'       => $formattedUsers,
                    'total'             => $total,
                    'has_tag_config'    => true,
                    'has_list_config'   => true,
                    'has_status_config' => true,
                    'has_update_config' => false,
                    'has_silent_config' => true
                ]
            ];
        }

        $importType = 'customers_sync';

        $importTitle = sprintf(__('Sync %s Customers Now', 'fluentcampaign-pro'), $this->getPluginName());

        if (Commerce::isEnabled('edd')) {
            $importType = 'product_tags';
            $importTitle = sprintf(__('Import %s Customers Now', 'fluentcampaign-pro'), $this->getPluginName());
        }

        $configFields = [
            'config' => [
                'import_type'       => $importType,
                'product_type_maps' => [
                    [
                        'field_key'   => '',
                        'field_value' => ''
                    ]
                ]
            ],
            'fields' => [
                'product_type_maps' => [
                    'label'              => __('Please map your Product and associate FluentCRM Tags', 'fluentcampaign-pro'),
                    'type'               => 'form-many-drop-down-mapper',
                    'local_label'        => sprintf(__('Select %s Product', 'fluentcampaign-pro'), $this->getPluginName()),
                    'remote_label'       => __('Select FluentCRM Tag that will be applied', 'fluentcampaign-pro'),
                    'local_placeholder'  => sprintf(__('Select %s Product', 'fluentcampaign-pro'), $this->getPluginName()),
                    'remote_placeholder' => __('Select FluentCRM Tag', 'fluentcampaign-pro'),
                    'value_option_selector' => [
                        'option_key'   => 'tags',
                        'creatable' => true
                    ],
                    'field_ajax_selector' => [
                        'option_key' => 'edd_products'
                    ],
                    'dependency'         => [
                        'depends_on' => 'import_type',
                        'operator'   => '=',
                        'value'      => 'product_tags'
                    ]
                ],
                'sync_import_html'  => [
                    'type'       => 'html-viewer',
                    'heading'    => __('Easy Digital Downloads Data Sync', 'fluentcampaign-pro'),
                    'info'       => __('You can sync all your Easy Digital Downloads Customers into FluentCRM and all future customers and purchase data will be synced.', 'fluentcampaign-pro').'<br />'.__('After this sync you can import by product by product and provide appropriate tags', 'fluentcampaign-pro'),
                    'dependency' => [
                        'depends_on' => 'import_type',
                        'operator'   => '=',
                        'value'      => 'customers_sync'
                    ]
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
        $type = Arr::get($config, 'import_type');

        if ($type == 'customers_sync') {
            return (new DeepIntegration())->syncCustomers($config, $page);
        } else if ($type == 'product_tags') {
            return $this->mapCustomerProductTags($config, $page);
        }

        return new \WP_Error('not_found', 'Invalid Request');
    }

    private function mapCustomerProductTags($config, $page)
    {
        if (Arr::get($config, 'import_silently') == 'yes') {
            if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
                define('FLUENTCRM_DISABLE_TAG_LIST_EVENTS', true);
            }
        }

        $productTagMaps = [];

        $productIds = [];
        foreach ($config['product_type_maps'] as $map) {
            $productId = absint($map['field_key']);
            $productIds[] = $productId;
            if (!isset($productTagMaps[$productId])) {
                $productTagMaps[$productId] = [];
            }
            $productTagMaps[$productId][] = absint($map['field_value']);
        }

        $productIds = array_unique($productIds);

        $contactsQuery = ContactRelationItemsModel::provider('edd')->whereIn('item_id', $productIds)
            ->select(['subscriber_id'])
            ->groupBy('subscriber_id')
            ->orderBy('subscriber_id');

        $total = ContactRelationItemsModel::provider('edd')
            ->whereIn('item_id', $productIds)
            ->groupBy('item_id')
            ->count('subscriber_id');

        $limit = 150;
        $offset = ($page - 1) * $limit;

        $contactsIdsCollections = $contactsQuery
            ->with(['subscriber'])
            ->limit($limit)
            ->offset($offset)
            ->get();

        foreach ($contactsIdsCollections as $contactCollection) {
            if (!$contactCollection->subscriber) {
                continue;
            }

            $purchasedProducts = ContactRelationItemsModel::provider('edd')->whereIn('item_id', $productIds)
                ->where('subscriber_id', $contactCollection->subscriber_id)
                ->whereIn('item_id', $productIds)
                ->select(['item_id'])
                ->get();

            $assignedTags = [];
            foreach ($purchasedProducts as $purchasedProduct) {
                if (isset($productTagMaps[$purchasedProduct->item_id])) {
                    $assignedTags = array_merge($productTagMaps[$purchasedProduct->item_id], $assignedTags);
                }
            }

            $assignedTags = array_values(array_unique($assignedTags));

            if ($assignedTags) {
                $contactCollection->subscriber->attachTags($assignedTags);
            }

            if ($lists = Arr::get($config, 'lists', [])) {
                $contactCollection->subscriber->attachLists($lists);
            }
        }

        return [
            'page_total'   => ceil($total / $limit),
            'record_total' => $total,
            'has_more'     => $total > ($page * $limit),
            'current_page' => $page,
            'next_page'    => $page + 1
        ];
    }
}
