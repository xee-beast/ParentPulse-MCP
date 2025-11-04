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
            
            // Truncate if too large (max ~100k tokens ≈ 75k chars)
            // $maxChars = 60000;
            // if (strlen($context) > $maxChars) {
            //     $context = $this->intelligentTruncate($context, $maxChars, $query);
            // }

            Log::info('Context: ' . $context);

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

        if (isset($data['tenant'])) {
            $context[] = "School: " . json_encode($data['tenant'], JSON_PRETTY_PRINT);
        }

        if (isset($data['admins']) && !empty($data['admins'])) {
            $context[] = "Admins: " . json_encode($data['admins'], JSON_PRETTY_PRINT);
        }

        if (isset($data['parents']) && !empty($data['parents'])) {
            if ($isCountQuery) {
                // For count queries, provide summary instead of full array
                $context[] = "Parents: Total count = " . count($data['parents']);
                $context[] = "Parents Sample (first 5): " . json_encode(array_slice($data['parents'], 0, 5), JSON_PRETTY_PRINT);
            } else {
                $context[] = "Parents: " . json_encode($data['parents'], JSON_PRETTY_PRINT);
            }
        }

        if (isset($data['students']) && !empty($data['students'])) {
            if ($isCountQuery) {
                $context[] = "Students: Total count = " . count($data['students']);
                $context[] = "Students Sample (first 5): " . json_encode(array_slice($data['students'], 0, 5), JSON_PRETTY_PRINT);
            } else {
                $context[] = "Students: " . json_encode($data['students'], JSON_PRETTY_PRINT);
            }
        }

        if (isset($data['employees']) && !empty($data['employees'])) {
            if ($isCountQuery) {
                $context[] = "Employees: Total count = " . count($data['employees']);
                $context[] = "Employees Sample (first 5): " . json_encode(array_slice($data['employees'], 0, 5), JSON_PRETTY_PRINT);
            } else {
                $context[] = "Employees: " . json_encode($data['employees'], JSON_PRETTY_PRINT);
            }
        }

        if (isset($data['nps_calculation']) && !empty($data['nps_calculation'])) {
            $nps = $data['nps_calculation'];
            $context[] = "NPS Calculation (DO NOT RECALCULATE - USE THESE EXACT VALUES):";
            $context[] = "NPS Score: {$nps['nps_score']}";
            $context[] = "Total NPS Responses: {$nps['total_responses']}";
            $context[] = "Promoters (9-10): {$nps['promoters']} ({$nps['promoter_percent']}%)";
            $context[] = "Passives (7-8): {$nps['passives']} ({$nps['passive_percent']}%)";
            $context[] = "Detractors (0-6): {$nps['detractors']} ({$nps['detractor_percent']}%)";
            $context[] = "Formula: ({$nps['promoter_percent']}% Promoters - {$nps['detractor_percent']}% Detractors) = {$nps['nps_score']}";
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
            $context[] = "1. Extract their NPS score from cycle 1 (from answers array, find type='nps', read nps.score or value)";
            $context[] = "2. Extract their NPS score from cycle 2";
            $context[] = "3. Categorize: Promoters (9-10), Passives (7-8), Detractors (0-6)";
            $context[] = "4. Count how many match the query criteria (e.g., 'were passives or detractors in cycle 1 and became promoters in cycle 2')";
            $context[] = "5. Provide the exact count and list the matching respondents if count <= 20";
        }

        if (isset($data['survey_data']) && !empty($data['survey_data'])) {
            // For survey data, be smart about what to include
            $surveyData = $data['survey_data'];
            
            // For multi-cycle comparisons, increase limit to ensure we have enough data
            $surveyLimit = isset($data['multi_cycle_comparison']) && $data['multi_cycle_comparison'] ? 2000 : 500;
            
            if (count($surveyData) > $surveyLimit) {
                $surveyData = array_slice($surveyData, 0, $surveyLimit);
                $context[] = "Survey Data (showing first {$surveyLimit} of " . count($data['survey_data']) . " surveys):";
            } else {
                $context[] = "Survey Data (total " . count($surveyData) . " surveys):";
            }
            
            $context[] = json_encode($surveyData, JSON_PRETTY_PRINT);
        }

        return implode("\n\n", $context);
    }

    private ?string $currentQuery = null;

    /**
     * Intelligently truncate context while preserving important data
     */
    private function intelligentTruncate(string $context, int $maxChars, string $query): string
    {
        $normalized = Str::of($query)->lower();
        
        // If query is about NPS, prioritize NPS data
        if ($normalized->contains(['nps', 'net promoter', 'promoter'])) {
            // Extract NPS-related parts first
            $lines = explode("\n", $context);
            $important = [];
            $other = [];
            
            foreach ($lines as $line) {
                if (stripos($line, 'nps') !== false || stripos($line, 'promoter') !== false || 
                    stripos($line, 'type": "nps"') !== false) {
                    $important[] = $line;
                } else {
                    $other[] = $line;
                }
            }
            
            $importantText = implode("\n", $important);
            $remaining = $maxChars - strlen($importantText);
            
            if ($remaining > 0) {
                return $importantText . "\n" . substr(implode("\n", $other), 0, $remaining);
            }
            
            return substr($importantText, 0, $maxChars);
        }
        
        // Otherwise, truncate from end
        return substr($context, 0, $maxChars) . "\n\n[Data truncated due to size...]";
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
  * answered_at: timestamp of last response
  * survey_cycle: cycle name (null if before cycles existed)
  * answers: array of answer objects with:
    - type: "nps" (0-10 score), "filtering" (demographic), "benchmark" (rating), "comment" (text)
    - question: question text
    - value: for filtering type (array)
    - rating: for benchmark type (number)
    - comment: for comment type (text)
    - created_at: timestamp

NPS Calculation:
- NPS = (% Promoters - % Detractors) * 100
- Promoters: scores 9-10
- Passives: scores 7-8
- Detractors: scores 0-6
- Always display exact numerical NPS score prominently at the beginning of your response
- CRITICAL: If "NPS Calculation" data is provided with exact values, USE THOSE EXACT VALUES. Do NOT recalculate or estimate.
- When NPS Calculation data is provided, start your response with: "The NPS score is [EXACT_SCORE]." (use the exact number from the calculation)

Multi-Cycle Comparison Queries:
- When asked about respondents who changed categories between cycles (e.g., "passives or detractors in cycle 1 who became promoters in cycle 2"):
  1. Match respondents by respondent_name across different cycles (skip "Anonymous")
  2. Extract NPS scores from each cycle answers array (look for type="nps", read nps.score first, then value as fallback)
  3. Categorize each score: Promoters (9-10), Passives (7-8), Detractors (0-6)
  4. Match cycle names flexibly (e.g., "Sprint 2025" matches "Spring 2025", handle variations)
  5. Count exact matches based on the query criteria
  6. Provide the exact count and list matching respondents if count <= 20
- CRITICAL: You have access to ALL survey data from both cycles in the Survey Data section. Use it to perform the comparison accurately.

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

