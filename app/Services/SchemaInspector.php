<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SchemaInspector
{
    public function currentSchema(): string
    {
        $row = DB::connection('tenant')->select('select database() as db');
        return (string) ($row[0]->db ?? '');
    }

    /**
     * @return array<int, string>
     */
    public function listTables(array $tables): array
    {
        $schema = $this->currentSchema();
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $rows = DB::connection('tenant')->select(
            "select table_name as name from information_schema.tables where table_schema = ? and table_name in ({$placeholders}) order by table_name",
            array_merge([$schema], $tables)
        );
        return array_map(fn ($r) => (string) $r->name, $rows);
    }

    /**
     * @return array<int, string>
     */
    public function listColumns(string $table): array
    {
        $schema = $this->currentSchema();
        $rows = DB::connection('tenant')->select(
            'select column_name as name from information_schema.columns where table_schema = ? and table_name = ? order by ordinal_position',
            [$schema, $table]
        );
        return array_map(fn ($r) => (string) $r->name, $rows);
    }

    /**
     * Build a compact schema summary for LLM planning.
     *
     * @return array<string, array<int, string>> table => columns
     */
    public function schemaSummary(array $candidateTables): array
    {
        $existing = $this->listTables($candidateTables);
        $summary = [];
        foreach ($existing as $t) {
            $summary[$t] = $this->listColumns($t);
        }
        return $summary;
    }
}


