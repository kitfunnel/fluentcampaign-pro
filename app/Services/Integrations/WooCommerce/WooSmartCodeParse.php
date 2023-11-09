<?php

namespace FluentCampaign\App\Services\Integrations\WooCommerce;

use FluentCampaign\App\Services\Commerce\Commerce;
use FluentCampaign\App\Services\Commerce\ContactRelationItemsModel;
use FluentCampaign\App\Services\Commerce\ContactRelationModel;
use FluentCrm\App\Models\FunnelSubscriber;

class WooSmartCodeParse
{
    public function init()
    {
        add_filter('fluent_crm/smartcode_group_callback_woo_customer', array($this, 'parseCustomer'), 10, 4);
        add_filter('fluent_crm/smartcode_group_callback_woo_order', array($this, 'parseCurrentOrder'), 10, 4);
        add_filter('fluent_crm/smartcode_group_callback_woo_last_order', array($this, 'parseLastOrder'), 10, 4);

        add_filter('fluent_crm/extended_smart_codes', array($this, 'pushGeneralCodes'));
        add_filter('fluent_crm_funnel_context_smart_codes', array($this, 'pushContextCodes'), 10, 2);
    }

    public function parseCustomer($code, $valueKey, $defaultValue, $subscriber)
    {
        $userId = $subscriber->getWpUserId();

        if ($userId) {
            $customer = new \WC_Customer($userId);
            if ($customer->get_id()) {
                switch ($valueKey) {
                    case 'total_order_count':
                        return $customer->get_order_count();
                    case 'billing_address':
                        return $customer->get_billing_address();
                    case 'shipping_address':
                        return $customer->get_shipping_address();
                    case 'total_spent':
                        return wc_price($customer->get_total_spent());
                    case 'country':
                        return $customer->get_country();
                    case 'city':
                        return $customer->get_city();
                    case 'postal_code':
                        return $customer->get_postcode();
                }
            }
        }

        if (!Commerce::isEnabled('woo')) {
            return $defaultValue;
        }

        $commerce = ContactRelationModel::provider('woo')->where('subscriber_id', $subscriber->id)->first();

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
                return wc_price($commerce->total_order_value);
        }

