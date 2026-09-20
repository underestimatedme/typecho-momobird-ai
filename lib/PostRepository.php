<?php

final class MomoBirdAI_PostRepository
{
    private $db;
    private $options;
    private $permalinkBuilder;

    public function __construct($db, $options, $permalinkBuilder = null)
    {
        $this->db = $db;
        $this->options = $options;
        $this->permalinkBuilder = is_callable($permalinkBuilder) ? $permalinkBuilder : null;
    }

    public static function isEligible(array $row)
    {
        return isset($row['type'], $row['status'])
            && $row['type'] === 'post'
            && $row['status'] === 'publish'
            && (!isset($row['password']) || trim((string) $row['password']) === '');
    }

    public function find($cid)
    {
        $cid = (int) $cid;
        if ($cid < 1) {
            return null;
        }
        $row = $this->db->fetchRow(
            $this->db->select('cid', 'title', 'text', 'slug', 'created', 'modified', 'type', 'status', 'password')
                ->from('table.contents')
                ->where('cid = ?', $cid)
                ->limit(1)
        );
        if (!is_array($row) || !self::isEligible($row)) {
            return null;
        }
        return $this->decorate($row);
    }

    public function page($afterCid, $limit)
    {
        $afterCid = max(0, (int) $afterCid);
        $limit = min(50, max(1, (int) $limit));
        $rows = $this->db->fetchAll(
            $this->db->select('cid', 'title', 'text', 'slug', 'created', 'modified', 'type', 'status', 'password')
                ->from('table.contents')
                ->where('cid > ?', $afterCid)
                ->where('type = ?', 'post')
                ->where('status = ?', 'publish')
                ->where('(password IS NULL OR password = ?)', '')
                ->order('cid', 'ASC')
                ->limit($limit)
        );
        $posts = array();
        foreach ($rows as $row) {
            if (self::isEligible($row)) {
                $posts[] = $this->decorate($row);
            }
        }
        return $posts;
    }

    private function decorate(array $row)
    {
        $row['tags'] = $this->metaNames((int) $row['cid'], 'tag');
        $row['categories'] = $this->metaNames((int) $row['cid'], 'category');
        $row['permalink'] = $this->permalink($row);
        return $row;
    }

    private function metaNames($cid, $type)
    {
        $rows = $this->db->fetchAll(
            $this->db->select('table.metas.name')
                ->from('table.metas')
                ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
                ->where('table.relationships.cid = ?', $cid)
                ->where('table.metas.type = ?', $type)
                ->order('table.metas.name', 'ASC')
        );
        $names = array();
        foreach ($rows as $row) {
            if (isset($row['name']) && trim((string) $row['name']) !== '') {
                $names[] = (string) $row['name'];
            }
        }
        return $names;
    }

    private function permalink(array $row)
    {
        if ($this->permalinkBuilder !== null) {
            return (string) call_user_func($this->permalinkBuilder, $row);
        }
        if (class_exists('Helper') && method_exists('Helper', 'widgetById')) {
            try {
                $widget = Helper::widgetById('contents', (int) $row['cid']);
                if ($widget && isset($widget->permalink)) {
                    return (string) $widget->permalink;
                }
            } catch (Throwable $error) {
                // Fall through to a deterministic local URL.
            }
        }
        $base = isset($this->options->index) ? rtrim((string) $this->options->index, '/') : '';
        return $base . '/index.php/archives/' . (int) $row['cid'] . '/';
    }
}
