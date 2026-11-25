<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets through only deliveries the service signed in the last five minutes:
 * X-Webhook-Signature is "t=<unix seconds>,v1=<hex HMAC-SHA256 of "t.<raw body>">".
 */
class VerifyWebhookSignature
{
    /**
     * How far, in seconds, the signature's time may be from the app's clock.
     */
    public const int Tolerance = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('verifactu.webhook_secret');

        // A server error, so the service keeps retrying the delivery until the secret is set.
        throw_if(! is_string($secret) || blank($secret), RuntimeException::class, 'Set VERIFACTU_WEBHOOK_SECRET to the webhook secret of the app on the Verifactu service.');

        // A 403 is final: the service does not send the delivery again.
        abort_if($this->signatureIsInvalid($request->getContent(), (string) $request->headers->get('X-Webhook-Signature'), $secret), Response::HTTP_FORBIDDEN, 'Invalid Verifactu webhook signature.');

        /** @var Response */
        return $next($request);
    }

    /**
     * Whether the delivery is missing t or v1, was signed with another
     * secret or over another body, or was signed too long ago.
     */
    protected function signatureIsInvalid(string $payload, string $header, string $secret): bool
    {
        $parts = Str::of($header)
            ->explode(',')
            ->mapWithKeys(fn (string $part): array => [Str::trim(Str::before($part, '=')) => Str::trim(Str::after($part, '='))]);

        $timestamp = $parts->get('t');
        $signature = $parts->get('v1');

        if (! is_string($timestamp) || ! ctype_digit($timestamp) || ! is_string($signature)) {
            return true;
        }

        if (! hash_equals(hash_hmac('sha256', "{$timestamp}.{$payload}", $secret), $signature)) {
            return true;
        }

        return abs(now()->getTimestamp() - (int) $timestamp) > self::Tolerance;
    }
}
