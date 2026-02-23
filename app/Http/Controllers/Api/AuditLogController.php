<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class AuditLogController extends Controller
{
    #[OA\Get(
        path: "/api/audit-logs",
        summary: "Display a listing of audit logs",
        description: "Admin only.",
        tags: ["Audit Logs"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "user", in: "query", description: "Filter by user id, name, or email", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "search", in: "query", description: "Search across user, role, action, resource, or details", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "role", in: "query", description: "Filter by role name", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "action", in: "query", description: "Filter by action", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "date", in: "query", description: "Filter by created date (YYYY-MM-DD)", required: false, schema: new OA\Schema(type: "string", format: "date")),
            new OA\Parameter(name: "date_from", in: "query", description: "Filter created_at from (YYYY-MM-DD)", required: false, schema: new OA\Schema(type: "string", format: "date")),
            new OA\Parameter(name: "date_to", in: "query", description: "Filter created_at to (YYYY-MM-DD)", required: false, schema: new OA\Schema(type: "string", format: "date")),
            new OA\Parameter(name: "per_page", in: "query", description: "Number of items per page", required: false, schema: new OA\Schema(type: "integer", default: 10)),
            new OA\Parameter(name: "include_stats", in: "query", description: "Include action counts in response", required: false, schema: new OA\Schema(type: "boolean", default: false))
        ],
        responses: [
            new OA\Response(response: 200, description: "List of audit logs"),
            new OA\Response(
                response: 401,
                description: "Unauthenticated",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Unauthenticated.")
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: "Unauthorized",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Unauthorized")
                    ]
                )
            )
        ]
    )]
    public function index(Request $request)
    {
        $query = AuditLog::with(['user.role'])->latest();
        $this->applyFilters($request, $query);

        $stats = null;
        if ($request->boolean('include_stats')) {
            $stats = $this->buildStats(clone $query);
        }

        $logs = $query->paginate($request->input('per_page', 10));

        return response()->json([
            'status' => 'success',
            'data' => $logs,
            'stats' => $stats
        ]);
    }

    #[OA\Get(
        path: "/api/audit-logs/export",
        summary: "Export audit logs as CSV",
        description: "Admin only.",
        tags: ["Audit Logs"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "user", in: "query", description: "Filter by user id, name, or email", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "search", in: "query", description: "Search across user, role, action, resource, or details", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "role", in: "query", description: "Filter by role name", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "action", in: "query", description: "Filter by action", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "date", in: "query", description: "Filter by created date (YYYY-MM-DD)", required: false, schema: new OA\Schema(type: "string", format: "date")),
            new OA\Parameter(name: "date_from", in: "query", description: "Filter created_at from (YYYY-MM-DD)", required: false, schema: new OA\Schema(type: "string", format: "date")),
            new OA\Parameter(name: "date_to", in: "query", description: "Filter created_at to (YYYY-MM-DD)", required: false, schema: new OA\Schema(type: "string", format: "date"))
        ],
        responses: [
            new OA\Response(response: 200, description: "CSV export"),
            new OA\Response(
                response: 401,
                description: "Unauthenticated",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Unauthenticated.")
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: "Unauthorized",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Unauthorized")
                    ]
                )
            )
        ]
    )]
    public function export(Request $request)
    {
        $query = AuditLog::with(['user.role'])->latest();
        $this->applyFilters($request, $query);
        $logs = $query->get();

        $filename = 'audit-logs-' . now()->format('Ymd-His') . '.csv';

        return response()->stream(function () use ($logs) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Timestamp',
                'User Name',
                'User Email',
                'Role',
                'Action',
                'Resource',
                'Details',
                'IP Address',
                'User Agent'
            ]);

            foreach ($logs as $log) {
                $userName = $log->user?->name ?? 'System';
                $userEmail = $log->user?->email ?? '';
                $role = $log->role ?? $log->user?->role?->name ?? '';
                $resource = $log->entity_type ?? '';
                if ($log->entity_id) {
                    $resource .= ' #' . $log->entity_id;
                }
                if (!empty($log->meta['quote_id'])) {
                    $resource .= ' (' . $log->meta['quote_id'] . ')';
                }

                fputcsv($handle, [
                    $log->created_at?->format('Y-m-d H:i:s'),
                    $userName,
                    $userEmail,
                    $role,
                    $log->action,
                    $resource,
                    $log->description,
                    $log->ip_address,
                    $log->user_agent,
                ]);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function applyFilters(Request $request, $query): void
    {
        if ($request->filled('user')) {
            $userFilter = $request->input('user');
            $query->whereHas('user', function ($q) use ($userFilter) {
                if (is_numeric($userFilter)) {
                    $q->where('id', $userFilter);
                } else {
                    $q->where('name', 'LIKE', "%{$userFilter}%")
                      ->orWhere('email', 'LIKE', "%{$userFilter}%");
                }
            });
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('action', 'LIKE', "%{$search}%")
                  ->orWhere('role', 'LIKE', "%{$search}%")
                  ->orWhere('entity_type', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%")
                  ->orWhereHas('user', function ($qu) use ($search) {
                      $qu->where('name', 'LIKE', "%{$search}%")
                         ->orWhere('email', 'LIKE', "%{$search}%");
                  });
            });
        }

        if ($request->filled('role') && $request->role !== 'all') {
            $role = strtolower(str_replace(['_', '-'], ' ', trim($request->input('role'))));
            $query->where(function ($q) use ($role) {
                $q->whereRaw('LOWER(role) = ?', [$role])
                  ->orWhereHas('user.role', function ($qr) use ($role) {
                      $qr->whereRaw('LOWER(name) = ?', [$role]);
                  });
            });
        }

        if ($request->filled('action') && $request->action !== 'all') {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->input('date'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }
    }

    private function buildStats($query): array
    {
        $counts = $query->select('action', DB::raw('count(*) as total'))
            ->groupBy('action')
            ->pluck('total', 'action')
            ->toArray();

        $defaults = [
            'Create' => 0,
            'Update' => 0,
            'Delete' => 0,
            'Login' => 0,
            'Logout' => 0,
            'Approve' => 0,
            'Reject' => 0,
            'Send' => 0,
        ];

        foreach ($counts as $action => $total) {
            $defaults[$action] = (int) $total;
        }

        return $defaults;
    }
}
