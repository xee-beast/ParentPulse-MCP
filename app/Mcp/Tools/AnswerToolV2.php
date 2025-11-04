<?php

namespace App\Mcp\Tools;

use App\Services\ChatMemory;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AnswerToolV2 extends Tool
{
    protected string $name = 'answer_v2';

    protected string $title = 'Answer Router V2';

    protected string $description = 'Routes queries to helpdesk or knowledge base tools. V2 uses knowledge base JSON instead of SQL queries.';

    public function schema(\Illuminate\JsonSchema\JsonSchema $schema): array
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
            if ($this->isKnowledgeBaseFollowUp($query, $memory->lastAnalytics($sessionId))) {
                $kbTool = app(KnowledgeBaseTool::class);
                $proxy = new Request(['query' => $query], $sessionId);
                return $kbTool->handle($proxy);
            }
        }

        $intent = $this->determineIntent($query);

        if ($intent === 'helpdesk') {
            $tool = app(HelpdeskTool::class);
            $proxy = new Request(['message' => $query], $request->sessionId());
            return $tool->handle($proxy);
        }

        // Route to knowledge base tool for data questions
        $kbTool = app(KnowledgeBaseTool::class);
        $proxy = new Request(['query' => $query], $request->sessionId());
        return $kbTool->handle($proxy);
    }

    private function determineIntent(string $query): string
    {
        $normalized = Str::of($query)->lower();

        // Keywords that indicate helpdesk queries
        $helpdeskKeywords = [
            'how to', 'how do i', 'how can i', 'tutorial', 'guide', 'help',
            'error', 'bug', 'issue', 'problem', 'not working', 'broken',
            'invoice', 'billing', 'payment', 'subscription', 'plan',
            'import', 'export', 'download', 'upload',
            'documentation', 'docs', 'manual', 'instructions',
            'support', 'customer service', 'contact',
        ];

        foreach ($helpdeskKeywords as $keyword) {
            if ($normalized->contains($keyword)) {
                return 'helpdesk';
            }
        }

        // Keywords that indicate data/analytics queries
        $dataKeywords = [
            'nps', 'net promoter', 'promoter score', 'score', 'scores',
            'response', 'responses', 'answer', 'answers', 'survey',
            'count', 'how many', 'list', 'show', 'get', 'give me',
            'parent', 'student', 'employee', 'admin', 'administrator',
            'grade', 'demographic', 'cycle', 'sequence', 'period',
            'correlation', 'driver', 'factor', 'trend', 'pattern',
            'detractor', 'promoter', 'passive', 'sentiment',
            'question', 'rating', 'benchmark', 'comment',
        ];

        foreach ($dataKeywords as $keyword) {
            if ($normalized->contains($keyword)) {
                return 'knowledge_base';
            }
        }

        // Use AI classification as fallback
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

                $system = 'Classify user query as either "helpdesk" or "knowledge_base". Respond with a single word: helpdesk or knowledge_base.';
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
                if ($text === 'knowledge_base') {
                    return 'knowledge_base';
                }
            } catch (\Throwable $e) {
                // Ignore and fallback to default
            }
        }

        return 'helpdesk';
    }

    private function isKnowledgeBaseFollowUp(string $query, ?array $lastAnalytics): bool
    {
        if ($lastAnalytics === null) {
            return false;
        }

        $source = $lastAnalytics['source'] ?? '';
        if ($source !== 'knowledge-base') {
            return false;
        }

        $normalized = Str::of($query)->lower();
        
        $followUpMarkers = [
            'their', 'those', 'them', 'also', 'and', 'what about',
            'how about', 'can you', 'give me', 'show me', 'tell me',
            'more', 'details', 'also', 'additionally'
        ];

        foreach ($followUpMarkers as $marker) {
            if ($normalized->contains($marker)) {
                return true;
            }
        }

        return false;
    }
}

