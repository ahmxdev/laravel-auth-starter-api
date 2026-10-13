<?php

use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

// Tests for 'email/verify/{id}/{hash}'
test('unverified unauthenticated user can verify their email using verification url', function () {
    $user = User::factory()->create([
        'name' => fake()->name(),
        'email' => fake()->email(),
        'password' => fake()->password(8),
    ]);

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]
    );

    $response = postJson($url);

    $response->assertOk();
    $response->assertJson([
        'message' => 'Email verified successfully.'
    ]);

    $user->refresh();
    expect($user->email_verified_at)->not->toBeNull();
});
test('verified user cannot verify their email again', function () {
    $user = User::factory()->verified()->create();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]
    );

    $response = postJson($url);

    $response->assertConflict();
    $response->assertJson([
        'message' => 'Email already verified.'
    ]);

    expect($user->email_verified_at)->not->toBeNull();
});
test('expired verification url is rejected', function () {
    $user = User::factory()->create([
        'name' => fake()->name(),
        'email' => fake()->email(),
        'password' => fake()->password(8),
    ]);

    Sanctum::actingAs($user);

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->subMinutes(1),
        [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]
    );

    $response = postJson($url);

    $response->assertForbidden();

    $user->refresh();
    expect($user->email_verified_at)->toBeNull();
});
test('email verification cannot be requested more than 10 times from the same IP', function () {
    for ($i = 1; $i <= 10; $i++) {
        postJson('/api/email/verify/1/hash');
    }

    $response = postJson('/api/email/verify/1/hash');

    $response->assertTooManyRequests();
});

// Tests for 'email/verification-notification'
test('unverified user can request verification for registered email', function () {
    Notification::fake();

    $user = User::factory()->create([
        'name' => fake()->name(),
        'email' => fake()->email(),
        'password' => fake()->password(8),
    ]);

    $response = postJson('/api/email/verification-notification', [
        'email' => $user->email
    ]);

    $response->assertOk();
    $response->assertJson([
        'message' => 'Verification link sent successfully.'
    ]);
    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

test('guest user cannot request verification email for unregistered email', function () {
    $response = postJson('/api/email/verification-notification', [
        'email' => fake()->email()
    ]);

    $response->assertOk();
    $response->assertJson([
        'message' => 'Verification link sent successfully.'
    ]);
});

test('verified user cannot request verification email', function () {
    Notification::fake();

    $user = User::factory()->verified()->create();

    $response = postJson('/api/email/verification-notification', [
        'email' => $user->email
    ]);

    $response->assertOk();
    $response->assertJson([
        'message' => 'Verification link sent successfully.'
    ]);
    Notification::assertNothingSent();
});

test('guest user cannot request more than 2 verification emails', function () {
    $user = User::factory()->create();

    for ($i = 1; $i <= 2; $i++) {
        postJson('/api/email/verification-notification', [
            'email' => $user->email
        ]);
    }

    $response = postJson('/api/email/verification-notification', [
        'email' => $user->email
    ]);

    $response->assertTooManyRequests();
});

test('verification notification cannot be requested more than 10 times from the same IP', function () {
    $user = User::factory()->create();

    for ($i = 1; $i <= 10; $i++) {
        postJson('/api/email/verification-notification', [
            'email' => fake()->email()
        ]);
    }

    $response = postJson('/api/email/verification-notification', [
        'email' => $user->email
    ]);

    $response->assertTooManyRequests();
});
