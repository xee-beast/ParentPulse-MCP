<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;
use App\Mcp\Tools\HelpdeskTool;
use App\Mcp\Tools\AnswerTool;
use App\Mcp\Tools\AnalyticsTool;

class ParentPulseServer extends Server
{
    /**
     * The MCP server's name.
     */
    protected string $name = 'Parent Pulse Server';

    /**
     * The MCP server's version.
     */
    protected string $version = '0.0.1';

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = <<<'MARKDOWN'
        You are the ParentPulse MCP server. Use tools to assist tenant users.
        - Always infer tenant context from app('tenant_id') set by middleware.
        - Use the answer tool as the single entry point. It accepts { query } only.
        - The answer tool decides helpdesk vs database and routes accordingly.
        - Database tools will be added later.
    MARKDOWN;

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        AnswerTool::class,
        HelpdeskTool::class,
        AnalyticsTool::class,
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
