<?php

namespace FluentCampaign\App\Services\Integrations\Edd;

use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCampaign\App\Services\Commerce\ContactRelationItemsModel;
use FluentCampaign\App\Services\Commerce\ContactRelationModel;
use FluentCrm\App\Models\FunnelSubscriber;

class EddSmartCodeParse
{
    public function init()
    {
        add_filter('fluent_crm/smartcode_group_callback_edd_customer', array($this, 'parseCustomer'), 10, 4);
        add_filter('fluent_crm/smartcode_group_callback_edd_order', array($this, 'parseCurrentOrder'), 10, 4);
        add_filter('fluent_crm/smartcode_group_callback_edd_license', array($this, 'parseLicenseCodes'), 10, 4);

        add_filter('fluent_crm/extended_smart_codes', array($this, 'pushGeneralCodes'));
        add_filter('fluent_crm_funnel_context_smart_codes', array($this, 'pushContextCodes'), 10, 2);
    }

    public function parseCustomer($code, $valueKey, $defaultValue, $subscriber)
    {
        $userId = $subscriber->getWpUserId();

        $customer = false;

        if ($userId) {
            $customer = fluentCrmDb()->table('edd_customers')
                ->where('user_id', $userId)
                ->first();
        }

        if (!$customer) {
            $customer = fluentCrmDb()->table('edd_customers')
                ->where('email', $subscriber->email)
                ->first();
        }

        if ($customer) {
            switch ($valueKey) {
                case 'total_order_count':
                    return $customer->purchase_count;
                case 'total_spent':
                    return edd_format_amount($customer->purchase_value);
            }
        }

        if (!Commerce::isEnabled('edd')) {
            return $defaultValue;
        }

        $commerce = ContactRelationModel::provider('edd')->where('subscriber_id', $subscriber->id)->first();

        if (!$commerce) {
            return $defaultValue;
        }

        switch ($valueKey) {
            case 'first_order_date':
            case 'last_order_date':
                return date_i18n(get_option('date_format'), strtotime($commerce->{$valueKey}));
            case 'total_order_count':
                return $commerce->total_order_count;
            case 'total_spent':
                return edd_format_amount($commerce->total_order_value);
        }

        return $defaultValue;
    }

    public function parseLastOrder($code, $valueKey, $defaultValue, $subscriber)
    {
        $userId = $subscriber->getWpUserId();
        $lastOrder = false;
        if (Commerce::isEnabled('edd')) {
            $lastItem = ContactRelationItemsModel::provider('edd')
                ->where('subscriber_id', $subscriber->id)
                ->orderBy('origin_id', 'DESC')
                ->first();
            if ($lastItem && $lastItem->origin_id) {
                try {
                    $lastOrder = new \EDD_Payment($lastItem->origin_id);
                } catch (\Exception $exception) {
                    return $defaultValue;
                }
            } else {
                return $defaultValue;
            }
        } else {
            return $defaultValue;
        }

        if (!$lastOrder || !$lastOrder->ID) {
            return $defaultValue;
        }

        return $this->parseOrderProps($lastOrder, $valueKey, $defaultValue);
    }

    public function parseCurrentOrder($code, $valueKey, $defaultValue, $subscriber)
    {
        if (empty($subscriber->funnel_subscriber_id)) {
            return $this->parseLastOrder($code, $valueKey, $defaultValue, $subscriber);
        }

        $funnelSub = FunnelSubscriber::where('id', $subscriber->funnel_subscriber_id)->first();

        if (!$funnelSub || !$funnelSub->source_ref_id || !Helper::isEddTrigger($funnelSub->source_trigger_name)) {
            return $this->parseLastOrder($code, $valueKey, $defaultValue, $subscriber);
        }

        try {
            $order = new \EDD_Payment($funnelSub->source_ref_id);
        } catch (\Exception $exception) {
            return $defaultValue;
        }

        if (!$order || !$order->ID) {
            return $defaultValue;
        }

        return $this->parseOrderProps($order, $valueKey, $defaultValue);
    }

