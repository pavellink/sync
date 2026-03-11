<?php

namespace App\Http\Setting;

use Exception;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class ClientSyncService
{
    protected string $serverUrl;
    protected string $apiToken;

    public function __construct()
    {
        $this->serverUrl = rtrim(env('SYNC_SERVER_URL', ''), '\\/');
        $this->apiToken = env('SYNC_API_TOKEN', '');

        if (empty($this->serverUrl) || empty($this->apiToken)) {
            throw new Exception('Sync configuration (URL or Token) is missing in .env');
        }
    }

    public function syncFiles(): array
    {
        try {
            $response = Http::withHeaders([
                'X-Sync-Token' => $this->apiToken,
            ])->withoutVerifying()->post($this->serverUrl . '/api/sync/download-all');

            if ($response->failed()) {
                throw new Exception('Download failed: ' . $response->body());
            }

            $tempPath = sys_get_temp_dir() . '/sync_update_' . uniqid() . '.zip';
            file_put_contents($tempPath, $response->body());

            $zip = new ZipArchive;
            if ($zip->open($tempPath) === true) {
                $zip->extractTo(base_path());
                $zip->close();
            } else {
                throw new Exception('Failed to open ZIP archive.');
            }

            if (file_exists($tempPath)) unlink($tempPath);

            return ['status' => 'success', 'message' => 'Files synced successfully.'];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function syncDatabase(array $targetTables = []): array
    {
        try {
            $response = Http::withHeaders(['X-Sync-Token' => $this->apiToken])
                ->withoutVerifying()
                ->post($this->serverUrl . '/api/sync/get-schema', empty($targetTables) ? [] : ['tables' => $targetTables]);

            if ($response->failed()) throw new Exception('Failed to fetch schema: ' . $response->body());

            $remoteSchema = $response->json();
            $log = [];

            foreach ($remoteSchema as $tableName => $columns) {
                if (!Schema::hasTable($tableName)) {
                    Schema::create($tableName, function (Blueprint $table) use ($columns, $tableName, &$log) {
                        foreach ($columns as $name => $type) {
                            $this->addColumn($table, $name, $type);
                        }
                        $log[] = "Created table: {$tableName}";
                    });
                } else {
                    Schema::table($tableName, function (Blueprint $table) use ($columns, $tableName, &$log) {
                        foreach ($columns as $name => $type) {
                            // КЛЮЧЕВОЕ ИСПРАВЛЕНИЕ: Мы никогда не меняем структуру ID в существующей таблице
                            if ($name === 'id') continue;

                            if (!Schema::hasColumn($tableName, $name)) {
                                $this->addColumn($table, $name, $type);
                                $log[] = "Added column {$name} to {$tableName}";
                            } else {
                                $localType = Schema::getColumnType($tableName, $name);
                                if ($this->normalizeType($localType) !== $this->normalizeType($type)) {
                                    $this->addColumn($table, $name, $type, true);
                                    $log[] = "Updated column {$name} in {$tableName} ({$localType} -> {$type})";
                                }
                            }
                        }
                    });
                }
            }
            return ['status' => 'success', 'log' => $log];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function syncData(array $targetTables = []): array
    {
        try {
            $response = Http::withHeaders(['X-Sync-Token' => $this->apiToken])
                ->withoutVerifying()
                ->post($this->serverUrl . '/api/sync/get-data', empty($targetTables) ? [] : ['tables' => $targetTables]);

            if ($response->failed()) throw new Exception('Failed to fetch data: ' . $response->body());

            $remoteData = $response->json();
            $log = [];

            foreach ($remoteData as $tableName => $rows) {
                if (!Schema::hasTable($tableName)) continue;

                foreach ($rows as $row) {
                    $rowArray = (array) $row;
                    if (isset($rowArray['id'])) {
                        DB::table($tableName)->updateOrInsert(['id' => $rowArray['id']], $rowArray);
                    }
                }
                $log[] = "Synced data for {$tableName}";
            }
            return ['status' => 'success', 'log' => $log];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected function normalizeType(string $type): string
    {
        $type = strtolower($type);
        $type = str_replace(['integer', 'string'], ['int', 'varchar'], $type);
        
        // Убираем длину (11) для интов, но оставляем для varchar, чтобы ловить расширение полей
        if (!str_contains($type, 'varchar')) {
            $type = preg_replace('/\(.*\)/', '', $type);
        }
        return trim($type);
    }

    protected function addColumn(Blueprint $table, string $name, string $type, bool $change = false): void
    {
        if ($name === 'id' && !$change) {
            $table->id();
            return;
        }

        $baseType = strtolower(preg_replace('/\(.*\)/', '', $type));
        $column = match ($baseType) {
            'int', 'integer' => $table->integer($name),
            'bigint'         => $table->bigInteger($name),
            'boolean', 'tinyint' => $table->boolean($name),
            'text'           => $table->text($name),
            'mediumtext'     => $table->mediumText($name),
            'longtext'       => $table->longText($name),
            'date'           => $table->date($name),
            'datetime'       => $table->dateTime($name),
            'timestamp'      => $table->timestamp($name),
            'json'           => $table->json($name),
            'varchar', 'string' => (function() use ($table, $name, $type) {
                preg_match('/\((\d+)\)/', $type, $m);
                return $table->string($name, (int)($m[1] ?? 255));
            })(),
            default          => $table->string($name),
        };

        if ($column && $name !== 'id') {
            $column->nullable();
            if ($change) $column->change();
        }
    }
}
