# 🗄️ CodeIgniter 3 Database Archiver

[![Latest Version on Packagist](https://img.shields.io/packagist/v/onlyphp/codeigniter3-housekeeping.svg)](https://packagist.org/packages/onlyphp/codeigniter3-housekeeping)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/onlyphp/codeigniter3-housekeeping.svg)](https://packagist.org/packages/onlyphp/codeigniter3-housekeeping)

A powerful and flexible database archiving solution for CodeIgniter 3 applications. This package helps you manage database growth by providing tools to archive and purge data while maintaining data integrity.

## ⚠️ Warning

**DO NOT USE THIS PACKAGE IN PRODUCTION**

This package is under active development and may contain critical bugs. It is primarily intended for personal use and testing. The current version has not undergone rigorous testing and may be unstable.

## 🚀 Features

- ✨ Backup and purge operations with transaction support
- 🔄 Parallel processing support for faster execution
- 📊 Progress tracking and integrity verification
- 📝 Detailed logging with customizable paths
- 🎯 Chunk-based processing to manage memory usage
- 🛡️ Support for unique constraints
- 🔌 Multiple database driver support (MySQL, Oracle)
- 🔍 Real-time progress monitoring
- 📈 Performance optimization options

## 💻 Requirements

- PHP >= 7.4
- CodeIgniter 3.x
- MySQL 5.7+ or Oracle 11g+
- PHP PCNTL extension (for parallel processing)
- Composer

## 📦 Installation

### Via Composer

```bash
composer require onlyphp/codeigniter3-housekeeping
```

### Manual Installation

1. Download the package
2. Extract to your application/libraries folder
3. Add to your autoload.php:

```php
$autoload['libraries'] = array('DatabaseArchiver');
```

## 🎓 Basic Concepts

### Operation Modes

- **Backup Only (BO)**: Only copies data to archive table
- **Purge Only (PO)**: Only deletes data from source table
- **Backup & Purge (BP)**: Copies data then deletes from source

### Processing Methods

- **Sequential**: Single-threaded processing
- **Parallel**: Multi-threaded processing (Unix/Linux only)

## 🎯 Usage Examples

### Example 1: Basic Backup Operation

Archive records older than 6 months from the 'orders' table:

```php
$archiver = new DatabaseArchiver();
$result = $archiver
    ->driver('mysql')
    ->backupFrom('orders')
    ->primaryKey('order_id')
    ->condition('created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)')
    ->mode('BO')  // Backup Only
    ->run();

print_r($result);
```

### Example 2: Backup and Purge with Unique Constraints

Archive and delete completed orders while ensuring no duplicate order numbers:

```php
$archiver = new DatabaseArchiver();
$result = $archiver
    ->driver('mysql')
    ->backupFrom('orders')
    ->primaryKey('order_id')
    ->uniqueColumns(['order_number'])
    ->condition('status = "completed" AND created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)')
    ->mode('BP')  // Backup and Purge
    ->chunk(500)  // Process 500 records at a time
    ->run();

// Verify archive integrity
$verification = $archiver->verifyArchive();
print_r($verification);
```

### Example 3: Parallel Processing with Custom Archive Table

Archive large datasets using parallel processing:

```php
$archiver = new DatabaseArchiver();
$result = $archiver
    ->driver('mysql')
    ->backupFrom('transactions')
    ->backupTo('transactions_archive_2023')
    ->primaryKey('transaction_id')
    ->condition('YEAR(transaction_date) = 2023')
    ->mode('BO')
    ->parallel(4)  // Use 4 parallel processes
    ->chunk(1000)
    ->run();

// Monitor progress
$progress = $archiver->getProgress();
print_r($progress);
```

### Example 4: Oracle Database with Debug Mode

Archive data from an Oracle database with debug logging:

```php
$archiver = new DatabaseArchiver();
$result = $archiver
    ->driver('oci')
    ->backupFrom('EMPLOYEES')
    ->primaryKey('EMPLOYEE_ID')
    ->condition('TERMINATION_DATE IS NOT NULL')
    ->mode('BP')
    ->onDebug()  // Enable debug mode
    ->logPath('/custom/path/archive.log')
    ->sqlHint('/*+ PARALLEL(4) */')  // Oracle-specific hint
    ->run();
```

### Example 5: Purge-Only Operation with Memory Management

Delete old log entries without backing them up:

```php
$archiver = new DatabaseArchiver();
$result = $archiver
    ->driver('mysql')
    ->backupFrom('system_logs')
    ->primaryKey('log_id')
    ->condition('created_at < DATE_SUB(NOW(), INTERVAL 3 MONTH)')
    ->mode('PO')  // Purge Only
    ->chunk(200)  // Smaller chunks to manage memory
    ->run();
```

### Example 6: Interactive Archiving Process with Progress Monitoring

This example shows a complete archiving process with real-time progress monitoring and verification:

```php
class ArchiveManager {
    private $archiver;
    private $maxAttempts = 3;
    private $progressCheckInterval = 5; // seconds

    public function __construct() {
        $this->archiver = new DatabaseArchiver();
    }

    public function runArchiveProcess() {
        try {
            // Configure the archiver
            $this->archiver
                ->driver('mysql')
                ->backupFrom('orders')
                ->primaryKey('order_id')
                ->condition('created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)')
                ->mode('BP')
                ->chunk(1000)
                ->onDebug();

            // Start the archiving process
            $processId = uniqid('archive_');
            log_message('info', "Starting archive process: {$processId}");

            // Run the archiving process
            $result = $this->archiver->run();
            
            // Monitor progress until completion
            $this->monitorProgress();

            // Verify the archive
            $verification = $this->verifyArchiveWithRetry();

            return [
                'process_id' => $processId,
                'archive_result' => $result,
                'verification' => $verification
            ];

        } catch (Exception $e) {
            log_message('error', "Archive process failed: " . $e->getMessage());
            throw $e;
        }
    }

    private function monitorProgress() {
        $completed = false;
        $lastPercentage = 0;

        while (!$completed) {
            $progress = $this->archiver->getProgress();
            
            if ($progress['percentage_complete'] != $lastPercentage) {
                $this->displayProgress($progress);
                $lastPercentage = $progress['percentage_complete'];
            }

            if ($progress['percentage_complete'] >= 100) {
                $completed = true;
                echo "\nArchiving process completed!\n";
            } else {
                sleep($this->progressCheckInterval);
            }
        }
    }

    private function displayProgress($progress) {
        $barLength = 50;
        $completed = round(($progress['percentage_complete'] * $barLength) / 100);
        $remaining = $barLength - $completed;
        
        $progressBar = "[" . 
            str_repeat("=", $completed) . 
            ">" . 
            str_repeat(" ", $remaining) . 
            "]";

        echo sprintf(
            "\rProgress: %s %d%% (%d/%d records)", 
            $progressBar,
            $progress['percentage_complete'],
            $progress['archived_records'],
            $progress['total_records']
        );
    }

    private function verifyArchiveWithRetry() {
        $attempts = 0;
        $success = false;
        $lastError = null;

        while ($attempts < $this->maxAttempts && !$success) {
            try {
                $verification = $this->archiver->verifyArchive();
                
                if ($verification['integrity_status'] === 'ok_complete') {
                    return $verification;
                }
                
                // Handle different verification statuses
                switch ($verification['integrity_status']) {
                    case 'warning_duplicates_found':
                        throw new Exception("Duplicate records found in archive");
                    case 'warning_incomplete_backup':
                        throw new Exception("Incomplete backup detected");
                    case 'warning_count_mismatch':
                        throw new Exception("Record count mismatch");
                }

                $success = true;
                return $verification;

            } catch (Exception $e) {
                $lastError = $e;
                $attempts++;
                if ($attempts < $this->maxAttempts) {
                    sleep(5); // Wait before retry
                }
            }
        }

        if (!$success) {
            throw new Exception(
                "Archive verification failed after {$this->maxAttempts} attempts. " .
                "Last error: " . $lastError->getMessage()
            );
        }
    }
}

// Usage example:
try {
    $manager = new ArchiveManager();
    $result = $manager->runArchiveProcess();
    
    echo "\n\nArchive Process Summary:\n";
    echo "Process ID: {$result['process_id']}\n";
    echo "Records Processed: {$result['archive_result']['processed']}\n";
    echo "Execution Time: {$result['archive_result']['execution_time']}\n";
    echo "Memory Usage: {$result['archive_result']['memory']['used']}\n";
    echo "Verification Status: {$result['verification']['integrity_status']}\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

## ⚙️ Configuration Options

| Method                 | Description                                  |
| ---------------------- | -------------------------------------------- |
| `driver(string)`       | Set database driver ('mysql' or 'oci')       |
| `backupFrom(string)`   | Set source table name                        |
| `backupTo(string)`     | Set custom archive table name                |
| `primaryKey(string)`   | Set primary key column                       |
| `condition(string)`    | Set WHERE clause for record selection        |
| `mode(string)`         | Set operation mode ('BO', 'PO', or 'BP')     |
| `chunk(int)`           | Set chunk size (100-50000)                   |
| `parallel(int)`        | Set number of parallel threads (1-16)        |
| `uniqueColumns(array)` | Set columns that should be unique in archive |
| `sqlHint(string)`      | Set SQL optimization hint                    |
| `logPath(string)`      | Set custom log file path                     |
| `onDebug()`            | Enable debug mode                            |

## 🔍 Monitoring and Verification

The package provides methods to monitor and verify the archiving process:

```php
// Get current progress
$progress = $archiver->getProgress();

// Verify archive integrity
$verification = $archiver->verifyArchive();
```

## ⚠️ Important Notes

1. Always backup your database before running archiving operations
2. Test the archiving process on a subset of data first
3. Monitor system resources during parallel processing
4. Consider database load and peak hours when scheduling archiving tasks
5. Verify archive integrity after completion

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
