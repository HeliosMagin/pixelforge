<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pixelforge-Signature: t=<unix timestamp>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">
 */
class VerifyPixelforgeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.pixelforge.webhook_secret');

        if ($secret === '' || ! $this->isValid($request->header('Pixelforge-Signature', ''), $request->getContent(), $secret)) {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        return $next($request);
    }

    private function isValid(string $header, string $payload, string $secret): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            match ($key) {
                't' => $timestamp = $value,
                'v1' => $signatures[] = $value,
                default => null,
            };
        }

        if (! ctype_digit((string) $timestamp) || $signatures === []) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > config('services.pixelforge.webhook_tolerance_seconds')) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
