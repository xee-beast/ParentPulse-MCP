<?php

namespace App\Mcp\Tools;

use App\Services\ChatMemory;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AnswerTool extends Tool
{
    protected string $name = 'answer';

    protected string $title = 'Answer Router';

    protected string $description = 'Accepts a user query and routes to helpdesk or database tools.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('User query in natural language')->min(1)->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = (string) ($request->get('query', '') ?? '');
        if ($query === '') {
            return Response::error('Please provide a "query" to proceed.');
        }

        $sessionId = (string) ($request->sessionId() ?? '');
        if ($sessionId !== '') {
            /** @var ChatMemory $memory */
            $memory = app(ChatMemory::class);
            if ($this->isAnalyticsFollowUp($query, $memory->lastAnalytics($sessionId))) {
                $analytics = app(AnalyticsTool::class);
                $proxy = new Request(['query' => $query], $sessionId);
                return $analytics->handle($proxy);
            }
        }

        $intent = $this->determineIntent($query);

        if ($intent === 'helpdesk') {
            $tool = app(HelpdeskTool::class);
            $proxy = new Request(['message' => $query], $request->sessionId());
            return $tool->handle($proxy);
        }

        // Route to analytics tool for database questions
        $analytics = app(AnalyticsTool::class);
        $proxy = new Request(['query' => $query], $request->sessionId());
        return $analytics->handle($proxy);
    }

    private function determineIntent(string $query): string
    {
        $normalized = Str::of($query)->lower();

        // Helpdesk administrative keywords (only for true administrative questions)
        $helpdeskAdminKeywords = [
            'how do i', 'how to', 'where is', 'where can i', 'settings', 'configuration', 'setup', 
            'admin panel', 'administrative panel', 'manage users', 'import data', 'export data'
        ];
        foreach ($helpdeskAdminKeywords as $kw) {
            if ($normalized->contains($kw)) {
                return 'helpdesk';
            }
        }

        // DB-first heuristics (prioritized) - more comprehensive patterns
        $dbKeywords = [
            'how many', 'number of', 'count', 'total',
            'sql', 'select', 'sum', 'avg', 'top', 'last 30 days', 'list', 'where clause',
            'net promoter score', 'nps', 'promoters', 'detractors', 'passives',
            'trend', 'trends', 'pattern', 'patterns', 'past 90 days', 'time period', 'recent', 'latest', 'last',
            'by grade', 'grade level', 'by campus', 'by demographic', 'demographic variable',
            'correlation', 'drivers', 'biggest drivers', 'factors',
            'respond', 'responded', 'response', 'responses', 'response count', 'survey responses', 'survey data', 'survey results',
            'comments', 'comment', 'feedback', 'unhappy', 'satisfied', 'dissatisfied', 'negative', 'positive',
            'feeling', 'feelings', 'sentiment', 'satisfaction', 'happy', 'happiness', 'overall', 'about our school',
            'how are', 'what do', 'parent', 'parents', 'student', 'students', 'employee', 'employees',
            // Add database-specific terms
            'cancelled', 'canceled', 'cancellation', 'status', 'active', 'inactive', 'completed', 'pending',
            'survey cycles', 'survey cycle', 'cycles', 'sequence', 'sequences',
            'admin', 'admins', 'administrator', 'administrators', 'school admin', 'school admins', 'management',
            'owner', 'school owner', 'permissions', 'user permissions', 'demographic permissions',
            'is_owner', 'user table', 'users table', 'show me users', 'list users', 'admin details', 'details', 'anne badger'
        ];
        foreach ($dbKeywords as $kw) {
            if ($normalized->contains($kw)) {
                return 'database';
            }
        }
        
        // Special pattern: admin + person name
        if ($normalized->contains('admin') && preg_match('/[a-z]+\s+[a-z]+/i', $query)) {
            return 'database';
        }
        
        // Special pattern: admin details + person name
        if ($normalized->contains('admin') && $normalized->contains('details') && preg_match('/[a-z]+\s+[a-z]+/i', $query)) {
            return 'database';
        }

        // Strong pattern: references a parent by name with responses/surveys
        if (preg_match('/parent\s+[a-z\'-]+\s+[a-z\'-]+/i', (string) $normalized) &&
            ($normalized->contains('response') || $normalized->contains('responses') || $normalized->contains('survey') || $normalized->contains('surveys')))
        {
            return 'database';
        }

        // Helpdesk heuristics (reduced)
        $helpdeskKeywords = [
            'how do', 'how to', 'where', 'faq', 'help', 'settings', 'reminder', 'module', 'import', 'export', 'billing', 'sequence', 'quiet period', 'multilingual', 'benchmark', 'review site', 'saved responses'
        ];
        foreach ($helpdeskKeywords as $kw) {
            if ($normalized->contains($kw)) {
                return 'helpdesk';
            }
        }

        // If OpenAI is configured, ask it to classify
        $apiKey = (string) config('app.openai_api_key', '');
        $model = (string) env('OPENAI_MODEL', 'gpt-4o-mini');
        if ($apiKey !== '') {
            try {
                $client = new \GuzzleHttp\Client([
                    'base_uri' => 'https://api.openai.com/v1/',
                    'timeout' => 12,
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'Content-Type' => 'application/json',
                    ],
                ]);

                $system = 'Classify user query as either "helpdesk" or "database". Respond with a single word: helpdesk or database.';
                $payload = [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $query],
                    ],
                    'temperature' => 0,
                ];

                $res = $client->post('chat/completions', ['json' => $payload]);
                $json = json_decode((string) $res->getBody(), true);
                $text = strtolower(trim((string) ($json['choices'][0]['message']['content'] ?? '')));
                if ($text === 'database') {
                    return 'database';
                }
            } catch (\Throwable $e) {
                // Ignore and fallback to default
            }
        }

        return 'helpdesk';
    }

    /**
     * @param  array<string, mixed>|null  $lastAnalytics
     */
    private function isAnalyticsFollowUp(string $query, ?array $lastAnalytics): bool
    {
        if ($lastAnalytics === null) {
            return false;
        }

        $normalized = Str::of($query)->lower()->trim();
        if ($normalized === '') {
            return false;
        }

        $keywords = [
            'this score', 'that score', 'improve', 'increase', 'boost', 'raise',
            'lower', 'reduce', 'why is', 'why the score', 'what does this mean',
            'explain it', 'explain this', 'more detail', 'break it down',
            'tell me more', 'next steps', 'how can i improve', 'what should i do',
            'recommend', 'suggestion', 'strategy', 'help us improve', 'action plan',
            'compare it', 'compare this', 'trend', 'follow up', 'any insight',
            'what now', 'what else', 'interpret', 'analysis', 'drivers'
        ];
        $keywords[] = 'permission';
        $keywords[] = 'permissions';
        foreach ($keywords as $keyword) {
            if (Str::contains($normalized, $keyword)) {
                return true;
            }
        }

        if (preg_match('/^(how|what|why|can|should)\b/', $normalized) &&
            Str::contains($normalized, ['score', 'nps', 'responses', 'result', 'data', 'numbers'])) {
            return true;
        }

        $wordCount = str_word_count($normalized);
        if ($wordCount > 0 && $wordCount <= 6 && Str::contains($normalized, ['it', 'this', 'that'])) {
            return true;
        }

        return false;
    }

    /**
     * Extract recognized database intents to show we understood the analytics request.
     *
     * @return array<int, string>
     */
    private function detectDbIntents(string $query): array
    {
        $q = Str::of($query)->lower();
        $intents = [];

        if ($q->contains(['net promoter score', 'nps'])) {
            $intents[] = 'metric:nps';
        }
        if ($q->contains(['by grade', 'grade level'])) {
            $intents[] = 'dimension:grade_level';
        }
        if ($q->contains(['by campus'])) {
            $intents[] = 'dimension:campus';
        }
        if ($q->contains(['demographic'])) {
            $intents[] = 'dimension:demographic';
        }
        if ($q->contains(['past 90 days', 'last 90 days'])) {
            $intents[] = 'time_window:90_days';
        }
        if ($q->contains(['cycle', 'time period'])) {
            $intents[] = 'time_window:cycle_or_period';
        }
        if ($q->contains(['promoters', 'detractors', 'passives'])) {
            $intents[] = 'cohorts:promoters_passives_detractors';
        }
        if ($q->contains(['correlation', 'drivers', 'factors'])) {
            $intents[] = 'analysis:correlation';
        }
        if ($q->contains(['trend', 'trends', 'pattern', 'patterns'])) {
            $intents[] = 'analysis:trend_detection';
        }

        return $intents === [] ? ['analysis:general'] : $intents;
    }
}
