<?php
/**
 * CWP Hosting Module for WHMCS — panel autologin.
 *
 * Mints a one-shot panel session for an account. Called on demand only, never during a
 * page render, and the returned URL is constrained to the configured host.
 *
 * @package cwp7
 * @version 2.0.2
 * @author  Booysen Logistics <bradley@booysenlogistics.co.za>
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

declare(strict_types=1);

namespace Cwp7\Actions;

use Cwp7\CwpClient;
use Cwp7\CwpException;

final class Session
{
    /** Autologin endpoint names, tried in order; CWP builds differ. */
    const ENDPOINTS = ['user_session', 'autologin'];

    /** Session lifetime hint sent to CWP. */
    const DEFAULT_TIMER = 5;

    /** @var CwpClient */
    private $client;

    /** @var string */
    private $username;

    /** @var bool */
    private $trustReturnedHost;

    public function __construct(CwpClient $client, string $username, bool $trustReturnedHost = false)
    {
        $this->client = $client;
        $this->username = $username;
        $this->trustReturnedHost = $trustReturnedHost;
    }

    /**
     * A URL that logs straight into this account's panel.
     *
     * @throws CwpException
     */
    public function url(): string
    {
        if (trim($this->username) === '') {
            throw CwpException::config('cannot build an autologin link without a username');
        }

        $lastError = null;

        foreach (self::ENDPOINTS as $endpoint) {
            try {
                $response = $this->client->call($endpoint, 'list', [
                    'user' => $this->username,
                    'username' => $this->username,
                    'timer' => (string) self::DEFAULT_TIMER,
                ]);
            } catch (CwpException $e) {
                // Transport and config faults affect every endpoint equally.
                if ($e->getKind() === CwpException::KIND_TRANSPORT
                    || $e->getKind() === CwpException::KIND_CONFIG
                ) {
                    throw $e;
                }

                $lastError = $e;
                continue;
            }

            $url = self::extractUrl(CwpClient::payload($response));

            if ($url !== null) {
                return $this->constrainToConfiguredHost($url);
            }

            $lastError = CwpException::protocol(
                $endpoint . ' returned no usable login URL: ' . CwpClient::flattenMessage(
                    CwpClient::payload($response)
                )
            );
        }

        throw $lastError !== null
            ? $lastError
            : CwpException::protocol('no autologin endpoint responded');
    }

    /**
     * Extract the login URL from the shapes CWP returns it in: a bare string, a single
     * row, a list of rows, or nested under a wrapper key.
     *
     * @param mixed $payload
     *
     * @return string|null
     */
    public static function extractUrl($payload)
    {
        if (is_string($payload)) {
            return self::looksLikeUrl($payload) ? $payload : null;
        }

        if (!is_array($payload)) {
            return null;
        }

        foreach (['url', 'link', 'autologin', 'redirect'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && self::looksLikeUrl($payload[$key])) {
                return $payload[$key];
            }
        }

        foreach ($payload as $value) {
            if (is_array($value) || is_string($value)) {
                $found = self::extractUrl($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private static function looksLikeUrl(string $value): bool
    {
        return stripos($value, 'http://') === 0 || stripos($value, 'https://') === 0;
    }

    /**
     * Force the URL onto the configured host.
     *
     * The session token is in the path and query, so replacing the host preserves the
     * link while preventing a redirect to any host the administrator did not enter. CWP
     * commonly returns its own FQDN where the server entry holds an IP.
     *
     * Set 'autologin_trust_returned_host' => true in config.php to use CWP's host as-is.
     *
     * @throws CwpException
     */
    private function constrainToConfiguredHost(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            throw CwpException::protocol('autologin URL could not be parsed');
        }

        $configuredHost = $this->client->getHost();

        if ($this->trustReturnedHost || strcasecmp($parts['host'], $configuredHost) === 0) {
            // This URL carries a live session token, so refuse cleartext.
            if (isset($parts['scheme']) && strcasecmp($parts['scheme'], 'https') !== 0) {
                $url = 'https://' . substr($url, strpos($url, '://') + 3);
            }

            return $url;
        }

        $rebuilt = 'https://' . $configuredHost;

        if (isset($parts['port'])) {
            $rebuilt .= ':' . (int) $parts['port'];
        }

        $rebuilt .= isset($parts['path']) ? $parts['path'] : '/';

        if (isset($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }

        if (isset($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        if (function_exists('logModuleCall')) {
            logModuleCall(
                'cwp7',
                'autologin host rewritten',
                ['returned' => $parts['host'], 'configured' => $configuredHost],
                'Rewrote the autologin host to the configured server. Set '
                . 'autologin_trust_returned_host => true in config.php to keep CWP\'s host.'
            );
        }

        return $rebuilt;
    }
}
