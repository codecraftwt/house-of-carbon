<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class OrderController extends Controller
{
    private const STATUSES = ['draft', 'confirmed', 'in_transit', 'arrived', 'clearance', 'delivered', 'cancelled'];

    #[OA\Get(
        path: "/api/orders",
        summary: "Display a listing of orders",
        description: "Admin sees all orders. Customers see their own orders.",
        tags: ["Order Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "search", in: "query", description: "Search by order number, customer/supplier name, email, or company", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "status", in: "query", description: "Filter by status", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "per_page", in: "query", description: "Number of items per page", required: false, schema: new OA\Schema(type: "integer", default: 10))
        ],
        responses: [
            new OA\Response(response: 200, description: "List of orders"),
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
        $query = Order::with(['customer.companyDetail', 'supplier.companyDetail', 'quotation']);

        if (!$this->isAdmin($request)) {
            $query->where('customer_id', $request->user()->id);
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('order_no', 'LIKE', "%{$search}%")
                  ->orWhereHas('customer', function ($q2) use ($search) {
                      $q2->where('name', 'LIKE', "%{$search}%")
                         ->orWhere('email', 'LIKE', "%{$search}%")
                         ->orWhereHas('companyDetail', function ($q3) use ($search) {
                             $q3->where('company_name', 'LIKE', "%{$search}%");
                         });
                  })
                  ->orWhereHas('supplier', function ($q2) use ($search) {
                      $q2->where('name', 'LIKE', "%{$search}%")
                         ->orWhere('email', 'LIKE', "%{$search}%")
                         ->orWhereHas('companyDetail', function ($q3) use ($search) {
                             $q3->where('company_name', 'LIKE', "%{$search}%");
                         });
                  });
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->input('status'));
        }

        $orders = $query->latest()->paginate($request->input('per_page', 10));

        return response()->json([
            'status' => 'success',
            'data' => $orders
        ]);
    }

    #[OA\Post(
        path: "/api/orders",
        summary: "Create a new order",
        description: "Admin only.",
        tags: ["Order Management"],
        security: [["bearerAuth" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["customer_id"],
                properties: [
                    new OA\Property(property: "customer_id", type: "integer", example: 2),
                    new OA\Property(property: "supplier_id", type: "integer", example: 3),
                    new OA\Property(property: "quotation_id", type: "integer", example: 12),
                    new OA\Property(property: "status", type: "string", example: "draft"),
                    new OA\Property(property: "origin_country", type: "string", example: "India"),
                    new OA\Property(property: "destination_port", type: "string", example: "Los Angeles"),
                    new OA\Property(property: "invoice_value", type: "number", format: "float", example: 25000.5),
                    new OA\Property(property: "currency", type: "string", example: "USD"),
                    new OA\Property(property: "expected_arrival_date", type: "string", format: "date", example: "2026-03-15"),
                    new OA\Property(property: "notes", type: "string", example: "Priority delivery requested.")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Order created"),
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
                        new OA\Property(property: "errors", type: "object", example: ["customer_id" => ["The customer id field is required."]])
                    ]
                )
            )
        ]
    )]
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:users,id',
            'supplier_id' => 'nullable|exists:users,id',
            'quotation_id' => 'nullable|exists:quotations,id',
            'status' => 'nullable|in:' . implode(',', self::STATUSES),
            'origin_country' => 'nullable|string|max:80',
            'destination_port' => 'nullable|string|max:120',
            'invoice_value' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'expected_arrival_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $year = date('Y');
        $count = Order::whereYear('created_at', $year)->count() + 1;
        $orderNo = 'O-' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
        $status = $request->status ?? 'draft';

        $order = Order::create([
            'order_no' => $orderNo,
            'customer_id' => $request->customer_id,
            'supplier_id' => $request->supplier_id,
            'quotation_id' => $request->quotation_id,
            'status' => $status,
            'origin_country' => $request->origin_country,
            'destination_port' => $request->destination_port,
            'invoice_value' => $request->invoice_value,
            'currency' => $request->input('currency', 'USD'),
            'expected_arrival_date' => $request->expected_arrival_date,
            'notes' => $request->notes,
            'status_timeline' => [
                $this->buildTimelineEntry($status, 'Order created', $request)
            ],
        ]);

        AuditLogger::log(
            'Create',
            'Order',
            $order->id,
            'Created order ' . $order->order_no,
            ['order_no' => $order->order_no, 'status' => $status],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Order created successfully',
            'data' => $order->load(['customer.companyDetail', 'supplier.companyDetail', 'quotation'])
        ], 201);
    }

    #[OA\Get(
        path: "/api/orders/{id}",
        summary: "Display the specified order",
        description: "Admin can view any order. Customers can view their own.",
        tags: ["Order Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Order details"),
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
                description: "Order not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Order not found")
                    ]
                )
            )
        ]
    )]
    public function show(Request $request, $id)
    {
        $order = Order::with(['customer.companyDetail', 'supplier.companyDetail', 'quotation'])->find($id);

        if (!$order) {
            return response()->json(['status' => 'error', 'message' => 'Order not found'], 404);
        }

        if (!$this->canAccessOrder($request, $order)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => $order
        ]);
    }

    #[OA\Patch(
        path: "/api/orders/{id}/status",
        summary: "Update order status",
        description: "Admin only.",
        tags: ["Order Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["status"],
                properties: [
                    new OA\Property(property: "status", type: "string", example: "in_transit"),
                    new OA\Property(property: "note", type: "string", example: "Order is now in production.")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Order status updated"),
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
                description: "Order not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Order not found")
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
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:' . implode(',', self::STATUSES),
            'note' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::find($id);

        if (!$order) {
            return response()->json(['status' => 'error', 'message' => 'Order not found'], 404);
        }

        $order->status = $request->status;
        $this->appendTimeline($order, $request->status, $request->input('note'), $request);
        $order->save();

        AuditLogger::log(
            'Update',
            'Order',
            $order->id,
            'Updated order status to ' . $request->status,
            ['order_no' => $order->order_no, 'status' => $request->status],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Order status updated successfully',
            'data' => $order
        ]);
    }

    #[OA\Get(
        path: "/api/orders/{id}/timeline",
        summary: "Get order status timeline",
        description: "Admin can view any order. Customers can view their own.",
        tags: ["Order Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Order timeline"),
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
                description: "Order not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Order not found")
                    ]
                )
            )
        ]
    )]
    public function timeline(Request $request, $id)
    {
        $order = Order::find($id);

        if (!$order) {
            return response()->json(['status' => 'error', 'message' => 'Order not found'], 404);
        }

        if (!$this->canAccessOrder($request, $order)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'status' => $order->status,
                'timeline' => $order->status_timeline ?? [],
            ]
        ]);
    }

    private function isAdmin(Request $request): bool
    {
        $role = $request->user()?->role?->name ?? '';
        return $this->normalizeRole($role) === 'admin';
    }

    private function normalizeRole(string $role): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($role)));
    }

    private function canAccessOrder(Request $request, Order $order): bool
    {
        if ($this->isAdmin($request)) {
            return true;
        }

        return $order->customer_id === $request->user()->id;
    }

    private function buildTimelineEntry(string $status, ?string $note, Request $request): array
    {
        return [
            'status' => $status,
            'note' => $note,
            'changed_at' => now()->toDateTimeString(),
            'changed_by' => $request->user()?->id,
        ];
    }

    private function appendTimeline(Order $order, string $status, ?string $note, Request $request): void
    {
        $timeline = $order->status_timeline ?? [];
        $timeline[] = $this->buildTimelineEntry($status, $note, $request);
        $order->status_timeline = $timeline;
    }
}
