<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class QuotationController extends Controller
{
    private const STATUSES = ['Draft', 'Sent', 'Approved', 'Rejected', 'ChangesRequested'];
    #[OA\Get(
        path: "/api/quotations",
        summary: "Display a listing of quotations",
        description: "Admin only.",
        tags: ["Quotation Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "search", in: "query", description: "Search by customer name, email, or company", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "status", in: "query", description: "Filter by status", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "per_page", in: "query", description: "Number of items per page", required: false, schema: new OA\Schema(type: "integer", default: 10))
        ],
        responses: [
            new OA\Response(response: 200, description: "List of quotations"),
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
        $query = Quotation::with(['user.companyDetail', 'items']);

        // Search by Customer (Name, Email, Company)
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%")
                  ->orWhereHas('companyDetail', function ($q2) use ($search) {
                      $q2->where('company_name', 'LIKE', "%{$search}%");
                  });
            });
        }

        // Filter by Status
        if ($request->filled('status') && $request->status != 'all') {
            $query->where('status', $request->input('status'));
        }

        $quotations = $query->latest()->paginate($request->input('per_page', 10));

        return response()->json([
            'status' => 'success',
            'data' => $quotations
        ]);
    }

    #[OA\Post(
        path: "/api/quotations",
        summary: "Create a new quotation",
        description: "Admin only.",
        tags: ["Quotation Management"],
        security: [["bearerAuth" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["user_id", "date", "valid_until", "items"],
                properties: [
                    new OA\Property(property: "user_id", type: "integer", example: 1),
                    new OA\Property(property: "date", type: "string", format: "date", example: "2024-01-22"),
                    new OA\Property(property: "valid_until", type: "string", format: "date", example: "2024-02-22"),
                    new OA\Property(property: "status", type: "string", example: "Draft"),
                    new OA\Property(property: "terms_and_conditions", type: "string", example: "Payment due in 30 days."),
                    new OA\Property(property: "customer_note", type: "string", example: "Need price breakup by item."),
                    new OA\Property(property: "items", type: "array", items: new OA\Items(
                        properties: [
                            new OA\Property(property: "description", type: "string"),
                            new OA\Property(property: "quantity", type: "integer"),
                            new OA\Property(property: "unit", type: "string"),
                            new OA\Property(property: "unit_price", type: "number", format: "float"),
                        ]
                    ))
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Quotation created"),
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
                        new OA\Property(property: "errors", type: "object", example: ["items" => ["The items field is required."]])
                    ]
                )
            )
        ]
    )]
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'date' => 'required|date',
            'valid_until' => 'required|date|after_or_equal:date',
            'status' => 'nullable|in:' . implode(',', self::STATUSES),
            'terms_and_conditions' => 'nullable|string',
            'customer_note' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit' => 'nullable|string',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Generate Quote ID
        $year = date('Y');
        $count = Quotation::whereYear('created_at', $year)->withTrashed()->count() + 1;
        $quoteId = 'Q-' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);

        // Calculate Total Amount
        $totalAmount = 0;
        foreach ($request->items as $item) {
            $totalAmount += $item['quantity'] * $item['unit_price'];
        }

        $quotation = Quotation::create([
            'quote_id' => $quoteId,
            'user_id' => $request->user_id,
            'date' => $request->date,
            'valid_until' => $request->valid_until,
            'status' => $request->status ?? 'Draft',
            'terms_and_conditions' => $request->terms_and_conditions,
            'customer_note' => $request->customer_note,
            'total_amount' => $totalAmount,
        ]);

        foreach ($request->items as $item) {
            $quotation->items()->create([
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'] ?? 'Pieces',
                'unit_price' => $item['unit_price'],
                'total' => $item['quantity'] * $item['unit_price'],
            ]);
        }

        AuditLogger::log(
            'Create',
            'Quotation',
            $quotation->id,
            'Created quotation ' . $quotation->quote_id,
            ['quote_id' => $quotation->quote_id],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Quotation created successfully',
            'data' => $quotation->load(['user.companyDetail', 'items'])
        ], 201);
    }

    #[OA\Get(
        path: "/api/quotations/{id}",
        summary: "Display the specified quotation",
        description: "Admin only.",
        tags: ["Quotation Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Quotation details"),
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
                description: "Quotation not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "status", type: "string", example: "error"),
                        new OA\Property(property: "message", type: "string", example: "Quotation not found")
                    ]
                )
            )
        ]
    )]
    public function show($id)
    {
        $quotation = Quotation::with(['user.companyDetail', 'items'])->find($id);

        if (!$quotation) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $quotation
        ]);
    }

    #[OA\Put(
        path: "/api/quotations/{id}",
        summary: "Update the specified quotation",
        description: "Admin only.",
        tags: ["Quotation Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "user_id", type: "integer"),
                    new OA\Property(property: "date", type: "string", format: "date"),
                    new OA\Property(property: "valid_until", type: "string", format: "date"),
                    new OA\Property(property: "status", type: "string"),
                    new OA\Property(property: "terms_and_conditions", type: "string"),
                    new OA\Property(property: "customer_note", type: "string"),
                    new OA\Property(property: "items", type: "array", items: new OA\Items(
                        properties: [
                            new OA\Property(property: "description", type: "string"),
                            new OA\Property(property: "quantity", type: "integer"),
                            new OA\Property(property: "unit", type: "string"),
                            new OA\Property(property: "unit_price", type: "number", format: "float")
                        ]
                    ))
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Quotation updated"),
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
                description: "Quotation not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Quotation not found")
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
        path: "/api/quotations/{id}",
        summary: "Partially update the specified quotation",
        description: "Admin only.",
        tags: ["Quotation Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "user_id", type: "integer"),
                    new OA\Property(property: "date", type: "string", format: "date"),
                    new OA\Property(property: "valid_until", type: "string", format: "date"),
                    new OA\Property(property: "status", type: "string"),
                    new OA\Property(property: "terms_and_conditions", type: "string"),
                    new OA\Property(property: "customer_note", type: "string"),
                    new OA\Property(property: "items", type: "array", items: new OA\Items(
                        properties: [
                            new OA\Property(property: "description", type: "string"),
                            new OA\Property(property: "quantity", type: "integer"),
                            new OA\Property(property: "unit", type: "string"),
                            new OA\Property(property: "unit_price", type: "number", format: "float")
                        ]
                    ))
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Quotation updated"),
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
                description: "Quotation not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Quotation not found")
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
    public function update(Request $request, $id)
    {
        $quotation = Quotation::with('items')->find($id);

        if (!$quotation) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'sometimes|required|exists:users,id',
            'date' => 'sometimes|required|date',
            'valid_until' => 'sometimes|required|date|after_or_equal:date',
            'status' => 'sometimes|nullable|in:' . implode(',', self::STATUSES),
            'terms_and_conditions' => 'sometimes|nullable|string',
            'customer_note' => 'sometimes|nullable|string',
            'items' => 'sometimes|array|min:1',
            'items.*.description' => 'required_with:items|string',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.unit' => 'nullable|string',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $quotation->update($request->only([
            'user_id',
            'date',
            'valid_until',
            'status',
            'terms_and_conditions',
            'customer_note',
        ]));

        if ($request->has('items')) {
            $totalAmount = $this->calculateTotal($request->items);
            $quotation->update([
                'total_amount' => $totalAmount,
            ]);

            $quotation->items()->delete();
            foreach ($request->items as $item) {
                $quotation->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'] ?? 'Pieces',
                    'unit_price' => $item['unit_price'],
                    'total' => $item['quantity'] * $item['unit_price'],
                ]);
            }
        }

        AuditLogger::log(
            'Update',
            'Quotation',
            $quotation->id,
            'Updated quotation ' . $quotation->quote_id,
            ['quote_id' => $quotation->quote_id],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Quotation updated successfully',
            'data' => $quotation->load(['user.companyDetail', 'items'])
        ]);
    }

    #[OA\Patch(
        path: "/api/quotations/{id}/status",
        summary: "Update quotation status",
        description: "Admin only.",
        tags: ["Quotation Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["status"],
                properties: [
                    new OA\Property(property: "status", type: "string", example: "Sent")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Quotation status updated"),
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
                description: "Quotation not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Quotation not found")
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
    public function updateStatus(Request $request, $id)
    {
        $quotation = Quotation::find($id);

        if (!$quotation) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:' . implode(',', self::STATUSES),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $quotation->update([
            'status' => $request->status
        ]);

        AuditLogger::log(
            $this->statusToAction($request->status),
            'Quotation',
            $quotation->id,
            'Updated quotation status to ' . $request->status,
            ['quote_id' => $quotation->quote_id, 'status' => $request->status],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Quotation status updated successfully',
            'data' => $quotation
        ]);
    }

    #[OA\Get(
        path: "/api/my/quotations",
        summary: "List authenticated customer's quotations",
        description: "Customer only.",
        tags: ["Customer Quotations"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "status", in: "query", description: "Filter by status", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "per_page", in: "query", description: "Number of items per page", required: false, schema: new OA\Schema(type: "integer", default: 10))
        ],
        responses: [
            new OA\Response(response: 200, description: "List of quotations"),
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
    public function myIndex(Request $request)
    {
        $query = Quotation::with('items')
            ->where('user_id', $request->user()->id);

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->input('status'));
        }

        $quotations = $query->latest()->paginate($request->input('per_page', 10));

        return response()->json([
            'status' => 'success',
            'data' => $quotations
        ]);
    }

    #[OA\Post(
        path: "/api/my/quotations",
        summary: "Create a quotation as the authenticated customer",
        description: "Customer only.",
        tags: ["Customer Quotations"],
        security: [["bearerAuth" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["date", "valid_until", "items"],
                properties: [
                    new OA\Property(property: "date", type: "string", format: "date", example: "2026-02-20"),
                    new OA\Property(property: "valid_until", type: "string", format: "date", example: "2026-03-20"),
                    new OA\Property(property: "status", type: "string", example: "Draft"),
                    new OA\Property(property: "terms_and_conditions", type: "string", example: "Payment due in 30 days."),
                    new OA\Property(property: "customer_note", type: "string", example: "Please include GST details."),
                    new OA\Property(property: "items", type: "array", items: new OA\Items(
                        properties: [
                            new OA\Property(property: "description", type: "string"),
                            new OA\Property(property: "quantity", type: "integer"),
                            new OA\Property(property: "unit", type: "string"),
                            new OA\Property(property: "unit_price", type: "number", format: "float")
                        ]
                    ))
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Quotation created"),
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
                        new OA\Property(property: "errors", type: "object", example: ["items" => ["The items field is required."]])
                    ]
                )
            )
        ]
    )]
    public function myStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'valid_until' => 'required|date|after_or_equal:date',
            'status' => 'nullable|in:' . implode(',', self::STATUSES),
            'terms_and_conditions' => 'nullable|string',
            'customer_note' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit' => 'nullable|string',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $year = date('Y');
        $count = Quotation::whereYear('created_at', $year)->withTrashed()->count() + 1;
        $quoteId = 'Q-' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);

        $totalAmount = $this->calculateTotal($request->items);

        $quotation = Quotation::create([
            'quote_id' => $quoteId,
            'user_id' => $request->user()->id,
            'date' => $request->date,
            'valid_until' => $request->valid_until,
            'status' => $request->status ?? 'Draft',
            'terms_and_conditions' => $request->terms_and_conditions,
            'customer_note' => $request->customer_note,
            'total_amount' => $totalAmount,
        ]);

        foreach ($request->items as $item) {
            $quotation->items()->create([
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'] ?? 'Pieces',
                'unit_price' => $item['unit_price'],
                'total' => $item['quantity'] * $item['unit_price'],
            ]);
        }

        AuditLogger::log(
            'Create',
            'Quotation',
            $quotation->id,
            'Customer created quotation ' . $quotation->quote_id,
            ['quote_id' => $quotation->quote_id],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Quotation created successfully',
            'data' => $quotation->load('items')
        ], 201);
    }

    #[OA\Get(
        path: "/api/my/quotations/{id}",
        summary: "Get authenticated customer's quotation",
        description: "Customer only.",
        tags: ["Customer Quotations"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Quotation details"),
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
                description: "Quotation not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Quotation not found")
                    ]
                )
            )
        ]
    )]
    public function myShow(Request $request, $id)
    {
        $quotation = Quotation::with('items')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$quotation) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $quotation
        ]);
    }

    #[OA\Post(
        path: "/api/my/quotations/{id}/approve",
        summary: "Approve a quotation",
        description: "Customer only.",
        tags: ["Customer Quotations"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Quotation approved"),
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
                description: "Quotation not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Quotation not found")
                    ]
                )
            )
        ]
    )]
    public function approve(Request $request, $id)
    {
        return $this->customerStatusChange($request, $id, 'Approved');
    }

    #[OA\Post(
        path: "/api/my/quotations/{id}/reject",
        summary: "Reject a quotation",
        description: "Customer only.",
        tags: ["Customer Quotations"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Quotation rejected"),
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
                description: "Quotation not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Quotation not found")
                    ]
                )
            )
        ]
    )]
    public function reject(Request $request, $id)
    {
        return $this->customerStatusChange($request, $id, 'Rejected');
    }

    #[OA\Post(
        path: "/api/my/quotations/{id}/request-changes",
        summary: "Request changes on a quotation",
        description: "Customer only.",
        tags: ["Customer Quotations"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "customer_note", type: "string", example: "Please revise payment terms.")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Change request submitted"),
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
                description: "Quotation not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Quotation not found")
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: "Validation error",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "status", type: "string", example: "error"),
                        new OA\Property(property: "errors", type: "object", example: ["customer_note" => ["The customer note must be a string."]])
                    ]
                )
            )
        ]
    )]
    public function requestChanges(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'customer_note' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        return $this->customerStatusChange($request, $id, 'ChangesRequested', $request->input('customer_note'));
    }

    #[OA\Delete(
        path: "/api/quotations/{id}",
        summary: "Soft delete the specified quotation",
        description: "Admin only.",
        tags: ["Quotation Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Quotation deleted"),
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
                description: "Quotation not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "status", type: "string", example: "error"),
                        new OA\Property(property: "message", type: "string", example: "Quotation not found")
                    ]
                )
            )
        ]
    )]
    public function destroy($id)
    {
        $quotation = Quotation::find($id);

        if (!$quotation) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }

        $quotation->delete();

        AuditLogger::log(
            'Delete',
            'Quotation',
            $quotation->id,
            'Deleted quotation ' . $quotation->quote_id,
            ['quote_id' => $quotation->quote_id],
            request()
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Quotation soft deleted successfully'
        ]);
    }

    private function calculateTotal(array $items): float
    {
        $total = 0;
        foreach ($items as $item) {
            $total += $item['quantity'] * $item['unit_price'];
        }

        return (float) $total;
    }

    private function customerStatusChange(Request $request, $id, string $status, ?string $note = null)
    {
        $quotation = Quotation::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$quotation) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }

        if (!in_array($status, self::STATUSES, true)) {
            return response()->json([
                'status' => 'error',
                'errors' => ['status' => ['The selected status is invalid.']]
            ], 422);
        }

        $update = ['status' => $status];
        if ($note !== null) {
            $update['customer_note'] = $note;
        }

        $quotation->update($update);

        AuditLogger::log(
            $this->statusToAction($status),
            'Quotation',
            $quotation->id,
            'Customer updated quotation status to ' . $status,
            ['quote_id' => $quotation->quote_id, 'status' => $status],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Quotation status updated successfully',
            'data' => $quotation
        ]);
    }

    private function statusToAction(string $status): string
    {
        return match ($status) {
            'Approved' => 'Approve',
            'Rejected' => 'Reject',
            'Sent' => 'Send',
            default => 'Update',
        };
    }
}
