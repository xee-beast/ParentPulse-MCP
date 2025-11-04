<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;
use App\Mcp\Tools\HelpdeskTool;
use App\Mcp\Tools\AnswerToolV2;
use App\Mcp\Tools\KnowledgeBaseTool;

class ParentPulseServerV2 extends Server
{
    /**
     * The MCP server's name.
     */
    protected string $name = 'Parent Pulse Server V2';

    /**
     * The MCP server's version.
     */
    protected string $version = '0.0.2';

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = <<<'MARKDOWN'
        You are the ParentPulse MCP server V2. Use tools to assist tenant users.
        - Always infer tenant context from app('tenant_id') set by middleware.
        - Use the answer_v2 tool as the single entry point. It accepts { query } only.
        - The answer_v2 tool decides helpdesk vs knowledge_base and routes accordingly.
        - Knowledge base tool uses pre-exported JSON data instead of generating SQL queries.
        - This is faster and more reliable for analytics queries.
    MARKDOWN;

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        AnswerToolV2::class,
        HelpdeskTool::class,
        KnowledgeBaseTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Resource>>
     */
    protected array $resources = [
        //
    ];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Prompt>>
     */
    protected array $prompts = [
        //
    ];
}

