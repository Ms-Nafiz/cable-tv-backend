<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'phone'    => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['The provided credentials do not match our records.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'phone' => ['Your account is inactive. Please contact system administrator.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        $role = $user->roles->first()?->name;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => [
                'id'       => $user->id,
                'name'     => $user->name,
                'phone'    => $user->phone,
                'email'    => $user->email,
                'role'     => $role,
                'area_id'  => $user->area_id,
                'area'     => $user->area,
            ],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $user->load('area');
        $role = $user->roles->first()?->name;

        return response()->json([
            'id'      => $user->id,
            'name'    => $user->name,
            'phone'   => $user->phone,
            'email'   => $user->email,
            'role'    => $role,
            'area_id' => $user->area_id,
            'area'    => $user->area,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }
}
