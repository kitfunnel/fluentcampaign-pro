
<?php
/**
 * @var $atts array
 * @var $product array
 * @var $image
 * @var $settings array
 */
?>
<table class="fce_row fc_woo_product <?php echo esc_attr($atts['selectedLayout']); ?>" border="0" cellpadding="0" cellspacing="0" width="40%" style="margin-bottom: 35px;<?php echo esc_attr($settings['itemStyle']); ?>">
    <tbody>
            <tr>
                <td width="100%">
                    <div class="fc_woo_product_img">
                        <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($product->get_title()); ?>">
                    </div>

                    <div class="fc_woo_product_info">
                        <div>
                            <h2 class="title">
                                <a style="<?php echo esc_attr($settings['titleStyle']); ?>" target="_blank" href="<?php echo esc_url($product->get_permalink()); ?>">
                                    <?php echo wp_kses_post($product->get_title()); ?>
                                </a>
                            </h2>

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
