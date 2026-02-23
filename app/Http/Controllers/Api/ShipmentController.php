<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class ShipmentController extends Controller
{
    private const STATUSES = ['Departed', 'In Transit', 'Arrived at Port', 'Customs Clearance', 'Delivered'];

    #[OA\Get(
        path: "/api/shipments",
        summary: "Display a listing of shipments",
        description: "CHA and Customer only. CHA sees all shipments; Customers see their own shipments.",
        tags: ["Shipment Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "search", in: "query", description: "Search by shipment number, tracking number, carrier, origin/destination, or customer info", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "status", in: "query", description: "Filter by status", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "per_page", in: "query", description: "Number of items per page", required: false, schema: new OA\Schema(type: "integer", default: 10))
        ],
        responses: [
            new OA\Response(response: 200, description: "List of shipments"),
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
        $baseQuery = Shipment::query();

        if (!$this->isCha($request)) {
            $baseQuery->where(function ($q) use ($request) {
                $q->where('customer_id', $request->user()->id)
                  ->orWhereHas('order', function ($q2) use ($request) {
                      $q2->where('customer_id', $request->user()->id);
                  });
            });
        }

        $stats = $this->buildStats(clone $baseQuery);

        $query = $baseQuery->with(['customer.companyDetail', 'order.customer.companyDetail']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('shipment_no', 'LIKE', "%{$search}%")
                  ->orWhere('tracking_no', 'LIKE', "%{$search}%")
                  ->orWhere('carrier_name', 'LIKE', "%{$search}%")
                  ->orWhere('origin', 'LIKE', "%{$search}%")
                  ->orWhere('destination', 'LIKE', "%{$search}%")
                  ->orWhereHas('customer', function ($q2) use ($search) {
                      $q2->where('name', 'LIKE', "%{$search}%")
                         ->orWhere('email', 'LIKE', "%{$search}%")
                         ->orWhereHas('companyDetail', function ($q3) use ($search) {
                             $q3->where('company_name', 'LIKE', "%{$search}%");
                         });
                  })
                  ->orWhereHas('order', function ($q2) use ($search) {
                      $q2->where('order_no', 'LIKE', "%{$search}%")
                         ->orWhereHas('customer', function ($q3) use ($search) {
                             $q3->where('name', 'LIKE', "%{$search}%")
                                ->orWhere('email', 'LIKE', "%{$search}%")
                                ->orWhereHas('companyDetail', function ($q4) use ($search) {
                                    $q4->where('company_name', 'LIKE', "%{$search}%");
                                });
                         });
                  });
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->input('status'));
        }

        $shipments = $query->latest()->paginate($request->input('per_page', 10));

        return response()->json([
            'status' => 'success',
            'data' => $shipments,
            'stats' => $stats
        ]);
    }

    #[OA\Get(
        path: "/api/shipments/{id}",
        summary: "Display the specified shipment",
        description: "CHA and Customer only. CHA can view any shipment; Customers can view shipments for their orders.",
        tags: ["Shipment Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Shipment details"),
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
                description: "Shipment not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Shipment not found")
                    ]
                )
            )
        ]
    )]
    public function show(Request $request, $id)
    {
        $shipment = Shipment::with(['customer.companyDetail', 'order.customer.companyDetail', 'documents'])->find($id);

        if (!$shipment) {
            return response()->json(['status' => 'error', 'message' => 'Shipment not found'], 404);
        }

        if (!$this->canAccessShipment($request, $shipment)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => $shipment
        ]);
    }

    #[OA\Patch(
        path: "/api/shipments/{id}/status",
        summary: "Update shipment status",
        description: "CHA and Customer only.",
        tags: ["Shipment Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["status"],
                properties: [
                    new OA\Property(property: "status", type: "string", example: "In Transit")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Shipment status updated"),
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
                description: "Shipment not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Shipment not found")
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $shipment = Shipment::find($id);

        if (!$shipment) {
            return response()->json(['status' => 'error', 'message' => 'Shipment not found'], 404);
        }

        if (!$this->canAccessShipment($request, $shipment)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $shipment->status = $request->status;
        $shipment->save();

        AuditLogger::log(
            'Update',
            'Shipment',
            $shipment->id,
            'Updated shipment status to ' . $request->status,
            ['shipment_no' => $shipment->shipment_no, 'status' => $request->status],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Shipment status updated successfully',
            'data' => $shipment
        ]);
    }

    #[OA\Post(
        path: "/api/shipments/{id}/documents",
        summary: "Upload shipment documents",
        description: "CHA and Customer only.",
        tags: ["Shipment Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: "multipart/form-data",
                schema: new OA\Schema(
                    required: ["documents"],
                    properties: [
                        new OA\Property(
                            property: "documents",
                            type: "array",
                            items: new OA\Items(type: "string", format: "binary")
                        )
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Documents uploaded"),
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
                description: "Shipment not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Shipment not found")
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: "Validation error",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "status", type: "string", example: "error"),
                        new OA\Property(property: "errors", type: "object", example: ["documents" => ["The documents field is required."]])
                    ]
                )
            )
        ]
    )]
    public function uploadDocuments(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'documents' => 'required|array|min:1',
            'documents.*' => 'file|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $shipment = Shipment::find($id);

        if (!$shipment) {
            return response()->json(['status' => 'error', 'message' => 'Shipment not found'], 404);
        }

        if (!$this->canAccessShipment($request, $shipment)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $created = [];
        foreach ($request->file('documents', []) as $file) {
            $path = $file->store('shipments/' . $shipment->shipment_no, 'public');

            $document = ShipmentDocument::create([
                'shipment_id' => $shipment->id,
                'uploaded_by' => $request->user()?->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
            ]);

            $created[] = [
                'id' => $document->id,
                'file_name' => $document->file_name,
                'file_url' => Storage::disk('public')->url($document->file_path),
                'mime_type' => $document->mime_type,
                'file_size' => $document->file_size,
            ];
        }

        AuditLogger::log(
            'Update',
            'Shipment',
            $shipment->id,
            'Uploaded shipment documents',
            ['shipment_no' => $shipment->shipment_no, 'files' => array_column($created, 'file_name')],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Documents uploaded successfully',
            'data' => $created
        ], 201);
    }

    private function isCha(Request $request): bool
    {
        $role = $request->user()?->role?->name ?? '';
        return $this->normalizeRole($role) === 'cha';
    }

    private function normalizeRole(string $role): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($role)));
    }

    private function canAccessShipment(Request $request, Shipment $shipment): bool
    {
        if ($this->isCha($request)) {
            return true;
        }

        return (int) $shipment->customer_id === (int) $request->user()->id
            || (int) $shipment->order?->customer_id === (int) $request->user()->id;
    }

    private function buildStats($query): array
    {
        $counts = $query->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $defaults = [];
        foreach (self::STATUSES as $status) {
            $defaults[$status] = 0;
        }

        foreach ($counts as $status => $total) {
            $defaults[$status] = (int) $total;
        }

        return $defaults;
    }
}
