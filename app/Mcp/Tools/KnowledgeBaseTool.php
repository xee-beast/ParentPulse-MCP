<?php

namespace App\Mcp\Tools;

use App\Services\ChatMemory;
use App\Services\KnowledgeBaseService;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Illuminate\Support\Facades\Log;

class KnowledgeBaseTool extends Tool
{
    protected string $name = 'knowledge_base';

    protected string $title = 'Knowledge Base Analytics';

    protected string $description = 'Answers questions about school data using the knowledge base JSON file. Handles NPS scores, survey responses, demographics, and analytics queries.';

    private KnowledgeBaseService $kbService;

    public function __construct()
    {
        $this->kbService = app(KnowledgeBaseService::class);
    }

    public function schema(\Illuminate\JsonSchema\JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('User query about school data, surveys, NPS, demographics, etc.')->min(1)->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = (string) ($request->get('query', '') ?? '');
        if ($query === '') {
            return Response::error('Please provide a "query" to proceed.');
        }

        $sessionId = (string) $request->sessionId();
        $memory = app(ChatMemory::class);

        // Check memory for follow-ups
        if ($sessionId !== '') {
            $followUp = $this->answerFromMemory($query, $sessionId, $memory);
            if ($followUp !== null) {
                return Response::text($followUp);
            }
        }

        try {
            // Extract relevant data based on query
            $relevantData = $this->kbService->extractRelevantData($query);
            
            // If no data found, return error
            if (empty($relevantData)) {
                return Response::text('I could not find any data matching your query. Please check that the knowledge base file exists and contains the requested information.');
            }

            // Analyze with AI
            $response = $this->analyzeWithAI($query, $relevantData);
            
            Log::info('KnowledgeBaseTool extracted data', [
                'has_survey_data' => isset($relevantData['survey_data']),
                'survey_data_count' => isset($relevantData['survey_data']) ? count($relevantData['survey_data']) : 0,
                'is_multi_cycle' => $relevantData['multi_cycle_comparison'] ?? false,
                'extracted_cycles' => $relevantData['extracted_cycles'] ?? [],
            ]);

            // Store in memory
            if ($sessionId !== '') {
                $memory->rememberAnalytics($sessionId, $query, $relevantData, [
                    'source' => 'knowledge-base',
                ], $response);
            }

            return Response::text($response);

        } catch (\Throwable $e) {
            Log::error('KnowledgeBaseTool error', [
                'query' => $query,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Response::error('An error occurred while processing your query: ' . $e->getMessage());
        }
    }

    /**
     * Analyze data with AI and generate response
     */
    private function analyzeWithAI(string $query, array $data): string
    {
        $apiKey = (string) config('app.openai_api_key', '');
        $model = (string) env('OPENAI_MODEL', 'gpt-4o-mini');
        
        if ($apiKey === '') {
            return 'AI analysis not available - API key missing.';
        }

        try {
            $this->currentQuery = $query; // Store for buildContext
            
            $client = new Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'timeout' => 60, // Increased timeout for large data
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            // Build context from data
            $context = $this->buildContext($data);
            
            // Calculate approximate token count (rough estimate: 4 characters per token)
            $contextChars = strlen($context);
            $systemChars = strlen($this->buildSystemPrompt($query, $data));
            $queryChars = strlen($query);
            $totalChars = $contextChars + $systemChars + $queryChars;
            
            // GPT-4o/gpt-4o-mini supports 128k tokens ≈ 512k characters
            // Leave buffer for response (max_tokens) and safety margin
            // Safe limit: ~400k characters for input (leaves ~100k for response)
            $maxChars = 400000;
            $tokenEstimate = (int)($totalChars / 4);
            
            Log::info('Context size check', [
                'context_chars' => $contextChars,
                'system_chars' => $systemChars,
                'query_chars' => $queryChars,
                'total_chars' => $totalChars,
                'estimated_tokens' => $tokenEstimate,
                'max_allowed_chars' => $maxChars,
                'will_truncate' => $totalChars > $maxChars,
            ]);
            
            // Only truncate if we're approaching the limit
            if ($totalChars > $maxChars) {
                Log::warning('Context too large, truncating', [
                    'total_chars' => $totalChars,
                    'max_chars' => $maxChars,
                    'query' => $query,
                ]);
                
                // Calculate how much we need to trim from context
                $availableForContext = $maxChars - $systemChars - $queryChars - 10000; // 10k buffer
                $context = $this->intelligentTruncate($context, max($availableForContext, 100000), $query);
                
                Log::info('Context truncated', [
                    'new_context_chars' => strlen($context),
                    'truncated_by' => $contextChars - strlen($context),
                ]);
            }

            // Log context summary instead of full content (too verbose)
            Log::debug('Context summary', [
                'context_preview' => substr($context, 0, 500) . '...',
            ]);

            $system = $this->buildSystemPrompt($query, $data);

            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "User Query: {$query}\n\nData:\n{$context}"],
                ],
                'temperature' => 0.3,
                'max_tokens' => 2000,
            ];

            $res = $client->post('chat/completions', ['json' => $payload]);
            $json = json_decode((string) $res->getBody(), true);
            
            $response = trim((string) ($json['choices'][0]['message']['content'] ?? 'Analysis failed'));
            
            // Ensure response is properly formatted HTML
            $response = $this->ensureHtmlFormatting($response);
            
            return $response;
            
        } catch (\Throwable $e) {
            Log::error('KnowledgeBaseTool AI error', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            
            return 'AI analysis failed: '.$e->getMessage();
        } finally {
            $this->currentQuery = null;
        }
    }

