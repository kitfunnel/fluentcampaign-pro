<?php

namespace FluentCampaign\App\Services\Integrations\WooCommerce;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class WooProductAdmin
{
    private $postMetaName = 'fcrm-settings-woo';

    public function init()
    {
        if (!apply_filters('fluentcrm_disable_integration_metaboxes', false, 'woocommerce')) {
            /*
             * Admin Product Edit Page Actions
             */
            add_action('woocommerce_product_write_panel_tabs', array($this, 'addPanelTitle'));
            add_action('woocommerce_product_data_panels', array($this, 'addPanelInputs'));
            add_action('save_post_product', array($this, 'saveMetaData'));

            /*
             * order success actions
             */
            add_action('woocommerce_order_status_processing', array($this, 'applyOrderTags'), 10, 2);
            add_action('woocommerce_order_status_completed', array($this, 'applyOrderTags'), 10, 2);
            add_action('woocommerce_order_status_refunded', array($this, 'applyRefundTags'), 10, 1);

            /*
             * Subscription Payment Related Hooks
             */
            add_action('woocommerce_subscription_status_updated', array($this, 'handleSubscriptionStatusUpdated'), 20, 2);
            add_action('woocommerce_subscription_renewal_payment_failed', array($this, 'handleSubPayFailed'), 10, 1);

        }
    }

    public function addPanelTitle()
    {
        if (!is_admin()) {
            return;
        }
        ?>
        <li class="custom_tab fluent_crm-settings-tab hide_if_grouped">
            <a href="#fluent_crm_tab">
                <svg width="14px" height="14px" viewBox="0 0 300 235" version="1.1" xmlns="http://www.w3.org/2000/svg"
                     xmlns:xlink="http://www.w3.org/1999/xlink" xml:space="preserve"
                     style="fill-rule:evenodd;clip-rule:evenodd;stroke-linejoin:round;stroke-miterlimit:2;"><g>
                        <path
                            d="M300,0c0,0 -211.047,56.55 -279.113,74.788c-12.32,3.301 -20.887,14.466 -20.887,27.221l0,38.719c0,0 169.388,-45.387 253.602,-67.952c27.368,-7.333 46.398,-32.134 46.398,-60.467c0,-7.221 0,-12.309 0,-12.309Z"/>
                        <path
                            d="M184.856,124.521c0,-0 -115.6,30.975 -163.969,43.935c-12.32,3.302 -20.887,14.466 -20.887,27.221l0,38.719c0,0 83.701,-22.427 138.458,-37.099c27.368,-7.334 46.398,-32.134 46.398,-60.467c0,-7.221 0,-12.309 0,-12.309Z"/>
                    </g></svg>
                <span><?php _e('FluentCRM', 'fluentcampaign-pro'); ?></span>
            </a>
        </li>
        <style>
            .fluent_crm-settings-tab a:before {
                content: none;
                display: none;
            }
        </style>
        <?php
    }

