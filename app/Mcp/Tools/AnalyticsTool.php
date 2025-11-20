<?php

namespace App\Mcp\Tools;

use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use App\Services\SchemaInspector;
use App\Services\AnalyticsPlanner;
use App\Services\ChatMemory;
use GuzzleHttp\Client;

class AnalyticsTool extends Tool
{
    protected string $name = 'analytics-answer';

    protected string $title = 'Analytics Answer';

    protected string $description = 'Executes ParentPulse analytics queries using intelligent SQL generation.';

    private ?array $pendingFollowUp = null;

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('User analytics question')->min(1)->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = (string) ($request->get('query', '') ?? '');
        if ($query === '') {
            return Response::error('Please provide a "query" to proceed.');
        }
        $query = $this->normalizeNumberWords($query);

        $sessionId = (string) $request->sessionId();
        $memory = app(ChatMemory::class);

        // DEBUG: entry log
        $this->debugLog(['phase' => 'handle:start', 'query' => $query]);
        
        // Use memory to answer quick follow-ups
        if ($sessionId !== '') {
            $followUp = $this->answerFromMemory($query, $sessionId, $memory);
            if ($followUp !== null) {
                $memory->rememberFollowUp($sessionId, $query, $followUp, ['source' => 'analytics-memory']);
                return Response::text($followUp);
            }
            if ($this->pendingFollowUp !== null) {
                $query = $this->augmentQueryWithContext($query, $this->pendingFollowUp);
                $this->pendingFollowUp = null;
            }
        }

        if ($this->isNpsDriversIntent($query)) {
            $driversResponse = $this->answerNpsDrivers($query, $sessionId, $memory);
            if ($driversResponse !== null) {
                return Response::text($driversResponse);
            }
        }

        // Check for question-specific NPS queries grouped by demographics (grade, etc.)
        $isGroupedNps = $this->isQuestionSpecificNpsWithGrouping($query);
        $this->debugLog(['phase' => 'handle:check_grouped_nps', 'query' => $query, 'isGroupedNps' => $isGroupedNps]);
        
        if ($isGroupedNps) {
            $this->debugLog(['phase' => 'handle:entering_grouped_nps', 'query' => $query]);
            $groupedNpsResponse = $this->answerQuestionSpecificNpsGrouped($query, $sessionId, $memory);
            $this->debugLog(['phase' => 'handle:grouped_nps_result', 'query' => $query, 'response' => $groupedNpsResponse !== null ? 'non-null' : 'null']);
            if ($groupedNpsResponse !== null) {
                return Response::text($groupedNpsResponse);
            }
        }

        if ($this->isNpsIntent($query)) {
            $npsResponse = $this->answerNpsQuery($query, $sessionId, $memory);
            if ($npsResponse !== null) {
                return Response::text($npsResponse);
            }
        }

        if ($this->looksLikeRosterQuery($query)) {
            $rosterResponse = $this->answerRosterQuery($query, $sessionId, $memory);
            if ($rosterResponse !== null) {
                return Response::text($rosterResponse);
            }
        }

        if (! $this->looksLikeRosterQuery($query)) {
            $resolvedQuestion = $this->resolveQuestionByText($query);
            if ($resolvedQuestion !== null) {
                $this->debugLog(['phase' => 'resolveQuestionByText:hit', 'query' => $query, 'resolved' => $resolvedQuestion]);
                $questionResponse = $this->answerResolvedQuestion($query, $resolvedQuestion, $sessionId, $memory);
                if ($questionResponse !== null) {
                    return Response::text($questionResponse);
                }
            } else {
                $this->debugLog(['phase' => 'resolveQuestionByText:miss', 'query' => $query]);
            }
        }

        // ALWAYS try the intelligent planner first for ALL queries
        $this->debugLog(['phase' => 'handle:before_planner', 'query' => $query, 'needsNps' => $this->queryNeedsNpsAnalysis($query)]);
        $planned = $this->planAndExecute($query, $sessionId, $memory);
        if ($planned !== null) {
            $this->debugLog(['phase' => 'handle:planner_returned', 'query' => $query, 'response_length' => strlen($planned)]);
            return Response::text($planned);
        }
        
        // Only fall back to intelligent analysis if planner fails
        $this->debugLog(['phase' => 'handle:planner_null_fallback', 'query' => $query]);
        $fallback = $this->intelligentFallback($query, $sessionId, $memory);
        if ($fallback !== null) {
            $this->debugLog(['phase' => 'handle:fallback_returned', 'query' => $query, 'response_length' => strlen($fallback)]);
            return Response::text($fallback);
        }

