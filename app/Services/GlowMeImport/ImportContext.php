<?php

namespace App\Services\GlowMeImport;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ImportContext
{
    private array $ids = [];

    private array $columns = [];

    public array $counts = [];

    public array $notes = [];

    public function __construct(public readonly array $snapshot)
    {
        foreach ($snapshot['tables'] as $table => $data) {
            $keys = array_map('strval', array_column($data['rows'], 'id'));
            sort($keys, SORT_STRING);
            foreach ($keys as $index => $key) {
                $this->ids[$table][$key] = $index + 1;
            }
        }
        foreach (DB::select('SELECT TABLE_NAME,COLUMN_NAME,DATA_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()') as $column) {
            $this->columns[$column->TABLE_NAME][$column->COLUMN_NAME] = $column;
        }
    }

    public function source(string $table): array
    {
        return $this->snapshot['tables'][str_contains($table, '.') ? $table : 'public.'.$table]['rows'] ?? [];
    }

    public function rows(string $table): array
    {
        return $this->source($table);
    }

    public function id(string $table, mixed $uuid): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        $key = str_contains($table, '.') ? $table : 'public.'.$table;
        if (! isset($this->ids[$key][(string) $uuid])) {
            throw new \RuntimeException('Missing source reference in '.$key);
        }

        return $this->ids[$key][(string) $uuid];
    }

    public function insert(string $table, array $row): void
    {
        if (! isset($this->columns[$table])) {
            throw new \RuntimeException('Unknown target table '.$table);
        }
        $filtered = [];
        foreach ($row as $key => $value) {
            if (! isset($this->columns[$table][$key])) {
                continue;
            }
            $column = $this->columns[$table][$key];
            if ($value !== null && $column->DATA_TYPE === 'json') {
                $value = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } elseif (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } elseif ($value !== null && in_array($column->DATA_TYPE, ['datetime', 'timestamp', 'date'])) {
                $value = (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format($column->DATA_TYPE === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s.u');
            } elseif (is_bool($value)) {
                $value = (int) $value;
            }
            $filtered[$key] = $value;
        }
        try {
            DB::table($table)->insert($filtered);
        } catch (\Throwable $error) {
            // Never leak email, password hashes, financial credentials or source payloads via SQL exceptions.
            $detail = '';
            if (preg_match('/(?:CONSTRAINT [`\x27]([A-Za-z0-9_]+)|Check constraint [\x27`]([A-Za-z0-9_]+)|Field [\x27`]([A-Za-z0-9_]+)[\x27`] doesn.t have a default)/i', $error->getMessage(), $match)) {
                $detail = ' identifier '.implode('', array_slice($match, 1));
            }
            throw new \RuntimeException('Import failed at '.$table.' source/local id '.($filtered['id'] ?? '(composite)').' (SQLSTATE '.($error instanceof QueryException ? $error->errorInfo[0] ?? 'unknown' : 'unknown').', code '.($error instanceof QueryException ? $error->errorInfo[1] ?? 'unknown' : 'unknown').')'.$detail);
        }
        $this->counts[$table] = ($this->counts[$table] ?? 0) + 1;
    }

    public function note(string $message): void
    {
        $this->notes[$message] = $message;
    }

    public function archive(): void
    {
        foreach ($this->snapshot['tables'] as $table => $data) {
            foreach (array_chunk($data['rawRows'], 200, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $index => $raw) {
                    $rows[] = ['source_table' => $table, 'row_number' => $index + 1, 'payload' => $raw, 'sha256' => hash('sha256', $raw)];
                }
                DB::table('glowme_import_rows')->insert($rows);
            }
        }
        foreach ($this->ids as $table => $ids) {
            foreach (array_chunk($ids, 300, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $source => $id) {
                    // Both document source tables share one native table; branch documents follow business documents.
                    $localId = $id + ($table === 'public.branch_documents' ? count($this->source('business_documents')) : 0);
                    $rows[] = ['source_table' => $table, 'source_id' => (string) $source, 'local_id' => $localId];
                }
                DB::table('glowme_import_ids')->insert($rows);
            }
        }
    }
}
