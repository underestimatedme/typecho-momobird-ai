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
        $adapterName = $db->getAdapterName();
        $prefix = $db->getPrefix();
        $sql = self::schemaSql($adapterName, $prefix);
        $writeMode = defined('Typecho_Db::WRITE') ? constant('Typecho_Db::WRITE') : 2;
        $db->query($sql, $writeMode);
        if (strpos(strtolower((string) $adapterName), 'sqlite') !== false) {
            $table = $prefix . 'momobird_sync';
            $db->query("CREATE INDEX IF NOT EXISTS {$prefix}momobird_status_idx ON {$table} (site_id, sync_status)", $writeMode);
            $db->query("CREATE INDEX IF NOT EXISTS {$prefix}momobird_post_idx ON {$table} (site_id, post_id)", $writeMode);
        }
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

    public function failed($afterId = 0, $limit = 200)
    {
        return $this->db->fetchAll(
            $this->db->select()->from(self::TABLE)
                ->where('site_id = ?', $this->siteId)
                ->where('post_id > ?', 0)
                ->where('id > ?', max(0, (int) $afterId))
                ->where('sync_status <> ?', 'synced')
                ->order('id', 'ASC')
                ->limit(min(200, max(1, (int) $limit)))
        );
    }

    public function counts()
    {
        $rows = $this->db->fetchAll(
            $this->db->select('sync_status', 'COUNT(*) AS count')->from(self::TABLE)
                ->where('site_id = ?', $this->siteId)
                ->where('post_id > ?', 0)
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
                ->where('post_id > ?', 0)
                ->where('sync_status = ?', 'synced')
        );
        $ids = array();
        foreach ($rows as $row) {
            $ids[$row['external_id']] = true;
        }
        return $ids;
    }

    public function removeFailedByExternalId($externalId)
    {
        $this->db->query(
            $this->db->delete(self::TABLE)
                ->where('site_id = ?', $this->siteId)
                ->where('external_id = ?', (string) $externalId)
                ->where('sync_status <> ?', 'synced')
        );
    }

    public function syncedPostCount()
    {
        $row = $this->db->fetchRow(
            $this->db->select('COUNT(DISTINCT post_id) AS count')->from(self::TABLE)
                ->where('site_id = ?', $this->siteId)
                ->where('post_id > ?', 0)
                ->where('sync_status = ?', 'synced')
        );
        return is_array($row) && isset($row['count']) ? (int) $row['count'] : 0;
    }

    public function lastFullSyncAt()
    {
        $row = $this->db->fetchRow(
            $this->db->select('last_success_at')->from(self::TABLE)
                ->where('site_id = ?', $this->siteId)
                ->where('post_id = ?', 0)
                ->where('chunk_key = ?', '_last_full_sync')
                ->limit(1)
        );
        return is_array($row) && isset($row['last_success_at']) ? (int) $row['last_success_at'] : 0;
    }

    public function recentError()
    {
        $row = $this->db->fetchRow(
            $this->db->select('last_error_code', 'last_error_message', 'last_attempt_at')->from(self::TABLE)
                ->where('site_id = ?', $this->siteId)
                ->where('post_id > ?', 0)
                ->where('sync_status <> ?', 'synced')
                ->order('last_attempt_at', 'DESC')
                ->limit(1)
        );
        if (!is_array($row)) {
            return null;
        }
        return array(
            'code' => isset($row['last_error_code']) ? (string) $row['last_error_code'] : 'sync_error',
            'message' => isset($row['last_error_message']) ? (string) $row['last_error_message'] : '',
            'at' => isset($row['last_attempt_at']) ? (int) $row['last_attempt_at'] : 0
        );
    }

    public function startRun($token)
    {
        $this->upsert(0, array(
            'chunk_key' => '_run',
            'external_id' => (string) $token,
            'entry_id' => null,
            'content_hash' => '0',
            'sync_status' => 'running'
        ), false);
    }

    public function expectedRunCursor($token)
    {
        if (!$this->runIsActive($token)) {
            throw new RuntimeException('Full sync run is invalid or expired');
        }
        $row = $this->runRow();
        $cursor = isset($row['content_hash']) ? (string) $row['content_hash'] : '';
        if (!ctype_digit($cursor)) {
            throw new RuntimeException('Full sync cursor state is invalid');
        }
        return (int) $cursor;
    }

    public function advanceRun($token, $expected, $next)
    {
        if ($this->expectedRunCursor($token) !== (int) $expected || (int) $next < (int) $expected) {
            throw new RuntimeException('Full sync cursor is out of sequence');
        }
        $row = $this->runRow();
        $row['content_hash'] = (string) (int) $next;
        $this->upsert(0, $row, false);
    }

    public function runIsActive($token)
    {
        $row = $this->runRow();
        return is_array($row)
            && in_array($row['sync_status'], array('running', 'cleanup_allowed'), true)
            && (int) $row['last_attempt_at'] >= time() - 7200
            && hash_equals((string) $row['external_id'], (string) $token);
    }

    public function allowCleanup($token, $expectedCursor)
    {
        if ($this->expectedRunCursor($token) !== (int) $expectedCursor) {
            throw new RuntimeException('Full sync run is invalid or expired');
        }
        $row = $this->runRow();
        $row['sync_status'] = 'cleanup_allowed';
        $this->upsert(0, $row, false);
    }

    public function cleanupIsAllowed($token)
    {
        $row = $this->runRow();
        return $this->runIsActive($token) && $row['sync_status'] === 'cleanup_allowed';
    }

    public function finishRun($token)
    {
        if (!$this->cleanupIsAllowed($token)) {
            throw new RuntimeException('Cleanup is not authorized for this run');
        }
        $this->upsert(0, array(
            'chunk_key' => '_last_full_sync',
            'external_id' => 'last-full-sync',
            'entry_id' => null,
            'content_hash' => '',
            'sync_status' => 'synced'
        ), false);
        $this->remove(0, '_run');
    }

    private function runRow()
    {
        return $this->db->fetchRow(
            $this->db->select()->from(self::TABLE)
                ->where('site_id = ?', $this->siteId)
                ->where('post_id = ?', 0)
                ->where('chunk_key = ?', '_run')
                ->limit(1)
        );
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