        return $defaultValue;
    }

    public function parseLastOrder($code, $valueKey, $defaultValue, $subscriber)
    {
        $userId = $subscriber->getWpUserId();
        $lastOrder = false;
        if ($userId) {
            $customer = new \WC_Customer($userId);
            $lastOrder = $customer->get_last_order();
            if (!$lastOrder) {
                return $defaultValue;
            }
        } else if (Commerce::isEnabled('woo')) {
            $lastItem = ContactRelationItemsModel::provider('woo')
                ->where('subscriber_id', $subscriber->id)
                ->orderBy('origin_id', 'DESC')
                ->first();
            if ($lastItem && $lastItem->origin_id) {
                try {
                    $lastOrder = new \WC_Order($lastItem->origin_id);
                } catch (\Exception $exception) {
                    return $defaultValue;
                }
            } else {
                return $defaultValue;
            }
        } else {
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

        if (!$funnelSub || !$funnelSub->source_ref_id || !Helper::isWooTrigger($funnelSub->source_trigger_name)) {
            return $this->parseLastOrder($code, $valueKey, $defaultValue, $subscriber);
        }

        try {
            $order = new \WC_Order($funnelSub->source_ref_id);
        } catch (\Exception $exception) {
            return $defaultValue;
        }

        if(!$order || !$order->get_id()) {
            return $defaultValue;
        }

        return $this->parseOrderProps($order, $valueKey, $defaultValue);
    }

    public function pushGeneralCodes($codes)
    {
        $codes['woo_customer'] = [
            'key'        => 'woo_customer',
            'title'      => 'Woo Customer',
            'shortcodes' => $this->getSmartCodes()
        ];

        return $codes;
    }

    public function pushContextCodes($codes, $context)
    {
        if (!Helper::isWooTrigger($context)) {
            return $codes;
        }

        $codes[] = [
            'key'        => 'woo_order',
            'title'      => 'Current Order - WC',
            'shortcodes' => $this->getSmartCodes('order')
        ];

        return $codes;
    }

    /**
     * @param $order \WC_Order
     * @param $valueKey string
     * @param $defaultValue string
     * @return string
     */
    protected function parseOrderProps($order, $valueKey, $defaultValue = '')
    {
        if (!$order || !$order->get_id()) {
            return $defaultValue;
        }

        switch ($valueKey) {
            case 'shipping_address':
                return $order->get_formatted_shipping_address($defaultValue);
            case 'billing_address':
                return $order->get_formatted_billing_address($defaultValue);
            case 'order_number':
                return $order->get_order_number();
            case 'order_note':
                return $order->get_customer_note();
            case 'order_id':
                return $order->get_id();
            case 'status':
                return wc_get_order_status_name($order->get_status());
            case 'currency':
                return $order->get_currency();
            case 'total_amount':
                return $order->get_formatted_order_total();
            case 'payment_method':
                return $order->get_payment_method_title();
            case 'date':
                return date_i18n(get_option('date_format'), strtotime($order->get_date_created()));
            case 'items_count':
                return $order->get_item_count();
            case 'order_items_table':
                return $this->getOrderDetailsTable($order);
            case 'request_review_table':
                return $this->getOrderLeaveReviewsTable($order);
            case 'view_url':
                if ($order->get_customer_id()) {
                    return $order->get_view_order_url();
                }
                return $order->get_checkout_order_received_url();
        }

        return $defaultValue;
    }

    private function getSmartCodes($context = '')
    {
        if (!$context) {
            $generalCodes = [
                '{{woo_customer.total_order_count}}' => 'Total Order Count',
                '{{woo_customer.billing_address}}'   => 'Billing Address',
                '{{woo_customer.shipping_address}}'  => 'Shipping Address',
                '{{woo_customer.total_spent}}'       => 'Total Spent',
                '{{woo_customer.country}}'           => 'Billing Country',
                '{{woo_customer.city}}'              => 'Billing City',
                '{{woo_customer.postal_code}}'       => 'Billing Postal Code'
            ];

            if (Commerce::isEnabled('woo')) {
                $generalCodes['{{woo_customer.first_order_date}}'] = 'First Order Date';
                $generalCodes['{{woo_customer.last_order_date}}'] = 'Last Order Date';
            }

            return $generalCodes;
        }

        if ($context == 'order') {
            return [
                '{{woo_order.shipping_address}}'     => 'Shipping Address',
                '{{woo_order.billing_address}}'      => 'Billing Address',
                '{{woo_order.order_number}}'         => 'Order Number',
                '{{woo_order.order_note}}'           => 'Customer Order Note',
                '{{woo_order.order_id}}'             => 'Customer Order ID',
                '{{woo_order.status}}'               => 'Status',
                '{{woo_order.currency}}'             => 'Currency',
                '{{woo_order.total_amount}}'         => 'Total Amount',
                '{{woo_order.payment_method}}'       => 'Payment Method',
                '{{woo_order.date}}'                 => 'Order Date',
                '{{woo_order.items_count}}'          => 'Items Count',
                '{{woo_order.order_items_table}}'    => 'Ordered Items (table)',
                '{{woo_order.request_review_table}}' => 'Request Review (table)',
                '##woo_order.view_url##'             => 'order View URL',
            ];
        }

        return [];
    }

    /**
     * @param $order \WC_Order
     * @return false|string
     */
    private function getOrderDetailsTable($order)
    {
        $order_items = $order->get_items(apply_filters('woocommerce_purchase_order_item_types', 'line_item'));
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
                foreach ($order_items as $item_id => $item) {
                    $product = $item->get_product();
                    ?>
                    <tr>
                        <td style="text-align: left; padding: 5px 10px; border: 1px solid #5f5f5f;">
                            <?php
                            $is_visible = $product && $product->is_visible();
                            $product_permalink = apply_filters('woocommerce_order_item_permalink', $is_visible ? $product->get_permalink($item) : '', $item, $order);

                            echo wp_kses_post(apply_filters('woocommerce_order_item_name', $product_permalink ? sprintf('<a href="%s">%s</a>', $product_permalink, $item->get_name()) : $item->get_name(), $item, $is_visible));

                            $qty = $item->get_quantity();
                            $refunded_qty = $order->get_qty_refunded_for_item($item_id);

                            if ($refunded_qty) {
                                $qty_display = '<del>' . esc_html($qty) . '</del> <ins>' . esc_html($qty - ($refunded_qty * -1)) . '</ins>';
                            } else {
                                $qty_display = esc_html($qty);
                            }
                            echo apply_filters('woocommerce_order_item_quantity_html', ' <strong class="product-quantity">' . sprintf('&times;&nbsp;%s', $qty_display) . '</strong>', $item); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            wc_display_item_meta($item); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            ?>
                        </td>
                        <td style="text-align: left; border: 1px solid #5f5f5f;">
                            <?php echo $order->get_formatted_line_subtotal($item); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>

                <tfoot>
                <?php
                foreach ($order->get_order_item_totals() as $key => $total) {
                    ?>
                    <tr>
                        <th style="text-align: right;border: 1px solid #5f5f5f;"><?php echo esc_html($total['label']); ?></th>
                        <td style="text-align: left;border: 1px solid #5f5f5f;"><?php echo ('payment_method' === $key) ? esc_html($total['value']) : wp_kses_post($total['value']); ?></td>
                    </tr>
                    <?php
                }
                ?>
                </tfoot>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    private function getOrderLeaveReviewsTable($order)
    {
        $order_items = $order->get_items(apply_filters('woocommerce_purchase_order_item_types', 'line_item'));
        $hash = apply_filters('fluent_crm/woo_product_review_hash', '#tab-reviews');

        $base = apply_filters('fluencrm_review_btn_bg_color', get_option('woocommerce_email_base_color'));
        $base_text = apply_filters('fluencrm_review_btn_color', wc_light_or_dark($base, '#202020', '#ffffff'));

        ob_start();
        ?>
        <div class="wp-block-table">
            <table class="woo_order_table">
                <tbody>
                <?php
                foreach ($order_items as $item_id => $item) {
                    $product = $item->get_product(); // WC_Product
                    if ($product) {
                    ?>
                    <tr>
                        <td width="100">
                            <?php echo wp_kses_post($this->getProductPhoto($product, 'shop_catalog', false, 100)); //phpcs:ignore WordPress.Security.EscapeOutput ?>
                        </td>
                        <td width="">
                            <p style="vertical-align:middle;"><?php echo $product->get_name(); ?></p>
                        </td>
                        <td align="center" width="170">
                            <div style="padding: 0px 10px;" class="fc_btn">
                                <a href="<?php echo esc_url_raw($product->get_permalink() . $hash); ?>"
                                   class="fc_review_btn">
                                    <!--[if mso]>
                                    <i style="letter-spacing: 25px;mso-font-width:-100%;mso-text-raise:30pt" hidden>&nbsp;</i>
                                    <![endif]-->
                                    <span
                                        style="mso-text-raise:15pt;"><?php echo apply_filters('fluent_crm/review_button_text', esc_html__('Leave a review', 'fluentcampaign-pro')); ?></span>
                                    <!--[if mso]>
                                    <i style="letter-spacing: 25px;mso-font-width:-100%" hidden>&nbsp;</i>
                                    <![endif]-->
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php }
                } ?>
                </tbody>
            </table>
            <style>
                .fc_review_btn {
                    background-color: <?php echo esc_attr( $base ); ?>;
                    color: <?php echo esc_attr( $base_text ); ?>;
                }
            </style>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * @param $product \WC_Product
     * @param $size
     * @param $url
     * @param $with
     * @return false|string
     */
    private function getProductPhoto($product, $size = 'shop_catalog', $url = false, $with = '')
    {
        $image_id = $product->get_image_id();

        $image_url = '';
        if (!empty($image_id)) {
            $image_url = wp_get_attachment_image_url($image_id, $size);
        }

        if (empty($image_url) && function_exists('wc_placeholder_img_src')) {
            $image_url = wc_placeholder_img_src($size);
        }

        if ($url) {
            $image = $image_url;
        } else {
            $style = !empty($with) ? "width='{$with}'" : '';
            $image = '<img src="' . $image_url . '" ' . $style . ' alt="' . sanitize_text_field($product->get_name()) . '" />';
        }

        return $image;
    }

}
