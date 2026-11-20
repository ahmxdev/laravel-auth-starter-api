# Laravel Auth Starter API

A JSON API starter for authentication built with Laravel and Sanctum. It includes registration, email verification, bearer-token authentication, logout, and password reset. It does not include an authorization or roles system.

## Features

- Register users and send email verification links.
- Issue Sanctum personal access tokens after verified users log in.
- View the authenticated user's profile and revoke the current token on logout.
- Request and complete password resets. Successful resets revoke all of the user's tokens.
- Validate requests and rate-limit authentication and email endpoints.
- Return generic responses for password-reset and verification requests to avoid revealing whether an email is registered.

## Requirements

- PHP 8.3 through 8.x
- Composer
- MySQL

## Setup

```bash
cp .env.example .env
composer install
php artisan key:generate
```

Create a MySQL database, then configure `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`. Set `APP_URL` to the API base URL and `FRONTEND_URL` to the frontend that will handle the email links. This repository does not include a frontend.

The example environment uses `MAIL_MAILER=log`, so email messages are written to `storage/logs/laravel.log`. Configure a mail transport in `.env` to deliver real email.

Run migrations and start the API:

```bash
php artisan migrate
php artisan serve
```

The local API base URL is `http://127.0.0.1:8000`.

## API

All endpoints use the `/api/v1` prefix and accept or return JSON. Protected endpoints require a Sanctum bearer token:

```http
Authorization: Bearer <token>
```

| Method | Endpoint                               | Authentication | Purpose                                                 |
| ------ | -------------------------------------- | -------------- | ------------------------------------------------------- |
| `POST` | `/api/v1/register`                        | No             | Create an account and send a verification email.        |
| `POST` | `/api/v1/login`                           | No             | Log in with a verified email and receive a token.       |
| `GET`  | `/api/v1/me`                              | Bearer token   | Return the authenticated user's profile.                |
| `POST` | `/api/v1/logout`                          | Bearer token   | Revoke the current token.                               |
| `POST` | `/api/v1/email/verification-notification` | No             | Request an email verification link.                     |
| `POST` | `/api/v1/email/verify/{id}/{hash}`        | Signed URL     | Verify an email using the signed link from the message. |
| `POST` | `/api/v1/forgot-password`                 | No             | Request a password reset link.                          |
| `POST` | `/api/v1/reset-password`                  | No             | Set a new password using the reset token.               |

### Request fields

| Endpoint                               | JSON body                                             |
| -------------------------------------- | ----------------------------------------------------- |
| `/api/v1/register`                        | `name`, `email`, `password`, `password_confirmation`  |
| `/api/v1/login`                           | `email`, `password`                                   |
| `/api/v1/email/verification-notification` | `email`                                               |
| `/api/v1/forgot-password`                 | `email`                                               |
| `/api/v1/reset-password`                  | `email`, `token`, `password`, `password_confirmation` |

Verification emails point to `FRONTEND_URL/email/verify?url=<signed-api-url>`. The frontend should submit that signed URL to the API with `POST`; it expires after 60 minutes. Password-reset emails point to `FRONTEND_URL/reset-password?token=<token>&email=<email>`. Reset tokens also expire after 60 minutes, and the password broker waits 60 seconds before issuing another link. Sanctum tokens expire after 24 hours.

Example login request:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/login \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"your-password"}'
```

Use the returned `token` as a bearer token for `/api/v1/me` and `/api/v1/logout`.

### Response behavior

- Registration returns `201`; validation failures return `422`.
- Login returns `401` for invalid credentials, `403` when the account email is not verified, and `422` for invalid input.
- Protected endpoints return `401` when the bearer token is missing or invalid.
- Email verification returns `200`, returns `409` if the email is already verified, and returns `403` for an expired or invalid signed URL.
- Password-reset requests return the same `200` response for registered and unregistered email addresses. Invalid, expired, or unregistered reset tokens return the same `422` response.
- Verification-email requests return the same `200` response for unregistered or already verified email addresses.
- Route-level rate-limit violations return `429`. If the password broker is still in its 60-second cooldown, forgot-password returns the same generic `200` response and sends no new link.
- Successful password resets revoke all existing Sanctum tokens for that user.

### Rate limits

| Endpoint                                            | Limit                                                       |
| --------------------------------------------------- | ----------------------------------------------------------- |
| Register                                            | 5 requests per minute per IP                                |
| Login                                               | 5 per minute per email; 30 per minute per IP                |
| Verification email, forgot password, reset password | 2 per minute and 5 per hour per email; 10 per minute per IP |
| Verify email link                                   | 10 per minute per IP                                        |
| Password-reset broker                               | 60 seconds between reset-link sends per email               |

## Tests

The test suite uses Pest and an in-memory SQLite database configured in `phpunit.xml`. Enable PHP's `pdo_sqlite` extension to run it.

```bash
php artisan test --compact
```
