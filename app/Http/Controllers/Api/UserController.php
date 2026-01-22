<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\CompanyDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    #[OA\Get(
        path: "/api/users",
        summary: "Display a listing of the users with search and filters",
        tags: ["User Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "search", in: "query", description: "Search by name, email, or company", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "role", in: "query", description: "Filter by role name (Admin, Customer, etc.)", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "status", in: "query", description: "Filter by status (active, inactive)", required: false, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "per_page", in: "query", description: "Number of items per page", required: false, schema: new OA\Schema(type: "integer", default: 10))
        ],
        responses: [
            new OA\Response(response: 200, description: "List of users with stats"),
            new OA\Response(response: 401, description: "Unauthenticated")
        ]
    )]
    public function index(Request $request)
    {
        $query = User::with(['role', 'companyDetail']);

        // Search
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%")
                  ->orWhereHas('companyDetail', function ($q) use ($search) {
                      $q->where('company_name', 'LIKE', "%{$search}%");
                  });
            });
        }

        // Filter by Role Name
        if ($request->filled('role') && $request->role != 'all') {
            $roleName = $request->input('role');
            $query->whereHas('role', function ($q) use ($roleName) {
                $q->where('name', $roleName);
            });
        }

        // Filter by Status
        if ($request->filled('status') && $request->status != 'all') {
            $query->where('status', $request->input('status'));
        }

        $users = $query->latest()->paginate($request->input('per_page', 10));

        // Get counts for dashboard cards
        $stats = [
            'total' => User::count(),
            'admin' => User::whereHas('role', function($q){ $q->where('name', 'Admin'); })->count(),
            'customer' => User::whereHas('role', function($q){ $q->where('name', 'Customer'); })->count(),
            'supplier' => User::whereHas('role', function($q){ $q->where('name', 'Supplier'); })->count(),
            'cha' => User::whereHas('role', function($q){ $q->where('name', 'CHA'); })->count(),
            'back_office' => User::whereHas('role', function($q){ $q->where('name', 'Back Office'); })->count(),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $users,
            'stats' => $stats
        ]);
    }

    #[OA\Post(
        path: "/api/users",
        summary: "Create a new user with company details",
        tags: ["User Management"],
        security: [["bearerAuth" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name", "email", "password", "role_id"],
                properties: [
                    new OA\Property(property: "name", type: "string", example: "John Doe"),
                    new OA\Property(property: "email", type: "string", example: "john@example.com"),
                    new OA\Property(property: "password", type: "string", example: "password123"),
                    new OA\Property(property: "role_id", type: "integer", example: 2),
                    new OA\Property(property: "status", type: "string", example: "active"),
                    new OA\Property(property: "company_name", type: "string", example: "Tech Corp"),
                    new OA\Property(property: "company_phone", type: "string", example: "1234567890"),
                    new OA\Property(property: "company_address", type: "string", example: "123 Main St")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "User created"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role_id' => 'required|exists:roles,id',
            'status' => 'nullable|string|in:active,inactive',
            'company_name' => 'nullable|string|max:255',
            'company_email' => 'nullable|email|max:255',
            'company_phone' => 'nullable|string|max:20',
            'company_address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'zip_code' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role_id' => $request->role_id,
            'status' => $request->status ?? 'active',
        ]);

        if ($request->filled('company_name')) {
            $user->companyDetail()->create($request->only([
                'company_name',
                'company_email',
                'company_phone',
                'company_address',
                'city',
                'state',
                'country',
                'zip_code',
            ]));
        }

        return response()->json([
            'status' => 'success',
            'message' => 'User created successfully',
            'data' => $user->load(['role', 'companyDetail'])
        ], 201);
    }

    #[OA\Put(
        path: "/api/users/{user}/role",
        summary: "Update the user's role",
        tags: ["User Role Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "user", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["role_id"],
                properties: [
                    new OA\Property(property: "role_id", type: "integer", example: 2)
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "User role updated"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function updateRole(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'role_id' => 'required|exists:roles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user->update([
            'role_id' => $request->role_id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'User role updated successfully',
            'data' => $user->load('role')
        ]);
    }

    #[OA\Get(
        path: "/api/users/{user}",
        summary: "Display the specified user",
        tags: ["User Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "user", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "User details"),
            new OA\Response(response: 404, description: "User not found")
        ]
    )]
    public function show(User $user)
    {
        return response()->json([
            'status' => 'success',
            'data' => $user->load(['role', 'companyDetail'])
        ]);
    }

    #[OA\Put(
        path: "/api/users/{user}",
        summary: "Update the specified user and company details",
        tags: ["User Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "user", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "email", type: "string"),
                    new OA\Property(property: "role_id", type: "integer"),
                    new OA\Property(property: "status", type: "string"),
                    new OA\Property(property: "company_name", type: "string")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "User updated"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function update(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
            'role_id' => 'sometimes|required|exists:roles,id',
            'status' => 'sometimes|nullable|string|in:active,inactive',
            'company_name' => 'sometimes|nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user->update($request->only(['name', 'email', 'role_id', 'status']));

        if ($request->has('company_name')) {
            $user->companyDetail()->updateOrCreate(
                ['user_id' => $user->id],
                $request->only([
                    'company_name',
                    'company_email',
                    'company_phone',
                    'company_address',
                    'city',
                    'state',
                    'country',
                    'zip_code',
                ])
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'User updated successfully',
            'data' => $user->load(['role', 'companyDetail'])
        ]);
    }

    #[OA\Delete(
        path: "/api/users/{user}",
        summary: "Delete the specified user",
        tags: ["User Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "user", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "User deleted"),
            new OA\Response(response: 404, description: "User not found")
        ]
    )]
    public function destroy(User $user)
    {
        // Using soft deletes for both user and company details
        if ($user->companyDetail) {
            $user->companyDetail()->delete();
        }
        $user->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'User soft deleted successfully'
        ]);
    }
}
