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
use GuzzleHttp\Client;

class AnalyticsTool extends Tool
{
    protected string $name = 'analytics-answer';

    protected string $title = 'Analytics Answer';

    protected string $description = 'Executes ParentPulse analytics queries using intelligent SQL generation.';

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
        
        // ALWAYS try the intelligent planner first for ALL queries
        $planned = $this->planAndExecute($query);
        if ($planned !== null) {
            return Response::text($planned);
        }
        
        // Only fall back to intelligent analysis if planner fails
        $fallback = $this->intelligentFallback($query);
        if ($fallback !== null) {
            return Response::text($fallback);
        }

        return Response::text('I could not generate a database query for your question. Please try rephrasing it.');
    }


    private function planAndExecute(string $query): ?string
    {
        $inspector = app(SchemaInspector::class);
        $summary = $inspector->schemaSummary([
            'survey_answers','survey_invites','people','parents','questions','students','employees'
        ]);

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
            return $this->analyzeWithAI($query, [], []);
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
            $rows = DB::connection('tenant')->select($sql, $params);
        } catch (\Throwable $e) {
            return $this->analyzeWithAI($query, [], []);
        }


        if ($rows === []) {
            return $this->analyzeWithAI($query, [], []);
        }

        if ($this->isAdminDetailQuery($query)) {
            $detail = $this->formatAdminDetailResponse($rows, $query);
            if ($detail !== null) {
                return $detail;
            }
        }

        if ($this->isAdminListQuery($query)) {
            $adminList = $this->formatAdminListResponse($rows);
            if ($adminList !== null) {
                return $adminList;
            }
        }

        // Check if this is a comment analysis query
        if ($this->isCommentAnalysisQuery($query, $sql)) {
            return $this->analyzeCommentsWithAI($query, $rows);
        }

        // Always use AI analysis for human-friendly responses
        return $this->analyzeWithAI($query, $rows, []);
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

                $required = ['completed', 'removed', 'active', 'inactive'];
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

    private function intelligentFallback(string $query): ?string
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
                    if ($this->isAdminDetailQuery($query)) {
                        $detail = $this->formatAdminDetailResponse($userData, $query);
                        if ($detail !== null) {
                            return $detail;
                        }
                    }

                    if ($this->isAdminListQuery($query)) {
                        $adminList = $this->formatAdminListResponse($userData);
                        if ($adminList !== null) {
                            return $adminList;
                        }
                    }

                    return $this->analyzeWithAI($query, $userData, $tableNames);
                }
            }
            
            // Check for cycle status queries
            if (in_array('survey_cycles', $tableNames) && 
                ($normalized->contains(['cancelled', 'canceled', 'cancellation', 'status', 'active', 'inactive', 'completed', 'pending']))) {
                
                $cycleStatusData = $this->getCycleStatusData($conn, $query, $tableNames);
                if (!empty($cycleStatusData)) {
                    return $this->analyzeCycleStatusWithAI($query, $cycleStatusData);
                }
            }
            
            // Get survey data for analysis with dynamic filtering
            if (in_array('survey_answers', $tableNames)) {
                $surveyData = $this->getSurveyDataWithFilters($conn, $query, $tableNames);
                
                if (!empty($surveyData)) {
                    return $this->analyzeWithAI($query, $surveyData, $tableNames);
                }
            }
            
            return 'Fallback: No survey data found. Available tables: ' . implode(', ', $tableNames);
        } catch (\Throwable $e) {
            return 'Fallback failed: ' . $e->getMessage();
        }
    }

    private function analyzeWithAI(string $query, array $data, array $availableTables): string
    {
        $apiKey = (string) env('OPENAI_API_KEY', '');
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
        $apiKey = (string) env('OPENAI_API_KEY', '');
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
        $apiKey = (string) env('OPENAI_API_KEY', '');
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
        if ($rows === []) {
            return null;
        }

        $total = count($rows);
        $ownerCount = 0;
        $lines = [];

        foreach ($rows as $row) {
            $isOwner = (int) ($row->is_owner ?? 0) === 1;
            if ($isOwner) {
                $ownerCount++;
            }

            $roleLabel = $isOwner ? 'Owner' : 'Admin';
            $name = trim((string) ($row->name ?? 'Unknown'));
            $email = trim((string) ($row->email ?? ''));

            $line = "- {$name}";
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

    private function formatAdminDetailResponse(array $rows, string $query): ?string
    {
        if ($rows === []) {
            return null;
        }

        $normalizedQuery = Str::of($query)->lower()->toString();

        $matches = array_filter($rows, function ($row) use ($normalizedQuery) {
            $name = trim((string) ($row->name ?? ''));
            if ($name === '') {
                return false;
            }

            $lower = Str::of($name)->lower()->toString();
            return $lower !== '' && str_contains($normalizedQuery, $lower);
        });

        if ($matches === []) {
            return null;
        }

        $intro = count($matches) === 1
            ? 'Here is the school admin information you asked for:'
            : 'Here are the matching school admins:';

        $lines = [];
        foreach ($matches as $row) {
            $name = trim((string) ($row->name ?? 'Unknown'));
            $email = trim((string) ($row->email ?? ''));
            $role = ((int) ($row->is_owner ?? 0) === 1) ? 'Owner' : 'Admin';

            $line = "- {$name} ({$role})";
            if ($email !== '') {
                $line .= " — {$email}";
            }

            $lines[] = $line;
        }

        return $intro . "\n" . implode("\n", $lines);
    }
}
