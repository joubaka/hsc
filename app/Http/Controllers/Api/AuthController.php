<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $user = \App\Models\User::where('email', $validated['email'])->first();

        if (
            ! $user
            || ! $user->active
            || $user->role === 'member'
            || ! Hash::check($validated['password'], $user->password)
        ) {
            return response()->json(['message' => 'The supplied credentials are invalid.'], 422);
        }

        $token = Str::random(80);
        $user->update(['api_token_hash' => hash('sha256', $token), 'last_login_at' => now()]);

        return response()->json(['token' => $token, 'user' => $this->userPayload($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->update(['api_token_hash' => null]);

        return response()->json(['message' => 'Signed out.']);
    }

    private function userPayload($user): array
    {
        $user->load('sportCodes:id,name,slug,colour');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'sport_codes' => $user->sportCodes,
            'last_login_at' => $user->last_login_at,
        ];
    }
}