    /**
     * Build context string from data
     */
    private function buildContext(array $data): string
    {
        $context = [];
        $normalized = Str::of($this->currentQuery ?? '')->lower();
        $isCountQuery = $normalized->contains(['how many', 'count', 'number of', 'total']);
        
        // Detect if query is about surveys/NPS/satisfaction (not roster)
        $isSurveyQuery = $normalized->contains([
            'survey', 'nps', 'net promoter', 'response', 'answer', 'satisfaction', 
            'satisfied', 'happy', 'doing well', 'feel', 'feeling', 'experience',
            'promoter', 'detractor', 'passive', 'cycle', 'sequence'
        ]);
        
        // Only include roster data if explicitly asking for it (not for survey queries)
        $needsRoster = !$isSurveyQuery && (
            $normalized->contains(['how many', 'count', 'number of', 'list', 'show', 'get', 'give me']) &&
            ($normalized->contains(['parent', 'student', 'employee', 'admin', 'email', 'name']) ||
             !$normalized->contains(['survey', 'response', 'answer', 'nps', 'cycle']))
        );

        if (isset($data['tenant'])) {
            $context[] = "School: " . json_encode($data['tenant'], JSON_PRETTY_PRINT);
        }

        if (isset($data['admins']) && !empty($data['admins']) && $needsRoster) {
            $context[] = "Admins: " . json_encode($data['admins'], JSON_PRETTY_PRINT);
        }

        if (isset($data['parents']) && !empty($data['parents']) && $needsRoster) {
            if ($isCountQuery) {
                // For count queries, provide summary instead of full array
                $context[] = "Parents: Total count = " . count($data['parents']);
                $context[] = "Parents Sample (first 5): " . json_encode(array_slice($data['parents'], 0, 5), JSON_PRETTY_PRINT);
            } else {
                $context[] = "Parents: " . json_encode($data['parents'], JSON_PRETTY_PRINT);
            }
        }

        if (isset($data['students']) && !empty($data['students']) && $needsRoster) {
            if ($isCountQuery) {
                $context[] = "Students: Total count = " . count($data['students']);
                $context[] = "Students Sample (first 5): " . json_encode(array_slice($data['students'], 0, 5), JSON_PRETTY_PRINT);
            } else {
                $context[] = "Students: " . json_encode($data['students'], JSON_PRETTY_PRINT);
            }
        }

        if (isset($data['employees']) && !empty($data['employees']) && $needsRoster) {
            if ($isCountQuery) {
                $context[] = "Employees: Total count = " . count($data['employees']);
                $context[] = "Employees Sample (first 5): " . json_encode(array_slice($data['employees'], 0, 5), JSON_PRETTY_PRINT);
            } else {
                $context[] = "Employees: " . json_encode($data['employees'], JSON_PRETTY_PRINT);
            }
        }

        if (isset($data['nps_calculation']) && !empty($data['nps_calculation'])) {
            // Only include NPS calculation if NOT a multi-cycle comparison query
            // Multi-cycle queries should focus on individual transitions, not overall NPS
            if (!isset($data['multi_cycle_comparison']) || !$data['multi_cycle_comparison']) {
                $nps = $data['nps_calculation'];
                
                // Check if NPS is calculated per module
                if (isset($nps['parent']) || isset($nps['student']) || isset($nps['employee'])) {
                    $context[] = "NPS Calculation by Module (DO NOT RECALCULATE - USE THESE EXACT VALUES):";
                    foreach (['parent', 'student', 'employee'] as $module) {
                        if (isset($nps[$module])) {
                            $moduleNps = $nps[$module];
                            $context[] = "{$module}: NPS Score = {$moduleNps['nps_score']}, Total Responses = {$moduleNps['total_responses']}, Promoters = {$moduleNps['promoters']} ({$moduleNps['promoter_percent']}%), Passives = {$moduleNps['passives']} ({$moduleNps['passive_percent']}%), Detractors = {$moduleNps['detractors']} ({$moduleNps['detractor_percent']}%)";
                        }
                    }
                } else {
                    // Single overall NPS calculation
                    $context[] = "NPS Calculation (DO NOT RECALCULATE - USE THESE EXACT VALUES):";
                    $context[] = "NPS Score: {$nps['nps_score']}";
                    $context[] = "Total NPS Responses: {$nps['total_responses']}";
                    $context[] = "Promoters (9-10): {$nps['promoters']} ({$nps['promoter_percent']}%)";
                    $context[] = "Passives (7-8): {$nps['passives']} ({$nps['passive_percent']}%)";
                    $context[] = "Detractors (0-6): {$nps['detractors']} ({$nps['detractor_percent']}%)";
                    $context[] = "Formula: ({$nps['promoter_percent']}% Promoters - {$nps['detractor_percent']}% Detractors) = {$nps['nps_score']}";
                }
            }
        }

        if (isset($data['survey_cycles']) && !empty($data['survey_cycles'])) {
            if ($isCountQuery) {
                // For count queries, provide summary with status breakdown
                $totalCycles = count($data['survey_cycles']);
                $completedCycles = array_filter($data['survey_cycles'], function($cycle) {
                    return strtolower($cycle['status'] ?? '') === 'completed';
                });
                $completedCount = count($completedCycles);
                
                $context[] = "Survey Cycles: Total count = {$totalCycles}";
                $context[] = "Completed Cycles: Count = {$completedCount}";
                $context[] = "Survey Cycles Sample (first 5): " . json_encode(array_slice($data['survey_cycles'], 0, 5), JSON_PRETTY_PRINT);
            } else {
                $context[] = "Survey Cycles: " . json_encode($data['survey_cycles'], JSON_PRETTY_PRINT);
            }
        }

        if (isset($data['demographic_question'])) {
            $context[] = "Demographic Question: " . json_encode($data['demographic_question'], JSON_PRETTY_PRINT);
        }

        // Multi-cycle comparison instructions
        if (isset($data['multi_cycle_comparison']) && $data['multi_cycle_comparison']) {
            $extractedCycles = $data['extracted_cycles'] ?? [];
            $context[] = "IMPORTANT: This is a MULTI-CYCLE COMPARISON query.";
            $context[] = "You need to match respondents across cycles by their respondent_name (ignoring 'Anonymous').";
            $context[] = "Extracted cycles to compare: " . implode(', ', $extractedCycles);
            $context[] = "For each respondent that appears in BOTH cycles:";
            $context[] = "1. Extract their NPS score from cycle 1 (from nps_score field - no need to search answers array)";
            $context[] = "2. Extract their NPS score from cycle 2 (from nps_score field)";
            $context[] = "3. Categorize: Promoters (9-10), Passives (7-8), Detractors (0-6)";
            $context[] = "4. Count how many match the query criteria (e.g., 'were passives or detractors in cycle 1 and became promoters in cycle 2')";
            $context[] = "5. Provide the exact count and list the matching respondents if count <= 20";
            $context[] = "NOTE: Survey data is optimized for multi-cycle comparison - each record has: respondent_name, survey_cycle, respondent_type, nps_score";
        }

        if (isset($data['survey_data']) && !empty($data['survey_data'])) {
            $surveyData = $data['survey_data'];
            $surveyCount = count($surveyData);
            
            // For satisfaction/NPS queries, provide summary and sample, not full data
            $normalizedQuery = Str::of($this->currentQuery ?? '')->lower();
            $isSatisfactionQuery = $normalizedQuery->contains(['happy', 'satisfied', 'doing well', 'overall', 'feel', 'feeling']);
            
            if ($isSatisfactionQuery || $normalizedQuery->contains(['nps', 'net promoter'])) {
                // Provide summary statistics instead of full data
                $context[] = "Survey Data Summary:";
                $context[] = "Total surveys: {$surveyCount}";
                
                // Count by module
                $byModule = [];
                foreach ($surveyData as $survey) {
                    $module = $survey['respondent_type'] ?? 'unknown';
                    $byModule[$module] = ($byModule[$module] ?? 0) + 1;
                }
                foreach ($byModule as $module => $count) {
                    $context[] = "  - {$module}: {$count} surveys";
                }
                
                // Count by status
                $byStatus = [];
                foreach ($surveyData as $survey) {
                    $status = $survey['status'] ?? 'unknown';
                    $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
                }
                $context[] = "Survey Status Breakdown:";
                foreach ($byStatus as $status => $count) {
                    $context[] = "  - {$status}: {$count}";
                }
                
                // Provide a small sample (first 10) for context
                $context[] = "Sample Survey Data (first 10 of {$surveyCount}):";
                $context[] = json_encode(array_slice($surveyData, 0, 10), JSON_PRETTY_PRINT);
                $context[] = "[Note: Full survey data available but summarized for efficiency. NPS calculations are provided separately above.]";
            } else {
                // For other queries, include all survey data
                $context[] = "Survey Data (total {$surveyCount} surveys):";
                $context[] = json_encode($surveyData, JSON_PRETTY_PRINT);
            }
        }

        return implode("\n\n", $context);
    }

