<?php

use Laravel\Mcp\Facades\Mcp;
use App\Mcp\Servers\ParentPulseServer;
use App\Mcp\Servers\ParentPulseServerV2;

// Expose a single MCP HTTP endpoint; tenant_id must be provided as a query param
Mcp::web('/mcp/parentpulse', ParentPulseServer::class)->middleware('tenant');

// V2 endpoint using knowledge base instead of SQL
Mcp::web('/mcp/parentpulse/v2', ParentPulseServerV2::class)->middleware('tenant');
