<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\RegisterRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\EventStream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController
{
    /**
     * Register a new user, issue a Sanctum token, and log the event.
     */
    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']), // Argon2id auto-used
            'auth_token_count' => 5.0, // default capacity
            'api_token_count' => 60.0, // default capacity
        ]);

        // Issue token
        $token = $user->createToken('apollo_auth_token')->plainTextToken;

        // L1 Event Stream Append: Register / Initial Login event
        EventStream::create([
            'user_id' => $user->id,
            'event_type' => 'login',
            'event_ts' => now(),
            'event_value' => 1.0,
            'metadata' => ['method' => 'registration'],
        ]);

        return response()->json([
            'message' => 'User registered successfully.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ], 201); // 201 Created
    }

    /**
     * Authenticate user, issue a Sanctum token, and log the event.
     */
    public function login(LoginRequest $request)
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])->first();

        // Timing-safe verification: check password hash even if the user does not exist
        // to prevent email enumeration timing analysis attacks.
        $passwordValid = $user ? Hash::check($validated['password'], $user->password) : false;

        if (!$user) {
            // Run dummy hashing attempt to consume identical execution cycles
            Hash::check($validated['password'], '$argon2id$v=19$m=65536,t=3,p=2$ZHVtbXlfc2FsdF9zdHJpbmc$dummyhashvalue');
        }

        if (!$user || !$passwordValid) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        // Issue token
        $token = $user->createToken('apollo_auth_token')->plainTextToken;

        // L1 Event Stream Append: Login event
        EventStream::create([
            'user_id' => $user->id,
            'event_type' => 'login',
            'event_ts' => now(),
            'event_value' => 1.0,
            'metadata' => ['ip' => $request->ip()],
        ]);

        return response()->json([
            'message' => 'Login successful.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ], 200);
    }

    /**
     * Log out the user, revoke Sanctum token, and log the event.
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        // L1 Event Stream Append: Logout event
        EventStream::create([
            'user_id' => $user->id,
            'event_type' => 'logout',
            'event_ts' => now(),
            'event_value' => 1.0,
            'metadata' => ['token_id' => $user->currentAccessToken()->id],
        ]);

        // Revoke token
        $user->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.'
        ], 200);
    }

    /**
     * Retrieve authenticated user information.
     */
    public function me(Request $request)
    {
        return response()->json([
            'user' => new UserResource($request->user())
        ], 200);
    }
}
