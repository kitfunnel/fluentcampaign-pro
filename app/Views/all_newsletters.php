<?php if(!apply_filters('fluent_crm/disable_newsletter_archive_css', false)): ?>
<style type="text/css">
    .fc_newsletters_archive ul.fc_newsletter_lists {
        display: block;
        list-style: none;
        padding: 0;
        margin: 0;
        position: relative;
    }
    .fc_newsletters_archive ul.fc_newsletter_lists li {
        display: block;
        margin-bottom: 30px;
        clear: both;
    }
    .fc_newsletters_archive ul.fc_newsletter_lists li a {
        display: block;
        text-decoration: none;
        font-size: 22px;
        font-weight: 700;
        border-bottom: solid 2px #eee;
        line-height: 29px;
    }
    .fc_newsletters_archive ul.fc_newsletter_lists li time {
        font-size: 12px;
        color: #888;
    }
</style>
<?php endif; ?>

<?php
/*
 * @var array $newsletters array of newsletters as formatted
 * @var collections $campaigns Campaign Collections
 */
do_action('fluent_crm/before_newsletter_archive', $newsletters, $campaigns);
?>
<div class="fc_newsletters_archive">
    <ul class="fc_newsletter_lists">
        <?php foreach ($newsletters as $newsletter): ?>
        <li>
            <a href="<?php echo esc_url($newsletter['permalink']); ?>"><?php echo esc_html($newsletter['title']); ?></a>
            <time datetime="<?php echo esc_attr($newsletter['date_time']); ?>" class="fc_email_time"><?php echo esc_html($newsletter['formatted_date']); ?></time>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php
do_action('fluent_crm/after_newsletter_archive', $newsletters, $campaigns);
