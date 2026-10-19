<?php

final class MomoBirdAI_ContentTransformer
{
    public static function document($title, $html)
    {
        $body = self::normalize($html);
        if ($body === '') {
            return '';
        }
        return '# ' . trim((string) $title) . "\n\n" . $body;
    }

    private static function normalize($text)
    {
        $text = (string) $text;
        $text = str_replace('<!--markdown-->', '', $text);
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#isu', '', $text);
        $text = preg_replace('/<!--(?!markdown).*?-->/su', '', $text);
        $text = preg_replace_callback('#<h([1-6])\b[^>]*>(.*?)</h\1\s*>#isu', function ($match) {
            return "\n\n" . str_repeat('#', (int) $match[1]) . ' ' . strip_tags($match[2]) . "\n\n";
        }, $text);
        $text = preg_replace('#<br\s*/?>#iu', "\n", $text);
        $text = preg_replace('#</(?:p|div|li|blockquote|pre|section|article|ul|ol|table|tr)>#iu', "\n\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $lines = explode("\n", $text);
        foreach ($lines as &$line) {
            $line = trim(preg_replace('/[\t ]+/u', ' ', $line));
        }
        unset($line);
        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text);

        return trim($text);
    }
}
