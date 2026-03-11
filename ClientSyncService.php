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
        $this->serverUrl = rtrim(env('SYNC_SERVER_URL', ''), '\/');
        $this->apiToken = env('SYNC_API_TOKEN', '');

        if (empty($this->serverUrl) || empty($this->apiToken)) {
            throw new Exception('Sync configuration (URL or Token) is missing in .env');
        }
    }

    /**
     * Скачивает архив с файлами и распаковывает в корень проекта.
     */
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
                // Распаковка в корень проекта (base_path)
                $zip->extractTo(base_path());
                $zip->close();
            } else {
                throw new Exception('Failed to open ZIP archive.');
            }

            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            return ['status' => 'success', 'message' => 'Files synced successfully.'];

        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Получает схему БД и обновляет структуру локальных таблиц.
     */
    public function syncDatabase(array $targetTables = []): array
    {
        try {
            $payload = empty($targetTables) ? [] : ['tables' => $targetTables];

            $response = Http::withHeaders([
                'X-Sync-Token' => $this->apiToken,
            ])->withoutVerifying()->post($this->serverUrl . '/api/sync/get-schema', $payload);

            if ($response->failed()) {
                throw new Exception('Failed to fetch schema: ' . $response->body());
            }

            $remoteSchema = $response->json();
            $log = [];

            foreach ($remoteSchema as $tableName => $columns) {
                if (!Schema::hasTable($tableName)) {
                    // Создание таблицы, если её нет
                    Schema::create($tableName, function (Blueprint $table) use ($columns, &$log, $tableName) {
                        foreach ($columns as $name => $type) {
                            $this->addColumn($table, $name, $type);
                        }
                        $log[] = "Created table: {$tableName}";
                    });
                } else {
                    // Обновление таблицы (добавление новых колонок или изменение существующих)
                    Schema::table($tableName, function (Blueprint $table) use ($columns, $tableName, &$log) {
                        foreach ($columns as $name => $type) {
                            if (!Schema::hasColumn($tableName, $name)) {
                                $this->addColumn($table, $name, $type);
                                $log[] = "Added column {$name} ({$type}) to {$tableName}";
                            } else {
                                $localType = Schema::getColumnType($tableName, $name);
                                if ($this->normalizeType($localType) !== $this->normalizeType($type)) {
                                    $this->addColumn($table, $name, $type, true);
                                    $log[] = "Changed column {$name} to {$type} in {$tableName}";
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

    /**
     * Получает данные БД и обновляет/создает записи.
     */
    public function syncData(array $targetTables = []): array
    {
        try {
            $payload = empty($targetTables) ? [] : ['tables' => $targetTables];

            $response = Http::withHeaders([
                'X-Sync-Token' => $this->apiToken,
            ])->withoutVerifying()->post($this->serverUrl . '/api/sync/get-data', $payload);

            if ($response->failed()) {
                throw new Exception('Failed to fetch data: ' . $response->body());
            }

            $remoteData = $response->json();
            $log = [];

            foreach ($remoteData as $tableName => $rows) {
                if (!Schema::hasTable($tableName)) {
                    $log[] = "Table {$tableName} does not exist locally. Skipping data sync.";
                    continue;
                }

                foreach ($rows as $row) {
                    $rowArray = (array) $row;
                    if (isset($rowArray['id'])) {
                        DB::table($tableName)->updateOrInsert(
                            ['id' => $rowArray['id']],
                            $rowArray
                        );
                    }
                }
                $log[] = "Synced data for table: {$tableName}";
            }

            return ['status' => 'success', 'log' => $log];

        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Нормализует тип колонки для сравнения.
     */
    protected function normalizeType(string $type): string
    {
        $type = strtolower(preg_replace('/\(.*\)/', '', $type));
        if ($type === 'integer') return 'int';
        if ($type === 'string') return 'varchar';
        return $type;
    }

    /**
     * Хелпер для маппинга типов данных.
     */
    protected function addColumn(Blueprint $table, string $name, string $type, bool $change = false): void
    {
        // Если это id, сразу делаем его первичным ключом для правильной структуры
        if ($name === 'id' && !$change) {
            $table->id();
            return;
        }

        $baseType = strtolower(preg_replace('/\(.*\)/', '', $type));
        $column = null;

        switch ($baseType) {
            case 'int':
            case 'integer':
                $column = $table->integer($name)->nullable();
                break;
            case 'bigint':
                $column = $table->bigInteger($name)->nullable();
                break;
            case 'tinyint':
            case 'boolean':
                $column = $table->boolean($name)->nullable();
                break;
            case 'varchar':
            case 'string':
                preg_match('/\((\d+)\)/', $type, $matches);
                $length = $matches[1] ?? 255;
                $column = $table->string($name, (int)$length)->nullable();
                break;
            case 'text':
                $column = $table->text($name)->nullable();
                break;
            case 'mediumtext':
                $column = $table->mediumText($name)->nullable();
                break;
            case 'longtext':
                $column = $table->longText($name)->nullable();
                break;
            case 'date':
                $column = $table->date($name)->nullable();
                break;
            case 'datetime':
                $column = $table->dateTime($name)->nullable();
                break;
            case 'timestamp':
                $column = $table->timestamp($name)->nullable();
                break;
            case 'float':
                $column = $table->float($name)->nullable();
                break;
            case 'decimal':
                $column = $table->decimal($name, 10, 2)->nullable();
                break;
            case 'json':
                $column = $table->json($name)->nullable();
                break;
            default:
                $column = $table->string($name)->nullable();
                break;
        }

        if ($change && $column) {
            $column->change();
        }
    }
}
