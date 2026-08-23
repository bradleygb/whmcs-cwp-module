<?php
/**
 * CWP Hosting Module for WHMCS — API failure.
 *
 * Carries a technical message for the Module Log and a separate client-safe message
 * for the client area.
 *
 * @package cwp7
 * @version 2.4.0
 * @author  Booysen Logistics <bradley@booysenlogistics.co.za>
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

declare(strict_types=1);

namespace Cwp7;

class CwpException extends \RuntimeException
{
    /** Could not reach CWP: DNS, TCP, TLS or timeout. */
    const KIND_TRANSPORT = 'transport';

    /** Reached CWP, but the reply was not a well-formed API response. */
    const KIND_PROTOCOL = 'protocol';

    /** CWP understood the request and refused it. */
    const KIND_API = 'api';

    /** The module is misconfigured; no request was attempted. */
    const KIND_CONFIG = 'config';

    /** A customer typed something the module refused; no request was attempted. */
    const KIND_INPUT = 'input';

    const GENERIC_CLIENT_MESSAGE =
        'We could not complete that request on the hosting server. '
        . 'Please try again shortly, or contact support if it keeps happening.';

    /** @var string */
    private $kind;

    /** @var string */
    private $clientMessage;

    /** @var array<string,mixed> */
    private $context;

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(
        string $kind,
        string $message,
        string $clientMessage = self::GENERIC_CLIENT_MESSAGE,
        array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);

        $this->kind = $kind;
        $this->clientMessage = $clientMessage;
        $this->context = $context;
    }

    /**
     * @param array<string,mixed> $context
     *
     * @return self
     */
    public static function transport(string $detail, array $context = [])
    {
        return new self(
            self::KIND_TRANSPORT,
            'Could not reach the CWP API: ' . $detail,
            'The hosting server is not responding right now. Please try again in a moment.',
            $context
        );
    }

    /**
     * @param array<string,mixed> $context
     *
     * @return self
     */
    public static function protocol(string $detail, array $context = [])
    {
        return new self(
            self::KIND_PROTOCOL,
            'Unexpected reply from the CWP API: ' . $detail,
            self::GENERIC_CLIENT_MESSAGE,
            $context
        );
    }

    /**
     * A refusal from CWP itself. $cwpMessage goes to the Module Log only.
     *
     * @param array<string,mixed> $context
     *
     * @return self
     */
    public static function api(string $cwpMessage, array $context = [])
    {
        return new self(
            self::KIND_API,
            'CWP rejected the request: ' . $cwpMessage,
            self::GENERIC_CLIENT_MESSAGE,
            $context
        );
    }

    /**
     * @param array<string,mixed> $context
     *
     * @return self
     */
    public static function config(string $detail, array $context = [])
    {
        return new self(
            self::KIND_CONFIG,
            'CWP module is misconfigured: ' . $detail,
            'This hosting service is not configured correctly. Please contact support.',
            $context
        );
    }

    /**
     * Something a customer entered, refused before any request was made.
     *
     * The only kind whose message is written for the customer rather than for the
     * Module Log: they typed it, so they are the one who can correct it. Safe to show
     * by construction, because the module authors every one of these strings — none of
     * them carries CWP output, which can name other accounts and filesystem paths.
     *
     * @param array<string,mixed> $context
     *
     * @return self
     */
    public static function input(string $message, array $context = [])
    {
        return new self(self::KIND_INPUT, $message, $message, $context);
    }

    /**
     * Attach a message that is safe to show the client.
     *
     * Constructs rather than clones: PHP declares Exception::__clone private and final,
     * so `clone $this` is a fatal Error.
     *
     * @return self
     */
    public function withClientMessage(string $clientMessage)
    {
        return new self(
            $this->kind,
            $this->getMessage(),
            $clientMessage,
            $this->context,
            $this->getPrevious()
        );
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    /** Safe to render in the client area. */
    public function getClientMessage(): string
    {
        return $this->clientMessage;
    }

    /**
     * Diagnostic detail for the Module Log. Not for client-facing output.
     *
     * @return array<string,mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /** True when retrying the same request might succeed. */
    public function isRetryable(): bool
    {
        return $this->kind === self::KIND_TRANSPORT;
    }
}
