<?php

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\postJson;

test('returns 200 and updates the password when the reset token is valid', function () {
    $user = User::factory()->create([
        'password' => 'Old-password-123!',
    ]);
    $user->createToken('first-device');
    $user->createToken('second-device');

    $token = Password::broker()->createToken($user);
    $newPassword = 'New-password-456!';

    Event::fake([PasswordReset::class]);

    $response = postJson('/api/v1/reset-password', [
        'email' => $user->email,
        'token' => $token,
        'password' => $newPassword,
        'password_confirmation' => $newPassword,
    ]);

    $response->assertOk();
    $response->assertExactJson([
        'message' => 'Your password has been reset.',
    ]);

    $user->refresh();
    expect(Hash::check($newPassword, $user->password))->toBeTrue();

    assertDatabaseMissing('password_reset_tokens', [
        'email' => $user->email,
    ]);
    assertDatabaseMissing('personal_access_tokens', [
        'tokenable_type' => User::class,
        'tokenable_id' => $user->id,
    ]);

    Event::assertDispatched(PasswordReset::class, function (PasswordReset $event) use ($user): bool {
        return $event->user->is($user);
    });
});

test('returns 422 and leaves the password unchanged when the reset token is invalid', function () {
    $user = User::factory()->create([
        'password' => 'Old-password-123!',
    ]);
    $token = Password::broker()->createToken($user);

    Event::fake([PasswordReset::class]);

    $response = postJson('/api/v1/reset-password', [
        'email' => $user->email,
        'token' => 'invalid-reset-token',
        'password' => 'New-password-456!',
        'password_confirmation' => 'New-password-456!',
    ]);

    $response->assertUnprocessable();
    $response->assertExactJson([
        'message' => 'The password reset link is invalid or expired.',
    ]);

    expect(Hash::check('Old-password-123!', $user->fresh()->password))->toBeTrue();

    assertDatabaseHas('password_reset_tokens', [
        'email' => $user->email,
    ]);

    Event::assertNotDispatched(PasswordReset::class);
});

test('returns 422 when the reset token has expired', function () {
    $user = User::factory()->create([
        'password' => 'Old-password-123!',
    ]);
    $token = Password::broker()->createToken($user);

    $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

    $response = postJson('/api/v1/reset-password', [
        'email' => $user->email,
        'token' => $token,
        'password' => 'New-password-456!',
        'password_confirmation' => 'New-password-456!',
    ]);

    $response->assertUnprocessable();
    $response->assertExactJson([
        'message' => 'The password reset link is invalid or expired.',
    ]);

    expect(Hash::check('Old-password-123!', $user->fresh()->password))->toBeTrue();
});

test('returns 422 when the email is not registered', function () {
    $response = postJson('/api/v1/reset-password', [
        'email' => 'unregistered@example.test',
        'token' => 'some-reset-token',
        'password' => 'New-password-456!',
        'password_confirmation' => 'New-password-456!',
    ]);

    $response->assertUnprocessable();
    $response->assertExactJson([
        'message' => 'The password reset link is invalid or expired.',
    ]);
});

test('returns validation errors when required reset fields are missing', function () {
    $response = postJson('/api/v1/reset-password');

    $response->assertUnprocessable();
    $response->assertJsonPath('errors.email.0', 'The email field is required.');
    $response->assertJsonPath('errors.token.0', 'The token field is required.');
    $response->assertJsonPath('errors.password.0', 'The password field is required.');
});

test('returns a validation error when the email is invalid', function () {
    $response = postJson('/api/v1/reset-password', [
        'email' => 'not-an-email',
        'token' => 'some-reset-token',
        'password' => 'New-password-456!',
        'password_confirmation' => 'New-password-456!',
    ]);

    $response->assertUnprocessable();
    $response->assertExactJson([
        'message' => 'The email field must be a valid email address.',
        'errors' => [
            'email' => [
                'The email field must be a valid email address.',
            ],
        ],
    ]);
});

test('returns a validation error when the password confirmation does not match', function () {
    $response = postJson('/api/v1/reset-password', [
        'email' => 'registered@example.test',
        'token' => 'some-reset-token',
        'password' => 'New-password-456!',
        'password_confirmation' => 'Different-password-789!',
    ]);

    $response->assertUnprocessable();
    $response->assertExactJson([
        'message' => 'The password field confirmation does not match.',
        'errors' => [
            'password' => [
                'The password field confirmation does not match.',
            ],
        ],
    ]);
});

test('returns a validation error when the password is shorter than the registration requirement', function () {
    $response = postJson('/api/v1/reset-password', [
        'email' => 'registered@example.test',
        'token' => 'some-reset-token',
        'password' => 'short',
        'password_confirmation' => 'short',
    ]);

    $response->assertUnprocessable();
    $response->assertExactJson([
        'message' => 'The password field must be at least 8 characters.',
        'errors' => [
            'password' => [
                'The password field must be at least 8 characters.',
            ],
        ],
    ]);
});

test('reset password cannot be requested more than 2 times per minute for the same email', function () {
    $email = 'same-email@example.test';

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $response = postJson('/api/v1/reset-password', [
            'email' => $email,
            'token' => 'invalid-reset-token',
            'password' => 'New-password-456!',
            'password_confirmation' => 'New-password-456!',
        ]);

        $response->assertUnprocessable();
    }

    $response = postJson('/api/v1/reset-password', [
        'email' => $email,
        'token' => 'invalid-reset-token',
        'password' => 'New-password-456!',
        'password_confirmation' => 'New-password-456!',
    ]);

    $response->assertTooManyRequests();
});

test('reset password cannot be requested more than 5 times per hour for the same email', function () {
    $email = 'hourly-limit@example.test';

    $this->freezeTime(function () use ($email): void {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = postJson('/api/v1/reset-password', [
                'email' => $email,
                'token' => 'invalid-reset-token',
                'password' => 'New-password-456!',
                'password_confirmation' => 'New-password-456!',
            ]);

            $response->assertUnprocessable();

            if ($attempt < 5) {
                $this->travel(61)->seconds();
            }
        }

        $response = postJson('/api/v1/reset-password', [
            'email' => $email,
            'token' => 'invalid-reset-token',
            'password' => 'New-password-456!',
            'password_confirmation' => 'New-password-456!',
        ]);

        $response->assertTooManyRequests();
    });
});

test('reset password cannot be requested more than 10 times from the same IP', function () {
    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $response = postJson('/api/v1/reset-password', [
            'email' => "ip-limit-{$attempt}@example.test",
            'token' => 'invalid-reset-token',
            'password' => 'New-password-456!',
            'password_confirmation' => 'New-password-456!',
        ]);

        $response->assertUnprocessable();
    }

    $response = postJson('/api/v1/reset-password', [
        'email' => 'ip-limit-final@example.test',
        'token' => 'invalid-reset-token',
        'password' => 'New-password-456!',
        'password_confirmation' => 'New-password-456!',
    ]);

    $response->assertTooManyRequests();
});