    public function addPanelInputs()
    {
        if (!is_admin()) {
            return '';
        }
        global $post;

        global $product_object;

        $defaults = [
            'purchase_apply_tags'  => array(),
            'purchase_remove_tags' => array(),
            'refund_apply_tags'    => array(),
            'refund_remove_tags'   => array()
        ];


        $isSubscription = false;

        if (defined('WCS_INIT_TIMESTAMP') && $product_object) {
            $subscriptionTypes = ['subscription', 'variable-subscription'];
            $isSubscription = in_array($product_object->get_type(), $subscriptionTypes);
            $defaults = array_merge($defaults, [
                'on_sub_pay_failed_apply_tags'  => [],
                'on_sub_pay_failed_remove_tags' => [],
                'on_sub_cancelled_apply_tags'   => [],
                'on_sub_cancelled_remove_tags'  => [],
                'on_sub_expired_apply_tags'     => [],
                'on_sub_expired_remove_tags'    => [],
            ]);
        }

        $settings = wp_parse_args(get_post_meta($post->ID, $this->postMetaName, true), $defaults);

        $tags = FluentCrmApi('tags')->all();

        // Add an nonce field so we can check for it later.
        wp_nonce_field('fcrm_meta_box_woo', 'fcrm_meta_box_woo_nonce');
        ?>
        <div id="fluent_crm_tab" class="panel woocommerce_options_panel fcrm-meta">
            <h3 style="margin: 10px 0;"><?php _e('FluentCRM Integration', 'fluentcampaign-pro'); ?></h3>
            <div class="fc_field_group">
                <h4><?php esc_html_e('Successful Purchase Actions', 'fluentcampaign-pro'); ?></h4>
                <p><?php esc_html_e('Please specify which tags will be added/removed to the contact when purchase', 'fluentcampaign-pro'); ?></p>
                <div class="fc_field_items">
                    <div class="fc_field">
                        <p><b><?php esc_html_e('Add Tags', 'fluentcampaign-pro'); ?></b></p>
                        <select placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>"
                                style="width:100%;" class="fc_multi_slect"
                                name="<?php echo $this->postMetaName; ?>[purchase_apply_tags][]" multiple="multiple"
                                id="fcrm_purchase_tags">
                            <?php foreach ($tags as $tag): ?>
                                <option
                                    value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['purchase_apply_tags'])) {
                                    echo 'selected';
                                } ?> ><?php echo $tag->title; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fc_field">
                        <p><b><?php esc_html_e('Remove Tags', 'fluentcampaign-pro'); ?></b></p>
                        <select placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>"
                                style="width:100%;" class="fc_multi_slect"
                                name="<?php echo $this->postMetaName; ?>[purchase_remove_tags][]" multiple="multiple"
                                id="fcrm_purchase_remove_tags">
                            <?php foreach ($tags as $tag): ?>
                                <option
                                    value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['purchase_remove_tags'])) {
                                    echo 'selected';
                                } ?> ><?php echo $tag->title; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="fc_field_group">
                <h4><?php esc_html_e('Refund Actions', 'fluentcampaign-pro'); ?></h4>
                <p><?php esc_html_e('Please specify which tags will be added/removed to the contact when refunded', 'fluentcampaign-pro'); ?></p>
                <div class="fc_field_items">
                    <div class="fc_field">
                        <p><b><?php esc_html_e('Add Tags', 'fluentcampaign-pro'); ?></b></p>
                        <select placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>"
                                style="width:100%;" class="fc_multi_slect"
                                name="<?php echo $this->postMetaName; ?>[refund_apply_tags][]" multiple="multiple">
                            <?php foreach ($tags as $tag): ?>
                                <option
                                    value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['refund_apply_tags'])) {
                                    echo 'selected';
                                } ?> ><?php echo $tag->title; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fc_field">
                        <p><b><?php esc_html_e('Remove Tags', 'fluentcampaign-pro'); ?></b></p>
                        <select placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>"
                                style="width:100%;" class="fc_multi_slect"
                                name="<?php echo $this->postMetaName; ?>[refund_remove_tags][]" multiple="multiple">
                            <?php foreach ($tags as $tag): ?>
                                <option
                                    value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['refund_remove_tags'])) {
                                    echo 'selected';
                                } ?> ><?php echo $tag->title; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <?php if ($isSubscription): ?>
                <div class="show_if_subscription show_if_variable-subscription">
                    <hr>
                    <h3><?php _e('Subscription', 'fluentcampaign-pro'); ?></h3>
                    <div class="fc_field_group">
                        <h4><?php esc_html_e('Renewal Payment Failed Actions', 'fluentcampaign-pro'); ?></h4>
                        <p><?php esc_html_e('Please specify which tags will be added/removed to the contact when renewal payment failed', 'fluentcampaign-pro'); ?></p>
                        <div class="fc_field_items">
                            <div class="fc_field">
                                <p><b><?php esc_html_e('Add Tags', 'fluentcampaign-pro'); ?></b></p>
                                <select placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>"
                                        style="width:100%;" class="fc_multi_slect"
                                        name="<?php echo $this->postMetaName; ?>[on_sub_pay_failed_apply_tags][]"
                                        multiple="multiple"
                                        id="fcrm_sub_pay_failed_tags">
                                    <?php foreach ($tags as $tag): ?>
                                        <option
                                            value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['on_sub_pay_failed_apply_tags'])) {
                                            echo 'selected';
                                        } ?> ><?php echo $tag->title; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fc_field">
                                <p><b><?php esc_html_e('Remove Tags', 'fluentcampaign-pro'); ?></b></p>
                                <select placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>"
                                        style="width:100%;" class="fc_multi_slect"
                                        name="<?php echo $this->postMetaName; ?>[on_sub_pay_failed_remove_tags][]"
                                        multiple="multiple"
                                        id="fcrm_sub_pay_failed_remove_tags">
                                    <?php foreach ($tags as $tag): ?>
                                        <option
                                            value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['on_sub_pay_failed_remove_tags'])) {
                                            echo 'selected';
                                        } ?> ><?php echo $tag->title; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="fc_field_group">
                        <h4><?php esc_html_e('Subscription Cancelled Actions', 'fluentcampaign-pro'); ?></h4>
                        <p><?php esc_html_e('Please specify which tags will be added/removed to the contact when subscription is cancelled', 'fluentcampaign-pro'); ?></p>
                        <div class="fc_field_items">
                            <div class="fc_field">
                                <p><b><?php esc_html_e('Add Tags', 'fluentcampaign-pro'); ?></b></p>
                                <select placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>"
                                        style="width:100%;" class="fc_multi_slect"
                                        name="<?php echo $this->postMetaName; ?>[on_sub_cancelled_apply_tags][]"
                                        multiple="multiple"
                                        id="fcrm_sub_cancel_add_tags">
                                    <?php foreach ($tags as $tag): ?>
                                        <option
                                            value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['on_sub_cancelled_apply_tags'])) {
                                            echo 'selected';
                                        } ?> ><?php echo $tag->title; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fc_field">
                                <p><b><?php esc_html_e('Remove Tags', 'fluentcampaign-pro'); ?></b></p>
                                <select placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>"
                                        style="width:100%;" class="fc_multi_slect"
                                        name="<?php echo $this->postMetaName; ?>[on_sub_cancelled_remove_tags][]"
                                        multiple="multiple"
                                        id="fcrm_sub_cancel_remove_tags">
                                    <?php foreach ($tags as $tag): ?>
                                        <option
                                            value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['on_sub_cancelled_remove_tags'])) {
                                            echo 'selected';
                                        } ?> ><?php echo $tag->title; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="fc_field_group">
                        <h4><?php esc_html_e('Subscription Expire Actions', 'fluentcampaign-pro'); ?></h4>
                        <p><?php esc_html_e('Please specify which tags will be added/removed to the contact when subscription is expired', 'fluentcampaign-pro'); ?></p>
                        <div class="fc_field_items">
                            <div class="fc_field">
                                <p><b><?php esc_html_e('Add Tags', 'fluentcampaign-pro'); ?></b></p>
                                <select placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>"
                                        style="width:100%;" class="fc_multi_slect"
                                        name="<?php echo $this->postMetaName; ?>[on_sub_expired_apply_tags][]"
                                        multiple="multiple"
                                        id="fcrm_sub_exp_add_tags">
                                    <?php foreach ($tags as $tag): ?>
                                        <option
                                            value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['on_sub_expired_apply_tags'])) {
                                            echo 'selected';
                                        } ?> ><?php echo $tag->title; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fc_field">
                                <p><b><?php esc_html_e('Remove Tags', 'fluentcampaign-pro'); ?></b></p>
                                <select placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>"
                                        style="width:100%;" class="fc_multi_slect"
                                        name="<?php echo $this->postMetaName; ?>[on_sub_expired_remove_tags][]"
                                        multiple="multiple"
                                        id="fcrm_sub_exp_remove_tags">
                                    <?php foreach ($tags as $tag): ?>
                                        <option
                                            value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['on_sub_expired_remove_tags'])) {
                                            echo 'selected';
                                        } ?> ><?php echo $tag->title; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>
        </div>
        <style>
            .fcrm-meta {
                padding: 0 20px;
            }

            .fcrm-meta h4 {
                margin: 5px 0 5px;
            }

            .fc_field_group {
                margin-bottom: 15px;
                padding: 0px 15px 10px;
                background: #fafafa;
            }

            .fcrm-meta p {
                margin: 0;
                padding: 0;
            }

            .fc_field_items {
                display: flex;
                width: 100%;
                margin-bottom: 10px;
                overflow: hidden;
                padding: 0;
                flex-direction: row;
                justify-content: flex-start;
                align-items: center;
            }

            .fc_field_items .fc_field {
                width: 50%;
                padding-right: 20px;
            }

            .fc_field_items .select2-container--default .select2-selection--multiple .select2-selection__rendered li {
                margin: 0;
            }
        </style>
        <?php

        add_action('admin_footer', function () {
            ?>
            <script>
                jQuery(document).ready(function () {
                    jQuery('.fc_multi_slect').select2();
                });
            </script>
            <?php
        }, 999);
    }

    public function saveMetaData($post_id)
    {
        if (
            !isset($_POST['fcrm_meta_box_woo_nonce']) ||
            !wp_verify_nonce($_POST['fcrm_meta_box_woo_nonce'], 'fcrm_meta_box_woo') ||
            (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        ) {
            return;
        }

        if ($_POST['post_type'] != 'product') {
            return;
        }

        $data = Arr::get($_POST, $this->postMetaName, []);
        update_post_meta($post_id, $this->postMetaName, $data);
    }

    public function applyOrderTags($orderId, $order)
    {
        if (get_post_meta($orderId, '_fcrm_order_success_complete', true)) {
            return true;
        }

        if (!$order) {
            return false;
        }

        $actions = $this->getActionTags($order, 'purchase');

        if (!$actions['apply_tags'] && !$actions['remove_tags']) {
            return false;
        }

        $subscriberData = \FluentCampaign\App\Services\Integrations\WooCommerce\Helper::prepareSubscriberData($order);
        if (!is_email($subscriberData['email'])) {
            return false;
        }

        $subscriberData['tags'] = $actions['apply_tags'];

        $subscriberClass = new Subscriber();
        $contact = $subscriberClass->updateOrCreate($subscriberData);

        if ($actions['remove_tags']) {
            $contact->detachTags($actions['remove_tags']);
        }

        update_post_meta($orderId, '_fcrm_order_success_complete', true);

        return true;
    }

    public function applyRefundTags($orderId)
    {
        $order = wc_get_order($orderId);

        if (!$order) {
            return false;
        }

        $actions = $this->getActionTags($order, 'refund');

        if (!$actions['apply_tags'] && !$actions['remove_tags']) {
            return false;
        }

        $subscriberData = \FluentCampaign\App\Services\Integrations\WooCommerce\Helper::prepareSubscriberData($order);

        if (!is_email($subscriberData['email'])) {
            return false;
        }

        $subscriberData['tags'] = $actions['apply_tags'];

        $subscriberClass = new Subscriber();
        $contact = $subscriberClass->updateOrCreate($subscriberData);

        if ($actions['remove_tags']) {
            $contact->detachTags($actions['remove_tags']);
        }

        return true;
    }

    public function handleSubPayFailed($subscription)
    {
        return $this->handleSubscriptionStatusUpdated($subscription, 'payment-failed');
    }

    public function handleSubscriptionStatusUpdated($subscription, $status)
    {
        if (!defined('WCS_INIT_TIMESTAMP')) {
            return false;
        }

        $user_id = $subscription->get_user_id();
        if ($status == 'on-hold') {
            return false;
        }

        /*
         * User may have other subscription active
         */
        if ($status != 'active') {
            foreach ($subscription->get_items() as $line_item) {
                $productId = $line_item->get_product_id();
                if (wcs_user_has_subscription($user_id, $productId, 'active')) {
                    return;
                }
            }
        }

        $validStatuses = [
            'active'         => 'purchase', // this is for reactivate
            'expired'        => 'on_sub_expired',
            'cancelled'      => 'on_sub_cancelled',
            'payment-failed' => 'on_sub_pay_failed'
        ];


        if (!isset($validStatuses[$status])) {
            return false;
        }

        $tagKey = $validStatuses[$status];

        if ($tagKey == 'on_sub_expired') {
            do_action('fluent_crm/woo_subscription_expired_simulated', $subscription, $user_id);
        }

        $actionTags = $this->getActionTags($subscription, $tagKey);

        if (empty($actionTags['apply_tags']) && empty($actionTags['remove_tags'])) {
            return false;
        }

        $userId = $subscription->get_user_id();

        if (!$userId) {
            return false;
        }

        $user = get_user_by('ID', $userId);

        if (!$user) {
            return false;
        }

        $subscriber = FunnelHelper::getSubscriber($user->user_email);

        if (!$subscriber) {
            $subscriberData = \FluentCrm\App\Services\Helper::getWPMapUserInfo($user);
            $subscriberData['source'] = 'woocommerce';
            $subscriber = FunnelHelper::createOrUpdateContact($subscriberData);
        }

        if (!$subscriber) {
            return false;
        }

        $actionTags['apply_tags'] && $subscriber->attachTags($actionTags['apply_tags']);

        $actionTags['remove_tags'] && $subscriber->detachTags($actionTags['remove_tags']);

        return true;
    }

    private function getActionTags($order, $type = 'purchase')
    {
        $applyTags = [];
        $removeTags = [];

        foreach ($order->get_items() as $item) {
            $productId = $item->get_product_id();
            $settings = get_post_meta($productId, $this->postMetaName, true);

            if (!$settings || !is_array($settings)) {
                continue;
            }

            if ($adds = Arr::get($settings, $type . '_apply_tags', [])) {
                $applyTags = array_merge($applyTags, $adds);
            }
            if ($removes = Arr::get($settings, $type . '_remove_tags', [])) {
                $removeTags = array_merge($removeTags, $removes);
            }
        }

        return [
            'apply_tags'  => array_unique($applyTags),
            'remove_tags' => array_unique($removeTags),
        ];
    }
}
