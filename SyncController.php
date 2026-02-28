<?php

namespace App\Http\Setting;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

class SyncController extends Controller
{
    /**
     * Упаковывает определенные файлы проекта в ZIP и отправляет клиенту.
     */
    public function downloadAllFiles()
    {
        $zipFileName = 'sync_packet_' . date('Y-m-d_H-i-s') . '.zip';
        $zipPath = sys_get_temp_dir() . '/' . $zipFileName;

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'Could not create ZIP archive'], 500);
        }

        // Список файлов можно обновлять через config/sync.php
        $filesToSync = config('sync.files', [
            'app/Http/Setting/EditorController.php',
            'resources/views/control/edit_ai.blade.php',
            'resources/views/control/edit_ai_result.blade.php',
            'resources/views/control/app.blade.php',
            'routes/ai.php',
            'app/Models/AiBackup.php',
            'app/Models/AiData.php',
            'app/Models/AiTemplate.php',
            'app/Models/Blocks.php',
            'app/Services/Editor/FileCollectorService.php',
            'app/Services/Editor/UrlResolverService.php',
            'app/Services/Editor/AiApiService.php',
            'app/Services/Editor/CodeApplierService.php',
        ]);

        foreach ($filesToSync as $relativePath) {
            $absolutePath = base_path($relativePath);
            
            if (file_exists($absolutePath)) {
                $zip->addFile($absolutePath, $relativePath);
            }
        }

        $zip->close();

        if (!file_exists($zipPath)) {
            return response()->json(['error' => 'ZIP file was not created'], 500);
        }

        return Response::download($zipPath)->deleteFileAfterSend(true);
    }

    /**
     * Возвращает схему указанных таблиц (колонки и типы).
     */
    public function getSchema(Request $request)
    {
        $requestedTables = $request->input('tables');

        if ($requestedTables !== null && !is_array($requestedTables)) {
            return response()->json(['error' => 'Tables must be an array'], 400);
        }

        // Список разрешенных таблиц (можно обновлять в config/sync.php)
        $allowedTables = config('sync.tables', [
            'ai_backup',
            'ai_data',
            'ai_template',
        ]);

        // Если клиент запрашивает конкретные таблицы, фильтруем их через разрешенный список.
        // Если список не передан, отдаем все разрешенные.
        $tables = ($requestedTables && is_array($requestedTables) && count($requestedTables) > 0)
            ? array_intersect($requestedTables, $allowedTables)
            : $allowedTables;

        $schemaData = [];

        foreach ($tables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            $columns = Schema::getColumnListing($tableName);
            $tableSchema = [];

            foreach ($columns as $column) {
                $type = Schema::getColumnType($tableName, $column);
                $tableSchema[$column] = $type;
            }

            $schemaData[$tableName] = $tableSchema;
        }

        return response()->json($schemaData);
    }
}
