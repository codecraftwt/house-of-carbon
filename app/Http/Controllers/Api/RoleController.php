<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class RoleController extends Controller
{
    #[OA\Get(
        path: "/api/roles",
        summary: "Display a listing of the roles",
        tags: ["Role Management"],
        security: [["bearerAuth" => []]],
        responses: [
            new OA\Response(response: 200, description: "List of roles"),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 403, description: "Unauthorized")
        ]
    )]
    public function index()
    {
        $roles = Role::all();
        return response()->json([
            'status' => 'success',
            'data' => $roles
        ]);
    }

    #[OA\Post(
        path: "/api/roles",
        summary: "Store a newly created role",
        tags: ["Role Management"],
        security: [["bearerAuth" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name"],
                properties: [
                    new OA\Property(property: "name", type: "string", example: "Manager")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Role created"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $role = Role::create([
            'name' => $request->name,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Role created successfully',
            'data' => $role
        ], 201);
    }

    #[OA\Get(
        path: "/api/roles/{role}",
        summary: "Display the specified role",
        tags: ["Role Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "role", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Role details"),
            new OA\Response(response: 404, description: "Role not found")
        ]
    )]
    public function show(Role $role)
    {
        return response()->json([
            'status' => 'success',
            'data' => $role
        ]);
    }

    #[OA\Put(
        path: "/api/roles/{role}",
        summary: "Update the specified role",
        tags: ["Role Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "role", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name"],
                properties: [
                    new OA\Property(property: "name", type: "string", example: "Supervisor")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Role updated"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function update(Request $request, Role $role)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $role->update([
            'name' => $request->name,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Role updated successfully',
            'data' => $role
        ]);
    }

    #[OA\Delete(
        path: "/api/roles/{role}",
        summary: "Remove the specified role",
        tags: ["Role Management"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "role", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Role deleted"),
            new OA\Response(response: 400, description: "Cannot delete role assigned to users"),
            new OA\Response(response: 404, description: "Role not found")
        ]
    )]
    public function destroy(Role $role)
    {
        // Check if role is assigned to any user
        if ($role->users()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete role assigned to users'
            ], 400);
        }

        $role->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Role deleted successfully'
        ]);
    }
}
