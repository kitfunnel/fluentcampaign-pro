<?php

namespace FluentCampaign\App\Services\PostParser;

use FluentCrm\Framework\Support\Arr;

class LatestPost
{
    private static $htmlCache = [];

    public static function renderPosts($postsHtml, $data)
    {
        $defaultAtts = [
            'selectedPostType'     => 'post',
            'selectedPostsPerPage' => '3',
            'selectedLayout'       => 'default',
            'showImage'            => true,
            'showMeta'             => true,
            'showMetaAuthor'       => true,
            'showMetaAuthorImg'    => true,
            'showMetaComments'     => true,
            'showButton'           => true,
            'showDescription'      => true,
            'selectedExcerptLength' => '55',
            'contentColor'         => '#6b6d7c',
            'titleColor'           => '#393d57',
            'backgroundColor'      => '#ffffff',
            'authorColor'          => '#393d57',
            'commentColor'         => '#acacac',
            'buttonColor'          => '#000000',
            'taxType'              => 'all',
            'catType'              => 'category',
            'order'                => 'desc',
            'orderBy'              => 'date',
            'recentPostDays'       => '',
            'buttonText'           => 'Read More'
        ];
        $atts = wp_parse_args($data['attrs'], $defaultAtts);

        $cacheKey = md5(maybe_serialize($atts));

        if(isset(self::$htmlCache[$cacheKey])) {
            return self::$htmlCache[$cacheKey];
        }

        if ($selectedLayout = Arr::get($atts, 'selectedLayout')) {
            $defaultAtts['selectedLayout'] = $selectedLayout;
        }

        $defaultArgs = [
            'post_type'      => Arr::get($atts, 'selectedPostType'),
            'post_status'    => 'publish',
            'posts_per_page' => Arr::get($atts, 'selectedPostsPerPage'),
            'order_by'       => Arr::get($atts, 'orderBy'),
            'order'          => Arr::get($atts, 'order')
        ];

        if ( !empty(Arr::get($atts, 'recentPostDays')) && Arr::get($atts, 'recentPostDays') !== '0' ) {
            $defaultArgs['date_query'] = [
                [
                    'after' => $atts['recentPostDays'].' days ago'
                ]
            ];
        }

        if (Arr::get($atts, 'taxType') && Arr::get($atts, 'taxType') !== 'all') {
            $defaultArgs['tax_query'] = [
                [
                    'taxonomy'         => Arr::get($atts, 'catType'),
                    'terms'            => Arr::get($atts, 'taxType'),
                ],
            ];
        }
        $posts = get_posts($defaultArgs);

        $content = '';
        foreach ($posts as $post) {
            $authorStyle = '';
            if ($atts['authorColor']) {
                $authorStyle = 'color: '.$atts['authorColor'];
            }

            $commentStyle = '';
            if ($atts['commentColor']) {
                $commentStyle = 'color: '.$atts['commentColor'];
            }

            $titleStyle = '';
            if ($atts['titleColor']) {
                $titleStyle = 'color: '.$atts['titleColor'];
            }

            $contentStyle = '';
            if ($atts['contentColor']) {
                $contentStyle = 'color: '.$atts['contentColor'];
            }

            $itemStyle = '';
            if ($atts['backgroundColor']) {
                $itemStyle = 'background: '.$atts['backgroundColor'];
            }

            $buttonStyle = '';
            if ($atts['buttonColor']) {
                $buttonStyle = 'color: '.$atts['buttonColor'].';';
                if ( Arr::get($atts, 'selectedLayout') === 'layout-7' ) {
                    $buttonStyle .= 'border: 2px solid '.$atts['buttonColor'];
                }
            }

            $content .= self::loadView($defaultAtts['selectedLayout'], [
                'atts' => $atts,
                'post' => $post,
                'settings' => [
                    'authorStyle'  => $authorStyle,
                    'commentStyle' => $commentStyle,
                    'titleStyle'   => $titleStyle,
                    'contentStyle' => $contentStyle,
                    'itemStyle'    => $itemStyle,
                    'buttonStyle'  => $buttonStyle
                ]
            ]);
        }

        if($content) {
            self::$htmlCache[$cacheKey] = $content;
        }

        return $content;
    }

    private static function loadView($templateName, $data)
    {
        extract($data, EXTR_SKIP);
        ob_start();
        include FLUENTCAMPAIGN_PLUGIN_PATH.'app/Services/PostParser/views/latest-post/'.$templateName.'.php';
        return ltrim(ob_get_clean());
    }
}
