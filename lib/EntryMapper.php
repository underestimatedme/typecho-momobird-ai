<?php

final class MomoBirdAI_EntryMapper
{
    public static function mapPost(array $post, $siteId)
    {
        $entries = array();
        $occurrences = array();
        foreach (MomoBirdAI_ContentTransformer::chunks($post['text']) as $chunk) {
            $identity = $chunk['heading'] !== ''
                ? 'heading:' . mb_strtolower($chunk['heading'], 'UTF-8')
                : 'body:' . mb_substr($chunk['content'], 0, 512, 'UTF-8');
            $occurrence = isset($occurrences[$identity]) ? $occurrences[$identity] + 1 : 1;
            $occurrences[$identity] = $occurrence;
            $key = substr(hash('sha256', $identity . ':' . $occurrence), 0, 20);
            $entries[] = array(
                'external_id' => 'typecho:' . $siteId . ':post:' . (int) $post['cid'] . ':' . $key,
                'title' => self::boundedTitle($post['title'], $chunk['heading']),
                'content' => $chunk['content'],
                'tags' => self::tags($post, $siteId),
                'source_ref' => (string) $post['permalink'],
                'metadata' => array(
                    'source' => 'typecho',
                    'site_id' => (string) $siteId,
                    'post_id' => (string) $post['cid'],
                    'chunk_key' => $key,
                    'language' => isset($post['language']) ? (string) $post['language'] : 'und',
                    'visibility' => 'public',
                    'modified' => isset($post['modified']) ? (string) $post['modified'] : ''
                )
            );
        }
        return $entries;
    }

    private static function boundedTitle($title, $heading)
    {
        $title = trim((string) $title);
        $heading = trim((string) $heading);
        $combined = $heading === '' ? $title : $title . ' — ' . $heading;
        return mb_substr($combined, 0, 300, 'UTF-8');
    }

    private static function tags(array $post, $siteId)
    {
        $raw = array('typecho', 'typecho-site-' . substr(hash('sha256', (string) $siteId), 0, 12));
        foreach (array('tags', 'categories') as $field) {
            if (!isset($post[$field]) || !is_array($post[$field])) {
                continue;
            }
            foreach ($post[$field] as $value) {
                if (is_array($value)) {
                    $value = isset($value['name']) ? $value['name'] : '';
                }
                $value = trim((string) $value);
                if ($value !== '') {
                    $raw[] = mb_substr($value, 0, 64, 'UTF-8');
                }
            }
        }

        $tags = array();
        foreach ($raw as $tag) {
            if (!in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
            if (count($tags) === 32) {
                break;
            }
        }
        return $tags;
    }
}
