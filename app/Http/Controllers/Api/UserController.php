<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    #[OA\Get(
        path: "/api/users",
        summary: "Display a listing of the users with their roles",
        tags: ["User Role Management"],
        security: [["bearerAuth" => []]],
        responses: [
            new OA\Response(response: 200, description: "List of users"),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 403, description: "Unauthorized")
        ]
    )]
    public function index()
    {
        $users = User::with('role')->get();
        return response()->json([
            'status' => 'success',
            'data' => $users
        ]);
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
        tags: ["User Role Management"],
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
            'data' => $user->load('role')
        ]);
    }
}
