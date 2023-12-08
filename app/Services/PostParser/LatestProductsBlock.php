<?php

namespace FluentCampaign\App\Services\PostParser;

use FluentCrm\Framework\Support\Arr;

class LatestProductsBlock
{
    private static $htmlCache = [];


    public static function renderProducts($postsHtml, $data)
    {
        $defaultAtts = [
            'selectedLayout'       => 'default',
            'selectedPostsPerPage' => '3',
            'showDescription'      => true,
            'showPrice'            => true,
            'showButton'           => true,
            'buttonText'           => 'Buy Now',
            'titleColor'           => '#2a363d',
            'descriptionColor'     => '',
            'priceColor'           => '#37454e',
            'buttonColor'          => '',
            'buttonBG'             => '#2a363d'
        ];
        $atts = wp_parse_args($data['attrs'], $defaultAtts);
//        $atts = $data['attrs'];

        $cacheKey = md5(maybe_serialize($atts));

        if(isset(self::$htmlCache[$cacheKey])) {
            return self::$htmlCache[$cacheKey];
        }

        $defaultArgs = [
            'status'    => 'publish',
            'limit' => Arr::get($atts, 'selectedPostsPerPage'),
        ];

        $products = wc_get_products($defaultArgs );

        $content = '';

        if ( $atts['selectedLayout'] !== 'layout-2' ) {
            $content .= '<table class="fc_woo_products" border="0" cellpadding="0" cellspacing="0" width="100%"><tbody><tr><td class="template-'.$atts['selectedLayout'].'">';
        }
        foreach ($products as $product) {
            $descStyle   = '';
            $priceStyle  = '';
            $buttonStyle = '';

            $titleStyle = 'color: '.$atts['titleColor'].';';
            if ( $atts['showDescription'] ) {
                $descStyle = 'color: '.$atts['descriptionColor'].';';
            }
            if ( $atts['showPrice'] ) {
                $priceStyle = 'color: '.$atts['priceColor'].';';
            }
            if ( $atts['showButton'] ) {
                $buttonStyle = 'color: '.$atts['buttonColor'].';';
                if ($atts['selectedLayout'] === 'layout-2') {
                    $buttonStyle .= 'background: '.$atts['buttonBG'].';';
                }
            }

            $content .= self::loadView($atts['selectedLayout'], [
                'atts'     => $atts,
                'product'     => $product,
                'image'    => self::getImage($product),
                'settings' => [
                    'titleStyle'  => $titleStyle,
                    'descStyle'   => $descStyle,
                    'priceStyle'  => $priceStyle,
                    'buttonStyle' => $buttonStyle
                ]
            ]);

        }

        if ( $atts['selectedLayout'] !== 'layout-2' ) {
            $content .= '</td></tr></tbody></table>';
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
        include FLUENTCAMPAIGN_PLUGIN_PATH.'app/Services/PostParser/views/LatestProducts/'.$templateName.'.php';
        return ltrim(ob_get_clean());
    }


    public static function getImage($product, $size = 'full')
    {
        if(!defined('WC_PLUGIN_FILE')) {
            return '';
        }

        static $imageCache = [];

        if(isset($imageCache[$product->get_id()])) {
            return $imageCache[$product->get_id()];
        }

        $image = '';
        if ($product->get_image_id()) {
            $image = wp_get_attachment_image_url($product->get_image_id(), $size);
        } elseif ($product->get_parent_id()) {
            $parent_product = wc_get_product($product->get_parent_id());
            if ($parent_product) {
                $image = wp_get_attachment_image_url($parent_product->get_image_id(), $size);
            }
        }

        $imageCache[$product->get_id()] = $image;

        return $imageCache[$product->get_id()] ;
    }


}
