<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use ZipArchive;
use Exception;

/**
 * Сервис для синхронизации файлов и базы данных на клиенте (Сайт-А).
 */
class SyncService
{
    protected string $serverUrl;
    protected ?string $apiToken;

    public function __construct()
    {
        $this->serverUrl = rtrim(env('SYNC_SERVER_URL'), '/');
        $this->apiToken = env('SYNC_API_TOKEN');
    }

    /**
     * Скачивает архив с файлами и распаковывает в корень проекта.
     */
    public function syncFiles(): array
    {
        try {
            $response = Http::withHeaders([
                'X-Sync-Token' => $this->apiToken,
            ])->post($this->serverUrl . '/api/sync/download-all');

            if ($response->failed()) {
                return ['error' => 'Failed to download files: ' . $response->body()];
            }

            $tempPath = sys_get_temp_dir() . '/sync_update.zip';
            file_put_contents($tempPath, $response->body());

            $zip = new ZipArchive;
            if ($zip->open($tempPath) === true) {
                // Распаковка в корень проекта (base_path)
                $zip->extractTo(base_path());
                $zip->close();
                unlink($tempPath);
                return ['status' => 'success', 'message' => 'Files synced successfully'];
            } else {
                return ['error' => 'Failed to open ZIP archive'];
            }
        } catch (Exception $e) {
            return ['error' => 'Exception during file sync: ' . $e->getMessage()];
        }
    }

    /**
     * Синхронизирует структуру БД на основе схемы сервера.
     */
    public function syncDatabase(array $tables): array
    {
        try {
            $response = Http::withHeaders([
                'X-Sync-Token' => $this->apiToken,
            ])->post($this->serverUrl . '/api/sync/get-schema', [
                'tables' => $tables,
            ]);

            if ($response->failed()) {
                return ['error' => 'Failed to fetch schema: ' . $response->body()];
            }

            $remoteSchema = $response->json();
            $log = [];

            foreach ($remoteSchema as $tableName => $columns) {
                if (!Schema::hasTable($tableName)) {
                    // Создаем таблицу
                    Schema::create($tableName, function (Blueprint $table) use ($columns) {
                        foreach ($columns as $columnName => $type) {
                            $this->addColumn($table, $columnName, $type);
                        }
                    });
                    $log[] = "Created table: {$tableName}";
                } else {
                    // Добавляем отсутствующие колонки
                    Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns, &$log) {
                        foreach ($columns as $columnName => $type) {
                            if (!Schema::hasColumn($tableName, $columnName)) {
                                $this->addColumn($table, $columnName, $type);
                                $log[] = "Added column '{$columnName}' to '{$tableName}'";
                            }
                        }
                    });
                }
            }

            return ['status' => 'success', 'log' => $log];

        } catch (Exception $e) {
            return ['error' => 'Exception during DB sync: ' . $e->getMessage()];
        }
    }

    /**
     * Хелпер для маппинга типов и добавления колонок.
     */
    protected function addColumn(Blueprint $table, string $name, string $type): void
    {
        // Обработка первичного ключа для новых таблиц
        if ($name === 'id') {
            $table->id();
            return;
        }

        switch ($type) {
            case 'integer':
            case 'int':
                $table->integer($name)->nullable();
                break;
            case 'bigint':
                $table->bigInteger($name)->nullable();
                break;
            case 'string':
                $table->string($name)->nullable();
                break;
            case 'text':
                $table->text($name)->nullable();
                break;
            case 'boolean':
                $table->boolean($name)->nullable();
                break;
            case 'datetime':
                $table->dateTime($name)->nullable();
                break;
            case 'date':
                $table->date($name)->nullable();
                break;
            case 'float':
                $table->float($name)->nullable();
                break;
            case 'decimal':
                $table->decimal($name, 10, 2)->nullable();
                break;
            case 'json':
                $table->json($name)->nullable();
                break;
            default:
                // Фоллбэк на строку для неизвестных типов
                $table->string($name)->nullable();
                break;
        }
    }
}