    private ?string $currentQuery = null;

    /**
     * Intelligently truncate context while preserving important data
     * Only used when context truly exceeds the model's limit
     */
    private function intelligentTruncate(string $context, int $maxChars, string $query): string
    {
        $normalized = Str::of($query)->lower();
        
        // If context is already under limit, return as-is
        if (strlen($context) <= $maxChars) {
            return $context;
        }
        
        // Strategy 1: If query is about NPS or satisfaction, prioritize NPS calculation data
        if ($normalized->contains(['nps', 'net promoter', 'promoter', 'happy', 'satisfied', 'satisfaction', 'doing well', 'overall'])) {
            // Extract NPS-related parts first
            $lines = explode("\n", $context);
            $important = [];
            $other = [];
            
            foreach ($lines as $line) {
                if (stripos($line, 'nps') !== false || 
                    stripos($line, 'promoter') !== false || 
                    stripos($line, 'type": "nps"') !== false ||
                    stripos($line, '"nps"') !== false ||
                    stripos($line, 'nps_calculation') !== false ||
                    stripos($line, 'NPS Calculation') !== false ||
                    stripos($line, 'Survey Data Summary') !== false) {
                    $important[] = $line;
                } else {
                    $other[] = $line;
                }
            }
            
            $importantText = implode("\n", $important);
            $remaining = $maxChars - strlen($importantText) - 100; // 100 char buffer
            
            if ($remaining > 10000) { // Only if we have significant space
                // Include some of the other data
                $otherText = implode("\n", array_slice($other, 0, (int)($remaining / 100))); // Rough estimate
                return $importantText . "\n\n[Additional context included]\n\n" . substr($otherText, 0, $remaining);
            }
            
            return substr($importantText, 0, $maxChars - 50) . "\n\n[Context truncated - preserving NPS calculation data]";
        }
        
        // Strategy 2: Preserve structure sections (School, Survey Cycles, NPS Calculation, etc.) and truncate survey_data
        $sections = explode("\n\n", $context);
        $preserved = [];
        $surveyDataSection = null;
        $totalPreserved = 0;
        
        foreach ($sections as $section) {
            $sectionLen = strlen($section);
            
            // ALWAYS preserve critical sections (NPS Calculation, important instructions)
            if (stripos($section, 'NPS Calculation') === 0 ||
                stripos($section, 'IMPORTANT:') === 0 ||
                stripos($section, 'Demographic Question:') === 0) {
                $preserved[] = $section;
                $totalPreserved += $sectionLen + 2; // +2 for \n\n
            } elseif (stripos($section, 'School:') === 0 ||
                stripos($section, 'Survey Cycles:') === 0) {
                if ($totalPreserved + $sectionLen < $maxChars * 0.3) { // Keep first 30% for metadata
                    $preserved[] = $section;
                    $totalPreserved += $sectionLen + 2; // +2 for \n\n
                }
            } elseif (stripos($section, 'Survey Data') === 0 || stripos($section, 'Survey Data Summary:') === 0) {
                $surveyDataSection = $section;
            } else {
                // Other sections - preserve if we have space
                if ($totalPreserved + $sectionLen < $maxChars * 0.5) {
                    $preserved[] = $section;
                    $totalPreserved += $sectionLen + 2;
                }
            }
        }
        
        // Calculate remaining space for survey_data
        $remainingForData = $maxChars - $totalPreserved - 500; // 500 buffer
        if ($surveyDataSection && $remainingForData > 5000) {
            // Include survey data section (it's already optimized/summarized)
            $preserved[] = substr($surveyDataSection, 0, min(strlen($surveyDataSection), $remainingForData));
        } elseif ($surveyDataSection) {
            // Too little space - just indicate data exists but NPS calculations are preserved
            $preserved[] = "[Survey data available but truncated - NPS calculations are preserved above]";
        }
        
        return implode("\n\n", $preserved);
    }

