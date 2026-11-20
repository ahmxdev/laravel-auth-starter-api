<?php

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\postJson;

test('returns 200 and sends a reset notification for a registered email', function () {
    $user = User::factory()->create([
        'email' => 'forgot-password@example.test',
    ]);

    Notification::fake();

    $response = postJson('/api/v1/forgot-password', [
        'email' => $user->email,
    ]);

    $response->assertOk();
    $response->assertExactJson([
        'message' => 'If the email is registered and eligible, reset instructions will be sent.',
    ]);

    assertDatabaseHas('password_reset_tokens', [
        'email' => $user->email,
    ]);

    Notification::assertSentTo(
        $user,
        ResetPasswordNotification::class,
        function (ResetPasswordNotification $notification, array $channels) use ($user): bool {
            expect($channels)->toBe(['mail']);
            expect($notification->token)->not->toBeEmpty();

            $storedToken = DB::table('password_reset_tokens')
                ->where('email', $user->email)
                ->first();

            expect(Hash::check($notification->token, $storedToken->token))->toBeTrue();

            $mailMessage = $notification->toMail($user);

            expect($mailMessage->view)->toBe('emails.reset-password-email');
            expect($mailMessage->viewData['url'])->toBe(
                config('app.frontend_url')
                .'/reset-password?token='.$notification->token
                .'&email=forgot-password%40example.test'
            );

            return true;
        }
    );
});

test('returns 422 when the email is missing', function () {
    $response = postJson('/api/v1/forgot-password');

    $response->assertUnprocessable();
    $response->assertExactJson([
        'message' => 'The email field is required.',
        'errors' => [
            'email' => [
                'The email field is required.',
            ],
        ],
    ]);
});

test('returns 422 when the email is invalid', function () {
    $response = postJson('/api/v1/forgot-password', [
        'email' => 'not-an-email',
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

test('returns the generic 200 response without notifying an unregistered email', function () {
    Notification::fake();

    $response = postJson('/api/v1/forgot-password', [
        'email' => 'unknown@example.test',
    ]);

    $response->assertOk();
    $response->assertExactJson([
        'message' => 'If the email is registered and eligible, reset instructions will be sent.',
    ]);

    Notification::assertNothingSent();
});

test('returns the generic 200 response when the password broker throttles a reset link', function () {
    $user = User::factory()->create();

    Notification::fake();

    $this->freezeTime(function () use ($user): void {
        $firstResponse = postJson('/api/v1/forgot-password', [
            'email' => $user->email,
        ]);

        $firstResponse->assertOk();

        $secondResponse = postJson('/api/v1/forgot-password', [
            'email' => $user->email,
        ]);

        $secondResponse->assertOk();
        $secondResponse->assertExactJson([
            'message' => 'If the email is registered and eligible, reset instructions will be sent.',
        ]);

        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    });
});

test('forgot password cannot be requested more than 2 times per minute for the same email', function () {
    $email = 'same-email@example.test';

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $response = postJson('/api/v1/forgot-password', [
            'email' => $email,
        ]);

        $response->assertOk();
    }

    $response = postJson('/api/v1/forgot-password', [
        'email' => $email,
    ]);

    $response->assertTooManyRequests();
});

test('forgot password cannot be requested more than 5 times per hour for the same email', function () {
    $email = 'hourly-limit@example.test';

    $this->freezeTime(function () use ($email): void {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = postJson('/api/v1/forgot-password', [
                'email' => $email,
            ]);

            $response->assertOk();

            if ($attempt < 5) {
                $this->travel(61)->seconds();
            }
        }

        $response = postJson('/api/v1/forgot-password', [
            'email' => $email,
        ]);

        $response->assertTooManyRequests();
    });
});

test('forgot password cannot be requested more than 10 times from the same IP', function () {
    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $response = postJson('/api/v1/forgot-password', [
            'email' => "ip-limit-{$attempt}@example.test",
        ]);

        $response->assertOk();
    }

    $response = postJson('/api/v1/forgot-password', [
        'email' => 'ip-limit-final@example.test',
    ]);

    $response->assertTooManyRequests();
});
