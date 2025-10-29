<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TenantFromQuery
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Prefer tenant header; fallback to ?tenant_id=
        $tenantId = $request->headers->get('tenant') ?: $request->query('tenant_id');

        if (!$tenantId) {
            return response('Tenant ID is required.', 400);
        }

        $prefix = (string) env('TENANT_DB_PREFIX', 'parentpulse_');
        $databaseName = $prefix . $tenantId;

        Config::set('database.connections.tenant.database', $databaseName);

        // Ensure the next queries use the fresh connection
        DB::purge('tenant');
        DB::reconnect('tenant');

        // Bind for downstream usage if needed
        app()->instance('tenant_id', $tenantId);

        return $next($request);
    }
}
