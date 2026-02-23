<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\CompanyDetail;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    #[OA\Get(
        path: "/api/users",
        summary: "Display a listing of the users with search and filters",
        description: "Admin only.",
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
            $roleName = $this->normalizeRoleName($request->input('role'));
            $query->whereHas('role', function ($q) use ($roleName) {
                $q->whereRaw('LOWER(name) = ?', [$roleName]);
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
        description: "Admin only.",
        tags: ["User Management"],
        security: [["bearerAuth" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name", "email", "password", "role"],
                properties: [
                    new OA\Property(property: "name", type: "string", example: "John Doe"),
                    new OA\Property(property: "email", type: "string", example: "john@example.com"),
                    new OA\Property(property: "password", type: "string", example: "password123"),
                    new OA\Property(property: "role", type: "string", example: "admin", description: "Role name (admin, customer, supplier, cha, back_office)"),
                    new OA\Property(property: "role_name", type: "string", example: "customer", description: "Alias for role"),
                    new OA\Property(property: "status", type: "string", example: "active"),
                    new OA\Property(property: "company_name", type: "string", example: "Tech Corp")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "User created"),
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
                        new OA\Property(property: "errors", type: "object", example: ["email" => ["The email has already been taken."]])
                    ]
                )
            )
        ]
    )]
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required_without:role_name|string',
            'role_name' => 'required_without:role|string',
            'status' => 'nullable|string|in:active,inactive',
            'company_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $roleId = $this->resolveRoleId($request);
        if (!$roleId) {
            return response()->json([
                'status' => 'error',
                'errors' => ['role' => ['The selected role is invalid.']]
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role_id' => $roleId,
            'status' => $request->status ?? 'active',
        ]);

        if ($request->filled('company_name')) {
            $user->companyDetail()->create($request->only([
                'company_name',
            ]));
        }

        AuditLogger::log(
            'Create',
            'User',
            $user->id,
            'Created user ' . $user->email,
            ['email' => $user->email],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'User created successfully',
            'data' => $user->load(['role', 'companyDetail'])
        ], 201);
    }

    #[OA\Put(
        path: "/api/users/{user}/role",
        summary: "Update the user's role",
        description: "Admin only.",
        tags: ["User Role Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "user", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["role"],
                properties: [
                    new OA\Property(property: "role", type: "string", example: "admin", description: "Role name (admin, customer, supplier, cha, back_office)"),
                    new OA\Property(property: "role_name", type: "string", example: "customer", description: "Alias for role")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "User role updated"),
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
                        new OA\Property(property: "errors", type: "object", example: ["role_id" => ["The selected role id is invalid."]])
                    ]
                )
            )
        ]
    )]
    public function updateRole(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'role' => 'required_without:role_name|string',
            'role_name' => 'required_without:role|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $roleId = $this->resolveRoleId($request);
        if (!$roleId) {
            return response()->json([
                'status' => 'error',
                'errors' => ['role' => ['The selected role is invalid.']]
            ], 422);
        }

        $user->update([
            'role_id' => $roleId,
        ]);

        AuditLogger::log(
            'Update',
            'User',
            $user->id,
            'Updated user role for ' . $user->email,
            ['role_id' => $request->role_id],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'User role updated successfully',
            'data' => $user->load('role')
        ]);
    }

    #[OA\Get(
        path: "/api/users/{user}",
        summary: "Display the specified user",
        description: "Admin only.",
        tags: ["User Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "user", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "User details"),
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
                description: "User not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "User not found")
                    ]
                )
            )
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
        description: "Admin only.",
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
                    new OA\Property(property: "role", type: "string", description: "Role name (admin, customer, supplier, cha, back_office)"),
                    new OA\Property(property: "role_name", type: "string", description: "Alias for role"),
                    new OA\Property(property: "status", type: "string"),
                    new OA\Property(property: "company_name", type: "string")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "User updated"),
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
                        new OA\Property(property: "errors", type: "object", example: ["email" => ["The email has already been taken."]])
                    ]
                )
            )
        ]
    )]
    public function update(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
            'role' => 'sometimes|string',
            'role_name' => 'sometimes|string',
            'status' => 'sometimes|nullable|string|in:active,inactive',
            'company_name' => 'sometimes|nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $updates = $request->only(['name', 'email', 'status']);

        if ($request->filled('role') || $request->filled('role_name')) {
            $roleId = $this->resolveRoleId($request);
            if (!$roleId) {
                return response()->json([
                    'status' => 'error',
                    'errors' => ['role' => ['The selected role is invalid.']]
                ], 422);
            }
            $updates['role_id'] = $roleId;
        }

        $user->update($updates);

        if ($request->has('company_name')) {
            $user->companyDetail()->updateOrCreate(
                ['user_id' => $user->id],
                $request->only([
                    'company_name',
                ])
            );
        }

        AuditLogger::log(
            'Update',
            'User',
            $user->id,
            'Updated user ' . $user->email,
            ['email' => $user->email],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'User updated successfully',
            'data' => $user->load(['role', 'companyDetail'])
        ]);
    }

    private function resolveRoleId(Request $request): ?int
    {
        $roleName = $request->input('role') ?? $request->input('role_name');
        if (!$roleName) {
            return null;
        }

        $normalized = $this->normalizeRoleName($roleName);
        $role = Role::whereRaw('LOWER(name) = ?', [$normalized])->first();

        return $role?->id;
    }

    private function normalizeRoleName(string $roleName): string
    {
        return strtolower(str_replace(['_', '-'], ' ', trim($roleName)));
    }

    #[OA\Delete(
        path: "/api/users/{user}",
        summary: "Delete the specified user",
        description: "Admin only.",
        tags: ["User Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "user", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "User deleted"),
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
                description: "User not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "User not found")
                    ]
                )
            )
        ]
    )]
    public function destroy(User $user)
    {
        // Using soft deletes for both user and company details
        if ($user->companyDetail) {
            $user->companyDetail()->delete();
        }
        $user->delete();

        AuditLogger::log(
            'Delete',
            'User',
            $user->id,
            'Deleted user ' . $user->email,
            ['email' => $user->email],
            request()
        );

        return response()->json([
            'status' => 'success',
            'message' => 'User soft deleted successfully'
        ]);
    }
}
