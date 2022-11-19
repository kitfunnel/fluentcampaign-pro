<?php

namespace FluentCampaign\App\Services\Integrations\Edd;

use FluentCampaign\App\Services\MetaFormBuilder;
use FluentCrm\App\Models\Tag;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class EddMetaBoxes
{
    public function init()
    {
        add_action('add_meta_boxes', array($this, 'addBoxes'));
        add_action('save_post_download', array($this, 'saveProductMetaBox'));
        add_action('edd_download_price_option_row', array($this, 'perPriceMetaBox'), 1000, 2);
        
        /*
         * Payment Actions
         */
        add_action('edd_complete_purchase', array($this, 'handleCompletePurchase'), 99, 2);
        add_action('edd_post_refund_payment', array($this, 'handleRefund'), 99);

    }

    public function addBoxes()
    {
        add_meta_box(
            'fluentcrm_edd_meta', __('FluentCRM Settings', 'fluentcampaign-pro'), array($this, 'productMetaBox',
        ), 'download', 'normal', 'default');
    }

    public function productMetaBox($post)
    {
        $settings = [
            'attach_tags' => [],
            'remove_tag'  => ''
        ];

        $crmSettings = get_post_meta($post->ID, '_fcrm_settings', true);
        if ($crmSettings) {
            $settings = wp_parse_args($crmSettings, $settings);
        }

        $tags = Tag::orderBy('title', 'ASC')->get();
        ?>
        <table class="form-table">
            <tr>
                <th><label for="fluentcrm_add_tags_member_type"><?php _e('Apply Tags', 'fluentcampaign-pro'); ?></label>
                </th>
                <td>
                    <input type="hidden" value="yes" name="_has_fcrm"/>
                    <select id="fluentcrm_add_tags_member_type" placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>" style="width:100%;"
                            class="fc_multi_select"
                            name="_fcrm[attach_tags][]" multiple="multiple">
                        <?php foreach ($tags as $tag): ?>
                            <option
                                value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['attach_tags'])) {
                                echo 'selected';
                            } ?> ><?php echo $tag->title; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="description"><?php esc_html_e('Apply these tags when purchased', 'fluentcampaign-pro'); ?></span>
                    <label style="margin-top: 20px; margin-bottom: 5px; display: block;"
                           for="fluentcrm_remove_tags_member_type">
                        <input id="fluentcrm_remove_tags_member_type" value="yes" type="checkbox"
                               name="_fcrm[remove_tag]" <?php checked($settings['remove_tag'], 'yes'); ?>>
                        <?php _e('Remove Tags on refund defined in "Apply Tags"', 'fluentcampaign-pro'); ?></label>
                </td>
            </tr>
        </table>
        <?php
        (new MetaFormBuilder())->initMultiSelect('.fc_multi_select', 'Select Tags');
    }

    public function perPriceMetaBox($postId, $key)
    {
        $crmSettings = get_post_meta($postId, '_fcrm_settings', true);
        $settings = array(
            'attach_tag_prices' => array(),
            'remove_tag_prices' => array(),
        );

        if ($crmSettings) {
            $settings = wp_parse_args($crmSettings, $settings);
        }

        if (empty($settings['attach_tag_prices'][$key])) {
            $settings['attach_tag_prices'][$key] = array();
        }

        if (empty($settings['remove_tag_prices'][$key])) {
            $settings['remove_tag_prices'][$key] = '';
        }

        $tags = Tag::orderBy('title', 'ASC')->get();
        ?>
        <div style="padding: 10px 20px;">
            <input type="hidden" value="yes" name="_has_fcrm"/>
            <span class="edd-custom-price-option-section-title"><?php esc_html_e('FluentCRM Settings', 'fluentcampaign-pro'); ?></span>
            <div>

                <label for="fluentcrm_add_tags_member_type"><?php _e('Apply Tags', 'fluentcampaign-pro'); ?></label>

                <select id="fluentcrm_add_tags_member_type" placeholder="<?php esc_attr_e('Select Tags', 'fluentcampaign-pro'); ?>" style="width:100%;"
                        class="fc_multi_select"
                        name="_fcrm[attach_tag_prices][<?php echo $key; ?>][]" multiple="multiple">
                    <?php foreach ($tags as $tag): ?>
                        <option
                            value="<?php echo $tag->id; ?>" <?php if (in_array($tag->id, $settings['attach_tag_prices'][$key])) {
                            echo 'selected';
                        } ?> ><?php echo $tag->title; ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="description"><?php esc_html_e('Apply these tags when purchase this variation', 'fluentcampaign-pro'); ?></span>

                <label style="margin-top: 20px; margin-bottom: 5px; display: block;"
                       for="fluentcrm_remove_tag_price_<?php echo $key; ?>">
                    <input id="fluentcrm_remove_tag_price_<?php echo $key; ?>" value="yes" type="checkbox"
                           name="_fcrm[remove_tag_prices][<?php echo $key; ?>]" <?php checked($settings['remove_tag_prices'][$key], 'yes'); ?>>
                    <?php _e('Remove selected Tags on refund', 'fluentcampaign-pro'); ?></label>
            </div>
        </div>
        <?php
    }

    public function saveProductMetaBox($post_id)
    {
        if (!isset($_POST['_has_fcrm']) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || $_POST['post_type'] == 'revision') {
            return;
        }

        if (isset($_POST['_fcrm'])) {
            $data = wp_unslash($_POST['_fcrm']);
        } else {
            $data = array();
        }

        update_post_meta($post_id, '_fcrm_settings', $data);
    }

    public function handleCompletePurchase($payment_id, $payment)
    {

        $tagIds = $this->getTagIdsFromPayment($payment);
        if(!$tagIds) {
            return false;
        }

        $subscriberData = Helper::prepareSubscriberData($payment);
        $subscriberData['tags'] = $tagIds;
        FunnelHelper::createOrUpdateContact($subscriberData);
        return true;

    }

    public function handleRefund($payment)
    {
        $removeTagIds = $this->getTagIdsFromPayment($payment, 'refund');
        if(!$removeTagIds) {
            return false;
        }

        $contactEmail = $payment->email;

        $contact = FluentCrmApi('contacts')->getContact($contactEmail);

        if(!$contact) {
            return false;
        }

        $contact->detachTags($removeTagIds);

        return true;
    }

    private function getTagIdsFromPayment($payment, $scope = 'success')
    {
        $tagIds = [];
        foreach($payment->cart_details as $item) {
            $productId = $item['id'];
            $settings = get_post_meta($productId, '_fcrm_settings', true);
            if (empty($settings)) {
                continue;
            }

            if (!empty($settings['attach_tags'])) {
                if($scope == 'refund') {
                    if (Arr::get($settings, 'remove_tag') == 'yes') {
                        $tagIds = array_merge($tagIds, $settings['attach_tags']);
                    }
                } else {
                    $tagIds = array_merge($tagIds, $settings['attach_tags']);
                }
            }

            if (isset($item['item_number'])) {
                $priceId = \FluentCrm\Framework\Support\Arr::get($item, 'item_number.options.price_id');
                if (!$priceId) {
                    continue;
                }
                $priceTags = \FluentCrm\Framework\Support\Arr::get($settings, 'attach_tag_prices.' . $priceId, []);

                if(!$priceTags) {
                    continue;
                }

                if($scope == 'refund') {
                    if(Arr::get($settings, 'remove_tag_prices.'.$priceId) == 'yes') {
                        $tagIds = array_merge($tagIds, $priceTags);
                    }
                } else {
                    $tagIds = array_merge($tagIds, $priceTags);
                }
            }
        }
        return array_unique($tagIds);
    }
}
