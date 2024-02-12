<?php if (!apply_filters('fluent_crm/disable_newsletter_archive_css', false)): ?>
    <style type="text/css">
        .fc_newsletter_single .fc_newsletter_body {
            overflow: hidden;
            display: block;
            width: 100%;
            clear: both;
        }

        .fc_newsletter_single .fc_newsletter_head {
            border-bottom: solid 10px #f5f5f5;
            padding-bottom: 10px;
            font-weight: 700;
            letter-spacing: -1px;
            margin-bottom: 20px;
        }

        .fc_newsletter_single h1.fc_newsletter_title {
            margin-bottom: 0;
            padding: 0;
        }

        .fc_newsletter_single .fc_newsletter_head time {
            font-size: 12px;
            color: #888;
        }
    </style>
<?php endif; ?>
<?php
/*
 * @var array $newsletter array of newsletters as formatted
 * @var collections $campaign Campaign Model
 */
do_action('fluent_crm/before_newsletter_single', $newsletter, $campaign);
?>
<div class="fc_newsletter_single">
    <div class="fc_newsletter_head">
        <time datetime="<?php echo esc_attr($newsletter['date_time']); ?>"
              class="fc_email_time"><?php echo esc_html($newsletter['formatted_date']); ?></time>
        <h1 class="fc_newsletter_title" itemprop="headline"><?php echo esc_html($newsletter['title']); ?></h1>
    </div>
    <div id="fluent_email_body" class="fc_newsletter_body"></div>
</div>
<?php do_action('fluent_crm/after_newsletter_single', $newsletter, $campaign); ?>
<?php
add_action('wp_footer', function () use ($newsletter) {
    ?>
    <script type="text/javascript">
        /* <![CDATA[ */
        (function () {
            var fluentCrmEmail = <?php echo json_encode(['rendered' => $newsletter['content']]); ?>;
            var shadowHost = document.querySelector("#fluent_email_body");
            var shadowDom = shadowHost.attachShadow({mode: "closed"});
            var itemDiv = document.createElement("div");
            itemDiv.innerHTML = fluentCrmEmail.rendered;
            shadowDom.appendChild(itemDiv);
        })();
        /* ]]> */
    </script>
    <?php
}, 999);
