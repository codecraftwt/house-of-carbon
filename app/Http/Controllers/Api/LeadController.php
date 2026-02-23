<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class LeadController extends Controller
{
    private const STATUSES = ['new', 'contacted', 'qualified', 'converted'];

    #[OA\Get(
        path: "/api/leads",
        summary: "Display a listing of leads with filters",
        description: "Admin only.",
        tags: ["Lead Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "search", in: "query", description: "Search by company, contact, email, or phone", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "status", in: "query", description: "Filter by status (new, contacted, qualified, converted)", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "date", in: "query", description: "Filter by added_date (YYYY-MM-DD)", required: false, schema: new OA\Schema(type: "string", format: "date")),
            new OA\Parameter(name: "date_from", in: "query", description: "Filter added_date from (YYYY-MM-DD)", required: false, schema: new OA\Schema(type: "string", format: "date")),
            new OA\Parameter(name: "date_to", in: "query", description: "Filter added_date to (YYYY-MM-DD)", required: false, schema: new OA\Schema(type: "string", format: "date")),
            new OA\Parameter(name: "per_page", in: "query", description: "Number of items per page", required: false, schema: new OA\Schema(type: "integer", default: 10))
        ],
        responses: [
            new OA\Response(response: 200, description: "List of leads"),
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
        $query = Lead::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('company', 'LIKE', "%{$search}%")
                  ->orWhere('contact', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%")
                  ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date')) {
            $query->whereDate('added_date', $request->input('date'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('added_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('added_date', '<=', $request->input('date_to'));
        }

        $leads = $query->latest()->paginate($request->input('per_page', 10));

        return response()->json([
            'status' => 'success',
            'data' => $leads
        ]);
    }

    #[OA\Post(
        path: "/api/leads",
        summary: "Create a new lead",
        description: "Admin only.",
        tags: ["Lead Management"],
        security: [["bearerAuth" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["company", "contact"],
                properties: [
                    new OA\Property(property: "company", type: "string", example: "Acme Corp"),
                    new OA\Property(property: "contact", type: "string", example: "John Doe"),
                    new OA\Property(property: "email", type: "string", example: "john@acme.com"),
                    new OA\Property(property: "phone", type: "string", example: "+91 99999 99999"),
                    new OA\Property(property: "value", type: "number", format: "float", example: 25000),
                    new OA\Property(property: "added_date", type: "string", format: "date", example: "2026-02-20"),
                    new OA\Property(property: "last_contact", type: "string", format: "date", example: "2026-02-21"),
                    new OA\Property(property: "status", type: "string", example: "new")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Lead created"),
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
            ),
            new OA\Response(
                response: 422,
                description: "Validation error",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "status", type: "string", example: "error"),
                        new OA\Property(property: "errors", type: "object", example: ["company" => ["The company field is required."]])
                    ]
                )
            )
        ]
    )]
    public function store(Request $request)
    {
        $this->normalizeDateInputs($request);

        $validator = Validator::make($request->all(), [
            'company' => 'required|string|max:255',
            'contact' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'value' => 'nullable|numeric|min:0',
            'added_date' => 'nullable|date',
            'last_contact' => 'nullable|date',
            'status' => 'nullable|in:' . implode(',', self::STATUSES),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $lead = Lead::create($request->only([
            'company',
            'contact',
            'email',
            'phone',
            'value',
            'added_date',
            'last_contact',
            'status',
        ]));

        AuditLogger::log(
            'Create',
            'Lead',
            $lead->id,
            'Created lead for ' . $lead->company,
            ['company' => $lead->company],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Lead created successfully',
            'data' => $lead
        ], 201);
    }

    #[OA\Get(
        path: "/api/leads/{id}",
        summary: "Display the specified lead",
        description: "Admin only.",
        tags: ["Lead Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Lead details"),
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
            ),
            new OA\Response(
                response: 404,
                description: "Lead not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Lead not found")
                    ]
                )
            )
        ]
    )]
    public function show(Lead $lead)
    {
        return response()->json([
            'status' => 'success',
            'data' => $lead
        ]);
    }

    #[OA\Put(
        path: "/api/leads/{id}",
        summary: "Update the specified lead",
        description: "Admin only.",
        tags: ["Lead Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "company", type: "string"),
                    new OA\Property(property: "contact", type: "string"),
                    new OA\Property(property: "email", type: "string"),
                    new OA\Property(property: "phone", type: "string"),
                    new OA\Property(property: "value", type: "number", format: "float"),
                    new OA\Property(property: "added_date", type: "string", format: "date"),
                    new OA\Property(property: "last_contact", type: "string", format: "date"),
                    new OA\Property(property: "status", type: "string")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Lead updated"),
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
            ),
            new OA\Response(
                response: 422,
                description: "Validation error",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "status", type: "string", example: "error"),
                        new OA\Property(property: "errors", type: "object", example: ["status" => ["The selected status is invalid."]])
                    ]
                )
            )
        ]
    )]
    #[OA\Patch(
        path: "/api/leads/{id}",
        summary: "Partially update the specified lead",
        description: "Admin only.",
        tags: ["Lead Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "company", type: "string"),
                    new OA\Property(property: "contact", type: "string"),
                    new OA\Property(property: "email", type: "string"),
                    new OA\Property(property: "phone", type: "string"),
                    new OA\Property(property: "value", type: "number", format: "float"),
                    new OA\Property(property: "added_date", type: "string", format: "date"),
                    new OA\Property(property: "last_contact", type: "string", format: "date"),
                    new OA\Property(property: "status", type: "string")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Lead updated"),
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
            ),
            new OA\Response(
                response: 422,
                description: "Validation error",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "status", type: "string", example: "error"),
                        new OA\Property(property: "errors", type: "object", example: ["status" => ["The selected status is invalid."]])
                    ]
                )
            )
        ]
    )]
    public function update(Request $request, Lead $lead)
    {
        $this->normalizeDateInputs($request);

        $validator = Validator::make($request->all(), [
            'company' => 'sometimes|required|string|max:255',
            'contact' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => 'sometimes|nullable|string|max:30',
            'value' => 'sometimes|nullable|numeric|min:0',
            'added_date' => 'sometimes|nullable|date',
            'last_contact' => 'sometimes|nullable|date',
            'status' => 'sometimes|nullable|in:' . implode(',', self::STATUSES),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $lead->update($request->only([
            'company',
            'contact',
            'email',
            'phone',
            'value',
            'added_date',
            'last_contact',
            'status',
        ]));

        AuditLogger::log(
            'Update',
            'Lead',
            $lead->id,
            'Updated lead for ' . $lead->company,
            ['company' => $lead->company],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Lead updated successfully',
            'data' => $lead
        ]);
    }

    #[OA\Delete(
        path: "/api/leads/{id}",
        summary: "Delete the specified lead",
        description: "Admin only.",
        tags: ["Lead Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Lead deleted"),
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
            ),
            new OA\Response(
                response: 404,
                description: "Lead not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Lead not found")
                    ]
                )
            )
        ]
    )]
    public function destroy(Lead $lead)
    {
        $lead->delete();

        AuditLogger::log(
            'Delete',
            'Lead',
            $lead->id,
            'Deleted lead for ' . $lead->company,
            ['company' => $lead->company],
            request()
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Lead deleted successfully'
        ]);
    }

    #[OA\Patch(
        path: "/api/leads/{id}/status",
        summary: "Update lead pipeline status",
        description: "Admin only.",
        tags: ["Lead Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["status"],
                properties: [
                    new OA\Property(property: "status", type: "string", example: "contacted")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Lead status updated"),
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
            ),
            new OA\Response(
                response: 422,
                description: "Validation error",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "status", type: "string", example: "error"),
                        new OA\Property(property: "errors", type: "object", example: ["status" => ["The selected status is invalid."]])
                    ]
                )
            )
        ]
    )]
    public function updateStatus(Request $request, Lead $lead)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:' . implode(',', self::STATUSES),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $lead->update([
            'status' => $request->status
        ]);

        AuditLogger::log(
            'Update',
            'Lead',
            $lead->id,
            'Updated lead status to ' . $request->status,
            ['company' => $lead->company, 'status' => $request->status],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Lead status updated successfully',
            'data' => $lead
        ]);
    }

    private function normalizeDateInputs(Request $request): void
    {
        if ($request->has('addedDate') && !$request->has('added_date')) {
            $request->merge(['added_date' => $request->input('addedDate')]);
        }
        if ($request->has('lastContact') && !$request->has('last_contact')) {
            $request->merge(['last_contact' => $request->input('lastContact')]);
        }
    }
}
