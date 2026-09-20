<?php

final class MomoBirdAI_ContentTransformer
{
    public static function chunks($text, $limit = 7500)
    {
        $limit = (int) $limit;
        if ($limit < 1) {
            throw new InvalidArgumentException('Chunk limit must be positive');
        }

        $normalized = self::normalize($text);
        if ($normalized === '') {
            return array();
        }

        return self::pack(self::sections($normalized), $limit);
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

    private static function sections($text)
    {
        $sections = array();
        $heading = '';
        $buffer = array();

        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^#{1,6}\s+(.+)$/u', $line, $match)) {
                self::appendSection($sections, $heading, $buffer);
                $heading = trim($match[1]);
                $buffer = array();
            } else {
                $buffer[] = $line;
            }
        }
        self::appendSection($sections, $heading, $buffer);

        return $sections;
    }

    private static function appendSection(array &$sections, $heading, array $buffer)
    {
        $body = trim(implode("\n", $buffer));
        if ($heading !== '' || $body !== '') {
            $sections[] = array('heading' => $heading, 'body' => $body);
        }
    }

    private static function pack(array $sections, $limit)
    {
        $chunks = array();
        foreach ($sections as $section) {
            $pieces = self::splitBody($section['body'], $limit);
            if (!$pieces && $section['heading'] !== '') {
                $pieces = array($section['heading']);
            }
            foreach ($pieces as $piece) {
                $piece = trim($piece);
                if ($piece !== '') {
                    $chunks[] = array('heading' => $section['heading'], 'content' => $piece);
                }
            }
        }
        return $chunks;
    }

    private static function splitBody($body, $limit)
    {
        $body = trim($body);
        if ($body === '') {
            return array();
        }

        $paragraphs = preg_split('/\n{2,}/u', $body, -1, PREG_SPLIT_NO_EMPTY);
        $chunks = array();
        $current = '';
        foreach ($paragraphs as $paragraph) {
            foreach (self::splitOversized(trim($paragraph), $limit) as $piece) {
                $candidate = $current === '' ? $piece : $current . "\n\n" . $piece;
                if (mb_strlen($candidate, 'UTF-8') <= $limit) {
                    $current = $candidate;
                } else {
                    if ($current !== '') {
                        $chunks[] = $current;
                    }
                    $current = $piece;
                }
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }
        return $chunks;
    }

    private static function splitOversized($text, $limit)
    {
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return array($text);
        }

        $sentences = preg_split('/(?<=[。！？.!?])\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (count($sentences) > 1) {
            $pieces = array();
            $current = '';
            foreach ($sentences as $sentence) {
                if (mb_strlen($sentence, 'UTF-8') > $limit) {
                    if ($current !== '') {
                        $pieces[] = $current;
                        $current = '';
                    }
                    $pieces = array_merge($pieces, self::hardSplit($sentence, $limit));
                    continue;
                }
                if (mb_strlen($current . $sentence, 'UTF-8') <= $limit) {
                    $current .= $sentence;
                } else {
                    $pieces[] = $current;
                    $current = $sentence;
                }
            }
            if ($current !== '') {
                $pieces[] = $current;
            }
            return $pieces;
        }

        return self::hardSplit($text, $limit);
    }

    private static function hardSplit($text, $limit)
    {
        $pieces = array();
        $length = mb_strlen($text, 'UTF-8');
        for ($offset = 0; $offset < $length; $offset += $limit) {
            $pieces[] = mb_substr($text, $offset, $limit, 'UTF-8');
        }
        return $pieces;
    }
}
