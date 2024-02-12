
<?php
/**
 * @var $atts array
 * @var $post array
 * @var $settings array
 */

?>
<table class="fce_row fc_latest_post_item <?php echo esc_attr($atts['selectedLayout']); ?>" border="0" cellpadding="0" cellspacing="0" width="100%" style="<?php echo esc_attr($settings['itemStyle']); ?>">
    <tbody>
        <tr class="fc_latest_post_item_tr">
            <td valign="middle">
                <table width="100%">
                    <tbody>
                        <tr>
                        <td style="padding: 20px 8px 20px 0;">
                            <div class="fc_latest_post_content">

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

                                if ($atts['showMeta'] == true) { ?>
                                    <p class="meta" style="<?php echo esc_attr($settings['authorStyle']); ?>">
                                        <?php
                                        if ( $atts['showMetaAuthor'] === true) {
                                        ?>
                                        <span class="author">
                                            <?php if ( $atts['showMetaAuthorImg'] === true ) { ?>
                                                <img style="display: block;width: 30px;height: 30px;border-radius: 30px;" src="<?php echo esc_url( get_avatar_url( $post->post_author ) ) ?>" alt="">
                                            <?php } ?>
                                            <span style="text-decoration: none;font-size: 16px; <?php echo esc_attr($settings['authorStyle']); ?>">
                                                <?php the_author_meta( 'display_name' , $post->post_author ); ?>
                                            </span>
                                        </span>
                                        <?php } ?>
                                        <?php if($atts['showMetaComments'] && $post->comment_count > 0) : ?>
                                            <?php if($atts['showMetaAuthor']) { esc_html_e('-', 'fluentcampaign-pro'); } ?>
                                            <span class="comments" style="font-size: 16px; <?php echo esc_attr($settings['commentStyle']); ?>">
                                                <?php printf(_n('%s comment', '%s comments', $post->comment_count), number_format_i18n( $post->comment_count )); ?>
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                <?php } ?>

                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </td>

            <?php if ( get_the_post_thumbnail_url($post) && $atts['showImage'] == true) { ?>
                <td class="fc_latest_post_img" width="220px" valign="middle" style="padding: 20px 0 20px 8px;">
                    <img width="220px" height="220px" style="border-radius: 4px;object-fit: cover; " src="<?php echo esc_url(get_the_post_thumbnail_url($post)); ?>" alt="<?php echo esc_attr($post->post_title); ?>">
                </td>
            <?php } ?>

        </tr>
    </tbody>
</table>