    public function parseLicenseCodes($code, $valueKey, $defaultValue, $subscriber)
    {
        if (!defined('EDD_SL_VERSION')) {
            return $defaultValue;
        }

        if (empty($subscriber->funnel_subscriber_id)) {
            return $defaultValue;
        }

        $funnelSub = FunnelSubscriber::where('id', $subscriber->funnel_subscriber_id)->first();
        if (!$funnelSub || !$funnelSub->source_ref_id) {
            return $defaultValue;
        }

        $licenseId = $funnelSub->source_ref_id;

        $license = edd_software_licensing()->get_license($licenseId);

        if (!$license || !$license->ID) {
            return $defaultValue;
        }

        switch ($valueKey) {
            case 'license_key':
                return $license->license_key;
            case 'product_name':
                if ($license->download_id && $product = get_post($license->download_id)) {
                    return $product->post_title;
                }
                return $defaultValue;
            case 'product_id':
                return $license->download_id;
            case 'expire_date':
                if ($license->expiration) {
                    return date_i18n(get_option('date_format'), strtotime($license->expiration));
                }
                return 'lifetime';
            case 'renew_url':
                return $license->get_renewal_url();
        }

        return $defaultValue;
    }

    public function pushGeneralCodes($codes)
    {
        $codes['edd_customer'] = [
            'key'        => 'edd_customer',
            'title'      => 'Edd Customer',
            'shortcodes' => $this->getSmartCodes()
        ];

        return $codes;
    }

    public function pushContextCodes($codes, $context)
    {
        if ($context == 'edd_sl_post_set_status' && defined('EDD_SL_VERSION')) {
            $codes[] = [
                'key'        => 'edd_license',
                'title'      => 'Edd License',
                'shortcodes' => $this->getSmartCodes('license')
            ];
            return $codes;
        }

        if (!Helper::isEddTrigger($context)) {
            return $codes;
        }

        $codes[] = [
            'key'        => 'edd_order',
            'title'      => 'Current Order - Edd',
            'shortcodes' => $this->getSmartCodes('order')
        ];

        return $codes;
    }

    /**
     * @param $payment \EDD_Payment
     * @param $valueKey string
     * @param $defaultValue string
     * @return string
     */
    protected function parseOrderProps($payment, $valueKey, $defaultValue = '')
    {
        if (!$payment || !$payment->ID) {
            return $defaultValue;
        }

        switch ($valueKey) {
            case 'address':
                return implode(' ', $payment->address);
            case 'order_number':
                return $payment->number;
            case 'order_id':
                return $payment->ID;
            case 'status':
                return $payment->status_nicename;
            case 'currency':
                return $payment->currency;
            case 'total_amount':
                return edd_format_amount($payment->total);
            case 'payment_method':
                return $payment->gateway;
            case 'date':
                return date_i18n(get_option('date_format'), strtotime($payment->date));
            case 'items_count':
                return count($payment->cart_details);
            case 'order_items_table':
                return $this->getOrderDetailsTable($payment);
            case 'download_lists':
                return $this->getDownloadList($payment);
        }

        return $defaultValue;
    }

    private function getSmartCodes($context = '')
    {
        if (!$context) {
            $generalCodes = [
                '{{edd_customer.total_order_count}}' => 'Total Order Count',
                '{{woo_customer.total_spent}}'       => 'Total Spent',
            ];

            if (Commerce::isEnabled('edd')) {
                $generalCodes['{{edd_customer.first_order_date}}'] = 'First Order Date';
                $generalCodes['{{edd_customer.last_order_date}}'] = 'Last Order Date';
            }

            return $generalCodes;
        }

        if ($context == 'order') {
            return [
                '{{edd_order.address}}'           => 'Address',
                '{{edd_order.order_number}}'      => 'Order Number',
                '{{edd_order.order_id}}'          => 'Customer Order ID',
                '{{edd_order.status}}'            => 'Status',
                '{{edd_order.currency}}'          => 'Currency',
                '{{edd_order.total_amount}}'      => 'Total Amount',
                '{{edd_order.payment_method}}'    => 'Payment Method',
                '{{edd_order.date}}'              => 'Order Date',
                '{{edd_order.items_count}}'       => 'Items Count',
                '{{edd_order.order_items_table}}' => 'Ordered Items (table)',
                '{{edd_order.download_lists}}'    => 'Order Download Lists'
            ];
        }

        if ($context == 'license') {
            return [
                '{{edd_license.license_key}}'  => 'License Key',
                '{{edd_license.product_name}}' => 'Product Name',
                '{{edd_license.product_id}}'   => 'Product ID',
                '{{edd_license.expire_date}}'  => 'Expire Date',
                '##edd_license.renew_url##'    => 'Renew URL'
            ];
        }

        return [];
    }

