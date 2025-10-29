<?php

namespace App\Mcp\Tools;

use App\Services\ChatMemory;
use App\Services\HelpdeskKnowledgeBase;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Illuminate\JsonSchema\JsonSchema;

class HelpdeskTool extends Tool
{
    protected string $name = 'helpdesk-query';

    protected string $title = 'Helpdesk Query';

    protected string $description = 'Answer general helpdesk questions for ParentPulse tenants.';

    /**
     * Define the input schema for this tool.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()->description('User question or helpdesk query')->min(1)->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $tenantId = (string) (app('tenant_id') ?? '');
        $message = (string) ($request->get('message', '') ?? '');
        $sessionId = (string) ($request->sessionId() ?? '');

        if ($message === '') {
            return Response::error('Please provide a helpdesk "message" to proceed.');
        }

        $category = $this->determineCategory($message);

        $kb = app(HelpdeskKnowledgeBase::class);
        $match = $kb->findBestMatch($message);

        $articleText = null;
        if ($match !== null) {
            $articleText = $kb->fetchArticleText($match['url']);
        }

        $formatted = $this->composeAnswer(
            userQuery: $message,
            category: $category,
            articleText: $articleText,
            articleUrl: $match['url'] ?? null,
            articleTitle: $match['title'] ?? null,
            tenantId: $tenantId,
        );

        if ($sessionId !== '') {
            app(ChatMemory::class)->rememberHelpdesk($sessionId, $message, $formatted, [
                'category' => $category,
                'article_url' => $match['url'] ?? null,
                'tenant_id' => $tenantId,
            ]);
        }

        return Response::text($formatted);
    }

    private function determineCategory(string $message): string
    {
        $normalized = Str::of($message)->trim()->lower();
        if ($normalized === '' || Str::contains($normalized, ['hello', 'hi', 'hey'])) {
            return 'greeting';
        }
        if (Str::contains($normalized, ['invoice', 'billing', 'payment', 'subscription'])) {
            return 'billing';
        }
        if (Str::contains($normalized, ['error', 'bug', 'issue', 'not working', 'failed'])) {
            return 'technical';
        }
        if (Str::contains($normalized, ['import', 'build survey', 'quiet period', 'sequence', 'benchmark', 'review site', 'multilingual'])) {
            return 'howto';
        }
        return 'general';
    }

    private function summarizeWithOpenAI(string $query, string $category, ?string $articleText, ?string $url): string
    {
        $apiKey = (string) config('app.openai_api_key', '');
        $model = (string) env('OPENAI_MODEL', 'gpt-4o-mini');

        if ($apiKey === '') {
            return $this->fallbackSummary($query, $category, $articleText, $url);
        }

        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $system = 'You are a helpful ParentPulse helpdesk assistant. Summarize clearly with short paragraphs and bullet points. If article content is provided, base the answer on it. Keep it actionable, avoid fluff. Do not include JSON. Append a final line: "For more info, visit {URL}" if a source URL exists.';
            $user = trim("User Query: {$query}\nCategory: {$category}\nSource URL: ".($url ?? 'n/a')."\n\nArticle Content (may be empty):\n".($articleText ?? ''));

            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature' => 0.2,
            ];

            $res = $client->post('chat/completions', ['json' => $payload]);
            $json = json_decode((string) $res->getBody(), true);
            $text = $json['choices'][0]['message']['content'] ?? null;
            if (is_string($text) && $text !== '') {
                return $text;
            }
        } catch (\Throwable $e) {
            // fall through to fallback
        }

        return $this->fallbackSummary($query, $category, $articleText, $url);
    }

    private function fallbackSummary(string $query, string $category, ?string $articleText, ?string $url): string
    {
        if ($articleText) {
            $bulleted = $this->bulletize($articleText, 6);
            $out = "Here is a concise summary:\n{$bulleted}";
        } else {
            $out = "I can help with that. This topic is covered in our documentation.";
        }
        if ($url) {
            $out .= "\n\nFor more info, visit {$url}";
        }
        return $out;
    }

    private function composeAnswer(string $userQuery, string $category, ?string $articleText, ?string $articleUrl, ?string $articleTitle, string $tenantId): string
    {
        // Prefer LLM summary when available
        $summary = $this->summarizeWithOpenAI($userQuery, $category, $articleText, $articleUrl);

        // Ensure we always end with the source link
        if ($articleUrl && ! Str::contains($summary, 'For more info')) {
            $summary = rtrim($summary)."\n\nFor more info, visit {$articleUrl}";
        }

        // Add an optional header title line if known
        if ($articleTitle) {
            $summary = $articleTitle."\n\n".$summary;
        }

        return $summary;
    }

    private function bulletize(string $text, int $maxItems = 6): string
    {
        $text = trim($text);
        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [];
        $items = array_slice(array_filter(array_map(fn ($s) => trim($s), $sentences), fn ($s) => $s !== ''), 0, $maxItems);
        if ($items === []) {
            return Str::limit($text, 400);
        }
        $bullets = array_map(fn ($s) => '- '.Str::limit($s, 280), $items);
        return implode("\n", $bullets);
    }
}

