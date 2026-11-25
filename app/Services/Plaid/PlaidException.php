<?php

namespace App\Services\Plaid;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class PlaidException extends RuntimeException
{
    /**
     * Error codes that can only be fixed by the user re-authenticating with
     * their bank through Link in update mode.
     */
    public const array LOGIN_REQUIRED_CODES = [
        'ITEM_LOGIN_REQUIRED',
        'PENDING_EXPIRATION',
        'PENDING_DISCONNECT',
        'ITEM_LOCKED',
        'INVALID_CREDENTIALS',
        'INVALID_MFA',
        'USER_SETUP_REQUIRED',
    ];

    public function __construct(
        string $message,
        public readonly ?string $errorType = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $displayMessage = null,
        public readonly ?string $requestId = null,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $body = $response->json() ?? [];

        return new self(
            message: $body['error_message'] ?? "Plaid request failed with HTTP {$response->status()}.",
            errorType: $body['error_type'] ?? null,
            errorCode: $body['error_code'] ?? null,
            displayMessage: $body['display_message'] ?? null,
            requestId: $body['request_id'] ?? null,
            status: $response->status(),
        );
    }

    /**
     * A successful status with a body that isn't JSON, as a proxy or an
     * outage page in front of Plaid can send.
     */
    public static function unreadable(Response $response): self
    {
        return new self(
            "Plaid sent a reply Green could not read (HTTP {$response->status()}).",
            errorType: 'API_ERROR',
            errorCode: 'UNREADABLE_RESPONSE',
            status: $response->status(),
        );
    }

    public static function notConfigured(): self
    {
        return new self(
            'Plaid is not configured. Set PLAID_CLIENT_ID, PLAID_SECRET and PLAID_ENV in your .env file.',
            errorType: 'CONFIGURATION_ERROR',
            errorCode: 'NOT_CONFIGURED',
        );
    }

    public static function connectionFailed(Throwable $previous): self
    {
        return new self(
            'Could not reach Plaid: '.$previous->getMessage(),
            errorType: 'CONNECTION_ERROR',
            errorCode: 'CONNECTION_FAILED',
            previous: $previous,
        );
    }

    public function requiresLogin(): bool
    {
        return in_array($this->errorCode, self::LOGIN_REQUIRED_CODES, true);
    }

    /**
     * A message suitable for showing to the person using the app.
     */
    public function userMessage(): string
    {
        return $this->displayMessage ?: $this->getMessage();
    }
}
