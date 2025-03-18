<?php

namespace Shah\LaravelUpdater\Classes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Exception;
use Shah\LaravelUpdater\Traits\Loggable;

class DatabaseBackup
{ 
    use Loggable;

    public function backup(string $backupDir): string|false
    {
        try {
            // Create backup directory if it doesn't exist
            if (!File::isDirectory($backupDir)) {
                File::makeDirectory($backupDir, 0755, true);
            }

            $filename = 'database_backup_' . date('Ymd_His') . '.json';
            $path = $backupDir . '/' . $filename;

            // Get database configuration
            $connection = config('database.default');
            $driver = config("database.connections.{$connection}.driver");

            $data = [];

            // Get all tables
            $tables = DB::select('SHOW TABLES');
            $tableKey = 'Tables_in_' . config("database.connections.{$connection}.database");

            foreach ($tables as $table) {
                $tableName = $table->$tableKey;

                // Get table structure
                $structure = DB::select("SHOW CREATE TABLE `{$tableName}`");
                $structureKey = 'Create Table';
                $data[$tableName]['structure'] = $structure[0]->$structureKey;

                // Get table data
                $rows = DB::table($tableName)->get();
                $data[$tableName]['data'] = $rows;
            }

            // Save to JSON file
            File::put($path, json_encode($data, JSON_PRETTY_PRINT));

            $this->log('Database backed up to: ' . $path, 'info');
            return $path;
        } catch (Exception $e) {
            $this->log('Error backing up database: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    public function restore(string $path): bool
    {
        try {
            if (!File::exists($path)) {
                throw new Exception("Backup file not found: {$path}");
            }

            // Get database configuration
            $connection = config('database.default');
            $driver = config("database.connections.{$connection}.driver");

            // Load backup data
            $content = File::get($path);
            $data = json_decode($content, true);

            if (!$data) {
                throw new Exception("Invalid backup file format");
            }

            // Disable foreign key checks for the restore process
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            foreach ($data as $tableName => $tableData) {
                // Drop table if it exists
                Schema::dropIfExists($tableName);

                // Create table
                DB::statement($tableData['structure']);

                // Insert data in batches
                $rows = $tableData['data'];
                if (count($rows) > 0) {
                    $chunks = array_chunk($rows, 100);
                    foreach ($chunks as $chunk) {
                        DB::table($tableName)->insert((array) $chunk);
                    }
                }
            }

            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            $this->log('Database restored from: ' . $path, 'info');
            return true;
        } catch (Exception $e) {
            $this->log('Error restoring database: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Create a backup using Laravel's Schema and DB facades
     * This method supports multiple database drivers
     */
    public function backupAdvanced(string $backupDir): string|false
    {
        try {
            // Create backup directory if it doesn't exist
            if (!File::isDirectory($backupDir)) {
                File::makeDirectory($backupDir, 0755, true);
            }

            $filename = 'database_backup_' . date('Ymd_His') . '.json';
            $path = $backupDir . '/' . $filename;

            // Get database configuration
            $connection = config('database.default');
            $driver = config("database.connections.{$connection}.driver");

            $data = [
                'metadata' => [
                    'driver' => $driver,
                    'created_at' => now()->toDateTimeString(),
                    'version' => '1.0'
                ],
                'tables' => []
            ];

            // Get all tables
            $tables = [];

            if ($driver === 'mysql') {
                $tables = DB::select('SHOW TABLES');
                $tableKey = 'Tables_in_' . config("database.connections.{$connection}.database");
                $tables = array_map(function ($table) use ($tableKey) {
                    return $table->$tableKey;
                }, $tables);
            } elseif ($driver === 'pgsql') {
                $tableResults = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
                $tables = array_map(function ($table) {
                    return $table->table_name;
                }, $tableResults);
            } elseif ($driver === 'sqlite') {
                $tableResults = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                $tables = array_map(function ($table) {
                    return $table->name;
                }, $tableResults);
            }

            foreach ($tables as $tableName) {
                // Get table structure using Laravel's Schema
                $columns = Schema::getColumnListing($tableName);
                $columnData = [];

                foreach ($columns as $column) {
                    $type = DB::connection()->getDoctrineColumn($tableName, $column)->getType()->getName();
                    $columnData[$column] = [
                        'type' => $type,
                        'nullable' => DB::connection()->getDoctrineColumn($tableName, $column)->getNotnull() ? false : true,
                    ];
                }

                $data['tables'][$tableName] = [
                    'columns' => $columnData,
                    'records' => DB::table($tableName)->get()->toArray()
                ];
            }

            // Save to JSON file
            File::put($path, json_encode($data, JSON_PRETTY_PRINT));

            $this->log('Database backed up to: ' . $path, 'info');
            return $path;
        } catch (Exception $e) {
            $this->log('Error backing up database: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Restore a backup created with the backupAdvanced method
     */
    public function restoreAdvanced(string $path): bool
    {
        try {
            if (!File::exists($path)) {
                throw new Exception("Backup file not found: {$path}");
            }

            // Load backup data
            $content = File::get($path);
            $data = json_decode($content, true);

            if (!$data || !isset($data['tables']) || !isset($data['metadata'])) {
                throw new Exception("Invalid backup file format");
            }

            // Disable foreign key checks for the restore process
            if ($data['metadata']['driver'] === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
            } elseif ($data['metadata']['driver'] === 'pgsql') {
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
            }

            foreach ($data['tables'] as $tableName => $tableData) {
                // Drop table if it exists
                Schema::dropIfExists($tableName);

                // Create table
                Schema::create($tableName, function ($table) use ($tableData) {
                    foreach ($tableData['columns'] as $column => $attributes) {
                        // Map Doctrine types to Laravel's Schema types
                        $type = $this->mapColumnType($attributes['type']);

                        if ($type) {
                            $tableColumn = $table->$type($column);

                            if ($attributes['nullable']) {
                                $tableColumn->nullable();
                            }
                        }
                    }
                });

                // Insert data in batches
                $records = $tableData['records'];
                if (count($records) > 0) {
                    $chunks = array_chunk($records, 100);
                    foreach ($chunks as $chunk) {
                        DB::table($tableName)->insert($chunk);
                    }
                }
            }

            // Re-enable foreign key checks
            if ($data['metadata']['driver'] === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            } elseif ($data['metadata']['driver'] === 'pgsql') {
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            }

            $this->log('Database restored from: ' . $path, 'info');
            return true;
        } catch (Exception $e) {
            $this->log('Error restoring database: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Map Doctrine column types to Laravel Schema types
     */
    private function mapColumnType(string $doctrineType): string
    {
        $map = [
            'string' => 'string',
            'text' => 'text',
            'integer' => 'integer',
            'smallint' => 'smallInteger',
            'bigint' => 'bigInteger',
            'boolean' => 'boolean',
            'decimal' => 'decimal',
            'float' => 'float',
            'date' => 'date',
            'datetime' => 'dateTime',
            'time' => 'time',
            'json' => 'json',
        ];

        return $map[$doctrineType] ?? 'string';
    }
}
