<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendVerificationEmailRequest;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $data = $request->validated();

        $user = User::create($data);
        event(new Registered($user));

        return response()->json([
            'message' => 'User created successfully.',
            'user' => new UserResource($user)
        ], 201);
    }
    public function login(LoginRequest $request)
    {
        $data = $request->validated();

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.'
            ], 401);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email not verified.'
            ], 403);
        }

        $token = $user->createToken('auth-token');

        return response()->json([
            'message' => 'User logged in successfully.',
            'user' => new UserResource($user),
            'token' => $token->plainTextToken
        ], 200);
    }
    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();

        $token->delete();

        return response()->json([
            'message' => 'User logged out successfully.',
        ], 200);
    }
    public function me(Request $request)
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ], 200);
    }
    public function verifyEmail(VerifyEmailRequest $request)
    {
        if (! $request->fulfill()) {
            return response()->json([
                'message' => 'Email already verified.'
            ], 409);
        }

        return response()->json([
            'message' => 'Email verified successfully.'
        ], 200);
    }
    public function sendVerificationEmail(SendVerificationEmailRequest $request)
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Verification link sent successfully.'
            ], 200);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Verification link sent successfully.'
        ], 200);
    }
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $status = Password::sendResetLink(
            $request->only('email')
        );

        if (in_array($status, [
            Password::ResetLinkSent,
            Password::InvalidUser,
            Password::ResetThrottled,
        ], true)) {
            return response()->json([
                'message' => 'If the email is registered and eligible, reset instructions will be sent.',
            ], 200);
        }

        return response()->json([
            'message' => __($status),
        ], 422);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->validated(),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                ]);
                $user->save();
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if (in_array($status, [Password::InvalidUser, Password::InvalidToken], true)) {
            return response()->json([
                'message' => 'The password reset link is invalid or expired.',
            ], 422);
        }

        if ($status === Password::PasswordReset) {
            return response()->json([
                'message' => __($status),
            ]);
        }

        return response()->json([
            'message' => __($status),
        ], 422);
    }
}
