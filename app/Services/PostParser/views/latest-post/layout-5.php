
<?php
/**
 * @var $atts array
 * @var $post array
 * @var $settings array
 */
?>

<table class="fce_row fc_latest_post_item <?php echo esc_attr($atts['selectedLayout']); ?>" border="0" cellpadding="0" cellspacing="0" width="100%" style="<?php echo esc_attr($settings['itemStyle']); ?>">
    <tbody>
    <tr>
        <td>
            <span class="fc_latest_post_marker"></span>
            <a href="<?php echo esc_url(get_the_permalink($post)); ?>" style="<?php echo esc_attr($settings['titleStyle']); ?>">
                <?php
                if ( $post->post_title ) {
                    echo esc_html($post->post_title);
                } else {
                    esc_html_e('(no title)', 'fluentcampaign-pro');
                }
                ?>
            </a>
        </td>
    </tr>
    </tbody>
</table>