    /**
     * @param $payment \EDD_Payment
     * @return false|string
     */
    private function getOrderDetailsTable($payment, $default = '')
    {
        $order_items = edd_get_payment_meta_cart_details($payment->ID, true);

        if (!$order_items) {
            return $default;
        }
        ob_start();
        ?>
        <div class="wp-block-table">
            <table class="woo_order_table">
                <thead>
                <tr>
                    <th style="text-align: left;"><?php esc_html_e('Product', 'fluentcampaign-pro'); ?></th>
                    <th style="text-align: left;"><?php esc_html_e('Total', 'fluentcampaign-pro'); ?></th>
                </tr>
                </thead>

                <tbody>
                <?php
                foreach ($order_items as $item) {
                    if (empty($item['in_bundle'])) :
                        $price_id = edd_get_cart_item_price_id($item);
                        ?>
                        <tr>
                            <td style="text-align: left; padding: 5px 10px; border: 1px solid #5f5f5f;">
                                <?php echo esc_html($item['name']); ?>
                                <?php if (!is_null($price_id) && edd_has_variable_prices($item['id'])) : ?>
                                    <span
                                        class="edd_purchase_receipt_price_name">&nbsp;&ndash;&nbsp;<?php echo esc_html(edd_get_price_option_name($item['id'], $price_id, $payment->ID)); ?></span>
                                <?php endif; ?>
                                x <?php echo $item['quantity']; ?>
                            </td>
                            <td style="text-align: left; border: 1px solid #5f5f5f;">
                                <?php echo edd_format_amount($item['subtotal']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php } ?>

                <?php if (($fees = edd_get_payment_fees($payment->ID, 'item'))) : ?>
                    <?php foreach ($fees as $fee) : ?>
                        <tr>
                            <td style="text-align: left; padding: 5px 10px; border: 1px solid #5f5f5f;"
                                class="edd_fee_label">
                                <?php echo esc_html($fee['label']); ?></td>
                            <td style="text-align: left; border: 1px solid #5f5f5f;"
                                class="edd_fee_amount"><?php echo edd_currency_filter(edd_format_amount($fee['amount'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * @param $payment \EDD_Payment
     * @return false|string
     */
    private function getDownloadList($payment, $default = '')
    {
        $order_items = edd_get_payment_meta_cart_details($payment->ID, true);

        if (!$order_items) {
            return $default;
        }

        $meta = edd_get_payment_meta($payment->ID);
        $email = edd_get_payment_user_email($payment->ID);

        ob_start();

        echo '<ul>';
        foreach ($order_items as $key => $item) :
            if (!empty($item['in_bundle'])) {
                continue;
            }
            $price_id = edd_get_cart_item_price_id($item);
            $download_files = edd_get_download_files($item['id'], $price_id);
            if (edd_is_payment_complete($payment->ID) && !empty($download_files) && is_array($download_files)) :
                foreach ($download_files as $filekey => $file) :
                    ?>
                    <li class="edd_download_file">
                        <a href="<?php echo esc_url(edd_get_download_file_url($meta['key'], $email, $filekey, $item['id'], $price_id)); ?>"
                           class="edd_download_file_link"><?php echo edd_get_file_name($file); ?></a>
                    </li>
                <?php endforeach;
            endif;
        endforeach;
        echo '</ul>';
        return ob_get_clean();
    }
}
