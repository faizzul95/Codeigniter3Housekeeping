<?php

namespace OnlyPHP\Codeigniter3Housekeeping;

use InvalidArgumentException;
use RuntimeException;
use Exception;

/**
 * Database Archiver Class for CodeIgniter 3
 * 
 * This class provides database archiving functionality with support for
 * backup, purge, and combined operations.
 */
class DatabaseArchiver
{
    // Constants for operation modes
    public const MODE_BACKUP_ONLY = 'BO';
    public const MODE_PURGE_ONLY = 'PO';
    public const MODE_BACKUP_PURGE = 'BP';

    // Constants for logging levels
    private const LOG_LEVEL_INFO = 'INFO';
    private const LOG_LEVEL_ERROR = 'ERROR';
    private const LOG_LEVEL_WARNING = 'WARNING';
    private const LOG_LEVEL_DEBUG = 'DEBUG';

    // Database drivers
    public const DRIVER_MYSQL = 'mysql';
    public const DRIVER_ORACLE = 'oci';

    // Configuration properties
    private $CI;
    private $connection;
    private $driver;
    private $originalTable;
    private $archiveTable;
    private $primaryKey;
    private $whereClause;
    private $mode;
    private $chunkSize = 1000;
    private $parallelThreads = 1;
    private $logPath;
    private $sqlHint = '';
    private $uniqueColumns = [];
    private $primaryKeyRange = null;
    private $debug = false;
    private $memoryLimit;
    private $startMemory;

    /**
     * Constructor for Database_archiver
     * 
     * @throws InvalidArgumentException If database connection is invalid
     */
    public function __construct()
    {
        $this->CI = &get_instance();
        $this->CI->load->database();

        $this->connection = $this->CI->db;

        if (!$this->isValidConnection($this->connection)) {
            throw new InvalidArgumentException("Invalid database connection provided");
        }

        $this->logPath = APPPATH . 'logs/archive_' . date('Y-m-d') . '.log';
        $this->memoryLimit = $this->getMemoryLimitInBytes();
        $this->startMemory = memory_get_usage(true);
    }

    /**
     * Validate database connection object
     * 
     * @param object $connection Database connection object
     * @return bool Whether the connection is valid
     */
    private function isValidConnection($connection): bool
    {
        return is_object($connection) && method_exists($connection, 'query') && method_exists($connection, 'simple_query');
    }

    /**
     * Set database driver
     * 
     * @param string $driver Database driver name
     * @return self
     * @throws InvalidArgumentException If driver is not supported
     */
    public function driver($driver)
    {
        $supportedDrivers = [self::DRIVER_MYSQL, self::DRIVER_ORACLE];
        $driver = strtolower($driver);

        if (!in_array($driver, $supportedDrivers, true)) {
            throw new InvalidArgumentException("Unsupported database driver: {$driver}");
        }

        $this->driver = $driver;
        return $this;
    }

