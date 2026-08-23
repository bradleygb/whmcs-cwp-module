<?php
/**
 * CWP Hosting Module for WHMCS — the client area's AJAX transport.
 *
 * Requests arrive as an ordinary WHMCS product-details request carrying `cwpajax`, so
 * WHMCS has authenticated the session and resolved the service to the logged-in client
 * before the module sees them. That is the security anchor for every operation here,
 * and the reason the module ships no endpoint of its own.
 *
 * This class owns transport only: is this request ours, is its token valid, has this
 * service exceeded its rate, and what JSON goes back. What each operation does lives in
 * the Actions classes.
 *
 * @package cwp7
 * @version 2.3.0
 * @author  Booysen Logistics <bradley@booysenlogistics.co.za>
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

declare(strict_types=1);

namespace Cwp7;

final class ClientRequest
{
    /** Mutating operations allowed per service, per window. */
    const MAX_MUTATIONS = 10;

    /** Seconds the mutation allowance covers. */
    const WINDOW = 60;

    /** Longest operation name accepted, before it is matched against the routes. */
    const MAX_OPERATION = 40;

    /**
     * Is this request one of ours?
     *
     * @param array<string,mixed> $request
     */
    public static function wanted(array $request): bool
    {
        return isset($request['cwpajax']) && $request['cwpajax'] !== '';
    }

    /**
     * The operation being asked for, as `resource.verb`.
     *
     * Shape-checked here so a route lookup never sees anything odd; whether the route
     * exists is the caller's business.
     *
     * @param array<string,mixed> $request
     */
    public static function operation(array $request): string
    {
        $op = isset($request['cwpop']) ? trim((string) $request['cwpop']) : '';

        if ($op === '' || strlen($op) > self::MAX_OPERATION) {
            return '';
        }

        return preg_match('/^[a-z]+\.[a-z]+$/', $op) === 1 ? $op : '';
    }

    /**
     * One submitted field, as a trimmed string.
     *
     * Deliberately the only way in: there is no reader for a username or an account,
     * because those come from WHMCS's own parameters. A request says what to do, never
     * whose account to do it to.
     *
     * @param array<string,mixed> $request
     */
    public static function field(array $request, string $name, string $default = ''): string
    {
        if (!isset($request[$name]) || !is_scalar($request[$name])) {
            return $default;
        }

        return trim((string) $request[$name]);
    }

    /**
     * Does this operation change something?
     *
     * Reads are cheap and idempotent; everything else needs a token and counts against
     * the rate limit.
     */
    public static function mutates(string $operation): bool
    {
        $verb = substr($operation, (int) strpos($operation, '.') + 1);

        return $verb !== 'list';
    }

    /**
     * Compare a submitted CSRF token against WHMCS's.
     *
     * WHMCS's own check_token() renders an error page and stops, which would replace the
     * JSON body with HTML, so the comparison is made here instead using the same token
     * value WHMCS issues.
     *
     * Fails closed: without WHMCS's token function there is no way to verify anything,
     * and an unverified mutation is not worth performing.
     */
    public static function tokenValid(string $submitted): bool
    {
        if ($submitted === '' || !function_exists('generate_token')) {
            return false;
        }

        $expected = (string) generate_token('plain');

        return $expected !== '' && hash_equals($expected, $submitted);
    }

    /**
     * Record a mutation against the allowance, and say whether it may proceed.
     *
     * Takes its store and its clock so the rule can be tested without a session or a
     * wait. $store holds one window: the timestamp it opened and the count within it.
     *
     * @param array<string,mixed> $store
     */
    public static function withinRate(array &$store, int $now): bool
    {
        $startedAt = isset($store['at']) ? (int) $store['at'] : 0;
        $count = isset($store['n']) ? (int) $store['n'] : 0;

        if ($now - $startedAt >= self::WINDOW) {
            $store = ['at' => $now, 'n' => 1];

            return true;
        }

        if ($count >= self::MAX_MUTATIONS) {
            return false;
        }

        $store = ['at' => $startedAt, 'n' => $count + 1];

        return true;
    }

    /**
     * Apply the rate limit to one service, keeping the window in the WHMCS session.
     *
     * A session the module cannot write to means the allowance cannot be tracked, so the
     * request is allowed rather than blocked — the customer is already authenticated,
     * and CWP applies its own limits underneath.
     */
    public static function allow(int $serviceId, int $now): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return true;
        }

        $key = 'cwp7_rate_' . $serviceId;
        $store = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];
        $allowed = self::withinRate($store, $now);
        $_SESSION[$key] = $store;

        return $allowed;
    }

    /**
     * Send a JSON reply and stop.
     *
     * Everything WHMCS has buffered is discarded first: the page this request nominally
     * asked for is half-assembled by now, and any of it left in the buffer would arrive
     * ahead of the JSON and make it unparseable.
     *
     * @param array<string,mixed> $payload
     */
    public static function respond(array $payload, int $status = 200): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        echo json_encode($payload);

        exit;
    }

    /**
     * Send a refusal and stop. The message is shown to the customer, so callers pass
     * CwpException::getClientMessage() rather than the technical one.
     */
    public static function refuse(string $message, int $status = 400): void
    {
        self::respond(['ok' => false, 'error' => $message], $status);
    }
}
