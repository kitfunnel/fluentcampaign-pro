
<?php
/**
 * @var $atts array
 * @var $post array
 * @var $settings array
 */
?>
<table class="fce_row fc_latest_post_item <?php echo esc_attr($atts['selectedLayout']); ?>" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:20px;<?php echo esc_attr($settings['itemStyle']); ?>">
    <tbody>
            <?php if ( get_the_post_thumbnail_url($post) && $atts['showImage'] == true) { ?>
                <tr>
                    <td width="100%" height="300px" style="background: url('<?php echo esc_url(get_the_post_thumbnail_url($post)); ?>') center no-repeat; background-size: cover;">
                    </td>
                </tr>
            <?php } ?>
            <tr>
                <td valign="middle" width="100%" style="padding: 35px 40px 30px 40px;text-align:center;">
                    <table width="100%">
                        <tbody>
                            <tr>
                                <td>
                                    <div class="fc_latest_post_content" style="text-align:center;">
                                        <h1 class="title" style="<?php echo esc_attr($settings['titleStyle']); ?>">
                                            <a href="<?php echo esc_url(get_the_permalink($post)); ?>" style="<?php echo esc_attr($settings['titleStyle']); ?>">
                                                <?php
                                                if ( $post->post_title ) {
                                                    echo esc_html($post->post_title);
                                                } else {
                                                    esc_html_e('(no title)', 'fluentcampaign-pro');
                                                }
                                                ?>
                                            </a>
                                        </h1>
                                        <?php
                                        if ( $atts['showDescription'] == true) {
                                            ?>
                                            <p class="description" style="<?php echo esc_attr($settings['contentStyle']); ?>">
                                                <?php
                                                echo wp_trim_words(get_the_excerpt($post), $atts['selectedExcerptLength'], '...');
                                                ?>
                                            </p>
                                        <?php
                                        }
                                        if ( !empty($atts['buttonText']) && $atts['showButton'] == true ) {
                                        ?>
                                            <a href="<?php echo esc_url(get_the_permalink($post->ID)); ?>" style="<?php echo esc_attr($settings['buttonStyle']); ?>" class="fc_latest_post_btn">
                                                <?php echo esc_html($atts['buttonText']); ?>
                                            </a>
                                        <?php } ?>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
    </tbody>
</table>
<?php //} ?>