    /**
     * Set source and archive table names
     * 
     * @param string $table Source table name
     * @return self
     * @throws InvalidArgumentException If table name is invalid
     */
    public function backupFrom($table)
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new InvalidArgumentException("Invalid source table name format");
        }

        $this->originalTable = $table;
        $this->archiveTable = $table . '_ARC';
        return $this;
    }

    /**
     * Set custom target table names
     * 
     * @param string $table target table name
     * @return self
     * @throws InvalidArgumentException If table name is invalid
     */
    public function backupTo($table)
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new InvalidArgumentException("Invalid target table name format");
        }

        $this->archiveTable = $table;
        return $this;
    }

    /**
     * Set columns that should be unique in archive table
     */
    public function uniqueColumns($columns)
    {
        foreach ($columns as $key => $column) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
                throw new InvalidArgumentException("Invalid column name: {$column}");
            }

            $columns[$key] = trim($column);
        }

        $this->uniqueColumns = $columns;
        return $this;
    }

    /**
     * Set primary key column
     * 
     * @param string $primaryKey Primary key column name
     * @return self
     * @throws InvalidArgumentException If primary key is invalid
     */
    public function primaryKey($primaryKey)
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $primaryKey)) {
            throw new InvalidArgumentException("Invalid primary key name");
        }

        $this->primaryKey = $primaryKey;
        return $this;
    }

    /**
     * Set archiving condition
     * 
     * @param string $whereClause SQL WHERE clause for record selection
     * @return self
     * @throws InvalidArgumentException If where clause is empty
     */
    public function condition($whereClause)
    {
        if (empty(trim($whereClause))) {
            throw new InvalidArgumentException("WHERE clause cannot be empty");
        }

        $this->whereClause = $whereClause;
        return $this;
    }

    /**
     * Set archiving mode
     * 
     * @param string $mode Archiving mode (BO, PO, BP)
     * @return self
     * @throws InvalidArgumentException If mode is invalid
     */
    public function mode($mode)
    {
        $validModes = [self::MODE_BACKUP_ONLY, self::MODE_PURGE_ONLY, self::MODE_BACKUP_PURGE];

        if (!in_array($mode, $validModes, true)) {
            throw new InvalidArgumentException("Invalid mode. Use 'BO', 'PO', or 'BP'");
        }

        $this->mode = $mode;
        return $this;
    }

    /**
     * Set debug mode
     * 
     * @param bool $mode Debug mode
     * @return self
     */
    public function onDebug()
    {
        $this->debug = true;
        return $this;
    }

    /**
     * Set chunk processing size
     * 
     * @param int $size Number of records to process in each chunk
     * @return self
     */
    public function chunk($size)
    {
        $this->chunkSize = max(100, min($size, 50000));
        return $this;
    }

    /**
     * Set parallel processing threads
     * 
     * @param int $threads Number of parallel threads
     * @return self
     */
    public function parallel($threads)
    {
        $this->parallelThreads = max(1, min($threads, 16));
        return $this;
    }

    /**
     * Set custom log file path
     * 
     * @param string $path Log file path
     * @return self
     * @throws RuntimeException If log directory cannot be created
     */
    public function logPath($path)
    {
        $directory = dirname($path);

        if (!file_exists($directory)) {
            if (!mkdir($directory, 0777, true)) {
                throw new RuntimeException("Unable to create log directory: {$directory}");
            }
        }

        if (!is_writable($directory)) {
            throw new RuntimeException("Log directory is not writable: {$directory}");
        }

        $this->logPath = $path;
        return $this;
    }

    /**
     * Set SQL query hint
     * 
     * @param string $hint SQL query optimization hint
     * @return self
     * @throws InvalidArgumentException If hint is empty
     */
    public function sqlHint($hint)
    {
        if (empty(trim($hint))) {
            throw new InvalidArgumentException("SQL hint cannot be empty");
        }

        $this->sqlHint = $hint;
        return $this;
    }

    /**
     * Run archiving process
     */
    public function run()
    {
        $startTime = microtime(true);
        $initialMemory = memory_get_usage(true);

        try {
            $this->validateConfiguration();
            $this->prepareArchiveTable();
            $this->determinePrimaryKeyRange();

            if ($this->primaryKeyRange['count'] === 0) {
                return $this->createEmptyResult($startTime, $initialMemory);
            }

            $processedCount = $this->isParallelSupported() && $this->parallelThreads > 1
                ? $this->runParallelArchiving()
                : $this->runSequentialArchiving();

            return $this->createArchivingResult($startTime, $processedCount, $this->primaryKeyRange['count'], $initialMemory);
        } catch (Exception $e) {
            $this->log("Archiving failed: " . $e->getMessage(), self::LOG_LEVEL_ERROR);
            throw new RuntimeException("Archiving failed: " . $e->getMessage(), 0, $e);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Determine the range of primary keys to be processed
     */
    private function determinePrimaryKeyRange()
    {
        $sql = "SELECT MIN({$this->primaryKey}) as min_id, MAX({$this->primaryKey}) as max_id, COUNT(*) as total_count
                FROM {$this->originalTable}
                WHERE {$this->whereClause}";

        if ($this->debug) {
            $this->log("Running this query for `determinePrimaryKeyRange` : {$sql}", self::LOG_LEVEL_DEBUG);
        }

        $query = $this->CI->db->query($sql);
        $result = $query->row_array();

        if ($this->debug) {
            $this->log("Check data result for `determinePrimaryKeyRange` : " . json_encode($result, JSON_PRETTY_PRINT), self::LOG_LEVEL_DEBUG);
        }

        $this->primaryKeyRange = [
            'min' => (int)$result['min_id'],
            'max' => (int)$result['max_id'],
            'count' => (int)$result['total_count']
        ];
    }

    /**
     * Run sequential archiving
     */
    private function runSequentialArchiving()
    {
        $processedCount = 0;
        $currentId = $this->primaryKeyRange['min'];
        $maxId = $this->primaryKeyRange['max'];

        while ($currentId <= $maxId) {
            $this->log("Processing sequential chunk starting from ID {$currentId}");

            $this->CI->db->trans_start();
            try {
                $processedInChunk = $this->processChunk($currentId, $this->chunkSize);
                $this->CI->db->trans_complete();

                if ($this->CI->db->trans_status() === FALSE) {
                    throw new RuntimeException("Transaction failed");
                }

                $processedCount += $processedInChunk;
            } catch (Exception $e) {
                $this->CI->db->trans_rollback();
                throw $e;
            }

            $currentId += $this->chunkSize;
        }

        return $processedCount;
    }

    /**
     * Run parallel archiving
     */
    private function runParallelArchiving()
    {
        if (!$this->isParallelSupported()) {
            $this->log("Parallel processing not supported. Falling back to sequential.", self::LOG_LEVEL_WARNING);
            return $this->runSequentialArchiving();
        }

        $range = $this->primaryKeyRange['max'] - $this->primaryKeyRange['min'] + 1;
        $chunkSize = (int)ceil($range / $this->parallelThreads);
        $processes = [];
        $processedCount = 0;

        for ($i = 0; $i < $this->parallelThreads; $i++) {
            $startId = $this->primaryKeyRange['min'] + ($i * $chunkSize);
            $endId = min($startId + $chunkSize - 1, $this->primaryKeyRange['max']);

            $pid = pcntl_fork();

            if ($pid == -1) {
                throw new RuntimeException("Could not fork process");
            }

            if ($pid) {
                // Parent process
                $processes[] = $pid;
            } else {
                // Child process
                try {
                    $count = $this->processIdRange($startId, $endId);
                    exit($count);
                } catch (Exception $e) {
                    $this->log("Child process error: " . $e->getMessage(), self::LOG_LEVEL_ERROR);
                    exit(0);
                }
            }
        }

        // Wait for all child processes
        foreach ($processes as $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
            $processedCount += pcntl_wexitstatus($status);
        }

        return $processedCount;
    }

    /**
     * Process a specific ID range
     */
    private function processIdRange($startId, $endId)
    {
        $processedCount = 0;
        $currentId = $startId;

        while ($currentId <= $endId) {
            $this->CI->db->trans_start();
            try {
                $processedInChunk = $this->processChunk($currentId, $this->chunkSize);
                $this->CI->db->trans_complete();

                if ($this->CI->db->trans_status() === FALSE) {
                    throw new RuntimeException("Transaction failed");
                }

                $processedCount += $processedInChunk;
            } catch (Exception $e) {
                $this->CI->db->trans_rollback();
                throw $e;
            }
            $currentId += $this->chunkSize;
        }

        return $processedCount;
    }

    /**
     * Process a chunk of records
     */
    private function processChunk($startId, $chunkSize)
    {
        $this->checkMemoryUsage();

        $endId = $startId + $chunkSize - 1;
        $idRangeCondition = "{$this->primaryKey} BETWEEN {$startId} AND {$endId}";

        $processedCount = 0;

        // BACKUP OPERATION
        if (in_array($this->mode, [self::MODE_BACKUP_ONLY, self::MODE_BACKUP_PURGE])) {
            $processedCount = $this->backupOperation($idRangeCondition);
        }

        // PURGE OPERATION
        if (in_array($this->mode, [self::MODE_PURGE_ONLY, self::MODE_BACKUP_PURGE])) {
            $this->purgeOperation($idRangeCondition);
        }

        // Free result sets
        $this->CI->db->free_result();

        return $processedCount;
    }

    /**
     * Process backup a chunk of records
     */
    private function backupOperation($idRangeCondition)
    {
        $uniqueCondition = '';
        if (!empty($this->uniqueColumns)) {
            $uniqueCondition = ' AND NOT EXISTS (
                SELECT 1 FROM ' . $this->archiveTable . ' arc WHERE ';
            $conditions = [];
            foreach ($this->uniqueColumns as $column) {
                $conditions[] = "arc.{$column} = o.{$column}";
            }
            $uniqueCondition .= implode(' AND ', $conditions) . ')';
        }

        $backupSql = "INSERT INTO {$this->archiveTable} 
                     SELECT {$this->sqlHint} o.* 
                     FROM {$this->originalTable} o 
                     WHERE {$idRangeCondition}
                     AND ({$this->whereClause})
                     {$uniqueCondition}";

        if ($this->debug) {
            $this->log("Running this query for `processChunk` (backup) : {$backupSql}", self::LOG_LEVEL_DEBUG);
        }

        $this->CI->db->query($backupSql);
        return $this->CI->db->affected_rows();
    }

    /**
     * Process purge/delete a chunk of records
     */
    private function purgeOperation($idRangeCondition)
    {
        $purgeSql = "DELETE FROM {$this->originalTable}
        WHERE {$idRangeCondition}
        AND ({$this->whereClause})";

        if ($this->debug) {
            $this->log("Running this query for `processChunk` (purge) : {$purgeSql}", self::LOG_LEVEL_DEBUG);
        }

        $this->CI->db->query($purgeSql);
    }

    /**
     * Check if parallel processing is supported
     */
    private function isParallelSupported()
    {
        return (PHP_OS_FAMILY !== 'Windows') && function_exists('pcntl_fork') && function_exists('posix_getpid');
    }

    /**
     * Validate configuration requirements
     */
    private function validateConfiguration()
    {
        $requiredFields = [
            'driver' => $this->driver ?? '',
            'originalTable' => $this->originalTable ?? '',
            'primaryKey' => $this->primaryKey ?? '',
            'whereClause' => $this->whereClause ?? '',
            'mode' => $this->mode ?? ''
        ];

        foreach ($requiredFields as $field => $value) {
            if (empty($value)) {
                throw new RuntimeException("Configuration error: {$field} must be specified");
            }
        }
    }

    /**
     * Prepare archive table structure
     */
    private function prepareArchiveTable()
    {
        try {
            $this->CI->db->query("SELECT 1 FROM {$this->archiveTable} WHERE 1=0");
        } catch (Exception $e) {
            $createTableSql = match ($this->driver) {
                self::DRIVER_MYSQL => "CREATE TABLE IF NOT EXISTS {$this->archiveTable} LIKE {$this->originalTable}",
                self::DRIVER_ORACLE => "CREATE TABLE {$this->archiveTable} AS SELECT * FROM {$this->originalTable} WHERE 1=2",
                default => throw new RuntimeException("Unsupported database driver for table creation")
            };

            if ($this->debug) {
                $this->log("Running this query for `prepareArchiveTable` function : {$createTableSql}", self::LOG_LEVEL_DEBUG);
            }

            $this->CI->db->query($createTableSql);
            $this->log("Created archive table: {$this->archiveTable}");
        }
    }

    /**
     * Create empty result array
     */
    private function createEmptyResult($startTime)
    {
        $result = [
            'status' => 'completed',
            'processed' => 0,
            'total' => 0,
            'execution_time' => $this->formatTime(microtime(true) - $startTime)
        ];

        $this->log("RESULT RUN() : " . json_encode($result, JSON_PRETTY_PRINT));

        return $result;
    }

    /**
     * Create archiving result array
     */
    private function createArchivingResult($startTime, $processedCount, $totalRecords, $initialMemory)
    {
        $endMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);

        $result = [
            'status' => 'completed',
            'processed' => $processedCount,
            'total' => $totalRecords,
            'execution_time' => $this->formatTime(microtime(true) - $startTime),
            'threads' => $this->parallelThreads,
            'memory' => [
                'initial' => $this->formatBytes($initialMemory),
                'final' => $this->formatBytes($endMemory),
                'peak' => $this->formatBytes($peakMemory),
                'used' => $this->formatBytes($endMemory - $initialMemory)
            ]
        ];

        $this->log("RESULT RUN() : " . json_encode($result, JSON_PRETTY_PRINT));
        return $result;
    }

    /**
     * Format execution time
     */
    private function formatTime($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = floor($seconds % 60);
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    /**
     * Logging method
     */
    public function log($message, $level = self::LOG_LEVEL_INFO)
    {
        $timestamp = date('Y-m-d H:i:s');
        $pid = $this->isParallelSupported() ? posix_getpid() : 'main';
        $logMessage = "[{$timestamp}] [{$level}] [PID:{$pid}] {$message}" . PHP_EOL;

        file_put_contents($this->logPath, $logMessage, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get the current progress of the archiving process
     */
    public function getProgress()
    {
        if (!$this->primaryKeyRange) {
            return ['status' => 'not_started'];
        }

        $sql = "SELECT COUNT(*) as archived_count 
                FROM {$this->archiveTable} 
                WHERE {$this->primaryKey} BETWEEN ? AND ?";

        $query = $this->CI->db->query($sql, [
            $this->primaryKeyRange['min'],
            $this->primaryKeyRange['max']
        ]);

        $result = $query->row();
        $archivedCount = (int)$result->archived_count;

        return [
            'status' => 'in_progress',
            'total_records' => $this->primaryKeyRange['count'],
            'archived_records' => $archivedCount,
            'percentage_complete' => $this->primaryKeyRange['count'] > 0
                ? round(($archivedCount / $this->primaryKeyRange['count']) * 100, 2)
                : 0
        ];
    }

    /**
     * Verify archive integrity
     */
    public function verifyArchive()
    {
        if (!$this->primaryKeyRange) {
            return ['status' => 'not_archived'];
        }

        $originalCount = $this->primaryKeyRange['count'];

        $sql = "SELECT COUNT(*) as archived_count 
                FROM {$this->archiveTable} 
                WHERE {$this->primaryKey} BETWEEN ? AND ?";

        $query = $this->CI->db->query($sql, [
            $this->primaryKeyRange['min'],
            $this->primaryKeyRange['max']
        ]);

        $result = $query->row();
        $archivedCount = (int)$result->archived_count;

        // Check for duplicate records if unique columns are specified
        $duplicateCount = 0;
        if (!empty($this->uniqueColumns)) {
            $duplicateCheckSql = "SELECT COUNT(*) - COUNT(DISTINCT " .
                implode(', ', $this->uniqueColumns) .
                ") as duplicate_count 
                FROM {$this->archiveTable}
                WHERE {$this->primaryKey} BETWEEN ? AND ?";

            $query = $this->CI->db->query($duplicateCheckSql, [
                $this->primaryKeyRange['min'],
                $this->primaryKeyRange['max']
            ]);
            $result = $query->row();
            $duplicateCount = (int)$result->duplicate_count;
        }

        return [
            'status' => 'verified',
            'original_count' => $originalCount,
            'archived_count' => $archivedCount,
            'duplicate_count' => $duplicateCount,
            'integrity_status' => $this->getIntegrityStatus($originalCount, $archivedCount, $duplicateCount)
        ];
    }

    /**
     * Get integrity status based on counts
     */
    private function getIntegrityStatus($originalCount, $archivedCount, $duplicateCount)
    {
        if ($duplicateCount > 0) {
            return 'warning_duplicates_found';
        }

        if ($this->mode === self::MODE_BACKUP_ONLY && $archivedCount < $originalCount) {
            return 'warning_incomplete_backup';
        }

        if ($archivedCount === $originalCount) {
            return 'ok_complete';
        }

        return 'warning_count_mismatch';
    }

    /**
     * Clean up resources and temporary data
     */
    public function cleanup()
    {
        // Reset internal state
        $this->primaryKeyRange = null;

        // Close database connection
        $this->CI->db->close();

        // Collect garbage
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        // Optionally rotate log file if it's too large
        if (file_exists($this->logPath) && filesize($this->logPath) > 50 * 1024 * 1024) { // 50MB
            rename($this->logPath, $this->logPath . '.' . date('Y-m-d-His'));
        }
    }

    // HELPER SECTION

    private function getMemoryLimitInBytes()
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int)substr($memoryLimit, 0, -1);

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => (int)$memoryLimit,
        };
    }

    private function checkMemoryUsage()
    {
        $currentMemory = memory_get_usage(true);
        $memoryUsed = $currentMemory - $this->startMemory;
        $memoryThreshold = $this->memoryLimit * 0.9; // 90% of memory limit

        if ($currentMemory > $memoryThreshold) {
            $this->log("High memory usage detected: " . $this->formatBytes($memoryUsed), self::LOG_LEVEL_WARNING);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
    }

    private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        return round($bytes, 2) . ' ' . $units[$index];
    }
}
