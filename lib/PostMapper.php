<?php

final class MomoBirdAI_PostMapper
{
    public static function mapPost(array $post, $siteOrigin)
    {
        $title = trim((string) $post['title']);
        $content = MomoBirdAI_ContentTransformer::document($title, $post['text']);
        $url = self::absoluteUrl((string) $post['permalink'], (string) $siteOrigin);
        if ($content === '' || $url === '') {
            return null;
        }
        return array(
            'external_id' => (string) (int) $post['cid'],
            'title' => self::boundedTitle($title),
            'content' => $content,
            'url' => $url,
            'modified_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $post['modified'])
        );
    }

    private static function absoluteUrl($permalink, $siteOrigin)
    {
        $permalink = trim($permalink);
        if (preg_match('#^https?://#i', $permalink)) {
            return $permalink;
        }
        $siteOrigin = rtrim(trim($siteOrigin), '/');
        if ($permalink === '' || $siteOrigin === '') {
            return '';
        }
        return $siteOrigin . '/' . ltrim($permalink, '/');
    }

    private static function boundedTitle($title)
    {
        if ($title === '') {
            return 'Untitled';
        }
        return mb_substr($title, 0, 255, 'UTF-8');
    }
}
