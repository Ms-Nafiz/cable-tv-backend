<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with(['roles', 'area'])->get()->map(function ($u) {
            return [
                'id'       => $u->id,
                'name'     => $u->name,
                'phone'    => $u->phone,
                'email'    => $u->email,
                'role'     => $u->roles->first()?->name,
                'area_id'  => $u->area_id,
                'area'     => $u->area,
                'status'   => $u->status,
                'created_at' => $u->created_at,
            ];
        });

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'phone'    => 'required|string|unique:users,phone',
            'email'    => 'nullable|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role'     => 'required|string|in:super_admin,accounts,collector',
            'area_id'  => 'nullable|exists:areas,id',
            'status'   => 'required|in:active,inactive',
        ]);

        $user = User::create([
            'name'     => $request->name,
            'phone'    => $request->phone,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'area_id'  => $request->area_id,
            'status'   => $request->status,
        ]);

        $user->assignRole($request->role);

        return response()->json($user->load(['roles', 'area']), 201);
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'phone'    => 'required|string|unique:users,phone,' . $user->id,
            'email'    => 'nullable|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:6',
            'role'     => 'required|string|in:super_admin,accounts,collector',
            'area_id'  => 'nullable|exists:areas,id',
            'status'   => 'required|in:active,inactive',
        ]);

        $data = [
            'name'    => $request->name,
            'phone'   => $request->phone,
            'email'   => $request->email,
            'area_id' => $request->area_id,
            'status'  => $request->status,
        ];

        if (!empty($request->password)) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        $user->syncRoles([$request->role]);

        return response()->json($user->load(['roles', 'area']));
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Cannot delete currently logged in user'], 422);
        }

        $user->delete();
        return response()->json(['message' => 'User deleted successfully']);
    }
}