        return Response::text('I could not generate a database query for your question. Please try rephrasing it.');
    }

    private function planAndExecute(string $query, string $sessionId, ChatMemory $memory): ?string
    {
        $this->debugLog(['phase' => 'planAndExecute:start', 'query' => $query]);
        $inspector = app(SchemaInspector::class);
        $summary = $inspector->schemaSummary([
            'survey_answers',
            'survey_invites',
            'survey_cycles',
            'people',
            'students',
            'employees',
            'users',
            'questions',
            'survey_answer_comments',
            'survey_invite_comments',
            'survey_answer_forwards',
            'survey_invite_forwards'
        ]);
        $availableTables = array_keys($summary);

        $planner = app(AnalyticsPlanner::class);
        $plan = $planner->plan($query, $summary);
        
        if ($plan === null) {
            Log::info('AnalyticsTool planner returned null; using intelligent fallback', [
                'user_query' => $query,
                'available_tables' => $availableTables,
            ]);
            $this->debugLog(['phase' => 'planner:null', 'query' => $query]);
            return null; // Return null to trigger fallback instead of error message
        }
        
        if (! is_array($plan)) {
            $msg = 'Planner returned invalid format';
            $this->debugLog(['phase' => 'planner:invalid', 'query' => $query, 'plan' => $plan]);
            return $msg . ': ' . json_encode($plan);
        }
        
        if (($plan['action'] ?? '') !== 'sql') {
            $this->debugLog(['phase' => 'planner:non-sql', 'query' => $query, 'plan' => $plan]);
            // Don't return error to user - let it fall through to fallback/memory
            return null;
        }

        $sql = (string) $plan['sql'];
        $params = $plan['params'] ?? [];
        
        // Disallow dangerous statements
        if (! Str::startsWith(Str::lower(Str::trim($sql)), 'select')) {
            $this->debugLog(['phase' => 'planner:nonselect', 'query' => $query, 'sql' => $sql, 'params' => $params]);
            $responseText = $this->analyzeWithAI($query, [], $availableTables);
            if ($sessionId !== '') {
                $memory->rememberAnalytics($sessionId, $query, [], [
                    'sql' => $sql,
                    'params' => $params,
                    'source' => 'planner-nonselect',
                ], $responseText);
            }
            return $responseText;
        }

        $originalSql = $sql;
        $sql = $this->cleanupSql($sql, $query);

        Log::info('AnalyticsTool executing planner SQL', [
            'user_query' => $query,
            'sql_before_cleanup' => $originalSql,
            'sql_after_cleanup' => $sql,
            'params' => $params,
        ]);
        $this->debugLog(['phase' => 'planner:execute', 'query' => $query, 'sql' => $sql, 'params' => $params]);

        // DB query listener (tenant) to capture actual executed SQL/bindings
        DB::connection('tenant')->listen(function ($q) use ($query) {
            $this->debugLog([
                'phase' => 'db:listen',
                'user_query' => $query,
                'sql' => $q->sql,
                'bindings' => $q->bindings,
                'time_ms' => $q->time ?? null,
            ]);
        });

        try {
            // Clean up common SQL issues before execution
            $rawRows = DB::connection('tenant')->select($sql, $params);
        } catch (\Throwable $e) {
            Log::warning('AnalyticsTool planner SQL failed; falling back to AI analysis', [
                'user_query' => $query,
                'sql_after_cleanup' => $sql,
                'params' => $params,
                'error' => $e->getMessage(),
            ]);
            $this->debugLog(['phase' => 'planner:error', 'query' => $query, 'sql' => $sql, 'params' => $params, 'error' => $e->getMessage()]);
            return $this->analyzeWithAI($query, [], $availableTables);
        }

        $rows = $this->normalizeResultSet($rawRows);

        if ($rows === []) {
            $responseText = $this->analyzeWithAI($query, [], $availableTables);
            if ($sessionId !== '') {
                $memory->rememberAnalytics($sessionId, $query, [], [
                    'sql' => $sql,
                    'params' => $params,
                    'source' => 'planner-empty',
                ], $responseText);
            }
            return $responseText;
        }

        if ($this->isAdminDetailQuery($query)) {
            $responseText = $this->formatAdminDetailResponse($rows, $query)
                ?? $this->analyzeWithAI($query, $rows, $availableTables);
        } elseif ($this->isAdminListQuery($query)) {
            $responseText = $this->formatAdminListResponse($rows)
                ?? $this->analyzeWithAI($query, $rows, $availableTables);
        } elseif ($this->isAdminPermissionQuery($query)) {
            $responseText = $this->formatAdminPermissionResponse($rows, $query)
                ?? $this->analyzeWithAI($query, $rows, $availableTables);
        } elseif ($this->isParentListQuery($query)) {
            $responseText = $this->formatParentListResponse($rows)
                ?? $this->analyzeWithAI($query, $rows, $availableTables);
        } elseif ($this->isCommentAnalysisQuery($query, $sql)) {
            $responseText = $this->analyzeCommentsWithAI($query, $rows);
        } elseif ($this->isEmployeeListQuery($query)) {
            $responseText = $this->formatEmployeeListResponse($rows)
                ?? $this->analyzeWithAI($query, $rows, $availableTables);
        } elseif ($this->isDetractorsPromotersListQuery($query)) {
            $responseText = $this->formatDetractorsPromotersListResponse($rows, $query)
                ?? $this->analyzeWithAI($query, $rows, $availableTables);
        } elseif ($this->queryNeedsNpsAnalysis($query)) {
            // Query needs NPS analysis - calculate NPS first, then analyze with context
            $module = $this->extractModuleFromQuery($query);
            $timeConditions = $this->buildRelativeDateConditions((string) Str::of($query)->lower());
            $cycleLabel = $this->extractCycleLabelFromQuery($query);
            
            $npsScore = $this->fetchOverallNpsScore($module, $timeConditions, $cycleLabel);
            $npsBreakdown = $this->fetchNpsBreakdown($module, $timeConditions, $cycleLabel);
            
            $this->debugLog([
                'phase' => 'planner:nps_analysis',
                'query' => $query,
                'module' => $module,
                'timeConditions' => $timeConditions,
                'cycleLabel' => $cycleLabel,
                'npsScore' => $npsScore,
                'npsBreakdown' => $npsBreakdown,
            ]);
            
            if ($npsScore !== null) {
                $this->debugLog(['phase' => 'planner:using_nps_context', 'query' => $query, 'npsScore' => $npsScore, 'hasBreakdown' => $npsBreakdown !== null]);
                $responseText = $this->analyzeWithNpsContext($query, $rows, $availableTables, $npsScore, $npsBreakdown);
            } else {
                $this->debugLog(['phase' => 'planner:nps_null_using_ai', 'query' => $query]);
                $responseText = $this->analyzeWithAI($query, $rows, $availableTables);
            }
        } else {
            $responseText = $this->analyzeWithAI($query, $rows, $availableTables);
        }

        if ($sessionId !== '') {
            $memory->rememberAnalytics($sessionId, $query, $rows, [
                'sql' => $sql,
                'params' => $params,
                'source' => 'planner',
            ], $responseText);
        }

        return $responseText;
    }

    private function cleanupSql(string $sql, string $userQuery = ''): string
    {
        // Fix MySQL interval syntax issues
        $sql = preg_replace("/(INTERVAL)\s*'\s*(\d+)\s*(day|days|month|months|year|years)\s*'\s*/i", '$1 $2 $3 ', $sql);
        $sql = preg_replace('/\bDATE\s*\(\s*NOW\s*\(\s*\)\s*\)\b/i', 'NOW()', $sql);
        $sql = preg_replace('/\bCURRENT_TIMESTAMP\(\s*\)\b/i', 'NOW()', $sql);

        // Normalize excessive whitespace
        $sql = preg_replace('/\s+ORDER\s+BY\s+/i', ' ORDER BY ', $sql);
        $sql = preg_replace('/\s+GROUP\s+BY\s+/i', ' GROUP BY ', $sql);
        $sql = preg_replace('/\s+LIMIT\s+/i', ' LIMIT ', $sql);

        $normalizedUserQuery = Str::lower($userQuery);

        // Always read answer text from value column; comments are stored in sa.value
        $sql = preg_replace('/\bsa\.comments\b/i', 'sa.value', $sql);

        // If the user is asking for comments/feedback, ensure we scope to question_type = 'comment'
        $wantsComments = $normalizedUserQuery !== '' && Str::contains($normalizedUserQuery, ['comment', 'comments', 'feedback', 'testimonial', 'note', 'remark']);
        if ($wantsComments) {
            // If no explicit question_type filter present, add it
            if (! preg_match("/\bsa\.question_type\s*=\s*'?comment'?/i", $sql)) {
                // Insert WHERE clause or AND depending on presence
                if (preg_match('/\bWHERE\b/i', $sql)) {
                    $sql = preg_replace('/\bWHERE\b/i', 'WHERE sa.question_type = \''."comment".'\' AND ', $sql, 1);
                } else {
                    // No WHERE found; add one before ORDER/LIMIT or at end
                    if (preg_match('/\bORDER\s+BY\b/i', $sql)) {
                        $sql = preg_replace('/\bORDER\s+BY\b/i', 'WHERE sa.question_type = \''."comment".'\' ORDER BY ', $sql, 1);
                    } elseif (preg_match('/\bLIMIT\b/i', $sql)) {
                        $sql = preg_replace('/\bLIMIT\b/i', 'WHERE sa.question_type = \''."comment".'\' LIMIT ', $sql, 1);
                    } else {
                        $sql .= " WHERE sa.question_type = 'comment'";
                    }
                }
            }
        }

        // Unless explicitly asked for completed/answered, remove si.status filters
        $explicitStatus = Str::contains($normalizedUserQuery, ['answered', 'completed', 'complete', 'finished', 'submitted']);
        if (! $explicitStatus) {
            // Remove simple equality filters on si.status
            $sql = preg_replace("/\s+AND\s+si\.status\s*=\s*'[^']*'/i", ' ', $sql);
            $sql = preg_replace("/\s+WHERE\s+si\.status\s*=\s*'[^']*'\s*(AND)?/i", ' WHERE ', $sql);
            // Remove IN (...) filters on si.status
            $sql = preg_replace("/\s+AND\s+si\.status\s+IN\s*\([^\)]*\)/i", ' ', $sql);
            $sql = preg_replace("/\s+WHERE\s+si\.status\s+IN\s*\([^\)]*\)\s*(AND)?/i", ' WHERE ', $sql);
            // Clean up duplicated WHERE/AND occurrences
            $sql = preg_replace('/\bWHERE\s+AND\b/i', 'WHERE ', $sql);
            $sql = preg_replace('/\s{2,}/', ' ', $sql);
        }

        return trim($sql);
    }

    private function normalizeNumberWords(string $text): string
    {
        $map = [
            'zero' => '0',
            'one' => '1',
            'two' => '2',
            'three' => '3',
            'four' => '4',
            'five' => '5',
            'six' => '6',
            'seven' => '7',
            'eight' => '8',
            'nine' => '9',
            'ten' => '10',
            'eleven' => '11',
            'twelve' => '12',
            'thirteen' => '13',
            'fourteen' => '14',
            'fifteen' => '15',
            'sixteen' => '16',
            'seventeen' => '17',
            'eighteen' => '18',
            'nineteen' => '19',
            'twenty' => '20',
            'thirty' => '30',
            'forty' => '40',
            'fifty' => '50',
            'sixty' => '60',
            'seventy' => '70',
            'eighty' => '80',
            'ninety' => '90',
        ];

        return preg_replace_callback(
            '/\b(' . implode('|', array_keys($map)) . ')\b/i',
            static fn ($matches) => $map[strtolower($matches[1])] ?? $matches[1],
            $text
        );
    }

    private function augmentQueryWithContext(string $query, array $context): string
    {
        $previousQuery = trim((string) ($context['last']['query'] ?? ''));
        $previousResponse = trim((string) ($context['last']['response'] ?? ''));
        if ($previousResponse === '' && ! empty($context['rows'])) {
            $previousResponse = Str::limit(json_encode(array_slice($context['rows'], 0, 5), JSON_PRETTY_PRINT), 400);
        }

        $prefix = 'Follow-up analytics request.';
        if ($previousQuery !== '') {
            $prefix .= ' Previous question: "'.$previousQuery.'".';
        }
        if ($previousResponse !== '') {
            $prefix .= ' Prior answer summary: '.$previousResponse.'.';
        }

        $prefix .= ' Reuse the same audience, filters, and entities referenced previously unless the user clearly changes them. ';
        $prefix .= 'New user request: '.$query;

        return $prefix;
    }

    private function buildRelativeDateConditions(string $query): array
    {
        $conditions = [];
        $normalized = strtolower($query);

        if (str_contains($normalized, 'this year')) {
            $conditions[] = 'YEAR(si.created_at) = YEAR(NOW())';
        }

        if (preg_match('/\b(last|past|previous|recent)\s+(\d+)\s+(day|week|month|quarter|year)s?\b/', $normalized, $matches)) {
            $amount = (int) $matches[2];
            $unit = $matches[3];

            switch ($unit) {
                case 'day':
                    $conditions[] = "si.created_at >= DATE_SUB(NOW(), INTERVAL {$amount} DAY)";
                    break;
                case 'week':
                    $conditions[] = "si.created_at >= DATE_SUB(NOW(), INTERVAL {$amount} WEEK)";
                    break;
                case 'month':
                    $conditions[] = "si.created_at >= DATE_SUB(NOW(), INTERVAL {$amount} MONTH)";
                    break;
                case 'quarter':
                    $conditions[] = "si.created_at >= DATE_SUB(NOW(), INTERVAL " . ($amount * 3) . " MONTH)";
                    break;
                case 'year':
                    $conditions[] = "si.created_at >= DATE_SUB(NOW(), INTERVAL {$amount} YEAR)";
                    break;
            }
        } else {
            if (preg_match('/\b(last|past|previous|recent)\s+quarter\b/', $normalized)) {
                $conditions[] = 'si.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)';
            }
            if (preg_match('/\b(last|past|previous|recent)\s+month\b/', $normalized)) {
                $conditions[] = 'si.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)';
            }
            if (preg_match('/\b(last|past|previous|recent)\s+week\b/', $normalized)) {
                $conditions[] = 'si.created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)';
            }
            if (preg_match('/\b(last|past|previous|recent)\s+year\b/', $normalized)) {
                $conditions[] = 'si.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)';
            }
        }

        return array_values(array_unique($conditions));
    }

    private function getSurveyDataWithFilters($conn, string $query, array $tableNames): array
    {
        $normalized = Str::of($query)->lower();
        $timeConditions = $this->buildRelativeDateConditions((string) $normalized);
        
        // Build base query
        $baseQuery = "
            SELECT sa.*, si.created_at as survey_date, si.survey_cycle_id,
                   p.first_name, p.last_name, p.id as person_id
            FROM survey_answers sa 
            LEFT JOIN survey_invites si ON si.id = sa.survey_invite_id 
            LEFT JOIN people p ON p.id = si.people_id 
            WHERE sa.value IS NOT NULL 
        ";
        
        $joins = [];
        $whereConditions = [];
        $params = [];
        foreach ($timeConditions as $condition) {
            $whereConditions[] = $condition;
        }
        
        // Check for cycle/sequence references
        $cycleLabel = null;
        $cycleModule = null;
        
        // Look for "cycle" or "sequence" keywords in the query
        if (preg_match('/(?:cycle|sequence)\s+([^?]+)/i', $query, $matches)) {
            $cycleLabel = trim($matches[1]);
            $cycleModule = $this->extractModuleFromQuery($query);
        }
        // Also check for patterns like "2025-2026 Fall Survey cycle" or "Aug. 30, 2024 - Nov. 29, 2024 student sequence"
        elseif (preg_match('/([^?]+?)\s+(?:cycle|sequence)/i', $query, $matches)) {
            $cycleLabel = trim($matches[1]);
            $cycleModule = $this->extractModuleFromQuery($query);
        }
        
        if ($cycleLabel && in_array('survey_cycles', $tableNames)) {
            $joins[] = "LEFT JOIN survey_cycles sc ON sc.id = si.survey_cycle_id";
            $whereConditions[] = "sc.label = ?";
            $params[] = $cycleLabel;
            
            // If module is specified, filter by survey_for column
            if ($cycleModule) {
                $whereConditions[] = "sc.survey_for = ?";
                $params[] = $cycleModule;
            }
        }
        
        // Check for specific user types using module_type column
        if ($normalized->contains(['parent', 'parents']) && !$normalized->contains(['student', 'students', 'employee', 'employees'])) {
            // Parents only
            $whereConditions[] = "sa.module_type = 'parent'";
        } elseif ($normalized->contains(['student', 'students']) && !$normalized->contains(['parent', 'parents', 'employee', 'employees'])) {
            // Students only
            $whereConditions[] = "sa.module_type = 'student'";
        } elseif ($normalized->contains(['employee', 'employees']) && !$normalized->contains(['parent', 'parents', 'student', 'students'])) {
            // Employees only
            $whereConditions[] = "sa.module_type = 'employee'";
        } elseif ($normalized->contains(['parent', 'parents']) && $normalized->contains(['student', 'students'])) {
            // Both parents and students
            $whereConditions[] = "sa.module_type IN ('parent', 'student')";
        }
        
        // Build final query
        $finalQuery = $baseQuery;
        if (!empty($joins)) {
            $finalQuery .= " " . implode(" ", $joins);
        }
        if (!empty($whereConditions)) {
            $finalQuery .= " AND " . implode(" AND ", $whereConditions);
        }
        $finalQuery .= " ORDER BY si.created_at DESC";
        
        // Execute query
        try {
            Log::info('AnalyticsTool executing fallback survey query', [
                'user_query' => $query,
                'sql' => $finalQuery,
                'params' => $params,
            ]);

            if (isset($params)) {
                $results = $conn->select($finalQuery, $params);
            } else {
                $results = $conn->select($finalQuery);
            }
            
            // Debug: Log the query and results for troubleshooting
            if (empty($results)) {
                // Try to get some basic data to understand what's available
                $debugQuery = "SELECT COUNT(*) as total FROM survey_answers sa LEFT JOIN survey_invites si ON si.id = sa.survey_invite_id LEFT JOIN people p ON p.id = si.people_id WHERE sa.value IS NOT NULL";
                $debugResults = $conn->select($debugQuery);
                $totalCount = $debugResults[0]->total ?? 0;
                
                // Return empty array with debug info
                return [];
            }
            
            return $results;
        } catch (\Throwable $e) {
            // Fallback to simple query if complex one fails
            return $conn->select("
                SELECT sa.*, si.created_at as survey_date, p.first_name, p.last_name 
                FROM survey_answers sa 
                LEFT JOIN survey_invites si ON si.id = sa.survey_invite_id 
                LEFT JOIN people p ON p.id = si.people_id 
                WHERE sa.value IS NOT NULL 
                ORDER BY si.created_at DESC
            ");
        }
    }

    private function intelligentFallback(string $query, string $sessionId, ChatMemory $memory): ?string
    {
        Log::info('AnalyticsTool entering intelligentFallback', [
            'user_query' => $query,
        ]);
        $this->debugLog(['phase' => 'fallback:enter', 'query' => $query]);
        $normalized = Str::of($query)->lower();
        
        // Get basic data first
        $conn = DB::connection('tenant');
        
        try {
            // Check what tables exist
            $tables = $conn->select("SHOW TABLES");
            $tableNames = array_map(fn($t) => current((array)$t), $tables);
            
            // Check for admin/user queries first
            if (in_array('users', $tableNames) && 
                ($normalized->contains(['admin', 'admins', 'administrator', 'administrators', 'owner', 'school owner', 'management', 'who is', 'who are']))) {
                
                $userData = $this->getUserData($conn, $query, $tableNames);
                if (!empty($userData)) {
                    $rows = $this->normalizeResultSet($userData);
                    $meta = [
                        'source' => 'fallback:userData',
                        'tables' => $tableNames,
                    ];

                    if ($this->isAdminDetailQuery($query)) {
                        $detail = $this->formatAdminDetailResponse($rows, $query);
                        if ($detail !== null) {
                            if ($sessionId !== '') {
                                $memory->rememberAnalytics($sessionId, $query, $rows, $meta, $detail);
                            }
                            return $detail;
                        }
                    }

                    if ($this->isAdminListQuery($query)) {
                        $adminList = $this->formatAdminListResponse($rows);
                        if ($adminList !== null) {
                            if ($sessionId !== '') {
                                $memory->rememberAnalytics($sessionId, $query, $rows, $meta, $adminList);
                            }
                            return $adminList;
                        }
                    }

                    if ($this->isAdminPermissionQuery($query)) {
                        $permissions = $this->formatAdminPermissionResponse($rows, $query);
                        if ($permissions !== null) {
                            if ($sessionId !== '') {
                                $memory->rememberAnalytics($sessionId, $query, $rows, $meta, $permissions);
                            }
                            return $permissions;
                        }
                    }

                    if ($this->isEmployeeListQuery($query)) {
                        $employees = $this->formatEmployeeListResponse($rows);
                        if ($employees !== null) {
                            if ($sessionId !== '') {
                                $memory->rememberAnalytics($sessionId, $query, $rows, $meta, $employees);
                            }
                            return $employees;
                        }
                    }

                    if ($this->isParentListQuery($query)) {
                        $parents = $this->formatParentListResponse($rows);
                        if ($parents !== null) {
                            if ($sessionId !== '') {
                                $memory->rememberAnalytics($sessionId, $query, $rows, $meta, $parents);
                            }
                            return $parents;
                        }
                    }

                    $analysis = $this->analyzeWithAI($query, $rows, $tableNames);
                    if ($sessionId !== '') {
                        $memory->rememberAnalytics($sessionId, $query, $rows, $meta, $analysis);
                    }
                    return $analysis;
                }
            }

            if ($this->isAdminPermissionQuery($query) && in_array('user_permissions', $tableNames)) {
                $permissionData = $this->getAdminPermissionData($conn, $query, $tableNames);
                if (!empty($permissionData)) {
                    $rows = $this->normalizeResultSet($permissionData);
                    $formatted = $this->formatAdminPermissionResponse($rows, $query)
                        ?? $this->analyzeWithAI($query, $rows, $tableNames);

                    if ($sessionId !== '') {
                        $memory->rememberAnalytics($sessionId, $query, $rows, [
                            'source' => 'fallback:admin_permissions',
                            'tables' => $tableNames,
                        ], $formatted);
                    }

                    return $formatted;
                }
            }
            
            // Check for cycle status queries
            if (in_array('survey_cycles', $tableNames) && 
                ($normalized->contains(['cancelled', 'canceled', 'cancellation', 'status', 'active', 'inactive', 'completed', 'pending']))) {
                
                $cycleStatusData = $this->getCycleStatusData($conn, $query, $tableNames);
                if (!empty($cycleStatusData)) {
                    $rows = $this->normalizeResultSet($cycleStatusData);
                    $analysis = $this->analyzeCycleStatusWithAI($query, $rows);
                    if ($sessionId !== '') {
                        $memory->rememberAnalytics($sessionId, $query, $rows, [
                            'source' => 'fallback:cycle_status',
                            'tables' => $tableNames,
                        ], $analysis);
                    }
                    return $analysis;
                }
            }
            
            // Get survey data for analysis with dynamic filtering
            if (in_array('survey_answers', $tableNames)) {
                // Check if query needs NPS calculation
                $needsNps = $this->queryNeedsNpsAnalysis($query);
                $npsScore = null;
                $npsData = null;
                
                if ($needsNps) {
                    // Extract module and time conditions from query
                    $module = $this->extractModuleFromQuery($query);
                    $timeConditions = $this->buildRelativeDateConditions((string) Str::of($query)->lower());
                    $cycleLabel = $this->extractCycleLabelFromQuery($query);
                    
                    // Calculate actual NPS score from database
                    $npsScore = $this->fetchOverallNpsScore($module, $timeConditions, $cycleLabel);
                    
                    // Also get NPS breakdown data for context
                    $npsData = $this->fetchNpsBreakdown($module, $timeConditions, $cycleLabel);
                    
                    $this->debugLog([
                        'phase' => 'fallback:nps_calculation',
                        'query' => $query,
                        'module' => $module,
                        'timeConditions' => $timeConditions,
                        'cycleLabel' => $cycleLabel,
                        'npsScore' => $npsScore,
                        'npsBreakdown' => $npsData,
                    ]);
                }
                
                $surveyData = $this->getSurveyDataWithFilters($conn, $query, $tableNames);
                
                if (!empty($surveyData) || $npsScore !== null) {
                    $rows = $this->normalizeResultSet($surveyData);
                    
                    // If we have NPS score, enrich the data with it
                    if ($npsScore !== null) {
                        $this->debugLog(['phase' => 'fallback:using_nps_context', 'query' => $query, 'npsScore' => $npsScore, 'hasBreakdown' => $npsData !== null]);
                        $analysis = $this->analyzeWithNpsContext($query, $rows, $tableNames, $npsScore, $npsData);
                    } else {
                        $this->debugLog(['phase' => 'fallback:nps_null_using_ai', 'query' => $query]);
                        $analysis = $this->analyzeWithAI($query, $rows, $tableNames);
                    }
                    
                    if ($sessionId !== '') {
                        $memory->rememberAnalytics($sessionId, $query, $rows, [
                            'source' => 'fallback:survey_answers',
                            'nps_score' => $npsScore,
                            'tables' => $tableNames,
                        ], $analysis);
                    }
                    return $analysis;
                }
            }
            
            return 'Fallback: No survey data found. Available tables: ' . implode(', ', $tableNames);
        } catch (\Throwable $e) {
            $this->debugLog(['phase' => 'fallback:error', 'query' => $query, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function analyzeWithAI(string $query, array $data, array $availableTables): string
    {
        $apiKey = (string) config('app.openai_api_key', '');
        $model = (string) env('OPENAI_MODEL', 'gpt-4o-mini');
        
        if ($apiKey === '') {
            return 'AI analysis not available - API key missing. Raw data: ' . json_encode($data);
        }

        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $system = 'You are a friendly school administrator analyzing ParentPulse survey data. Provide warm, human, conversational responses that parents and staff would appreciate.

Guidelines:
- Write like you\'re talking to a colleague, not a computer
- Focus on insights and what the data means for the school community
- Never mention database IDs, technical details, or raw data structures
- Use percentages and simple numbers that make sense to educators
- Highlight positive trends and areas for improvement
- Keep responses concise but meaningful
- Use encouraging, supportive language
- If no data is found, explain what this might mean in friendly terms
- CRITICAL: Always prominently display exact numerical values (NPS scores, counts, percentages) at the beginning of your response
- For NPS queries, start with "The NPS score is [exact value]" before providing analysis
- For count queries, start with "We have [exact number] responses" before providing context
- When the user asks for contact details (emails, phone numbers, names), list them explicitly if present—do not refuse or mention privacy concerns
- CRITICAL: ALL responses MUST be formatted in valid HTML with proper semantic tags:
  * Use <p> for paragraphs (wrap all text in paragraphs)
  * Use <ol> for ordered lists (numbered lists)
  * Use <ul> for unordered lists (bullet points)
  * Use <li> for each list item
  * Use <strong> for names, important numbers, and emphasis
  * Use <em> for subtle emphasis
  * Use <h3> or <h4> for section headings if needed
  * Always escape HTML special characters in text content
  * Example for lists: <p>We have <strong>117</strong> detractors:</p><ol><li><strong>John Doe</strong> - john@example.com</li><li><strong>Jane Smith</strong> - jane@example.com</li></ol>
  * Example for regular text: <p>The NPS score is <strong>65.5</strong>. This indicates strong satisfaction among our community members.</p>

Key facts about the data:
- Survey responses are on 0-10 scales for satisfaction/NPS questions
- Comments provide qualitative feedback
- Data includes parents, students, and employees
- Focus on what the numbers mean for school improvement

Available tables: ' . implode(', ', $availableTables);
            
            if (empty($data)) {
                $user = "User Query: {$query}\n\nNo survey data was found matching this criteria. Please provide a friendly explanation of what this might mean.";
            } else {
                // For list queries, send all data; otherwise send a sample
                $normalizedQuery = Str::of($query)->lower();
                $isListQuery = $normalizedQuery->contains(['list', 'lists', 'show', 'get', 'display']) &&
                               ($normalizedQuery->contains(['detractor', 'detractors', 'promoter', 'promoters', 'passive', 'passives']) ||
                                $normalizedQuery->contains(['parent', 'parents', 'student', 'students', 'employee', 'employees']));
                
                $dataToSend = $isListQuery ? $data : array_slice($data, 0, 20);
                $dataLabel = $isListQuery ? 'Survey Data' : 'Survey Data (sample)';
                $user = "User Query: {$query}\n\n{$dataLabel}:\n" . json_encode($dataToSend, JSON_PRETTY_PRINT);
            }

            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature' => 0.3,
            ];

            $res = $client->post('chat/completions', ['json' => $payload]);
            $json = json_decode((string) $res->getBody(), true);
            return trim((string) ($json['choices'][0]['message']['content'] ?? 'Analysis failed'));
            
        } catch (\Throwable $e) {
            return 'AI analysis failed: '.$e->getMessage() . '. Raw data sample: ' . json_encode(array_slice($data, 0, 5));
        }
    }

    private function isCommentAnalysisQuery(string $query, string $sql): bool
    {
        $normalized = Str::of($query)->lower();
        return $normalized->contains(['comment', 'comments', 'feedback', 'unhappy', 'happy', 'satisfied', 'dissatisfied']) &&
               Str::contains(Str::lower($sql), 'comments');
    }

    private function analyzeCommentsWithAI(string $query, array $rows): string
    {
        $apiKey = (string) config('app.openai_api_key', '');
        $model = (string) env('OPENAI_MODEL', 'gpt-4o-mini');
        
        if ($apiKey === '') {
            return 'AI analysis not available - API key missing. Raw data: ' . json_encode($rows);
        }

        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $system = 'You are analyzing survey comments for ParentPulse. Given a user query and comment data, filter and analyze the comments to answer their question. Return a clear, helpful response with specific examples from the data.';
            
            $user = "User Query: {$query}\n\nComment Data:\n" . json_encode($rows, JSON_PRETTY_PRINT);

            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature' => 0.3,
            ];

            $res = $client->post('chat/completions', ['json' => $payload]);
            $json = json_decode((string) $res->getBody(), true);
            return trim((string) ($json['choices'][0]['message']['content'] ?? 'Analysis failed'));
            
        } catch (\Throwable $e) {
            return 'AI analysis failed: '.$e->getMessage() . '. Raw data: ' . json_encode($rows);
        }
    }
    
    private function extractModuleFromQuery(string $query): ?string
    {
        $normalized = Str::of($query)->lower();

        if ($normalized->contains(['parent', 'parents']) && !$normalized->contains(['student', 'students', 'employee', 'employees'])) {
            return 'parent';
        } elseif ($normalized->contains(['student', 'students']) && !$normalized->contains(['parent', 'parents', 'employee', 'employees'])) {
            return 'student';
        } elseif ($normalized->contains(['employee', 'employees']) && !$normalized->contains(['parent', 'parents', 'student', 'students'])) {
            return 'employee';
        }

        return null; // No specific module mentioned
    }

    private function extractCycleLabelFromQuery(string $query): ?string
    {
        // Match explicit season + year patterns (e.g., "Spring 2025", "Fall 2024")
        if (preg_match('/\b(Spring|Summer|Fall|Winter)\s+(\d{2,4})\b/i', $query, $match)) {
            $season = ucfirst(strtolower($match[1]));
            $year = $match[2];
            if (strlen($year) === 2) {
                $year = '20'.$year;
            }
            return $season.' '.$year;
        }

        // Match year-year season pattern (e.g., "2025-2026 Fall", "2024-2025 Spring")
        if (preg_match('/\b(\d{2}-\d{2})\s*(Spring|Summer|Fall|Winter)\b/i', $query, $match)) {
            return strtoupper($match[1]).' '.ucfirst(strtolower($match[2]));
        }

        // Match explicit cycle/sequence mentions with proper patterns
        // Only match if followed by actual cycle identifiers (years, dates, or known cycle names)
        if (preg_match('/\b(?:cycle|sequence)\s+([A-Za-z0-9\-\s]{3,40}?)(?:\s+(?:for|about|regarding|module|parents|parent|students|student|employees|employee|survey|responses|results|data))?\b/i', $query, $match)) {
            $phrase = trim($match[1]);
            // Check if it's actually a cycle pattern (contains year, date, or known cycle keywords)
            if (preg_match('/(\d{2,4}|Aug\.|Sept\.|Oct\.|Nov\.|Dec\.|Jan\.|Feb\.|Mar\.|Apr\.|May\.|Jun\.|Jul\.)/i', $phrase)) {
                $phrase = preg_split('/\b(for|about|regarding|module|parents|parent|students|student|employees|employee|survey|responses|results|data)\b/i', $phrase)[0];
                $phrase = trim($phrase, " ?!.,#");
                if ($phrase !== '' && strlen($phrase) >= 3) {
                    return $phrase;
                }
            }
        }

        // Match date range patterns (e.g., "Aug. 30, 2024 - Nov. 29, 2024")
        if (preg_match('/([A-Za-z]+\.\s+\d{1,2},\s+\d{4}\s*-\s*[A-Za-z]+\.\s+\d{1,2},\s+\d{4})/i', $query, $match)) {
            return trim($match[1]);
        }

        return null;
    }

    private function queryNeedsNpsAnalysis(string $query): bool
    {
        $normalized = Str::of($query)->lower();
        $npsKeywords = [
            'nps', 'net promoter', 'popularity', 'popular', 'satisfaction', 'satisfied',
            'recommend', 'recommendation', 'promoters', 'detractors', 'passives',
            'improve', 'increase', 'enhance', 'better', 'steps to', 'ways to',
            'how to improve', 'how to increase', 'make better'
        ];
        
        foreach ($npsKeywords as $keyword) {
            if ($normalized->contains($keyword)) {
                return true;
            }
        }
        
        return false;
    }

    private function fetchNpsBreakdown(?string $module, array $timeConditions, ?string $cycleLabel = null): ?array
    {
        try {
            $whereParts = [
                "nps.question_type = 'nps'",
                "nps.value REGEXP '^[0-9]+$'",
                "CAST(nps.value AS UNSIGNED) BETWEEN 0 AND 10",
                "si.status IN ('answered','send')",
            ];
            $bindings = [];
            foreach ($timeConditions as $condition) {
                $whereParts[] = $condition;
            }
            if ($module !== null) {
                $whereParts[] = 'nps.module_type = ?';
                $bindings[] = $module;
            }

            if ($cycleLabel !== null) {
                $whereParts[] = 'LOWER(sc.label) LIKE ?';
                $bindings[] = '%'.Str::lower($cycleLabel).'%';
            }

            $sql = "
                SELECT
                    COUNT(*) AS total,
                    COUNT(CASE WHEN CAST(nps.value AS UNSIGNED) BETWEEN 9 AND 10 THEN 1 END) AS promoters,
                    COUNT(CASE WHEN CAST(nps.value AS UNSIGNED) BETWEEN 7 AND 8 THEN 1 END) AS passives,
                    COUNT(CASE WHEN CAST(nps.value AS UNSIGNED) BETWEEN 0 AND 6 THEN 1 END) AS detractors
                FROM survey_answers nps
                JOIN survey_invites si ON si.id = nps.survey_invite_id
                LEFT JOIN survey_cycles sc ON sc.id = si.survey_cycle_id
                WHERE " . implode("\n  AND ", $whereParts);

            $result = DB::connection('tenant')->selectOne($sql, $bindings);
            if ($result === null) {
                $this->debugLog(['phase' => 'fetchNpsBreakdown:null_result', 'sql' => $sql, 'bindings' => $bindings]);
                return null;
            }
            
            $breakdown = [
                'total' => (int) ($result->total ?? 0),
                'promoters' => (int) ($result->promoters ?? 0),
                'passives' => (int) ($result->passives ?? 0),
                'detractors' => (int) ($result->detractors ?? 0),
            ];
            
            $this->debugLog(['phase' => 'fetchNpsBreakdown:result', 'breakdown' => $breakdown, 'sql' => $sql, 'bindings' => $bindings]);
            
            return $breakdown;
        } catch (\Throwable $e) {
            $this->debugLog(['phase' => 'fetchNpsBreakdown:error', 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return null;
        }
    }

    private function analyzeWithNpsContext(string $query, array $data, array $availableTables, ?float $npsScore, ?array $npsBreakdown): string
    {
        $apiKey = (string) config('app.openai_api_key', '');
        $model = (string) env('OPENAI_MODEL', 'gpt-4o-mini');
        
        if ($apiKey === '') {
            return 'AI analysis not available - API key missing. Raw data: ' . json_encode($data);
        }

        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $system = 'You are a friendly school administrator analyzing ParentPulse survey data. Provide warm, human, conversational responses that parents and staff would appreciate.

Guidelines:
- Write like you\'re talking to a colleague, not a computer
- Focus on insights and what the data means for the school community
- Never mention database IDs, technical details, or raw data structures
- Use percentages and simple numbers that make sense to educators
- Highlight positive trends and areas for improvement
- Keep responses concise but meaningful
- Use encouraging, supportive language
- If no data is found, explain what this might mean in friendly terms
- CRITICAL: Always prominently display exact numerical values (NPS scores, counts, percentages) at the beginning of your response
- CRITICAL: ALL responses MUST be formatted in valid HTML with proper semantic tags:
  * Use <p> for paragraphs (wrap all text in paragraphs)
  * Use <ol> for ordered lists, <ul> for unordered lists
  * Use <li> for each list item
  * Use <strong> for names, important numbers, and emphasis
  * Use <em> for subtle emphasis
  * Use <h3> or <h4> for section headings if needed
  * Always escape HTML special characters in text content

Key facts about NPS:
- NPS is calculated as: (% Promoters - % Detractors) * 100
- Promoters: scores 9-10
- Passives: scores 7-8
- Detractors: scores 0-6
- The NPS score is a number between -100 and +100

Available tables: ' . implode(', ', $availableTables);
            
            $npsContext = '';
            if ($npsScore !== null) {
                $npsContext = "\n\n===== CALCULATED NPS DATA - USE EXACTLY AS PROVIDED =====\n";
                $npsContext .= "NPS Score (DO NOT CALCULATE OR CHANGE): {$npsScore}\n";
                
                if ($npsBreakdown !== null && isset($npsBreakdown['total']) && $npsBreakdown['total'] > 0) {
                    $total = (int) $npsBreakdown['total'];
                    $promoters = (int) $npsBreakdown['promoters'];
                    $passives = (int) $npsBreakdown['passives'];
                    $detractors = (int) $npsBreakdown['detractors'];
                    $promoterPct = $total > 0 ? round(($promoters / $total) * 100, 1) : 0;
                    $detractorPct = $total > 0 ? round(($detractors / $total) * 100, 1) : 0;
                    $passivePct = $total > 0 ? round(($passives / $total) * 100, 1) : 0;
                    
                    $npsContext .= "\nBreakdown (ACTUAL DATA FROM DATABASE):\n";
                    $npsContext .= "- Total NPS responses: {$total}\n";
                    $npsContext .= "- Promoters (scores 9-10): {$promoters} responses ({$promoterPct}%)\n";
                    $npsContext .= "- Passives (scores 7-8): {$passives} responses ({$passivePct}%)\n";
                    $npsContext .= "- Detractors (scores 0-6): {$detractors} responses ({$detractorPct}%)\n";
                    $npsContext .= "\nVERIFICATION: ({$promoterPct}% Promoters - {$detractorPct}% Detractors) * 100 = {$npsScore} NPS score\n";
                    $npsContext .= "\nNOTE: An NPS score of {$npsScore} means ";
                    if ($npsScore > 0) {
                        $npsContext .= "we have more promoters than detractors. ";
                    } elseif ($npsScore < 0) {
                        $npsContext .= "we have more detractors than promoters. ";
                    } else {
                        $npsContext .= "the number of promoters equals the number of detractors (balanced). ";
                    }
                    $npsContext .= "There ARE {$total} total responses, with {$promoters} promoters and {$detractors} detractors.\n";
                } else {
                    $npsContext .= "\nNote: NPS breakdown data not available, but the score {$npsScore} is calculated correctly from the database.\n";
                }
                
                $npsContext .= "\nCRITICAL INSTRUCTIONS:\n";
                $npsContext .= "1. Start your response with: 'The NPS score is {$npsScore}.'\n";
                $npsContext .= "2. Use ONLY the NPS score {$npsScore} provided above - DO NOT calculate, estimate, or change this number.\n";
                $npsContext .= "3. If breakdown is provided, reference the exact promoter/detractor/passive counts and percentages in your explanation.\n";
                $npsContext .= "4. Do NOT say 'no responses' or make assumptions - use the actual data provided above.\n";
                $npsContext .= "5. Explain what the NPS score of {$npsScore} means for the school based on the breakdown provided.\n";
                $npsContext .= "================================================\n";
            }
            
            if (empty($data)) {
                $user = "User Query: {$query}\n\nNo survey data was found matching this criteria.{$npsContext}\n\nPlease provide a friendly explanation of what this might mean.";
            } else {
                $user = "User Query: {$query}\n\nSurvey Data (sample):\n" . json_encode(array_slice($data, 0, 50), JSON_PRETTY_PRINT) . $npsContext;
            }

            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature' => 0.3,
            ];

            $res = $client->post('chat/completions', ['json' => $payload]);
            $json = json_decode((string) $res->getBody(), true);
            return trim((string) ($json['choices'][0]['message']['content'] ?? 'Analysis failed'));
            
        } catch (\Throwable $e) {
            return 'AI analysis failed: '.$e->getMessage() . '. Raw data sample: ' . json_encode(array_slice($data, 0, 5));
        }
    }
    
    private function getUserData($conn, string $query, array $tableNames): array
    {
        $normalized = Str::of($query)->lower();

        // Build query for users table
        $sql = "SELECT u.id, u.name, u.email, u.is_owner, u.created_at FROM users u";
        $whereConditions = [];
        $params = [];
        
        // Check for owner-specific queries
        if ($normalized->contains(['owner', 'school owner', 'who is the owner'])) {
            $whereConditions[] = "u.is_owner = 1";
        }
        
        // Check for admin queries
        if ($normalized->contains(['admin', 'admins', 'administrator', 'administrators', 'who are the admins'])) {
            // No specific WHERE clause - get all users
        }
        
        if (!empty($whereConditions)) {
            $sql .= " WHERE " . implode(" AND ", $whereConditions);
        }

        $sql .= " ORDER BY u.is_owner DESC, u.created_at ASC";

        try {
            $results = $conn->select($sql, $params);
            return $results;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getAdminPermissionData($conn, string $query, array $tableNames): array
    {
        $select = [
            'up.name AS permission_name',
            'up.module_type',
            'up.extras',
        ];

        $joins = [
            'LEFT JOIN user_permissions up ON up.user_id = u.id',
        ];

        if (in_array('user_demographic_permissions', $tableNames)) {
            $select[] = 'udp.demographic_type';
            $select[] = 'udp.question_id';
            $select[] = 'udp.can_view_question';
            $select[] = 'udp.can_view_answer';
            $joins[] = 'LEFT JOIN user_demographic_permissions udp ON udp.user_id = u.id';
        } else {
            $select[] = 'NULL AS demographic_type';
            $select[] = 'NULL AS question_id';
            $select[] = 'NULL AS can_view_question';
            $select[] = 'NULL AS can_view_answer';
        }

        $sql = 'SELECT '.implode(', ', $select).' FROM users u '.implode(' ', $joins);

        $name = $this->extractPersonNameFromQuery($query);
        $params = [];
        if ($name !== null) {
            $sql .= ' WHERE u.name LIKE ?';
            $params[] = '%'.$name.'%';
        }

        $sql .= ' ORDER BY u.name ASC, up.name ASC';

        try {
            return $conn->select($sql, $params);
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    private function getCycleStatusData($conn, string $query, array $tableNames): array
    {
        $normalized = Str::of($query)->lower();
        
        // Build base query for survey_cycles
        $baseQuery = "SELECT sc.* FROM survey_cycles sc WHERE 1=1";
        $whereConditions = [];
        $params = [];
        
        // Check for specific status
        if ($normalized->contains(['cancelled', 'canceled', 'cancellation'])) {
            $whereConditions[] = "sc.status = ?";
            $params[] = 'cancelled';
        } elseif ($normalized->contains(['active'])) {
            $whereConditions[] = "sc.status = ?";
            $params[] = 'active';
        } elseif ($normalized->contains(['completed'])) {
            $whereConditions[] = "sc.status = ?";
            $params[] = 'completed';
        } elseif ($normalized->contains(['pending'])) {
            $whereConditions[] = "sc.status = ?";
            $params[] = 'pending';
        }
        
        // Check for module filter
        $module = $this->extractModuleFromQuery($query);
        if ($module) {
            $whereConditions[] = "sc.survey_for = ?";
            $params[] = $module;
        }
        
        // Build final query
        $finalQuery = $baseQuery;
        if (!empty($whereConditions)) {
            $finalQuery .= " AND " . implode(" AND ", $whereConditions);
        }
        $finalQuery .= " ORDER BY sc.created_at DESC";
        
        try {
            return $conn->select($finalQuery, $params);
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    private function analyzeCycleStatusWithAI(string $query, array $cycleData): string
    {
        $apiKey = (string) config('app.openai_api_key', '');
        $model = (string) env('OPENAI_MODEL', 'gpt-4o-mini');
        
        if ($apiKey === '') {
            return 'AI analysis not available - API key missing. Raw data: ' . json_encode($cycleData);
        }

        try {
            $client = new Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $system = 'You are a friendly school administrator analyzing survey cycle data. Provide warm, human, conversational responses about survey cycle status and management.

       Guidelines:
       - Write like you\'re talking to a colleague, not a computer
       - Focus on insights about survey cycle management and status
       - Never mention database IDs, technical details, or raw data structures
       - Use simple numbers and percentages that make sense to educators
       - Highlight patterns in cycle status and timing
       - Keep responses concise but meaningful
       - Use encouraging, supportive language

       Key facts about the data:
       - survey_cycles contains information about survey cycles/sequences
       - status column shows: active, completed, cancelled, pending
       - survey_for column indicates target audience: parent, student, employee
       - Focus on what the cycle status means for school operations';
            
            $user = "User Query: {$query}\n\nSurvey Cycle Data:\n" . json_encode($cycleData, JSON_PRETTY_PRINT);

            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature' => 0.3,
            ];

            $res = $client->post('chat/completions', ['json' => $payload]);
            $json = json_decode((string) $res->getBody(), true);
            return trim((string) ($json['choices'][0]['message']['content'] ?? 'Analysis failed'));
            
        } catch (\Throwable $e) {
            return 'AI analysis failed: '.$e->getMessage() . '. Raw data sample: ' . json_encode(array_slice($cycleData, 0, 3));
        }
    }

    private function isAdminListQuery(string $query): bool
    {
        $normalized = Str::of($query)->lower();
        if (! $normalized->contains('admin')) {
            return false;
        }

        if ($normalized->contains(['permission', 'permissions'])) {
            return false;
        }

        if ($normalized->contains('owner') && ! $normalized->contains('admins')) {
            return false;
        }

        if ($normalized->contains('admins')) {
            return true;
        }

        return $normalized->contains([
            'all the admin',
            'all admin',
            'list of admin',
            'who are the admin',
            'who are our admin',
            'show me the admin',
            'get me the admin',
            'get me all the admin',
            'get me all the school admin',
        ]);
    }

    private function formatAdminListResponse(array $rows): ?string
    {
        $normalizedRows = $this->normalizeResultSet($rows);
        if ($normalizedRows === []) {
            return null;
        }

        $total = count($normalizedRows);
        $ownerCount = 0;

        $html = '<div class="admin-list">';
        
        foreach ($normalizedRows as $row) {
            $isOwnerValue = $row['is_owner'] ?? $row['isOwner'] ?? $row['owner'] ?? 0;
            $isOwner = (int) $isOwnerValue === 1;
            if ($isOwner) {
                $ownerCount++;
            }
        }

        $ownerSuffix = '';
        if ($ownerCount > 0) {
            $ownerSuffix = $ownerCount === 1
                ? ' including <strong>1</strong> owner'
                : " including <strong>{$ownerCount}</strong> owners";
        }

        $html .= '<p>We have <strong>' . $total . '</strong> school admins' . $ownerSuffix . '.</p>';
        $html .= '<ol>';
        
        foreach ($normalizedRows as $row) {
            $isOwnerValue = $row['is_owner'] ?? $row['isOwner'] ?? $row['owner'] ?? 0;
            $isOwner = (int) $isOwnerValue === 1;
            $roleLabel = $isOwner ? 'Owner' : 'Admin';
            
            $name = $this->stringValue($row, ['name', 'full_name', 'fullName'])
                ?? $this->combineNameParts($row, ['first_name', 'firstname'], ['last_name', 'lastname'])
                ?? 'Unknown';
            $email = $this->stringValue($row, ['email', 'contact_email', 'user_email']) ?? '';

            $html .= '<li>';
            $html .= '<strong>' . htmlspecialchars(trim($name), ENT_QUOTES, 'UTF-8') . '</strong>';
            if ($email !== '') {
                $html .= ' (' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . ')';
            }
            $html .= ' — ' . htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8');
            $html .= '</li>';
        }
        
        $html .= '</ol>';
        $html .= '</div>';

        return $html;
    }

    private function isAdminDetailQuery(string $query): bool
    {
        $normalized = Str::of($query)->lower();
        if (! $normalized->contains('admin')) {
            return false;
        }

        if ($normalized->contains(['permission', 'permissions'])) {
            return false;
        }

        return (bool) preg_match('/[a-z][a-z\'-]+\s+[a-z][a-z\'-]+/i', $query);
    }

    private function isAdminPermissionQuery(string $query): bool
    {
        $normalized = Str::of($query)->lower();
        if (! $normalized->contains('permission')) {
            return false;
        }

        if ($normalized->contains(['admin', 'owner'])) {
            return true;
        }

        return (bool) preg_match('/[a-z][a-z\'-]+\s+[a-z][a-z\'-]+/i', $query);
    }

    private function formatAdminDetailResponse(array $rows, string $query): ?string
    {
        $normalizedRows = $this->normalizeResultSet($rows);
        if ($normalizedRows === []) {
            return null;
        }

        $normalizedQuery = Str::of($query)->lower()->toString();

        $matches = array_filter($normalizedRows, function (array $row) use ($normalizedQuery) {
            $name = $this->stringValue($row, ['name', 'full_name', 'fullName'])
                ?? $this->combineNameParts($row, ['first_name', 'firstname'], ['last_name', 'lastname']);

            if ($name === null) {
                return false;
            }

            $candidate = Str::of($name)->lower()->toString();
            if ($candidate === '') {
                return false;
            }

            return str_contains($normalizedQuery, $candidate);
        });

        if ($matches === []) {
            return null;
        }

        $intro = count($matches) === 1
            ? '<p>Here is the school admin information you asked for:</p>'
            : '<p>Here are the matching school admins:</p>';

        $html = '<div class="admin-detail">';
        $html .= $intro;
        $html .= '<ol>';
        
        foreach ($matches as $row) {
            $name = $this->stringValue($row, ['name', 'full_name', 'fullName'])
                ?? $this->combineNameParts($row, ['first_name', 'firstname'], ['last_name', 'lastname'])
                ?? 'Unknown';
            $email = $this->stringValue($row, ['email', 'contact_email', 'user_email']) ?? '';
            $role = ((int) ($row['is_owner'] ?? $row['isOwner'] ?? 0) === 1) ? 'Owner' : 'Admin';

            $html .= '<li>';
            $html .= '<strong>' . htmlspecialchars(trim($name), ENT_QUOTES, 'UTF-8') . '</strong>';
            $html .= ' (' . htmlspecialchars($role, ENT_QUOTES, 'UTF-8') . ')';
            if ($email !== '') {
                $html .= ' — ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
            }
            $html .= '</li>';
        }
        
        $html .= '</ol>';
        $html .= '</div>';

        return $html;
    }

    private function formatAdminPermissionResponse(array $rows, string $query): ?string
    {
        $normalizedRows = $this->normalizeResultSet($rows);
        if ($normalizedRows === []) {
            return null;
        }

        $filtered = array_filter($normalizedRows, function (array $row) {
            return isset($row['permission_name']) || isset($row['permissionName']) || isset($row['name']);
        });

        $adminName = $this->extractPersonNameFromQuery($query);
        if ($filtered === []) {
            return $adminName ? "I did not find any explicit permissions assigned to {$adminName}." : 'I did not find any explicit permissions assigned to this admin.';
        }

        $moduleTypeMap = [
            1 => 'Parent',
            2 => 'Student',
            3 => 'Employee',
            4 => 'Custom',
        ];

        $lines = [];
        foreach ($filtered as $row) {
            $permission = trim((string) ($row['permission_name'] ?? $row['permissionName'] ?? $row['name'] ?? ''));
            if ($permission === '') {
                continue;
            }

            $segments = ["- {$permission}"];

            $moduleLabel = null;
            if (!empty($row['module'])) {
                $moduleLabel = Str::title((string) $row['module']);
            }

            if ($moduleLabel === null && isset($row['module_type'])) {
                $moduleLabel = $moduleTypeMap[(int) $row['module_type']] ?? (string) $row['module_type'];
            }

            if ($moduleLabel !== null) {
                $segments[] = "Module: {$moduleLabel}";
            }

            $extras = $row['extras'] ?? null;
            if (is_string($extras) && $extras !== '') {
                $decoded = json_decode($extras, true);
                if (is_array($decoded)) {
                    $extras = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }
                $segments[] = 'Details: '.Str::limit((string) $extras, 200);
            }

            if (isset($row['type']) && $row['type'] !== null && $row['type'] !== '') {
                $segments[] = 'Type: '.Str::title((string) $row['type']);
            }

            if (isset($row['question_id']) && $row['question_id'] !== null) {
                $segments[] = 'Question ID: '.(string) $row['question_id'];
            }

            if (isset($row['question_answer_id']) && $row['question_answer_id'] !== null) {
                $segments[] = 'Answer ID: '.(string) $row['question_answer_id'];
            }

            if (array_key_exists('hide_filter', $row)) {
                $segments[] = 'Hide Filter: '.($this->truthy($row['hide_filter']) ? 'Yes' : 'No');
            }

            if (array_key_exists('is_custom_answer', $row)) {
                $segments[] = 'Custom Answer Only: '.($this->truthy($row['is_custom_answer']) ? 'Yes' : 'No');
            }

            $lines[] = implode(' | ', $segments);
        }

        if ($lines === []) {
            return $adminName ? "I did not find any explicit permissions assigned to {$adminName}." : 'I did not find any explicit permissions assigned to this admin.';
        }

        $intro = $adminName ? "Here are {$adminName}'s permissions:" : 'Here are the permissions currently assigned:';
        return $intro."\n".implode("\n", $lines);
    }

    private function isEmployeeListQuery(string $query): bool
    {
        $normalized = Str::of($query)->lower();
        if (! $normalized->contains(['employee', 'staff'])) {
            return false;
        }

        return $normalized->contains([
            'employee list',
            'list of employees',
            'list employees',
            'employees list',
            'show employees',
            'get employees',
            'staff list',
            'list staff',
            'staff directory',
            'employee directory',
            'employee roster',
            'staff roster',
        ]);
    }

    private function formatEmployeeListResponse(array $rows): ?string
    {
        $normalizedRows = $this->normalizeResultSet($rows);
        if ($normalizedRows === []) {
            return null;
        }

        $total = count($normalizedRows);

        $html = '<div class="employee-list">';
        $html .= '<p>We have <strong>' . $total . '</strong> employees.</p>';
        $html .= '<ol>';
        
        foreach ($normalizedRows as $row) {
            $name = $this->combineNameParts($row, ['firstname', 'first_name'], ['lastname', 'last_name'])
                ?? $this->stringValue($row, [
                    'name', 'full_name', 'fullName', 'detractor_name', 'promoter_name',
                    'person_name', 'respondent_name', 'participant_name'
                ])
                ?? 'Unknown employee';

            $email = $this->stringValue($row, ['email', 'work_email', 'contact_email']) ?? '';
            $role = $this->stringValue($row, ['title', 'position', 'role', 'job_title']) ?? '';

            $html .= '<li>';
            $html .= '<strong>' . htmlspecialchars(trim($name), ENT_QUOTES, 'UTF-8') . '</strong>';
            $details = [];
            if ($role !== '') {
                $details[] = htmlspecialchars($role, ENT_QUOTES, 'UTF-8');
            }
            if ($email !== '') {
                $details[] = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
            }
            if ($details !== []) {
                $html .= ' - ' . implode(' · ', $details);
            }
            $html .= '</li>';
        }
        
        $html .= '</ol>';
        $html .= '</div>';

        return $html;
    }

    private function isParentListQuery(string $query): bool
    {
        $normalized = Str::of($query)->lower();
        if (! $normalized->contains('parent')) {
            return false;
        }

        return $normalized->contains([
            'parent list',
            'list of parents',
            'list parents',
            'parents list',
            'show parents',
            'get parents',
            'parent directory',
            'parent roster',
            'parent contacts',
            'parent contact list',
            'parent emails',
            'parents contact',
        ]);
    }

    private function formatParentListResponse(array $rows): ?string
    {
        $normalizedRows = $this->normalizeResultSet($rows);
        if ($normalizedRows === []) {
            return null;
        }

        $total = count($normalizedRows);

        $html = '<div class="parent-list">';
        $html .= '<p>We have <strong>' . $total . '</strong> parents.</p>';
        $html .= '<ol>';
        
        foreach ($normalizedRows as $row) {
            $name = $this->stringValue($row, [
                'name', 'full_name', 'parent_name', 'detractor_name', 'promoter_name',
                'person_name', 'respondent_name', 'participant_name'
            ])
                ?? $this->combineNameParts($row, ['first_name', 'firstname'], ['last_name', 'lastname'])
                ?? 'Unknown parent';

            $email = $this->stringValue($row, ['email', 'contact_email']) ?? '';
            $phone = $this->stringValue($row, ['phone', 'contact_phone', 'mobile']) ?? '';

            $html .= '<li>';
            $html .= '<strong>' . htmlspecialchars(trim($name), ENT_QUOTES, 'UTF-8') . '</strong>';
            $details = [];
            if ($email !== '') {
                $details[] = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
            }
            if ($phone !== '') {
                $details[] = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
            }
            if ($details !== []) {
                $html .= ' - ' . implode(' · ', $details);
            }
            $html .= '</li>';
        }
        
        $html .= '</ol>';
        $html .= '</div>';

        return $html;
    }

    private function isDetractorsPromotersListQuery(string $query): bool
    {
        $normalized = Str::of($query)->lower();
        $listMarkers = ['list', 'lists', 'show', 'get', 'display'];
        $cohortMarkers = ['detractor', 'detractors', 'promoter', 'promoters', 'passive', 'passives'];
        
        $hasListMarker = false;
        foreach ($listMarkers as $marker) {
            if ($normalized->contains($marker)) {
                $hasListMarker = true;
                break;
            }
        }
        
        $hasCohortMarker = false;
        foreach ($cohortMarkers as $marker) {
            if ($normalized->contains($marker)) {
                $hasCohortMarker = true;
                break;
            }
        }
        
        return $hasListMarker && $hasCohortMarker;
    }

    private function formatDetractorsPromotersListResponse(array $rows, string $query): ?string
    {
        $normalizedRows = $this->normalizeResultSet($rows);
        if ($normalizedRows === []) {
            return null;
        }

        $total = count($normalizedRows);
        $normalized = Str::of($query)->lower();
        $isDetractors = $normalized->contains(['detractor', 'detractors']);
        $isPromoters = $normalized->contains(['promoter', 'promoters']);
        $isPassives = $normalized->contains(['passive', 'passives']);
        
        $label = 'individuals';
        if ($isDetractors) {
            $label = 'detractors';
        } elseif ($isPromoters) {
            $label = 'promoters';
        } elseif ($isPassives) {
            $label = 'passives';
        }

        $html = '<div class="detractors-promoters-list">';
        $html .= '<p>We have <strong>' . $total . '</strong> ' . $label . '.</p>';
        $html .= '<ol>';
        
        foreach ($normalizedRows as $idx => $row) {
            $name = $this->stringValue($row, [
                'detractor_name', 'promoter_name', 'passive_name',
                'name', 'full_name', 'parent_name', 'student_name', 'employee_name',
                'person_name', 'respondent_name', 'participant_name'
            ])
                ?? $this->combineNameParts($row, ['first_name', 'firstname'], ['last_name', 'lastname'])
                ?? 'Unknown';

            // More robust email detection - check all possible column variations
            $emailColumns = $this->detectColumnsContaining([$row], ['email']);
            $email = '';
            if ($emailColumns !== []) {
                $email = $this->stringValue($row, $emailColumns) ?? '';
            }
            if ($email === '') {
                // Fallback to common column names
                $email = $this->stringValue($row, ['email', 'contact_email', 'user_email', 'work_email']) ?? '';
            }
            
            $html .= '<li>';
            $html .= '<strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>';
            if ($email !== '' && trim($email) !== '') {
                $html .= ' - ' . htmlspecialchars(trim($email), ENT_QUOTES, 'UTF-8');
            }
            $html .= '</li>';
        }
        
        $html .= '</ol>';
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeResultSet(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized[] = $row;
            } elseif (is_object($row)) {
                $normalized[] = get_object_vars($row);
            }
        }
        return $normalized;
    }

    private function stringValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }
            $value = $row[$key];
            if ($value === null) {
                continue;
            }
            $string = trim((string) $value);
            if ($string !== '') {
                return $string;
            }
        }
        return null;
    }

    private function combineNameParts(array $row, array $firstKeys, array $lastKeys): ?string
    {
        $first = $this->stringValue($row, $firstKeys);
        $last = $this->stringValue($row, $lastKeys);

        $candidate = trim(trim((string) ($first ?? '')) . ' ' . trim((string) ($last ?? '')));
        if ($candidate !== '') {
            return $candidate;
        }

        if ($first !== null && trim($first) !== '') {
            return trim($first);
        }

        if ($last !== null && trim($last) !== '') {
            return trim($last);
        }

        return null;
    }

    private function answerFromMemory(string $query, string $sessionId, ChatMemory $memory): ?string
    {
        $last = $memory->lastAnalytics($sessionId);
        if ($last === null) {
            return null;
        }

        $rows = is_array($last['rows'] ?? null) ? $last['rows'] : [];
        $rows = $this->normalizeResultSet($rows);
        $responseText = isset($last['response']) ? (string) $last['response'] : '';

        $normalizedQuery = Str::of($query)->lower()->toString();

        if (! $this->shouldUseMemory($query, $last, $rows, $responseText)) {
            return null;
        }

        $this->pendingFollowUp = [
            'last' => $last,
            'rows' => $rows,
            'response' => $responseText,
        ];

        if ($rows === [] && $responseText !== '') {
            $fallback = $this->answerFromResponseText($normalizedQuery, $responseText, $last['query'] ?? null);
            if ($fallback !== null) {
                $this->pendingFollowUp = null;
                return $fallback;
            }
            return null;
        }

        $previousQuery = isset($last['query']) ? (string) $last['query'] : null;

        // Check if user wants both names and emails
        $wantsBoth = (Str::contains($normalizedQuery, ['both', 'and']) && 
                      Str::contains($normalizedQuery, 'email') && 
                      Str::contains($normalizedQuery, 'name'));
        
        // Check if user wants names and emails (even without "both")
        $wantsNamesAndEmails = Str::contains($normalizedQuery, 'email') && 
                               (Str::contains($normalizedQuery, 'name') || 
                                Str::contains($normalizedQuery, ['list', 'show', 'get', 'give']));

        if ($wantsBoth || $wantsNamesAndEmails) {
            // Extract both names and emails from rows
            $emailColumns = $this->detectColumnsContaining($rows, ['email']);
            $nameColumns = $this->detectColumnsContaining($rows, ['name', 'first_name', 'firstname', 'last_name', 'lastname']);
            
            if ($emailColumns !== [] || $nameColumns !== []) {
                $items = [];
                foreach ($rows as $row) {
                    $name = null;
                    // Try to get name from various columns
                    if ($nameColumns !== []) {
                        foreach ($nameColumns as $col) {
                            $val = $this->stringValue($row, [$col]);
                            if ($val !== null && trim($val) !== '') {
                                if ($name === null) {
                                    $name = trim($val);
                                } else {
                                    $name .= ' ' . trim($val);
                                }
                            }
                        }
                    }
                    // Fallback to combining name parts
                    if ($name === null || trim($name) === '') {
                        $name = $this->combineNameParts($row, ['first_name', 'firstname'], ['last_name', 'lastname'])
                            ?? $this->stringValue($row, [
                                'detractor_name', 'promoter_name', 'passive_name',
                                'name', 'full_name', 'parent_name', 'student_name', 'employee_name',
                                'person_name', 'respondent_name', 'participant_name'
                            ])
                            ?? 'Unknown';
                    }
                    
                    $email = $this->stringValue($row, $emailColumns ?: ['email', 'contact_email']) ?? '';
                    
                    $items[] = [
                        'name' => trim($name),
                        'email' => trim($email)
                    ];
                }
                
                if ($items !== []) {
                    $this->pendingFollowUp = null;
                    return $this->formatNamesAndEmailsList($items, $previousQuery);
                }
            }
        }

        if (Str::contains($normalizedQuery, 'email')) {
            $emailColumns = $this->detectColumnsContaining($rows, ['email']);
            $emails = $this->extractColumnValues($rows, $emailColumns, 500);
            if ($emails === []) {
                $emails = $this->fetchEmailsForContext($last) ?? [];
            }
            if ($emails !== []) {
                $this->pendingFollowUp = null;
                return $this->formatListResponse('email addresses', $emails, $previousQuery);
            }
        }

        if (Str::contains($normalizedQuery, 'permission')) {
            $permissions = $this->collectPermissions($rows, 30);
            if ($permissions !== []) {
                $this->pendingFollowUp = null;
                return $this->formatListResponse('permissions', $permissions, $previousQuery);
            }
        }

        if (Str::contains($normalizedQuery, 'name') || Str::contains($normalizedQuery, ['employee', 'staff'])) {
            $names = $this->collectNames($rows, 500);
            if ($names !== []) {
                $this->pendingFollowUp = null;
                return $this->formatListResponse('names', $names, $previousQuery);
            }
        }

        if (Str::contains($normalizedQuery, ['comment', 'comments', 'feedback'])) {
            $comments = $this->collectComments($rows, 10000);
            if ($comments !== []) {
                $this->pendingFollowUp = null;
                return $this->formatListResponse('comments', $comments, $previousQuery);
            }
        }

        if (Str::contains($normalizedQuery, ['count', 'how many', 'number'])) {
            $count = count($rows);
            $label = $previousQuery ? 'previous result for "' . Str::limit($previousQuery, 80) . '"' : 'previous result';
            $this->pendingFollowUp = null;
            return "The {$label} contained {$count} records.";
        }

        if (Str::contains($normalizedQuery, ['response', 'responses'])) {
            $count = count($rows);
            $label = $previousQuery ? 'previous result for "' . Str::limit($previousQuery, 80) . '"' : 'previous result';
            $this->pendingFollowUp = null;
            return "The {$label} included {$count} responses.";
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $needles
     * @return array<int, string>
     */
    private function detectColumnsContaining(array $rows, array $needles): array
    {
        $matches = [];
        foreach ($rows as $row) {
            foreach ($row as $column => $_value) {
                $lower = strtolower((string) $column);
                foreach ($needles as $needle) {
                    if (str_contains($lower, strtolower($needle))) {
                        $matches[$column] = true;
                    }
                }
            }
        }
        return array_keys($matches);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    private function extractColumnValues(array $rows, array $columns, int $limit = 50): array
    {
        if ($columns === []) {
            return [];
        }

        $values = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                if (! array_key_exists($column, $row)) {
                    continue;
                }
                $value = $row[$column];
                if ($value === null) {
                    continue;
                }

                $string = trim((string) $value);
                if ($string !== '') {
                    $values[] = $string;
                }
            }
        }

        $unique = array_values(array_unique($values));
        return array_slice($unique, 0, $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function collectNames(array $rows, int $limit = 2000): array
    {
        $names = [];
        foreach ($rows as $row) {
            $name = $this->stringValue($row, ['name', 'full_name', 'fullName'])
                ?? $this->combineNameParts($row, ['first_name', 'firstname'], ['last_name', 'lastname']);
            if ($name !== null && trim($name) !== '') {
                $names[] = trim($name);
            }
        }

        $unique = array_values(array_unique($names));
        return array_slice($unique, 0, $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function collectPermissions(array $rows, int $limit = 300): array
    {
        $permissions = [];
        foreach ($rows as $row) {
            $permission = $this->stringValue($row, ['permission_name', 'permissionName', 'name']);
            if ($permission === null) {
                continue;
            }

            $permission = trim($permission);
            if ($permission === '' || $permission === 'Unknown permission') {
                continue;
            }

            $suffixParts = [];
            if (!empty($row['module'])) {
                $suffixParts[] = 'module: '.Str::title((string) $row['module']);
            } elseif (isset($row['module_type'])) {
                $labels = [1 => 'Parent', 2 => 'Student', 3 => 'Employee', 4 => 'Custom'];
                $module = (int) $row['module_type'];
                $suffixParts[] = 'module: '.($labels[$module] ?? $module);
            }

            if (isset($row['type']) && $row['type'] !== null && $row['type'] !== '') {
                $suffixParts[] = 'type: '.Str::title((string) $row['type']);
            }

            if (isset($row['question_id']) && $row['question_id'] !== null) {
                $suffixParts[] = 'Q: '.$row['question_id'];
            }

            if (isset($row['question_answer_id']) && $row['question_answer_id'] !== null) {
                $suffixParts[] = 'Ans: '.$row['question_answer_id'];
            }

            $suffix = $suffixParts === [] ? '' : ' ('.implode(', ', $suffixParts).')';
            $permissions[] = $permission.$suffix;
        }

        $unique = array_values(array_unique($permissions));
        return array_slice($unique, 0, $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function collectComments(array $rows, int $limit = 10000): array
    {
        $commentColumns = $this->detectColumnsContaining($rows, ['comment', 'feedback', 'note']);
        if ($commentColumns === []) {
            return [];
        }

        $comments = $this->extractColumnValues($rows, $commentColumns, $limit);
        return $comments;
    }

    private function formatListResponse(string $label, array $values, ?string $sourceQuery = null): string
    {
        $header = "Here are the {$label}";
        if ($sourceQuery !== null && $sourceQuery !== '') {
            $header .= ' you asked for';
        }
        $header .= ":\n";

        $lines = array_map(static fn ($value) => '- '.$value, $values);
        return $header . implode("\n", $lines);
    }

    /**
     * Format a list of items with both names and emails in HTML
     * @param array<int, array{name: string, email: string}> $items
     */
    private function formatNamesAndEmailsList(array $items, ?string $sourceQuery = null): string
    {
        $total = count($items);
        $header = '<p>Here are the <strong>' . $total . '</strong> entries';
        if ($sourceQuery !== null && $sourceQuery !== '') {
            $header .= ' you asked for';
        }
        $header .= ':</p>';

        $html = '<div class="names-emails-list">';
        $html .= $header;
        $html .= '<ol>';
        
        foreach ($items as $item) {
            $name = $item['name'] ?? 'Unknown';
            $email = $item['email'] ?? '';
            
            $html .= '<li>';
            $html .= '<strong>' . htmlspecialchars(trim($name), ENT_QUOTES, 'UTF-8') . '</strong>';
            if ($email !== '') {
                $html .= ' - ' . htmlspecialchars(trim($email), ENT_QUOTES, 'UTF-8');
            }
            $html .= '</li>';
        }
        
        $html .= '</ol>';
        $html .= '</div>';

        return $html;
    }

    private function shouldUseMemory(string $query, array $last, array $rows, string $responseText): bool
    {
        if ($rows === [] && trim($responseText) === '') {
            return false;
        }

        if (! $this->contextuallyCompatible($query, (string) ($last['query'] ?? ''))) {
            return false;
        }

        $heuristic = $this->heuristicFollowUp($query);
        if ($heuristic) {
            return true;
        }

        $aiDecision = $this->classifyFollowUpUsingAI($query, $last, $rows, $responseText);
        if ($aiDecision !== null) {
            return $aiDecision;
        }

        return $heuristic;
    }

    private function classifyFollowUpUsingAI(string $query, array $last, array $rows, string $responseText): ?bool
    {
        $apiKey = (string) env('OPENAI_API_KEY', '');
        if ($apiKey === '') {
            return null;
        }

        try {
            $client = new Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'timeout' => 12,
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $previousQuery = (string) ($last['query'] ?? 'unknown');
            $previousResponse = Str::limit((string) ($last['response'] ?? ''), 600);
            if ($previousResponse === '' && $rows !== []) {
                $previousResponse = Str::limit(json_encode(array_slice($rows, 0, 5), JSON_PRETTY_PRINT), 600);
            }

            $system = 'You decide whether a new analytics question should reuse the previous query result. Reply with "yes" if the user is clearly asking to refine or filter the prior result, otherwise reply "no".';
            $user = "Previous query: {$previousQuery}\nPrevious answer (summary): {$previousResponse}\nNew query: {$query}\nShould the new query reuse the previous result? Reply with yes or no.";

            $payload = [
                'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature' => 0,
            ];

            $res = $client->post('chat/completions', ['json' => $payload]);
            $json = json_decode((string) $res->getBody(), true);
            $text = strtolower(trim((string) ($json['choices'][0]['message']['content'] ?? '')));
            if ($text !== '') {
                if (str_starts_with($text, 'yes')) {
                    return true;
                }
                if (str_starts_with($text, 'no')) {
                    return false;
                }
            }
        } catch (\Throwable $e) {
            // fall through to heuristics
        }

        return null;
    }

    private function heuristicFollowUp(string $query): bool
    {
        $normalized = Str::of($query)->lower()->trim();
        if ($normalized === '') {
            return false;
        }

        $explicitReferences = [
            'from previous', 'from earlier', 'from the previous', 'previous result', 'previous data',
            'previous answer', 'same list', 'same data', 'that list', 'that data', 'that result',
            'those results', 'those emails', 'those names', 'those permissions', 'those comments',
        ];
        foreach ($explicitReferences as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        if ((str_contains($normalized, 'only') || str_contains($normalized, 'just')) &&
            Str::contains($normalized, ['email', 'emails', 'name', 'names', 'permission', 'permissions', 'comment', 'comments', 'records', 'values'])) {
            return true;
        }

        if (str_word_count($normalized) <= 4 && Str::contains($normalized, ['emails', 'names', 'permissions', 'comments', 'responses', 'records'])) {
            return true;
        }

        $pronouns = [' this ', ' that ', ' those ', ' these ', ' it ', ' them ', ' their '];
        if (Str::contains($normalized, ['email', 'name', 'permission', 'comment', 'score', 'data', 'result'])) {
            foreach ($pronouns as $pronoun) {
                if (str_contains(' '.$normalized.' ', $pronoun)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function contextuallyCompatible(string $current, string $previous): bool
    {
        $current = Str::of($current)->lower();
        $previous = Str::of($previous)->lower();

        $audienceKeywords = [
            'students' => ['student', 'students'],
            'parents' => ['parent', 'parents'],
            'employees' => ['employee', 'employees'],
        ];

        foreach ($audienceKeywords as $terms) {
            $currHas = $current->contains($terms);
            $prevHas = $previous->contains($terms);
            if ($currHas && ! $prevHas) {
                return false;
            }
        }

        $npsTerms = ['nps', 'promoter', 'promoters', 'detractor', 'detractors', 'net promoter', 'satisfaction', 'happy', 'unhappy'];
        if ($current->contains($npsTerms) && ! $previous->contains($npsTerms)) {
            return false;
        }

        $attributeGroups = [
            ['email', 'emails', 'email address', 'contact email'],
            ['name', 'names'],
            ['phone', 'phones', 'phone number', 'numbers'],
            ['count', 'how many', 'number of', 'total'],
            ['id', 'ids', 'identifier'],
        ];

        $pronounFollowUp = Str::contains(' '.(string) $current.' ', [' this ', ' that ', ' those ', ' these ', ' it ', ' them ', ' their ']);

        foreach ($attributeGroups as $group) {
            $currHas = $current->contains($group);
            $prevHas = $previous->contains($group);
            if ($currHas && ! $prevHas) {
                if ($pronounFollowUp) {
                    continue;
                }
                return false;
            }
        }

        return true;
    }

    private function isNpsDriversIntent(string $query): bool
    {
        $normalized = Str::of($query)->lower();

        if (! $normalized->contains(['nps', 'net promoter'])) {
            return false;
        }

        $driverMarkers = [
            'driver', 'drivers', 'factor', 'factors', 'correlation', 'correlate',
            'influence', 'influences', 'impact', 'impacts', 'impacting', 'predictor',
            'predictors', 'root cause', 'regression', 'model', 'variance', 'relationship',
            'explain', 'explaining', 'explanation', 'associated', 'association',
            'biggest contributors', 'what drives', 'which drives', 'key drivers', 'why is',
        ];

        foreach ($driverMarkers as $marker) {
            if ($normalized->contains($marker)) {
                return true;
            }
        }

        return false;
    }

    private function isNpsIntent(string $query): bool
    {
        $normalized = Str::of($query)->lower();

        // CRITICAL: Exclude queries that need grouping by demographics - these should be handled by answerQuestionSpecificNpsGrouped
        $groupingMarkers = [
            'each', 'for each', 'per', 'grouped by', 'group by',
            'grade', 'grades', 'grade level', 'grade levels',
            'class', 'classes', 'classroom',
            'demographic', 'demographics', 'segment', 'segments',
            'category', 'categories', 'department', 'departments',
            'by grade', 'by class', 'by department'
        ];
        
        $hasGrouping = false;
        foreach ($groupingMarkers as $marker) {
            if ($normalized->contains($marker)) {
                $hasGrouping = true;
                break;
            }
        }
        
        // If query asks for grouping and has NPS terms, exclude from simple NPS handler
        if ($hasGrouping && $normalized->contains(['nps', 'net promoter', 'promoter score'])) {
            return false; // Let answerQuestionSpecificNpsGrouped handle it
        }

        // Exclude multi-cycle comparison queries - these should go to the planner
        $multiCycleMarkers = [
            'Filter cycle', 'from', 'to', 'improved', 'improvement', 'changed', 'change',
            'comparison', 'compare', 'between', 'across',
        ];
        $hasFromTo = false;
        $cycleCount = 0;
        
        // Count cycle/period references
        if (preg_match_all('/\b(spring|summer|fall|winter|sprint)\s+\d{2,4}\b/i', $query, $matches)) {
            $cycleCount += count($matches[0]);
        }
        if (preg_match_all('/\b\d{2}-\d{2}\b/', $query, $matches)) {
            $cycleCount += count($matches[0]);
        }
        if (preg_match_all('/\b(cycle|cycles|survey|sequence|period|periods)\b/i', $query, $matches)) {
            $cycleCount += count($matches[0]);
        }
        
        // Check for "from X to Y" pattern indicating multi-cycle comparison
        if (preg_match('/\bfrom\s+.+?\s+to\s+/i', $query) || 
            preg_match('/\b(improved|changed|moved)\s+(from|to)\b/i', $query)) {
            return false; // Let planner handle multi-cycle comparisons
        }
        
        foreach ($multiCycleMarkers as $marker) {
            if ($normalized->contains($marker)) {
                if ($marker === 'from' || $marker === 'to') {
                    $hasFromTo = true;
                }
                // If we have "from X to Y" pattern or multiple cycles, let planner handle it
                if ($hasFromTo || $cycleCount >= 2) {
                    return false;
                }
            }
        }

        $analysisMarkers = [
            'driver', 'drivers', 'factor', 'factors', 'correlation', 'correlate',
            'predictor', 'predictors', 'influence', 'influences', 'influencing',
            'impact', 'impacts', 'impacting', 'relationship', 'relationships',
            'cause', 'causes', 'why', 'because', 'regression', 'model', 'analysis',
            'analyses', 'analytics', 'root cause', 'explain', 'explaining', 'explanation',
        ];

        foreach ($analysisMarkers as $marker) {
            if ($normalized->contains($marker)) {
                return false;
            }
        }

        $npsTermsPresent = $normalized->contains(['nps', 'net promoter']);
        $cohortTermsPresent = $normalized->contains(['promoter', 'promoters', 'detractor', 'detractors', 'passive', 'passives']);

        $scoreMarkers = [
            'score', 'scores', 'value', 'number', 'rate', 'rating', 'ratings', 'percentage', 'percent',
            'count', 'counts', 'distribution', 'breakdown', 'trend', 'trends', 'change', 'changes',
            'increase', 'decrease', 'improve', 'improvement', 'current', 'latest', 'recent', 'for last', 'over last'
        ];

        if ($npsTermsPresent) {
            foreach ($scoreMarkers as $marker) {
                if ($normalized->contains($marker)) {
                    return true;
                }
            }

            // explicit question like "what is nps" even without score marker
            if ($normalized->contains(['what is', "what's", 'whats', 'give me', 'show me', 'calculate', 'tell me'])) {
                return true;
            }

            return false;
        }

        if ($cohortTermsPresent) {
            return true;
        }

        if ($normalized->contains(['satisfaction', 'satisfied', 'dissatisfied', 'happy', 'unhappy'])) {
            foreach ($scoreMarkers as $marker) {
                if ($normalized->contains($marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function answerNpsDrivers(string $query, string $sessionId, ChatMemory $memory): ?string
    {
        try {
            $module = $this->extractModuleFromQuery($query);
            $timeConditions = $this->buildRelativeDateConditions($query);
            $cycleLabel = $this->extractCycleLabelFromQuery($query);

            $centralDb = (string) env('DB_DATABASE', '');
            $centralQuestions = $centralDb !== '' ? "{$centralDb}.questions" : null;

            $whereParts = [
                "nps.question_type = 'nps'",
                "driver.question_type NOT IN ('comment','filtering','nps')",
                "driver.value REGEXP '^[0-9]+$'",
                "driver.value <> ''",
                "driver.questionable_type IN ('App\\\\Models\\\\Question','App\\\\Models\\\\Tenant\\\\Question')",
                "driver.questionable_id <> nps.questionable_id",
                "si.status IN ('answered','send')",
                "nps.value REGEXP '^[0-9]+$'",
                "CAST(nps.value AS UNSIGNED) BETWEEN 0 AND 10",
            ];
            $bindings = [];

            foreach ($timeConditions as $condition) {
                $whereParts[] = $condition;
            }

            if ($module !== null) {
                $whereParts[] = 'nps.module_type = ?';
                $bindings[] = $module;
            }

            if ($cycleLabel !== null) {
                $whereParts[] = 'LOWER(sc.label) LIKE ?';
                $bindings[] = '%'.Str::lower($cycleLabel).'%';
            }

            $tenantTextColumns = $this->questionTextColumns('tenant');
            $centralTextColumns = $centralQuestions !== null
                ? $this->questionTextColumns(config('database.default', 'mysql'))
                : [];

            $questionColumns = $this->buildQuestionTextSelect('tq', 'cq', $tenantTextColumns, $centralTextColumns);

            $sql = <<<SQL
                SELECT
                    driver.questionable_type,
                    driver.questionable_id,
                    {$questionColumns} AS driver_question,
                    COUNT(*) AS response_count,
                    AVG(CASE
                        WHEN CAST(nps.value AS UNSIGNED) BETWEEN 9 AND 10 THEN 1
                        WHEN CAST(nps.value AS UNSIGNED) BETWEEN 0 AND 6 THEN -1
                        ELSE 0
                    END) AS nps_signal,
                    AVG(CAST(driver.value AS DECIMAL(10,2))) AS avg_driver_score
                FROM survey_answers nps
                JOIN survey_invites si ON si.id = nps.survey_invite_id
                JOIN survey_answers driver ON driver.survey_invite_id = nps.survey_invite_id
                LEFT JOIN survey_cycles sc ON sc.id = si.survey_cycle_id
                LEFT JOIN questions tq ON driver.questionable_type = 'App\\\\Models\\\\Tenant\\\\Question' AND tq.id = driver.questionable_id
            SQL;

            if ($centralQuestions !== null) {
                $sql .= "\nLEFT JOIN {$centralQuestions} cq ON driver.questionable_type = 'App\\\\Models\\\\Question' AND cq.id = driver.questionable_id";
            } else {
                $sql .= "\nLEFT JOIN questions cq ON 1 = 0";
            }

            $sql .= "\nWHERE " . implode("\n  AND ", $whereParts) . "
                GROUP BY driver.questionable_type, driver.questionable_id, driver_question
                HAVING response_count >= 3 AND driver_question IS NOT NULL
                ORDER BY ABS(nps_signal) DESC, response_count DESC";

            Log::debug('AnalyticsTool NPS driver SQL', [
                'query' => $query,
                'sql' => $sql,
                'bindings' => $bindings,
            ]);

            $rows = DB::connection('tenant')->select($sql, $bindings);
            $drivers = $this->normalizeResultSet($rows);

            if ($drivers === []) {
                Log::debug('AnalyticsTool NPS drivers returned empty set', [
                    'query' => $query,
                    'bindings' => $bindings,
                    'sql' => $sql,
                ]);
                return 'I could not identify any numeric survey questions that correlate strongly with the NPS responses in this timeframe.';
            }

            $overallScore = $this->fetchOverallNpsScore($module, $timeConditions, $cycleLabel);
            $summary = $this->formatDriverSummary($drivers, $overallScore);

            if ($sessionId !== '') {
                $memory->rememberAnalytics($sessionId, $query, $drivers, [
                    'source' => 'nps-drivers',
                    'module' => $module,
                    'time_conditions' => $timeConditions,
                    'overall_nps' => $overallScore,
                ], $summary);
            }

            return $summary;
        } catch (\Throwable $e) {
            Log::warning('AnalyticsTool NPS driver handling failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function fetchOverallNpsScore(?string $module, array $timeConditions, ?string $cycleLabel = null): ?float
    {
        try {
            $whereParts = [
                "nps.question_type = 'nps'",
                "nps.value REGEXP '^[0-9]+$'",
                "CAST(nps.value AS UNSIGNED) BETWEEN 0 AND 10",
                "si.status IN ('answered','send')",
            ];
            $bindings = [];
            foreach ($timeConditions as $condition) {
                $whereParts[] = $condition;
            }
            if ($module !== null) {
                $whereParts[] = 'nps.module_type = ?';
                $bindings[] = $module;
            }

            if ($cycleLabel !== null) {
                $whereParts[] = 'LOWER(sc.label) LIKE ?';
                $bindings[] = '%'.Str::lower($cycleLabel).'%';
            }

            $sql = "
                SELECT
                    ((COUNT(CASE WHEN CAST(nps.value AS UNSIGNED) BETWEEN 9 AND 10 THEN 1 END) -
                      COUNT(CASE WHEN CAST(nps.value AS UNSIGNED) BETWEEN 0 AND 6 THEN 1 END)) / NULLIF(COUNT(*), 0)) * 100 AS nps_score
                FROM survey_answers nps
                JOIN survey_invites si ON si.id = nps.survey_invite_id
                LEFT JOIN survey_cycles sc ON sc.id = si.survey_cycle_id
                WHERE " . implode("\n  AND ", $whereParts);

            $result = DB::connection('tenant')->selectOne($sql, $bindings);
            if ($result === null) {
                $this->debugLog(['phase' => 'fetchOverallNpsScore:null_result', 'sql' => $sql, 'bindings' => $bindings]);
                return null;
            }
            $score = (float) ($result->nps_score ?? 0.0);
            $rounded = round($score, 1);
            $this->debugLog(['phase' => 'fetchOverallNpsScore:result', 'raw_score' => $score, 'rounded_score' => $rounded, 'sql' => $sql, 'bindings' => $bindings]);
            return $rounded;
        } catch (\Throwable $e) {
            $this->debugLog(['phase' => 'fetchOverallNpsScore:error', 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return null;
        }
    }

    private function formatDriverSummary(array $drivers, ?float $overallScore): string
    {
        $limit = 5;

        $positive = $this->hydrateDriverQuestions(array_filter($drivers, static fn ($row) => ($row['nps_signal'] ?? 0) >= 0));
        $negative = $this->hydrateDriverQuestions(array_filter($drivers, static fn ($row) => ($row['nps_signal'] ?? 0) < 0));

        $renderItems = static function (array $items, string $class) use ($limit): string {
            $rows = array_slice($items, 0, $limit);
            if ($rows === []) {
                return '<p class="text-muted">No items found.</p>';
            }

            $html = '<ol class="'.$class.'">';
            foreach ($rows as $row) {
                $question = htmlspecialchars((string) ($row['driver_question'] ?? 'Unknown question'), ENT_QUOTES, 'UTF-8');
                $signal = round(((float) ($row['nps_signal'] ?? 0.0)) * 100, 1);
                $avg = round((float) ($row['avg_driver_score'] ?? 0.0), 2);
                $count = (int) ($row['response_count'] ?? 0);
                $direction = $signal >= 0 ? 'boosts NPS' : 'pulls NPS down';
                $meta = [];
                if (! empty($row['question_module'])) {
                    $meta[] = Str::title((string) $row['question_module']).' audience';
                }
                if (! empty($row['question_type'])) {
                    $meta[] = Str::title(str_replace('_', ' ', (string) $row['question_type']));
                }
                if (! empty($row['question_source'])) {
                    $meta[] = $row['question_source'] === 'tenant' ? 'Campus question' : 'Benchmark question';
                }
                if ($meta !== []) {
                    $metaEscaped = array_map(static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'), $meta);
                    $metaText = '<span class="metric meta">'.implode(' • ', $metaEscaped).'</span><br>';
                } else {
                    $metaText = '';
                }

                $html .= sprintf(
                    '<li><strong>%s</strong><br>%s<span class="metric">Driver signal: %s</span> • <span class="metric">Average score: %0.2f</span> • <span class="metric">Responses: %d</span> • <span class="metric">%s</span></li>',
                    $question,
                    $metaText,
                    $signal,
                    $avg,
                    $count,
                    $direction
                );
            }
            $html .= '</ol>';
            return $html;
        };

        $sections = [];
        if ($overallScore !== null) {
            $sections[] = sprintf(
                '<p class="lead">Our current NPS score is <strong>%s</strong>.</p>',
                rtrim(rtrim(number_format($overallScore, 1, '.', ''), '0'), '.')
            );
        }

        $sections[] = '<section class="nps-drivers nps-drivers--positive"><h3>Top factors lifting NPS</h3>'.$renderItems($positive, 'drivers-list drivers-list--positive').'</section>';
        if ($negative !== []) {
            $sections[] = '<section class="nps-drivers nps-drivers--negative"><h3>Factors pulling NPS down</h3>'.$renderItems($negative, 'drivers-list drivers-list--negative').'</section>';
        }

        $sections[] = '<p class="note">Reinforce the positive drivers and shore up the negative ones to shift the overall score.</p>';

        return implode("\n", $sections);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function hydrateDriverQuestions(array $rows): array
    {
        $cache = [];
        foreach ($rows as &$row) {
            $type = (string) ($row['questionable_type'] ?? '');
            $id = (int) ($row['questionable_id'] ?? 0);
            if ($type === '' || $id === 0) {
                continue;
            }

            $details = $this->fetchQuestionDetails($type, $id, $cache);
            if ($details === null) {
                continue;
            }

            $row['driver_question'] = $details['text'] ?? ($row['driver_question'] ?? null);
            $row['question_type'] = $details['type'] ?? null;
            $row['question_module'] = $details['module_type'] ?? null;
            $row['question_source'] = $details['source'] ?? null;
        }

        return $rows;
    }

    private function answerNpsQuery(string $query, string $sessionId, ChatMemory $memory): ?string
    {
        try {
            $timeConditions = $this->buildRelativeDateConditions($query);
            $module = $this->extractModuleFromQuery($query);
            $cycleLabel = $this->extractCycleLabelFromQuery($query);

            $base = DB::connection('tenant')
                ->table('survey_answers as sa')
                ->join('survey_invites as si', 'si.id', '=', 'sa.survey_invite_id')
                ->leftJoin('survey_cycles as sc', 'sc.id', '=', 'si.survey_cycle_id')
                ->where('sa.question_type', 'nps')
                ->whereRaw("sa.value REGEXP '^[0-9]+$'")
                ->whereRaw('CAST(sa.value AS UNSIGNED) BETWEEN 0 AND 10');

            foreach ($timeConditions as $condition) {
                $base->whereRaw($condition);
            }

            if ($module !== null) {
                $base->where('sa.module_type', $module);
            }

            $base->where(function ($query) {
                $query->whereNull('sc.status')
                    ->orWhereIn('sc.status', ['completed', 'removed', 'active', 'inactive', 'cancelled']);
            });

            if ($cycleLabel !== null) {
                $base->whereRaw('LOWER(sc.label) LIKE ?', ['%'.Str::lower($cycleLabel).'%']);
            }

            $aggregateBuilder = clone $base;
            $aggregateBuilder->selectRaw("
                SUM(CASE WHEN CAST(sa.value AS UNSIGNED) BETWEEN 9 AND 10 THEN 1 ELSE 0 END) AS promoters,
                SUM(CASE WHEN CAST(sa.value AS UNSIGNED) BETWEEN 7 AND 8 THEN 1 ELSE 0 END) AS passives,
                SUM(CASE WHEN CAST(sa.value AS UNSIGNED) BETWEEN 0 AND 6 THEN 1 ELSE 0 END) AS detractors,
                COUNT(*) AS total_responses
            ");

            Log::debug('AnalyticsTool NPS SQL', [
                'query' => $query,
                'sql' => $aggregateBuilder->toSql(),
                'bindings' => $aggregateBuilder->getBindings(),
                'time_conditions' => $timeConditions,
                'module' => $module,
            ]);

            $counts = $aggregateBuilder->first();
            if ($counts === null) {
                return null;
            }

            $promoters = (int) ($counts->promoters ?? 0);
            $passives = (int) ($counts->passives ?? 0);
            $detractors = (int) ($counts->detractors ?? 0);
            $total = (int) ($counts->total_responses ?? 0);

            if ($total === 0) {
                return 'I could not find any NPS responses for that timeframe.';
            }

            $score = $total > 0 ? (($promoters - $detractors) / $total) * 100 : 0.0;
            $score = round($score, 1);

            $scoreFormatted = rtrim(rtrim(number_format($score, 1, '.', ''), '0'), '.');
            $promoterPct = round(($promoters / $total) * 100, 1);
            $passivePct = round(($passives / $total) * 100, 1);
            $detractorPct = round(($detractors / $total) * 100, 1);

            $audienceMap = [
                'parent' => 'parents',
                'student' => 'students',
                'employee' => 'employees',
            ];
            $audienceLabel = $module !== null ? ($audienceMap[$module] ?? Str::plural($module)) : null;
            $audienceSuffix = $audienceLabel ? ' for '.$audienceLabel : '';

            $response = sprintf(
                'The NPS score is %s. Based on %d responses%s: %d promoters (%.1f%%), %d passives (%.1f%%), %d detractors (%.1f%%).',
                $scoreFormatted,
                $total,
                $audienceSuffix,
                $promoters,
                $promoterPct,
                $passives,
                $passivePct,
                $detractors,
                $detractorPct
            );

            $data = [[
                'nps_score' => $score,
                'total_responses' => $total,
                'promoters' => $promoters,
                'passives' => $passives,
                'detractors' => $detractors,
                'promoter_percent' => $promoterPct,
                'passive_percent' => $passivePct,
                'detractor_percent' => $detractorPct,
            ]];

            if ($sessionId !== '') {
                $memory->rememberAnalytics($sessionId, $query, $data, [
                    'source' => 'nps-summary',
                    'module' => $module,
                    'time_conditions' => $timeConditions,
                ], $response);
            }

            return $response;
        } catch (\Throwable $e) {
            Log::warning('AnalyticsTool NPS handling failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function isQuestionSpecificNpsWithGrouping(string $query): bool
    {
        $normalized = Str::of($query)->lower();
        
        // Check for NPS intent
        $hasNps = $normalized->contains(['nps', 'net promoter', 'promoter score']);
        
        // Check for grouping keywords - must have both a grouping word AND a dimension
        $hasGrouping = $normalized->contains(['each', 'for each', 'per', 'by', 'grouped by', 'group by']);
        $hasDimension = $normalized->contains([
            'grade', 'grades', 'grade level', 'grade levels',
            'class', 'classes', 'classroom',
            'demographic', 'demographics', 'segment', 'segments',
            'category', 'categories', 'department', 'departments'
        ]);
        
        // Check for question mention (optional - user might just say "NPS for each grade")
        $hasQuestion = $normalized->contains(['question', 'for the question', 'on the question']) ||
                       preg_match('/["\']([^"\']+)["\']/', $query); // Quoted question text
        
        $result = $hasNps && ($hasGrouping || $hasDimension) && ($hasDimension || $hasQuestion);
        
        $this->debugLog([
            'phase' => 'isQuestionSpecificNpsWithGrouping:check',
            'query' => $query,
            'hasNps' => $hasNps,
            'hasGrouping' => $hasGrouping,
            'hasDimension' => $hasDimension,
            'hasQuestion' => $hasQuestion,
            'result' => $result,
        ]);
        
        return $result;
    }

    private function answerQuestionSpecificNpsGrouped(string $query, string $sessionId, ChatMemory $memory): ?string
    {
        try {
            $normalized = Str::of($query)->lower();
            
            // Extract question text if mentioned
            $questionText = null;
            if (preg_match('/["\']([^"\']+)["\']/', $query, $matches)) {
                $questionText = $matches[1];
            } elseif (preg_match('/(?:question|for)\s+([^?,.]+)/i', $query, $matches)) {
                $questionText = trim($matches[1]);
            }
            
            // Determine grouping dimension (grade, etc.)
            $groupByDimension = 'grade';
            if ($normalized->contains(['grade', 'grades', 'grade level'])) {
                $groupByDimension = 'grade';
            } elseif ($normalized->contains(['class', 'classes', 'classroom'])) {
                $groupByDimension = 'class';
            } elseif ($normalized->contains(['department', 'departments'])) {
                $groupByDimension = 'department';
            }
            
            // Find the grade/demographic filtering question
            $gradeQuestion = $this->findGradeFilteringQuestion($groupByDimension);
            
            if ($gradeQuestion === null) {
                $this->debugLog(['phase' => 'nps_grouped:no_grade_question', 'query' => $query, 'dimension' => $groupByDimension]);
                // Fall back to regular NPS if we can't find grade question
                return null;
            }
            
            // If a specific question is mentioned, resolve it (for filtering context)
            $mentionedQuestion = null;
            if ($questionText !== null) {
                $resolved = $this->resolveQuestionByText($questionText);
                if ($resolved !== null) {
                    $mentionedQuestion = $resolved;
                }
            }
            
            $timeConditions = $this->buildRelativeDateConditions($query);
            $module = $this->extractModuleFromQuery($query);
            $cycleLabel = $this->extractCycleLabelFromQuery($query);
            
            // Build query: NPS answers joined with grade filtering answers, grouped by grade
            $base = DB::connection('tenant')
                ->table('survey_answers as nps')
                ->join('survey_invites as si', 'si.id', '=', 'nps.survey_invite_id')
                ->leftJoin('survey_cycles as sc', 'sc.id', '=', 'si.survey_cycle_id')
                ->join('survey_answers as grade_filter', function($join) use ($gradeQuestion) {
                    $join->on('grade_filter.survey_invite_id', '=', 'nps.survey_invite_id')
                         ->where('grade_filter.questionable_type', $gradeQuestion['questionable_type'])
                         ->where('grade_filter.questionable_id', $gradeQuestion['id']);
                })
                ->where('nps.question_type', 'nps')
                ->whereRaw("nps.value REGEXP '^[0-9]+$'")
                ->whereRaw('CAST(nps.value AS UNSIGNED) BETWEEN 0 AND 10');
            
            // If a specific question is mentioned, optionally filter to invites that answered that question
            // (This provides context - only calculate NPS for people who answered this question)
            if ($mentionedQuestion !== null) {
                $base->join('survey_answers as mentioned_q', function($join) use ($mentionedQuestion) {
                    $join->on('mentioned_q.survey_invite_id', '=', 'nps.survey_invite_id')
                         ->where('mentioned_q.questionable_type', $mentionedQuestion['questionable_type'])
                         ->where('mentioned_q.questionable_id', $mentionedQuestion['id']);
                });
                $this->debugLog([
                    'phase' => 'nps_grouped:mentioned_question_filter',
                    'mentionedQuestion' => $mentionedQuestion,
                ]);
            }
            
            foreach ($timeConditions as $condition) {
                $base->whereRaw($condition);
            }
            
            if ($module !== null) {
                $base->where('nps.module_type', $module);
            }
            
            $base->where(function ($q) {
                $q->whereNull('sc.status')
                  ->orWhereIn('sc.status', ['completed', 'removed', 'active', 'inactive', 'cancelled']);
            });
            
            if ($cycleLabel !== null) {
                $base->whereRaw('LOWER(sc.label) LIKE ?', ['%'.Str::lower($cycleLabel).'%']);
            }
            
            // Group by grade value and calculate NPS
            $aggregateBuilder = clone $base;
            $aggregateBuilder
                ->selectRaw('grade_filter.value AS grade_level')
                ->selectRaw('COUNT(*) AS total_responses')
                ->selectRaw('SUM(CASE WHEN CAST(nps.value AS UNSIGNED) BETWEEN 9 AND 10 THEN 1 ELSE 0 END) AS promoters')
                ->selectRaw('SUM(CASE WHEN CAST(nps.value AS UNSIGNED) BETWEEN 7 AND 8 THEN 1 ELSE 0 END) AS passives')
                ->selectRaw('SUM(CASE WHEN CAST(nps.value AS UNSIGNED) BETWEEN 0 AND 6 THEN 1 ELSE 0 END) AS detractors')
                ->whereNotNull('grade_filter.value')
                ->where('grade_filter.value', '<>', '')
                ->groupBy('grade_filter.value')
                ->orderBy('grade_filter.value');
            
            $this->debugLog([
                'phase' => 'nps_grouped:sql',
                'query' => $query,
                'questionText' => $questionText,
                'gradeQuestion' => $gradeQuestion,
                'dimension' => $groupByDimension,
                'sql' => $aggregateBuilder->toSql(),
                'bindings' => $aggregateBuilder->getBindings(),
            ]);
            
            $results = $aggregateBuilder->get();
            
            if ($results->isEmpty()) {
                return null;
            }
            
            // Format results
            $groupedData = [];
            foreach ($results as $row) {
                $grade = (string) ($row->grade_level ?? 'Unknown');
                $total = (int) ($row->total_responses ?? 0);
                $promoters = (int) ($row->promoters ?? 0);
                $passives = (int) ($row->passives ?? 0);
                $detractors = (int) ($row->detractors ?? 0);
                
                if ($total === 0) {
                    continue;
                }
                
                $npsScore = (($promoters - $detractors) / $total) * 100;
                $promoterPct = ($promoters / $total) * 100;
                $detractorPct = ($detractors / $total) * 100;
                
                $groupedData[] = [
                    'grade_level' => $grade,
                    'nps_score' => round($npsScore, 1),
                    'total_responses' => $total,
                    'promoters' => $promoters,
                    'passives' => $passives,
                    'detractors' => $detractors,
                    'promoter_percent' => round($promoterPct, 1),
                    'detractor_percent' => round($detractorPct, 1),
                ];
            }
            
            if (empty($groupedData)) {
                return null;
            }
            
            // Format response in HTML
            $html = '<div class="nps-by-grade">';
            $html .= '<p>Here is the Net Promoter Score for each grade level:</p>';
            $html .= '<ol>';
            
            foreach ($groupedData as $data) {
                $html .= '<li>';
                $html .= '<strong>Grade ' . htmlspecialchars($data['grade_level'], ENT_QUOTES, 'UTF-8') . ':</strong> ';
                $html .= 'NPS Score is <strong>' . htmlspecialchars((string) $data['nps_score'], ENT_QUOTES, 'UTF-8') . '</strong>. ';
                $html .= 'Based on ' . htmlspecialchars((string) $data['total_responses'], ENT_QUOTES, 'UTF-8') . ' responses: ';
                $html .= htmlspecialchars((string) $data['promoters'], ENT_QUOTES, 'UTF-8') . ' promoters (' . htmlspecialchars((string) $data['promoter_percent'], ENT_QUOTES, 'UTF-8') . '%), ';
                $html .= htmlspecialchars((string) $data['passives'], ENT_QUOTES, 'UTF-8') . ' passives, ';
                $html .= htmlspecialchars((string) $data['detractors'], ENT_QUOTES, 'UTF-8') . ' detractors (' . htmlspecialchars((string) $data['detractor_percent'], ENT_QUOTES, 'UTF-8') . '%)';
                $html .= '</li>';
            }
            
            $html .= '</ol>';
            $html .= '</div>';
            
            if ($sessionId !== '') {
                $memory->rememberAnalytics($sessionId, $query, $groupedData, [
                    'source' => 'nps-grouped-by-grade',
                    'module' => $module,
                    'time_conditions' => $timeConditions,
                    'grade_question' => $gradeQuestion,
                ], $html);
            }
            
            return $html;
            
        } catch (\Throwable $e) {
            $this->debugLog([
                'phase' => 'nps_grouped:error',
                'query' => $query,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    private function findGradeFilteringQuestion(string $dimension = 'grade'): ?array
    {
        try {
            $centralDb = (string) env('DB_DATABASE', '');
            $centralQuestions = $centralDb !== '' ? "{$centralDb}.questions" : null;
            
            $searchTerms = [];
            if ($dimension === 'grade') {
                $searchTerms = ['grade', 'grades', 'grade level', 'class level'];
            } elseif ($dimension === 'class') {
                $searchTerms = ['class', 'classes', 'classroom'];
            } elseif ($dimension === 'department') {
                $searchTerms = ['department', 'departments'];
            }
            
            // Search in tenant questions first
            $tenantQuery = DB::connection('tenant')
                ->table('questions')
                ->where('question_type', 'filtering')
                ->where(function($q) use ($searchTerms) {
                    foreach ($searchTerms as $term) {
                        $q->orWhere('question_text', 'LIKE', '%'.$term.'%');
                    }
                })
                ->whereNull('deleted_at')
                ->limit(10);
            
            $tenantQuestions = $tenantQuery->get();
            
            // Search in central questions
            $centralQuestionsList = [];
            if ($centralQuestions !== null) {
                $centralQuery = DB::connection('mysql')
                    ->table($centralQuestions)
                    ->where('question_type', 'filtering')
                    ->where(function($q) use ($searchTerms) {
                        foreach ($searchTerms as $term) {
                            $q->orWhere('question_text', 'LIKE', '%'.$term.'%');
                        }
                    })
                    ->whereNull('deleted_at')
                    ->limit(10);
                
                $centralQuestionsList = $centralQuery->get();
            }
            
            // Prefer tenant questions, but check both
            foreach ($tenantQuestions as $q) {
                // Verify this question has answers in survey_answers
                $hasAnswers = DB::connection('tenant')
                    ->table('survey_answers')
                    ->where('questionable_type', 'App\\Models\\Tenant\\Question')
                    ->where('questionable_id', $q->id)
                    ->where('question_type', 'filtering')
                    ->whereNotNull('value')
                    ->exists();
                
                if ($hasAnswers) {
                    return [
                        'id' => $q->id,
                        'text' => $q->question_text,
                        'questionable_type' => 'App\\Models\\Tenant\\Question',
                    ];
                }
            }
            
            foreach ($centralQuestionsList as $q) {
                $hasAnswers = DB::connection('tenant')
                    ->table('survey_answers')
                    ->where('questionable_type', 'App\\Models\\Question')
                    ->where('questionable_id', $q->id)
                    ->where('question_type', 'filtering')
                    ->whereNotNull('value')
                    ->exists();
                
                if ($hasAnswers) {
                    return [
                        'id' => $q->id,
                        'text' => $q->question_text,
                        'questionable_type' => 'App\\Models\\Question',
                    ];
                }
            }
            
            return null;
        } catch (\Throwable $e) {
            $this->debugLog(['phase' => 'findGradeFilteringQuestion:error', 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function answerRosterQuery(string $query, string $sessionId, ChatMemory $memory): ?string
    {
        if (! $this->looksLikeRosterQuery($query)) {
            return null;
        }

        $module = $this->determineRosterModule($query);
        if ($module === null) {
            return null;
        }

        if (! in_array($module, ['student', 'parent', 'employee'], true)) {
            return null;
        }

        $normalized = Str::of($query)->lower();
        $needsEmails = $normalized->contains(['email', 'emails', 'email address', 'email addresses']);
        $needsNames = $normalized->contains(['name', 'names', 'who are', 'list of']);
        $needsCount = $needsEmails || $needsNames
            ? $normalized->contains(['how many', 'count', 'total', 'number of', 'how much', 'how many do we have'])
            : true;

        $explicitLimit = $this->extractRosterLimit($query);
        $detailLimit = $explicitLimit ?? null;

        try {
            $data = $this->fetchRosterData($module, $detailLimit);
            if ($data === null) {
                return null;
            }

            $count = $data['count'];
            $rows = $data['rows'];

            $allNames = [];
            $allEmails = [];

            foreach ($rows as $row) {
                $name = trim(implode(' ', array_filter([$row['first_name'] ?? '', $row['last_name'] ?? ''])));
                if ($name !== '') {
                    $allNames[] = $name;
                }

                $email = $row['email'] ?? '';
                if ($email !== '') {
                    $allEmails[] = Str::lower($email);
                }
            }

            $allNames = array_slice(array_values(array_unique($allNames)), 0, 200);
            $allEmails = array_slice(array_values(array_unique($allEmails)), 0, 200);

            $namesForResponse = $needsNames ? $allNames : [];
            $emailsForResponse = $needsEmails ? $allEmails : [];

            $moduleLabels = [
                'student' => ['singular' => 'student', 'plural' => 'students'],
                'parent' => ['singular' => 'parent', 'plural' => 'parents'],
                'employee' => ['singular' => 'employee', 'plural' => 'employees'],
                'admin' => ['singular' => 'admin', 'plural' => 'admins'],
            ];

            $label = $moduleLabels[$module]['plural'] ?? Str::plural($module);
            $parts = [];

            if ($needsCount || (! $needsNames && ! $needsEmails)) {
                $parts[] = "We have {$count} {$label}.";
            }

            if ($needsNames) {
                if ($namesForResponse === []) {
                    $parts[] = 'I could not find any names for that group.';
                } else {
                    $lines = implode("\n", array_map(static fn ($value) => '- '.$value, $namesForResponse));
                    $parts[] = "Names:\n{$lines}";
                }
            }

            if ($needsEmails) {
                if ($emailsForResponse === []) {
                    $parts[] = 'I could not find any email addresses for that group.';
                } else {
                    $lines = implode("\n", array_map(static fn ($value) => '- '.$value, $emailsForResponse));
                    $parts[] = "Email addresses:\n{$lines}";
                }
            }

            if ($explicitLimit !== null) {
                $parts[] = "Showing {$explicitLimit} {$label} as requested.";
            }

            $response = implode("\n\n", array_filter($parts));

            if ($sessionId !== '') {
                $memory->rememberAnalytics($sessionId, $query, $rows, [
                    'source' => 'roster-summary',
                    'module' => $module,
                    'count' => $count,
                    'names' => $allNames,
                    'emails' => $allEmails,
                ], $response);
            }

            return $response;
        } catch (\Throwable $e) {
            Log::warning('AnalyticsTool roster handling failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function looksLikeRosterQuery(string $query): bool
    {
        $module = $this->determineRosterModule($query);
        if ($module === null) {
            return false;
        }

        $normalized = Str::of($query)->lower();
        $surveyMarkers = [
            'survey', 'question', 'questions', 'response', 'responses', 'answer', 'answers',
            'selected', 'select', 'choice', 'choices', 'option', 'options', 'score', 'scores',
            'rating', 'ratings', 'nps', 'promoter', 'promoters', 'detractor', 'detractors',
            'comment', 'comments', 'feedback', 'result', 'results', 'trend', 'trends',
        ];

        foreach ($surveyMarkers as $marker) {
            if ($normalized->contains($marker)) {
                return false;
            }
        }

        return true;
    }

    private function determineRosterModule(string $query): ?string
    {
        $normalized = Str::of($query)->lower();

        if ($normalized->contains(['student', 'students'])) {
            return 'student';
        }

        if ($normalized->contains(['parent', 'parents', 'family', 'families'])) {
            return 'parent';
        }

        if ($normalized->contains(['employee', 'employees', 'staff', 'teachers', 'teacher'])) {
            return 'employee';
        }

        if ($normalized->contains(['admin', 'admins', 'administrator', 'administrators', 'owner'])) {
            return 'admin';
        }

        return null;
    }

    private function extractRosterLimit(string $query): ?int
    {
        $normalized = Str::of($query)->lower()->toString();

        $patterns = [
            '/\b(?:first|top|last)\s+(\d{1,4})\b/',
            '/\b(?:limit|only|just|up to)\s+(\d{1,4})\b/',
            '/\bshow(?:\s+me)?\s+(\d{1,4})\b/',
            '/\blist\s+(\d{1,4})\b/',
            '/\b(\d{1,4})\s+(?:students?|parents?|employees?|admins?|emails?|names?|records?)\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                $limit = (int) ($matches[1] ?? 0);
                if ($limit > 0) {
                    return $limit;
                }
            }
        }

        return null;
    }

    /**
     * @return array{count: int, rows: array<int, array<string, string|null>>}|null
     */
    private function fetchRosterData(string $module, ?int $limit = null): ?array
    {
        $conn = DB::connection('tenant');

        switch ($module) {
            case 'student':
                if (! $this->tableExists('students')) {
                    return null;
                }
                $count = (int) $conn->table('students')->count();
                $query = $conn->table('students')->select('*');
                if ($limit !== null && $limit > 0) {
                    $query->limit($limit);
                }
                $rows = $query->get()->map(fn ($row) => (array) $row)->toArray();
                $normalized = $this->normalizeRosterRows($rows, [
                    'first' => ['first_name', 'firstname'],
                    'last' => ['last_name', 'lastname'],
                    'email' => ['email', 'email_address'],
                ]);
                return ['count' => $count, 'rows' => $normalized];

            case 'parent':
                if (! $this->tableExists('people')) {
                    return null;
                }
                $count = (int) $conn->table('people')->count();
                $query = $conn->table('people')->select('*');
                if ($limit !== null && $limit > 0) {
                    $query->limit($limit);
                }
                $rows = $query->get()->map(fn ($row) => (array) $row)->toArray();
                $normalized = $this->normalizeRosterRows($rows, [
                    'first' => ['first_name', 'firstname'],
                    'last' => ['last_name', 'lastname'],
                    'email' => ['email', 'email_address'],
                ]);
                return ['count' => $count, 'rows' => $normalized];

            case 'employee':
                if (! $this->tableExists('employees')) {
                    return null;
                }
                $count = (int) $conn->table('employees')->count();

                $builder = $conn->table('employees as e')->select('e.*');
                if ($limit !== null && $limit > 0) {
                    $builder->limit($limit);
                }

                $rows = $builder->get()->map(fn ($row) => (array) $row)->toArray();

                $normalized = $this->normalizeRosterRows($rows, [
                    'first' => ['first_name', 'firstname'],
                    'last' => ['last_name', 'lastname'],
                    'email' => ['email', 'work_email', 'personal_email'],
                ]);

                return ['count' => $count, 'rows' => $normalized];

            case 'admin':
                if (! $this->tableExists('users')) {
                    return null;
                }

                $count = (int) $conn->table('users')->count();
                $query = $conn->table('users')->select('*');
                if ($limit !== null && $limit > 0) {
                    $query->limit($limit);
                }

                $rows = $query->get()->map(fn ($row) => (array) $row)->toArray();

                $normalized = $this->normalizeRosterRows($rows, [
                    'first' => ['first_name'],
                    'last' => ['last_name'],
                    'full' => ['name'],
                    'email' => ['email'],
                ]);

                return ['count' => $count, 'rows' => $normalized];
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array{first: array<int, string>, last: array<int, string>, email: array<int, string>} $columns
     * @return array<int, array<string, string|null>>
     */
    private function normalizeRosterRows(array $rows, array $columns): array
    {
        return array_map(function (array $row) use ($columns) {
            $first = $this->pickColumnValue($row, $columns['first'] ?? []);
            $last = $this->pickColumnValue($row, $columns['last'] ?? []);
            $email = $this->pickColumnValue($row, $columns['email'] ?? []);

            if (($first === null || $last === null) && isset($columns['full'])) {
                $full = $this->pickColumnValue($row, $columns['full']);
                if ($full !== null) {
                    $pieces = preg_split('/\s+/', $full, 2);
                    if ($pieces !== false && count($pieces) >= 1) {
                        $first = $first ?? trim($pieces[0]);
                        if (count($pieces) === 2) {
                            $last = $last ?? trim($pieces[1]);
                        }
                    }
                    if ($last === null && $first === null) {
                        $first = $full;
                    }
                }
            }

            return [
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
            ];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $candidates
     */
    private function pickColumnValue(array $row, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! array_key_exists($candidate, $row)) {
                continue;
            }

            $value = $row[$candidate];
            if ($value === null) {
                continue;
            }

            $string = trim((string) $value);
            if ($string === '') {
                continue;
            }

            return $string;
        }

        return null;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::connection('tenant')->hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function resolveQuestionByText(string $query): ?array
    {
        $candidates = $this->extractQuestionCandidates($query);
        if ($candidates === []) {
            return null;
        }

        $connections = array_values(array_unique([
            config('database.default', 'mysql'),
            'tenant',
        ]));

        $matches = [];
        foreach ($connections as $connection) {
            $columns = $this->questionTextColumns($connection);
            if ($columns === []) {
                continue;
            }

            foreach ($candidates as $candidate) {
                $normalizedCandidate = $this->normalizeQuestionString($candidate);
                if ($normalizedCandidate === '') {
                    continue;
                }

                $like = '%'.$this->questionLikeFragment($candidate).'%';

                foreach ($columns as $column) {
                    try {
                        $rows = DB::connection($connection)
                            ->table('questions')
                            ->select('id', DB::raw("{$column} AS question_text"))
                            ->whereRaw(
                                "LOWER(REPLACE(REPLACE(REPLACE({$column}, '[CLIENT_NAME]', ''), '[client_name]', ''), '[Client Name]', '')) LIKE ?",
                                [$like]
                            )
                            ->limit(40)
                            ->get();
                    } catch (\Throwable $e) {
                        continue;
                    }

                    foreach ($rows as $row) {
                        $text = (string) ($row->question_text ?? '');
                        $normalizedText = $this->normalizeQuestionString($text);
                        if ($normalizedText === '') {
                            continue;
                        }

                        $score = $this->questionSimilarity($normalizedCandidate, $normalizedText);
                        if ($score < 0.55) {
                            continue;
                        }

                        $matches[] = [
                            'score' => $score,
                            'id' => (int) $row->id,
                            'text' => $text,
                            'questionable_type' => $connection === 'tenant'
                                ? 'App\\\\Models\\\\Tenant\\\\Question'
                                : 'App\\\\Models\\\\Question',
                        ];
                    }
                }
            }
        }

        if ($matches !== []) {
            $selected = $this->selectBestQuestionMatch($matches);
            if ($selected !== null) {
                Log::debug('Resolved question via question table search', [
                    'query' => $query,
                    'question_id' => $selected['id'],
                    'questionable_type' => $selected['questionable_type'],
                    'score' => $selected['score'],
                ]);
                return $selected;
            }

            Log::debug('Question matches lacked confirmed survey answers; falling back to answer-derived search', [
                'query' => $query,
                'candidates_count' => count($matches),
            ]);
        }

        $fallback = $this->searchQuestionsViaAnswers($candidates);
        if ($fallback !== null) {
            Log::debug('Resolved question via survey answer backfill', [
                'query' => $query,
                'question_id' => $fallback['id'] ?? null,
                'questionable_type' => $fallback['questionable_type'] ?? null,
                'score' => $fallback['score'] ?? null,
            ]);
        } else {
            Log::debug('Failed to resolve question from wording', [
                'query' => $query,
                'candidates' => $candidates,
            ]);
        }

        return $fallback;
    }
    private function answerResolvedQuestion(string $query, ?array $question, string $sessionId, ChatMemory $memory): ?string
    {
        if ($question === null) {
            return null;
        }

        try {
            $filters = $this->extractAnswerFilters($query);

            $base = DB::connection('tenant')
                ->table('survey_answers as sa')
                ->leftJoin('survey_invites as si', 'si.id', '=', 'sa.survey_invite_id')
                ->leftJoin('survey_cycles as sc', 'sc.id', '=', 'si.survey_cycle_id')
                ->where('sa.questionable_type', $question['questionable_type'])
                ->where('sa.questionable_id', $question['id']);

            $timeConditions = $this->buildRelativeDateConditions($query);
            foreach ($timeConditions as $condition) {
                $base->whereRaw($condition);
            }

            $module = $this->extractModuleFromQuery($query);
            if ($module) {
                $base->where('sa.module_type', $module);
            }

            if (! empty($filters['values'])) {
                $base->whereIn('sa.value', array_map('strval', $filters['values']));
            }

            $aggregateBuilder = clone $base;

            $aggregateBuilder
                ->select('sa.value', DB::raw('COUNT(*) as answer_count'))
                ->groupBy('sa.value')
                ->orderBy('sa.value')
                ->limit(200);

            Log::debug('AnalyticsTool resolved question SQL', [
                'query' => $query,
                'question' => $question,
                'sql' => $aggregateBuilder->toSql(),
                'bindings' => $aggregateBuilder->getBindings(),
            ]);

            $aggregates = $aggregateBuilder->get();

            $totalResponses = (int) $aggregates->sum('answer_count');

            $data = $aggregates->map(fn ($row) => [
                'value' => (string) ($row->value ?? ''),
                'answer_count' => (int) $row->answer_count,
            ])->values()->toArray();

            $analysis = $this->analyzeWithAI(
                $query,
                [
                    [
                        'question' => $question['text'],
                        'total_responses' => $totalResponses,
                        'aggregates' => $data,
                    ],
                ],
                ['survey_answers', 'survey_invites', 'survey_cycles', 'questions']
            );

            Log::debug('AnalyticsTool question aggregates', [
                'query' => $query,
                'question' => $question,
                'filters' => $filters,
                'total_responses' => $totalResponses,
                'aggregates_preview' => array_slice($data, 0, 5),
            ]);

            if ($sessionId !== '') {
                $memory->rememberAnalytics($sessionId, $query, $data, [
                    'source' => 'question-analysis',
                    'question' => [
                        'id' => $question['id'],
                        'text' => $question['text'],
                        'questionable_type' => $question['questionable_type'],
                    ],
                    'aggregates' => $data,
                    'total_responses' => $totalResponses,
                    'filters' => $filters,
                ], $analysis);
            }

            return $analysis;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function extractQuestionCandidates(string $query): array
    {
        $candidates = [];

        if (preg_match_all('/"([^"\n]{4,})"/', $query, $matches)) {
            foreach ($matches[1] as $match) {
                $candidates[] = trim($match);
            }
        }

        if (preg_match_all("/'([^'\n]{4,})'/", $query, $singleMatches)) {
            foreach ($singleMatches[1] as $match) {
                $candidates[] = trim($match);
            }
        }

        if (preg_match('/question\s*[:\-]\s*(.+)$/i', $query, $tail)) {
            $candidates[] = trim($tail[1]);
        }

        if (preg_match('/survey question\s*(?:is|was)?\s*(.+)/i', $query, $tail2)) {
            $candidates[] = trim($tail2[1]);
        }

        if (preg_match('/question\s+(?:about|regarding|named|called)?\s*(.+)$/i', $query, $tail3)) {
            $candidates[] = trim($tail3[1]);
        }

        $candidates[] = trim($query);

        $expanded = [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate, " \t\n\r\0\x0B?!.\"");
            if ($candidate === '') {
                continue;
            }

            $expanded[] = $candidate;

            $words = preg_split('/\s+/', $candidate) ?: [];
            $wordCount = count($words);
            for ($trim = 0; $trim < 3 && $wordCount - $trim > 4; $trim++) {
                array_pop($words);
                $expanded[] = trim(implode(' ', $words));
            }

            $expanded[] = preg_replace('/\[[^\]]+\]/', '', $candidate); // strip placeholders

            $expanded[] = preg_replace('/\b(?:[A-Z][a-z]+\s*)+$/', '', $candidate); // remove trailing proper nouns
        }

        $expanded = array_filter(array_map(fn ($c) => trim((string) $c), $expanded), fn ($c) => Str::length($c) >= 4);

        return array_values(array_unique($expanded));
    }

    private function questionTextColumns(string $connection): array
    {
        try {
            $columns = DB::connection($connection)->getSchemaBuilder()->getColumnListing('questions');
        } catch (\Throwable $e) {
            return [];
        }

        $priority = ['question_text', 'text', 'question', 'label', 'prompt', 'body', 'title', 'name'];
        return array_values(array_intersect($priority, $columns));
    }

    private function buildQuestionTextSelect(string $tenantAlias, string $centralAlias, array $tenantColumns, array $centralColumns): string
    {
        $parts = [];
        foreach ($centralColumns as $column) {
            $column = str_replace('`', '', $column);
            $parts[] = "{$centralAlias}.`{$column}`";
        }
        foreach ($tenantColumns as $column) {
            $column = str_replace('`', '', $column);
            $parts[] = "{$tenantAlias}.`{$column}`";
        }

        $parts[] = 'driver.questionable_type';
        $parts = array_unique($parts);

        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private function normalizeQuestionString(string $text): string
    {
        $text = Str::lower($text);
        $text = preg_replace('/\[[^\]]+\]/', ' ', $text);
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text ?? '');
        return trim((string) $text);
    }

    private function questionLikeFragment(string $text): string
    {
        $normalized = $this->normalizeQuestionString($text);
        return str_replace(' ', '%', $normalized);
    }

    private function questionSimilarity(string $candidate, string $text): float
    {
        if ($candidate === '' || $text === '') {
            return 0.0;
        }

        similar_text($candidate, $text, $percent);

        return $percent / 100;
    }

    private function searchQuestionsViaAnswers(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }

        $answers = DB::connection('tenant')
            ->table('survey_answers')
            ->select('questionable_type', 'questionable_id')
            ->whereNotNull('questionable_type')
            ->whereNotNull('questionable_id')
            ->distinct()
            ->limit(1000)
            ->get();

        if ($answers->isEmpty()) {
            return null;
        }

        $matches = [];
        $cache = [];

        foreach ($answers as $row) {
            $type = (string) ($row->questionable_type ?? '');
            $id = (int) ($row->questionable_id ?? 0);
            if ($type === '' || $id === 0) {
                continue;
            }

            $details = $this->fetchQuestionDetails($type, $id, $cache);
            if ($details === null) {
                continue;
            }

            $text = (string) ($details['text'] ?? '');
            
            $normalizedText = $this->normalizeQuestionString($text);
            if ($normalizedText === '') {
                continue;
            }

            foreach ($candidates as $candidate) {
                $normalizedCandidate = $this->normalizeQuestionString($candidate);
                if ($normalizedCandidate === '') {
                    continue;
                }

                $score = $this->questionSimilarity($normalizedCandidate, $normalizedText);
                if ($score < 0.55) {
                    continue;
                }

                $matches[] = [
                    'score' => $score,
                    'id' => $id,
                    'text' => $text,
                    'source' => $details['source'] ?? null,
                    'questionable_type' => $type,
                ];
            }
        }

        return $this->selectBestQuestionMatch($matches);
    }

    private function selectBestQuestionMatch(array $matches): ?array
    {
        if ($matches === []) {
            return null;
        }

        usort($matches, static fn ($a, $b) => $b['score'] <=> $a['score']);

        foreach ($matches as $match) {
            $resolvedType = $this->resolveQuestionableTypeFromAnswers($match['id'], (string) ($match['questionable_type'] ?? ''));
            if ($resolvedType === null) {
                continue;
            }

            if (! $this->questionHasAnswers($resolvedType, $match['id'])) {
                continue;
            }

            $match['questionable_type'] = $resolvedType;
            return $match;
        }

        return null;
    }

    private function questionHasAnswers(string $type, int $id): bool
    {
        try {
            return DB::connection('tenant')
                ->table('survey_answers')
                ->where('questionable_type', $type)
                ->where('questionable_id', $id)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function resolveQuestionableTypeFromAnswers(int $questionId, ?string $preferredType): ?string
    {
        try {
            $types = DB::connection('tenant')
                ->table('survey_answers')
                ->where('questionable_id', $questionId)
                ->whereNotNull('questionable_type')
                ->distinct()
                ->pluck('questionable_type');
        } catch (\Throwable $e) {
            return null;
        }

        $types = $types
            ->map(static fn ($type) => trim((string) $type))
            ->filter(static fn ($type) => $type !== '')
            ->values();

        if ($types->isEmpty()) {
            return null;
        }

        if ($preferredType !== null && $preferredType !== '' && $types->contains($preferredType)) {
            return $preferredType;
        }

        $withQuestionKeyword = $types->first(static fn ($type) => str_contains(strtolower($type), 'question'));
        if ($withQuestionKeyword !== null) {
            return $withQuestionKeyword;
        }

        return $types->first();
    }

    /**
     * @return array{text?: string, type?: string|null, module_type?: string|null, source?: string|null}|null
     */
    private function fetchQuestionDetails(string $type, int $id, array &$cache): ?array
    {
        $cacheKey = $type.'#'.$id;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $connection = str_contains($type, 'Tenant') ? 'tenant' : config('database.default', 'mysql');
        $columns = $this->questionTextColumns($connection);
        $selectColumns = array_values(array_unique(array_merge($columns, ['name', 'type', 'module_type'])));

        try {
            $query = DB::connection($connection)->table('questions')->where('id', $id);
        } catch (\Throwable $e) {
            return $cache[$cacheKey] = null;
        }

        try {
            $record = (array) ($query->first($selectColumns) ?? []);
        } catch (\Throwable $e) {
            return $cache[$cacheKey] = null;
        }

        if ($record === []) {
            return $cache[$cacheKey] = null;
        }

        $text = null;
        foreach (array_merge($columns, ['name', 'label', 'title', 'question']) as $column) {
            if ($column === null || ! array_key_exists($column, $record)) {
                continue;
            }
            $value = trim((string) ($record[$column] ?? ''));
            if ($value !== '') {
                $text = $value;
                break;
            }
        }

        if ($text === null || $text === '') {
            $text = $type.' #'.$id;
        }

        return $cache[$cacheKey] = [
            'text' => $text,
            'type' => $record['type'] ?? null,
            'module_type' => $record['module_type'] ?? null,
            'source' => $connection === 'tenant' ? 'tenant' : 'central',
        ];
    }

    /**
     * @return array{values?: array<int>}
     */
    private function extractAnswerFilters(string $query): array
    {
        $filters = [];
        $normalized = Str::lower($query);

        if (preg_match('/\b(?:answer(?:ed)?|responses?|value|score|option|selected)\b[^\d]{0,40}([\d\s,orand\-]+)/i', $query, $match)) {
            $segment = $match[1];
            preg_match_all('/\d+/', $segment, $numbers);
            $values = array_map('intval', $numbers[0] ?? []);
            if ($values !== []) {
                $filters['values'] = array_values(array_unique($values));
            }
        }

        if (preg_match_all('/"([^"\n]{1,60})"/', $query, $quoted)) {
            $filters['values'] = array_values(array_unique(array_merge(
                $filters['values'] ?? [],
                array_map('trim', $quoted[1])
            )));
        }

        if (preg_match_all("/'([^'\n]{1,60})'/", $query, $singleQuoted)) {
            $filters['values'] = array_values(array_unique(array_merge(
                $filters['values'] ?? [],
                array_map('trim', $singleQuoted[1])
            )));
        }

        if (! empty($filters['values'])) {
            $filters['values'] = array_map(static fn ($value) => is_numeric($value) ? (string) (int) $value : (string) $value, $filters['values']);

            $filters['values'] = array_values(array_filter(
                $filters['values'],
                static function (string $value) use ($normalized) {
                    $pattern = '/\b' . preg_quote($value, '/') . '\s+(?:day|days|week|weeks|month|months|quarter|quarters|year|years)\b/';
                    return ! preg_match($pattern, $normalized);
                }
            ));

            if ($filters['values'] === []) {
                unset($filters['values']);
            }
        }

        if (isset($filters['values']) && Str::contains($normalized, ['between', 'range'])) {
            sort($filters['values']);
        }

        return $filters;
    }

    private function answerFromResponseText(string $normalizedQuery, string $responseText, ?string $sourceQuery): ?string
    {
        if ($responseText === '') {
            return null;
        }

        if (Str::contains($normalizedQuery, 'email')) {
            $emails = $this->extractEmailsFromText($responseText);
            if ($emails !== []) {
                return $this->formatListResponse('email addresses', $emails, $sourceQuery);
            }
        }

        if (Str::contains($normalizedQuery, 'permission')) {
            $permissions = $this->extractPermissionsFromText($responseText);
            if ($permissions !== []) {
                return $this->formatListResponse('permissions', $permissions, $sourceQuery);
            }
        }

        if (Str::contains($normalizedQuery, 'name') || Str::contains($normalizedQuery, ['employee', 'staff'])) {
            $names = $this->extractNamesFromText($responseText);
            if ($names !== []) {
                $label = Str::contains($normalizedQuery, ['employee', 'staff']) ? 'employees' : 'names';
                return $this->formatListResponse($label, $names, $sourceQuery);
            }
        }

        if (Str::contains($normalizedQuery, ['comment', 'comments', 'feedback'])) {
            $comments = $this->extractCommentsFromText($responseText);
            if ($comments !== []) {
                return $this->formatListResponse('comments', $comments, $sourceQuery);
            }
        }

        if (Str::contains($normalizedQuery, ['count', 'how many', 'number', 'responses'])) {
            if (preg_match('/found\s+(\d+)\s+[a-z]+/i', $responseText, $matches)) {
                $count = (int) $matches[1];
                $label = $sourceQuery ? 'previous result for "' . Str::limit($sourceQuery, 80) . '"' : 'previous result';
                return "The {$label} contained {$count} records.";
            }
        }

        return null;
    }

    private function extractEmailsFromText(string $text): array
    {
        preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $matches);
        $emails = array_values(array_unique($matches[0] ?? []));
        return array_slice($emails, 0, 50);
    }

    private function extractNamesFromText(string $text): array
    {
        $lines = preg_split('/\r?\n/', $text);
        $names = [];
        foreach ($lines as $line) {
            $line = trim($line, "- \t\n\r\0\x0B");
            if ($line === '') {
                continue;
            }

            if (preg_match('/^([A-Z][A-Za-z\'\-]+(?:\s+[A-Z][A-Za-z\'\-]+)+)/', $line, $match)) {
                $names[] = trim($match[1]);
            }
        }

        $names = array_values(array_unique($names));
        return array_slice($names, 0, 50);
    }

    private function extractCommentsFromText(string $text): array
    {
        $lines = preg_split('/\r?\n/', $text);
        $comments = [];
        foreach ($lines as $line) {
            $line = trim($line, "- \t\n\r\0\x0B");
            if ($line === '') {
                continue;
            }
            if (str_contains(strtolower($line), 'comment')) {
                $comments[] = $line;
            }
        }

        return array_slice(array_values(array_unique($comments)), 0, 20);
    }

    private function extractPermissionsFromText(string $text): array
    {
        $lines = preg_split('/\r?\n/', $text);
        $permissions = [];
        foreach ($lines as $line) {
            $line = trim($line, "- \t\n\r\0\x0B");
            if ($line === '') {
                continue;
            }
            if (str_contains(strtolower($line), 'permission')) {
                $permissions[] = $line;
            }
        }

        return array_slice(array_values(array_unique($permissions)), 0, 30);
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'y'], true);
        }

        return false;
    }

    private function extractPersonNameFromQuery(string $query): ?string
    {
        if (preg_match('/([A-Z][A-Za-z\'\-]+)\s+([A-Z][A-Za-z\'\-]+)/i', $query, $matches)) {
            return trim($matches[1].' '.$matches[2]);
        }

        return null;
    }

    /**
     * Attempt to retrieve relevant email addresses for the prior audience context.
     *
     * @return array<int, string>|null
     */
    private function fetchEmailsForContext(array $last): ?array
    {
        $meta = $last['meta'] ?? [];
        $previousSql = strtolower((string) ($meta['sql'] ?? ''));
        $previousQueryText = strtolower((string) ($last['query'] ?? ''));

        $candidateTables = [];
        $candidateTables = array_merge($candidateTables, $this->extractAudienceTablesFromSql($previousSql));
        if ($candidateTables === []) {
            $candidateTables = array_merge($candidateTables, $this->extractAudienceTablesFromQuery($previousQueryText));
        }

        if ($candidateTables === []) {
            return null;
        }

        $inspector = app(SchemaInspector::class);
        $conn = DB::connection('tenant');
        $emails = [];

        foreach ($candidateTables as $table) {
            try {
                $columns = $inspector->listColumns($table);
            } catch (\Throwable $e) {
                continue;
            }

            $emailColumns = array_filter($columns, static fn ($col) => str_contains(strtolower($col), 'email'));
            if ($emailColumns === []) {
                continue;
            }

            foreach ($emailColumns as $column) {
                try {
                    $sql = sprintf(
                        'SELECT DISTINCT `%s` AS email FROM `%s` WHERE `%s` IS NOT NULL AND `%s` != "" LIMIT 2000',
                        $column,
                        $table,
                        $column,
                        $column
                    );
                    $results = $conn->select($sql);
                    foreach ($results as $row) {
                        $email = trim((string) ($row->email ?? ''));
                        if ($email !== '') {
                            $emails[] = $email;
                        }
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }

        $emails = array_values(array_unique($emails));
        return $emails === [] ? null : array_slice($emails, 0, 200);
    }

    /**
     * @return array<int, string>
     */
    private function extractAudienceTablesFromSql(string $sql): array
    {
        $tables = [];
        if ($sql === '') {
            return $tables;
        }

        $map = [
            'students' => [' students ', ' students\n', '`students`'],
            'people' => [' people ', '`people`'],
            'employees' => [' employees ', '`employees`'],
            'users' => [' users ', '`users`'],
        ];

        foreach ($map as $table => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($sql, $needle)) {
                    $tables[] = $table;
                    break;
                }
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * @return array<int, string>
     */
    private function extractAudienceTablesFromQuery(string $query): array
    {
        $tables = [];
        if ($query === '') {
            return $tables;
        }

        if (str_contains($query, 'student')) {
            $tables[] = 'students';
        }
        if (str_contains($query, 'parent')) {
            $tables[] = 'people';
        }
        if (str_contains($query, 'employee')) {
            $tables[] = 'employees';
        }
        if (str_contains($query, 'admin')) {
            $tables[] = 'users';
        }

        return array_values(array_unique($tables));
    }

    private function debugLog(array $data): void
    {
        try {
            $payload = json_encode($data, JSON_UNESCAPED_SLASHES);
            @file_put_contents(storage_path('logs/analytics_debug.log'), ($payload ?: '') . PHP_EOL, FILE_APPEND);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
