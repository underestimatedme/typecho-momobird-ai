<?php

final class MomoBirdAI_SyncRepository
{
    const TABLE = 'table.momobird_sync';

    private $db;
    private $siteId;

    public function __construct($db, $siteId)
    {
        $this->db = $db;
        $this->siteId = (string) $siteId;
    }

    public static function install($db)
    {
        $sql = self::schemaSql($db->getAdapterName(), $db->getPrefix());
        $writeMode = defined('Typecho_Db::WRITE') ? constant('Typecho_Db::WRITE') : 2;
        $db->query($sql, $writeMode);
    }

    public static function schemaSql($adapterName, $prefix)
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/', (string) $prefix)) {
            throw new InvalidArgumentException('Database prefix is unsafe');
        }
        $table = $prefix . 'momobird_sync';
        $adapter = strtolower((string) $adapterName);
        if (strpos($adapter, 'sqlite') !== false) {
            return "CREATE TABLE IF NOT EXISTS {$table} ("
                . "id INTEGER PRIMARY KEY AUTOINCREMENT,"
                . "site_id VARCHAR(64) NOT NULL,"
                . "post_id INTEGER NOT NULL,"
                . "chunk_key VARCHAR(64) NOT NULL,"
                . "external_id VARCHAR(256) NOT NULL,"
                . "entry_id VARCHAR(36) NULL,"
                . "content_hash VARCHAR(64) NOT NULL DEFAULT '',"
                . "sync_status VARCHAR(32) NOT NULL,"
                . "attempts INTEGER NOT NULL DEFAULT 0,"
                . "last_error_code VARCHAR(64) NULL,"
                . "last_error_message VARCHAR(255) NULL,"
                . "last_attempt_at INTEGER NOT NULL DEFAULT 0,"
                . "last_success_at INTEGER NOT NULL DEFAULT 0,"
                . "UNIQUE(site_id, post_id, chunk_key)"
                . ")";
        }
        if (strpos($adapter, 'mysql') === false && strpos($adapter, 'mysqli') === false) {
            throw new InvalidArgumentException('Unsupported database adapter');
        }
        return "CREATE TABLE IF NOT EXISTS `{$table}` ("
            . "`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,"
            . "`site_id` VARCHAR(64) NOT NULL,"
            . "`post_id` BIGINT UNSIGNED NOT NULL,"
            . "`chunk_key` VARCHAR(64) NOT NULL,"
            . "`external_id` VARCHAR(256) NOT NULL,"
            . "`entry_id` CHAR(36) NULL,"
            . "`content_hash` CHAR(64) NOT NULL DEFAULT '',"
            . "`sync_status` VARCHAR(32) NOT NULL,"
            . "`attempts` INT UNSIGNED NOT NULL DEFAULT 0,"
            . "`last_error_code` VARCHAR(64) NULL,"
            . "`last_error_message` VARCHAR(255) NULL,"
            . "`last_attempt_at` BIGINT NOT NULL DEFAULT 0,"
            . "`last_success_at` BIGINT NOT NULL DEFAULT 0,"
            . "PRIMARY KEY (`id`),"
            . "UNIQUE KEY `uniq_momobird_source` (`site_id`,`post_id`,`chunk_key`),"
            . "KEY `idx_momobird_status` (`site_id`,`sync_status`),"
            . "KEY `idx_momobird_post` (`site_id`,`post_id`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public function forPost($postId)
    {
        return $this->db->fetchAll(
            $this->db->select()->from(self::TABLE)
                ->where('site_id = ?', $this->siteId)
                ->where('post_id = ?', (int) $postId)
                ->order('id', 'ASC')
        );
    }

    public function replacePost($postId, array $next)
    {
        foreach ($next as $mapping) {
            $this->upsert($postId, $mapping, false);
        }
        $this->remove($postId, '_post');
    }

    public function markFailure($postId, $mapping, $status, $code, $message)
    {
        if ($mapping === null) {
            $mapping = array(
                'chunk_key' => '_post',
                'external_id' => 'typecho:' . $this->siteId . ':post:' . (int) $postId . ':_post',
                'entry_id' => null,
                'content_hash' => ''
            );
        }
        $mapping['sync_status'] = (string) $status;
        $mapping['last_error_code'] = mb_substr((string) $code, 0, 64, 'UTF-8');
        $mapping['last_error_message'] = mb_substr((string) $message, 0, 255, 'UTF-8');
        $this->upsert($postId, $mapping, true);
    }

    public function remove($postId, $chunkKey)
    {
        $this->db->query(
            $this->db->delete(self::TABLE)
                ->where('site_id = ?', $this->siteId)
                ->where('post_id = ?', (int) $postId)
                ->where('chunk_key = ?', (string) $chunkKey)
        );
    }

    public function failed()
    {
        return $this->db->fetchAll(
            $this->db->select()->from(self::TABLE)
                ->where('site_id = ?', $this->siteId)
                ->where('sync_status <> ?', 'synced')
                ->order('last_attempt_at', 'ASC')
                ->limit(200)
        );
    }

    public function counts()
    {
        $rows = $this->db->fetchAll(
            $this->db->select('sync_status', 'COUNT(*) AS count')->from(self::TABLE)
                ->where('site_id = ?', $this->siteId)
                ->group('sync_status')
        );
        $counts = array('synced' => 0, 'failed_upsert' => 0, 'failed_delete' => 0);
        foreach ($rows as $row) {
            $counts[$row['sync_status']] = (int) $row['count'];
        }
        return $counts;
    }

    public function allExternalIds()
    {
        $rows = $this->db->fetchAll(
            $this->db->select('external_id')->from(self::TABLE)
                ->where('site_id = ?', $this->siteId)
                ->where('sync_status = ?', 'synced')
        );
        $ids = array();
        foreach ($rows as $row) {
            $ids[$row['external_id']] = true;
        }
        return $ids;
    }

    private function upsert($postId, array $mapping, $incrementAttempts)
    {
        $existing = $this->db->fetchRow(
            $this->db->select('id', 'attempts')->from(self::TABLE)
                ->where('site_id = ?', $this->siteId)
                ->where('post_id = ?', (int) $postId)
                ->where('chunk_key = ?', (string) $mapping['chunk_key'])
                ->limit(1)
        );
        $now = time();
        $status = isset($mapping['sync_status']) ? $mapping['sync_status'] : 'synced';
        $row = array(
            'site_id' => $this->siteId,
            'post_id' => (int) $postId,
            'chunk_key' => (string) $mapping['chunk_key'],
            'external_id' => (string) $mapping['external_id'],
            'entry_id' => !empty($mapping['entry_id']) ? (string) $mapping['entry_id'] : null,
            'content_hash' => isset($mapping['content_hash']) ? (string) $mapping['content_hash'] : '',
            'sync_status' => $status,
            'attempts' => $incrementAttempts ? ((int) ($existing ? $existing['attempts'] : 0) + 1) : 0,
            'last_error_code' => isset($mapping['last_error_code']) ? $mapping['last_error_code'] : null,
            'last_error_message' => isset($mapping['last_error_message']) ? $mapping['last_error_message'] : null,
            'last_attempt_at' => $now,
            'last_success_at' => $status === 'synced' ? $now : 0
        );
        if ($existing) {
            $this->db->query($this->db->update(self::TABLE)->rows($row)->where('id = ?', (int) $existing['id']));
        } else {
            $this->db->query($this->db->insert(self::TABLE)->rows($row));
        }
    }
}
