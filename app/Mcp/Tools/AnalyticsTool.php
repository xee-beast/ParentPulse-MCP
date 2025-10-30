<?php

namespace App\Mcp\Tools;

use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

        $sessionId = (string) ($request->sessionId() ?? '');
        /** @var ChatMemory $memory */
        $memory = app(ChatMemory::class);

        $this->pendingFollowUp = null;

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
        
        // ALWAYS try the intelligent planner first for ALL queries
        $planned = $this->planAndExecute($query, $sessionId, $memory);
        if ($planned !== null) {
            return Response::text($planned);
        }
        
        // Only fall back to intelligent analysis if planner fails
        $fallback = $this->intelligentFallback($query, $sessionId, $memory);
        if ($fallback !== null) {
            return Response::text($fallback);
        }

        return Response::text('I could not generate a database query for your question. Please try rephrasing it.');
    }


    private function planAndExecute(string $query, string $sessionId, ChatMemory $memory): ?string
    {
        $inspector = app(SchemaInspector::class);
        $summary = $inspector->schemaSummary([
            'survey_answers','survey_invites','people','parents','questions','students','employees'
        ]);
        $availableTables = array_keys($summary);

        $planner = app(AnalyticsPlanner::class);
        $plan = $planner->plan($query, $summary);
        
        if ($plan === null) {
            return null; // Return null to trigger fallback instead of error message
        }
        
        if (! is_array($plan)) {
            return 'Planner returned invalid format: ' . json_encode($plan);
        }
        
        if (($plan['action'] ?? '') !== 'sql') {
            return 'Planner action is not "sql": ' . json_encode($plan);
        }

        $sql = (string) $plan['sql'];
        $params = $plan['params'] ?? [];
        
        // Disallow dangerous statements
        if (! Str::startsWith(Str::lower(Str::trim($sql)), 'select')) {
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
        $sql = $this->cleanupSql($sql);

        Log::debug('AnalyticsTool executing planner SQL', [
            'user_query' => $query,
            'sql_before_cleanup' => $originalSql,
            'sql_after_cleanup' => $sql,
            'params' => $params,
        ]);

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
            $responseText = $this->analyzeWithAI($query, [], $availableTables);
            if ($sessionId !== '') {
                $memory->rememberAnalytics($sessionId, $query, [], [
                    'sql' => $sql,
                    'params' => $params,
                    'source' => 'planner-exception',
                    'error' => $e->getMessage(),
                ], $responseText);
            }
            return $responseText;
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

    private function cleanupSql(string $sql): string
    {
        // Fix MySQL interval syntax issues
        $sql = preg_replace("/NOW\(\)\s*-\s*INTERVAL\s+'(\d+)\s+(days?)'/i", "DATE_SUB(NOW(), INTERVAL $1 DAY)", $sql);
        $sql = preg_replace("/NOW\(\)\s*-\s*INTERVAL\s+'(\d+)\s+(months?)'/i", "DATE_SUB(NOW(), INTERVAL $1 MONTH)", $sql);
        $sql = preg_replace("/NOW\(\)\s*-\s*INTERVAL\s+'(\d+)\s+(years?)'/i", "DATE_SUB(NOW(), INTERVAL $1 YEAR)", $sql);
        
        // Remove problematic MySQL interval syntax that can't be fixed
        $sql = preg_replace("/AND\s+si\.created_at\s*>=\s*NOW\(\)\s*-\s*INTERVAL\s+'[^']+'/i", '', $sql);
        
        // Clean up double spaces
        $sql = preg_replace('/\s+/', ' ', $sql);
        
        // Ensure proper spacing around keywords
        $sql = preg_replace('/\s+WHERE\s+/i', ' WHERE ', $sql);
        $sql = preg_replace('/\s+ORDER\s+BY\s+/i', ' ORDER BY ', $sql);
        $sql = preg_replace('/\s+LIMIT\s+/i', ' LIMIT ', $sql);

        // Always read answer text from value column
        $sql = preg_replace('/sa\.comments\b/i', 'sa.value', $sql);

        // Ensure survey cycle status filters include active/inactive phases
        if (preg_match_all('/sc\.status\s+IN\s*\(([^)]*)\)/i', $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $rawList = $match[1];
                $items = array_map(static function ($item) {
                    return trim($item, " '\"");
                }, explode(',', $rawList));

                $required = ['completed', 'removed', 'active', 'inactive', 'cancelled'];
                $normalizedItems = [];
                foreach ($items as $item) {
                    if ($item === '') {
                        continue;
                    }
                    $normalizedItems[strtolower($item)] = $item;
                }

                foreach ($required as $status) {
                    if (! array_key_exists($status, $normalizedItems)) {
                        $normalizedItems[$status] = $status;
                    }
                }

                $formatted = implode(', ', array_map(static fn ($value) => "'" . $value . "'", array_values($normalizedItems)));
                $sql = str_replace($match[0], "sc.status IN ($formatted)", $sql);
            }
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
        $finalQuery .= " ORDER BY si.created_at DESC LIMIT 200";
        
        // Execute query
        try {
            Log::debug('AnalyticsTool executing fallback survey query', [
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
                LIMIT 100
            ");
        }
    }

    private function intelligentFallback(string $query, string $sessionId, ChatMemory $memory): ?string
    {
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
                $surveyData = $this->getSurveyDataWithFilters($conn, $query, $tableNames);
                
                if (!empty($surveyData)) {
                    $rows = $this->normalizeResultSet($surveyData);
                    $analysis = $this->analyzeWithAI($query, $rows, $tableNames);
                    if ($sessionId !== '') {
                        $memory->rememberAnalytics($sessionId, $query, $rows, [
                            'source' => 'fallback:survey_answers',
                            'tables' => $tableNames,
                        ], $analysis);
                    }
                    return $analysis;
                }
            }
            
            return 'Fallback: No survey data found. Available tables: ' . implode(', ', $tableNames);
        } catch (\Throwable $e) {
            return 'Fallback failed: ' . $e->getMessage();
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

Key facts about the data:
- Survey responses are on 0-10 scales for satisfaction/NPS questions
- Comments provide qualitative feedback
- Data includes parents, students, and employees
- Focus on what the numbers mean for school improvement

Available tables: ' . implode(', ', $availableTables);
            
            if (empty($data)) {
                $user = "User Query: {$query}\n\nNo survey data was found matching this criteria. Please provide a friendly explanation of what this might mean.";
            } else {
                $user = "User Query: {$query}\n\nSurvey Data (sample):\n" . json_encode(array_slice($data, 0, 20), JSON_PRETTY_PRINT);
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

        $sql .= ' ORDER BY u.name ASC, up.name ASC LIMIT 200';

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
        $finalQuery .= " ORDER BY sc.created_at DESC LIMIT 50";
        
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
        $lines = [];

        foreach ($normalizedRows as $row) {
            $isOwnerValue = $row['is_owner'] ?? $row['isOwner'] ?? $row['owner'] ?? 0;
            $isOwner = (int) $isOwnerValue === 1;
            if ($isOwner) {
                $ownerCount++;
            }

            $roleLabel = $isOwner ? 'Owner' : 'Admin';
            $name = $this->stringValue($row, ['name', 'full_name', 'fullName'])
                ?? $this->combineNameParts($row, ['first_name', 'firstname'], ['last_name', 'lastname'])
                ?? 'Unknown';
            $email = $this->stringValue($row, ['email', 'contact_email', 'user_email']) ?? '';

            $line = '- '.trim($name);
            if ($email !== '') {
                $line .= " ({$email})";
            }
            $line .= " — {$roleLabel}";
            $lines[] = $line;
        }

        $ownerSuffix = '';
        if ($ownerCount > 0) {
            $ownerSuffix = $ownerCount === 1
                ? ' including 1 owner'
                : " including {$ownerCount} owners";
        }

        return "We have {$total} school admins{$ownerSuffix}:\n" . implode("\n", $lines);
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
            ? 'Here is the school admin information you asked for:'
            : 'Here are the matching school admins:';

        $lines = [];
        foreach ($matches as $row) {
            $name = $this->stringValue($row, ['name', 'full_name', 'fullName'])
                ?? $this->combineNameParts($row, ['first_name', 'firstname'], ['last_name', 'lastname'])
                ?? 'Unknown';
            $email = $this->stringValue($row, ['email', 'contact_email', 'user_email']) ?? '';
            $role = ((int) ($row['is_owner'] ?? $row['isOwner'] ?? 0) === 1) ? 'Owner' : 'Admin';

            $line = "- {$name} ({$role})";
            if ($email !== '') {
                $line .= " — {$email}";
            }

            $lines[] = $line;
        }

        return $intro . "\n" . implode("\n", $lines);
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
        $displayRows = array_slice($normalizedRows, 0, 50);
        $lines = [];

        foreach ($displayRows as $row) {
            $name = $this->combineNameParts($row, ['firstname', 'first_name'], ['lastname', 'last_name'])
                ?? $this->stringValue($row, ['name', 'full_name', 'fullName'])
                ?? 'Unknown employee';

            $email = $this->stringValue($row, ['email', 'work_email', 'contact_email']) ?? '';
            $role = $this->stringValue($row, ['title', 'position', 'role', 'job_title']) ?? '';

            $details = [];
            if ($role !== '') {
                $details[] = $role;
            }
            if ($email !== '') {
                $details[] = $email;
            }

            $line = '- '.trim($name);
            if ($details !== []) {
                $line .= ' ('.implode(' · ', $details).')';
            }
            $lines[] = $line;
        }

        $header = "We found {$total} employees";
        if ($total > count($displayRows)) {
            $remaining = $total - count($displayRows);
            $header .= ", showing the first ".count($displayRows)." (and {$remaining} more)";
        }

        return $header . ":\n" . implode("\n", $lines);
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
        $displayRows = array_slice($normalizedRows, 0, 50);
        $lines = [];

        foreach ($displayRows as $row) {
            $name = $this->stringValue($row, ['name', 'full_name', 'parent_name'])
                ?? $this->combineNameParts($row, ['first_name', 'firstname'], ['last_name', 'lastname'])
                ?? 'Unknown parent';

            $email = $this->stringValue($row, ['email', 'contact_email']) ?? '';
            $phone = $this->stringValue($row, ['phone', 'contact_phone', 'mobile']) ?? '';

            $line = '- '.trim($name);
            $details = [];
            if ($email !== '') {
                $details[] = $email;
            }
            if ($phone !== '') {
                $details[] = $phone;
            }
            if ($details !== []) {
                $line .= ' ('.implode(' · ', $details).')';
            }

            $lines[] = $line;
        }

        $header = "We found {$total} parents";
        if ($total > count($displayRows)) {
            $remaining = $total - count($displayRows);
            $header .= ", showing the first ".count($displayRows)." (and {$remaining} more)";
        }

        return $header . ":\n" . implode("\n", $lines);
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

        if (Str::contains($normalizedQuery, 'email')) {
            $emailColumns = $this->detectColumnsContaining($rows, ['email']);
            $emails = $this->extractColumnValues($rows, $emailColumns, 20);
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
            $names = $this->collectNames($rows, 20);
            if ($names !== []) {
                $this->pendingFollowUp = null;
                return $this->formatListResponse('names', $names, $previousQuery);
            }
        }

        if (Str::contains($normalizedQuery, ['comment', 'comments', 'feedback'])) {
            $comments = $this->collectComments($rows, 10);
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
    private function collectNames(array $rows, int $limit = 20): array
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
    private function collectPermissions(array $rows, int $limit = 30): array
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
    private function collectComments(array $rows, int $limit = 10): array
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

    private function shouldUseMemory(string $query, array $last, array $rows, string $responseText): bool
    {
        if ($rows === [] && trim($responseText) === '') {
            return false;
        }

        $aiDecision = $this->classifyFollowUpUsingAI($query, $last, $rows, $responseText);
        if ($aiDecision !== null) {
            return $aiDecision;
        }

        return $this->heuristicFollowUp($query);
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
                        'SELECT DISTINCT `%s` AS email FROM `%s` WHERE `%s` IS NOT NULL AND `%s` != "" LIMIT 200',
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
            'parents' => [' parents ', '`parents`'],
            'employees' => [' employees ', '`employees`'],
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
            $tables[] = 'parents';
        }
        if (str_contains($query, 'employee')) {
            $tables[] = 'employees';
        }

        return array_values(array_unique($tables));
    }
}
