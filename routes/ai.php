<?php

use Laravel\Mcp\Facades\Mcp;
use App\Mcp\Servers\ParentPulseServer;

// Expose a single MCP HTTP endpoint; tenant_id must be provided as a query param
Mcp::web('/mcp/parentpulse', ParentPulseServer::class)->middleware('tenant');
