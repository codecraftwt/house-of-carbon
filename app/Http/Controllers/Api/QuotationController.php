<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\QuotationItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class QuotationController extends Controller
{
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
            'status' => 'nullable|in:Draft,Sent,Pending,Approved,Rejected',
            'terms_and_conditions' => 'nullable|string',
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

        return response()->json([
            'status' => 'success',
            'message' => 'Quotation soft deleted successfully'
        ]);
    }
}