    /**
     * Build system prompt for AI
     */
    private function buildSystemPrompt(string $query, array $data): string
    {
        $normalized = Str::of($query)->lower();
        
        $prompt = 'You are a friendly school administrator analyzing ParentPulse survey data from a knowledge base. Provide warm, human, conversational responses.

Guidelines:
- Write like you\'re talking to a colleague, not a computer
- Focus on insights and what the data means for the school community
- Never mention technical details like JSON structure, IDs, or raw data
- Use percentages and simple numbers that make sense to educators
- Highlight positive trends and areas for improvement
- Keep responses concise but meaningful
- Use encouraging, supportive language
- CRITICAL: When asked "how many" or "count", ALWAYS use the exact count number provided in the data (e.g., "Total count = 2424"). Do NOT estimate or infer from samples.
- CRITICAL: ALL responses MUST be formatted in valid HTML with proper semantic tags:
  * Use <p> for paragraphs (wrap all text in paragraphs)
  * Use <ol> for ordered lists, <ul> for unordered lists
  * Use <li> for each list item
  * Use <strong> for names, important numbers, and emphasis
  * Use <em> for subtle emphasis
  * Use <h3> or <h4> for section headings if needed
  * Always escape HTML special characters in text content
  * Do NOT use <html>, <body>, or <head> tags - just the content tags

Data Structure Understanding:
- survey_data contains survey responses with:
  * status: "sent" (email sent, no response), "partial" (incomplete), "completed" (finished)
  * respondent_type: "parent", "student", or "employee"
  * respondent_name: name or "Anonymous"
  * survey_cycle: cycle name (null if before cycles existed)
  * answers: array of answer objects (optimized to only include relevant types based on query)
    - For NPS queries: answers contain type="nps", question, and nps_score (0-10)
    - For comment queries: answers contain type="comment", question, and comment (text)
    - For benchmark queries: answers contain type="benchmark", question, and rating (number)
    - For filtering queries: answers contain type="filtering", question, and value (array)
    - Note: Survey data is optimized to reduce context size - only relevant answer types are included

NPS Calculation:
- NPS = (% Promoters - % Detractors) * 100
- Promoters: scores 9-10
- Passives: scores 7-8
- Detractors: scores 0-6
- CRITICAL: If "NPS Calculation" data is provided with exact values, USE THOSE EXACT VALUES. Do NOT recalculate or estimate.
- When NPS Calculation data is provided AND the query asks about satisfaction/happiness (not a multi-cycle comparison), you MUST use the provided NPS scores to answer the question.
- For satisfaction queries that mention multiple modules (parents, students, employees), use the per-module NPS calculations provided.
- Always display NPS scores prominently when answering satisfaction/happiness questions - this is the primary metric for measuring satisfaction.
- When NPS Calculation data is provided AND the query asks for NPS score (not a multi-cycle comparison), start your response with: "The NPS score is [EXACT_SCORE]." (use the exact number from the calculation)
- IMPORTANT: For multi-cycle comparison queries, DO NOT display overall NPS score unless explicitly requested. Focus on answering the specific comparison question.

Multi-Cycle Comparison Queries:
- When asked about respondents who changed categories between cycles (e.g., "passives or detractors in cycle 1 who became promoters in cycle 2"):
  1. Match respondents by respondent_name across different cycles (skip "Anonymous")
  2. Extract NPS scores from each cycle - for multi-cycle queries, survey_data is optimized:
     * Each record contains: respondent_name, survey_cycle, respondent_type, nps_score
     * nps_score is a number from 0-10
     * No need to search through answers array - the score is directly available
  3. Categorize each score: Promoters (9-10), Passives (7-8), Detractors (0-6)
  4. Match cycle names flexibly (e.g., "Sprint 2025" matches "Spring 2025", handle variations)
  5. Count exact matches based on the query criteria
  6. Provide the exact count and list matching respondents if count <= 20
- CRITICAL: For multi-cycle comparisons, survey_data is already optimized - just use respondent_name, survey_cycle, and nps_score fields directly
- CRITICAL: For multi-cycle comparison queries, DO NOT mention overall NPS score unless the query explicitly asks for it. Focus only on answering the specific comparison question asked.
- CRITICAL: DO NOT calculate or display overall NPS scores for multi-cycle comparison queries - only answer the specific question about respondent transitions between cycles.

For count queries:
- When data shows "Total count = X", use that exact number
- When data shows "Completed Cycles: Count = X", use that exact number
- Do NOT count samples or estimate
- Always provide the exact count from the data
- For cycle queries, check the status field: "completed" means the cycle is completed

For cycle/sequence queries:
- "cycle" and "sequence" mean the same thing (survey cycles/sequences)
- When asked "how many cycles completed", count cycles with status="completed"
- When asked "how many cycles", count all cycles regardless of status

For demographic grouping queries:
- Use filtering answers to group data
- Filtering answers have type="filtering" and value is an array
- Group NPS scores by the filtering answer values

For multi-cycle comparisons:
- Match respondents by respondent_name (or handle anonymous)
- Compare NPS categories across cycles

For correlation analysis:
- Analyze relationships between benchmark ratings and NPS scores
- Look for patterns in comments and NPS scores
- Identify trends across demographic groups';

        return $prompt;
    }

