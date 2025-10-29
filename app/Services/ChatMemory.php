<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ChatMemory
{
    private const KEY_PREFIX = 'parentpulse:chat:session:';
    private const HISTORY_LIMIT = 20;
    private const ROW_LIMIT = 50;
    private const VALUE_MAX_LENGTH = 500;
    private const TTL_SECONDS = 86400; // 24 hours

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getInteractions(string $sessionId): array
    {
        if ($sessionId === '') {
            return [];
        }

        $stored = $this->store()->get($this->key($sessionId));
        if ($stored === null) {
            return [];
        }

        if (is_array($stored)) {
            return $stored;
        }

        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    public function lastAnalytics(string $sessionId): ?array
    {
        $items = $this->getInteractions($sessionId);
        for ($i = count($items) - 1; $i >= 0; $i--) {
            if (($items[$i]['type'] ?? '') === 'analytics') {
                return $items[$i];
            }
        }

        return null;
    }

    public function rememberAnalytics(
        string $sessionId,
        string $query,
        array $rows,
        array $meta = [],
        ?string $response = null
    ): void {
        if ($sessionId === '') {
            return;
        }

        $normalizedRows = $this->normalizeRows($rows);
        $interaction = [
            'type' => 'analytics',
            'query' => $query,
            'rows' => $normalizedRows,
            'columns' => $this->inferColumns($normalizedRows),
            'meta' => $meta,
            'response' => $this->truncate($response, 1500),
        ];

        $this->pushInteraction($sessionId, $interaction);
    }

    public function rememberHelpdesk(
        string $sessionId,
        string $query,
        string $response,
        array $meta = []
    ): void {
        if ($sessionId === '') {
            return;
        }

        $interaction = [
            'type' => 'helpdesk',
            'query' => $query,
            'response' => $this->truncate($response, 1500),
            'meta' => $meta,
        ];

        $this->pushInteraction($sessionId, $interaction);
    }

    public function rememberFollowUp(
        string $sessionId,
        string $query,
        string $response,
        array $meta = []
    ): void {
        if ($sessionId === '') {
            return;
        }

        $interaction = [
            'type' => 'followup',
            'query' => $query,
            'response' => $this->truncate($response, 1500),
            'meta' => $meta,
        ];

        $this->pushInteraction($sessionId, $interaction);
    }

    private function pushInteraction(string $sessionId, array $interaction): void
    {
        $interaction['timestamp'] = $interaction['timestamp'] ?? now()->timestamp;

        $items = $this->getInteractions($sessionId);
        $items[] = $interaction;

        if (count($items) > self::HISTORY_LIMIT) {
            $items = array_slice($items, -self::HISTORY_LIMIT);
        }

        $this->store()->put($this->key($sessionId), $items, self::TTL_SECONDS);
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        $normalized = [];

        foreach (array_slice($rows, 0, self::ROW_LIMIT) as $row) {
            if (is_array($row)) {
                $normalized[] = $this->sanitizeRow($row);
                continue;
            }

            if (is_object($row)) {
                $normalized[] = $this->sanitizeRow(get_object_vars($row));
                continue;
            }

            // Skip unsupported row types
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function sanitizeRow(array $row): array
    {
        $sanitized = [];
        foreach ($row as $key => $value) {
            $sanitized[$key] = $this->sanitizeValue($value);
        }
        return $sanitized;
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            if (is_string($value)) {
                return $this->truncate($value);
            }
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_array($value)) {
            return $this->truncate(json_encode($value));
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return $this->truncate((string) $value);
        }

        return $this->truncate(json_encode($value));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function inferColumns(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                $columns[$column] = true;
            }
        }

        return array_keys($columns);
    }

    private function truncate(?string $value, int $limit = self::VALUE_MAX_LENGTH): ?string
    {
        if ($value === null) {
            return null;
        }

        if (Str::length($value) <= $limit) {
            return $value;
        }

        return Str::limit($value, $limit, '…');
    }

    private function key(string $sessionId): string
    {
        return self::KEY_PREFIX.$sessionId;
    }

    private function store(): CacheRepository
    {
        static $store = null;
        if ($store instanceof CacheRepository) {
            return $store;
        }

        try {
            $redisStore = Cache::store('redis');
            $redisStore->put('__chat_memory_ping__', '1', 1);
            $redisStore->forget('__chat_memory_ping__');
            $store = $redisStore;
            return $store;
        } catch (\Throwable $e) {
            // fall through to default store
        }

        $store = Cache::store(config('cache.default'));
        return $store;
    }
}
