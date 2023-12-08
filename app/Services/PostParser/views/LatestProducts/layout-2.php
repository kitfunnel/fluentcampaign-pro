
<?php
/**
 * @var $atts array
 * @var $product array
 * @var $image
 * @var $settings array
 */
?>
<table class="fce_row fc_woo_product <?php echo esc_attr($atts['selectedLayout']); ?>" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 35px;<?php echo esc_attr($settings['itemStyle']); ?>">
    <tbody>
    <tr>
        <td width="45%" style="background: url('<?php echo esc_attr($image) ?>') center no-repeat; background-size: cover;">
        </td>
        <td width="55%" style="padding: 10px 10px 10px 20px;">
            <div class="fc_woo_product_info">
                <div>
                    <h2 class="title">
                        <a style="<?php echo esc_attr($settings['titleStyle']); ?>" target="_blank" href="<?php echo esc_url($product->get_permalink()); ?>">
                            <?php echo wp_kses_post($product->get_title()); ?>
                        </a>
                    </h2>

                    <?php
                    if ( $atts['showDescription'] ) {
                        echo sprintf(
                            '<div class="description" style="%1$s">%2$s</div>',
                            $settings['descriptionStyle'],
                            wc_format_content(wp_kses_post($product->get_short_description() ? $product->get_short_description() : wc_trim_string($product->get_description(), 400)))
                        );
                    }
                    ?>

                    <?php
                    echo sprintf(
                        '<span class="price" style="%1$s">%2$s</span>',
                        $settings['priceStyle'],
                        wp_kses_post($product->get_price_html())
                    );
                    ?>
                </div>
                <a href="<?php echo esc_url($product->get_permalink()); ?>" target="_blank" style="<?php echo esc_attr($settings['buttonStyle']) ?>" class="add-to-cart-btn">
                    <?php echo esc_html($atts['buttonText']); ?>
                </a>
            </div>
        </td>
    </tr>

    </tbody>
</table>
