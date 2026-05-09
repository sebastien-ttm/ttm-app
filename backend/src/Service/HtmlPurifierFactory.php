<?php

namespace App\Service;

class HtmlPurifierFactory
{
    public static function create(): \HTMLPurifier
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed',
            'p,br,strong,em,u,ul,ol,li,a[href|target|rel],h2,h3,h4,blockquote,code,pre,img[src|alt|title|width|height]'
        );
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('AutoFormat.Linkify', true);
        $config->set('AutoFormat.AutoParagraph', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Cache.SerializerPath', sys_get_temp_dir());
        return new \HTMLPurifier($config);
    }
}