    /**
     * Ensure response is properly formatted HTML
     */
    private function ensureHtmlFormatting(string $response): string
    {
        // Check if response already has HTML tags
        if (preg_match('/<[a-z][\s\S]*>/i', $response)) {
            // Already has HTML, just ensure it's valid
            return $response;
        }
        
        // If no HTML tags, wrap in paragraph tags
        $response = trim($response);
        if (empty($response)) {
            return '<p>No response generated.</p>';
        }
        
        // Split by double newlines to create paragraphs
        $paragraphs = preg_split('/\n\s*\n/', $response);
        $html = '';
        
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if (empty($para)) {
                continue;
            }
            
            // Escape HTML special characters
            $para = htmlspecialchars($para, ENT_QUOTES, 'UTF-8');
            
            // Wrap in paragraph tag
            $html .= '<p>' . nl2br($para) . '</p>';
        }
        
        return $html ?: '<p>' . htmlspecialchars($response, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    /**
     * Check memory for follow-up questions
     */
    private function answerFromMemory(string $query, string $sessionId, ChatMemory $memory): ?string
    {
        $lastAnalytics = $memory->lastAnalytics($sessionId);
        if ($lastAnalytics === null) {
            return null;
        }

        $normalized = Str::of($query)->lower();
        
        // Check for follow-up patterns
        $followUpMarkers = [
            'their', 'those', 'them', 'also', 'and', 'what about',
            'how about', 'can you', 'give me', 'show me', 'tell me'
        ];

        $isFollowUp = false;
        foreach ($followUpMarkers as $marker) {
            if ($normalized->contains($marker)) {
                $isFollowUp = true;
                break;
            }
        }

        if (!$isFollowUp) {
            return null;
        }

        // Use previous context to answer
        $prevQuery = $lastAnalytics['query'] ?? '';
        $prevData = $lastAnalytics['data'] ?? [];
        
        // Re-analyze with context
        $combinedQuery = $prevQuery . ' ' . $query;
        $relevantData = $this->kbService->extractRelevantData($combinedQuery);
        
        if (empty($relevantData)) {
            return null;
        }

        return $this->analyzeWithAI($query, $relevantData);
    }
}

