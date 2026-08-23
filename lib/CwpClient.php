<?php
/**
 * CWP Hosting Module for WHMCS — CWP API transport.
 *
 * POST https://<host>:2304/v1/<function>, form-encoded, with `key` and `action`.
 * Responses are JSON: {"status":"OK", ...} or {"status":"Error","msg":"..."}.
 *
 * @package cwp7
 * @version 2.3.0
 * @author  Booysen Logistics <bradley@booysenlogistics.co.za>
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

declare(strict_types=1);

namespace Cwp7;

final class CwpClient
{
    /** Reachability probe used by TestConnection. */
    const PROBE_FUNCTION = 'typeserver';

    /** Secondary probe, so an unsupported PROBE_FUNCTION is not reported as a dead server. */
    const PROBE_FALLBACK = 'account';

    /**
     * libcurl codes for a failed certificate verification.
     *
     * Numeric literals, not CURLE_* constants: the constant set varies by build, and
     * CURLE_PEER_FAILED_VERIFICATION is undefined on PHP 8.3.
     */
    const TLS_VERIFY_ERRORS = [51, 60, 77, 83];

    /**
     * Keys CWP has used to carry a response payload, in lookup order.
     *
     * Current builds return data under `result` and errors under `msg`. Older builds
     * used `msj` for both.
     */
    const MESSAGE_KEYS = ['result', 'msg', 'msj'];

    /** @var string */
    private $host;

    /** @var int */
    private $port;

    /** @var string */
    private $key;

    /** @var bool */
    private $verifyTls;

    /** @var string|null */
    private $caBundle;

    /** @var int */
    private $connectTimeout;

    /** @var int */
    private $timeout;

    /** @var int */
    private $provisionTimeout;

    /** @var bool */
    private $debug;

    /** @var int */
    private $panelPort;

    /** @var int */
    private $adminPort;

    /** @var array<string,mixed> */
    private $settings;

    /**
     * @param array<string,mixed> $settings
     *
     * @throws CwpException
     */
    public function __construct(array $settings)
    {
        $this->settings = $settings;

        $host = self::normaliseHost((string) (isset($settings['host']) ? $settings['host'] : ''));
        if ($host === null) {
            throw CwpException::config('server hostname/IP is missing or not a valid host');
        }

        $key = trim((string) (isset($settings['key']) ? $settings['key'] : ''));
        if ($key === '') {
            throw CwpException::config(
                'API key is empty — set it in the server entry\'s Access Hash field'
            );
        }

        $this->host = $host;
        $this->key = $key;
        $this->port = (int) (isset($settings['api_port']) ? $settings['api_port'] : 2304);
        $this->panelPort = (int) (isset($settings['panel_port']) ? $settings['panel_port'] : 2083);
        $this->adminPort = (int) (isset($settings['admin_port']) ? $settings['admin_port'] : 2031);
        $this->verifyTls = (bool) (isset($settings['verify_tls']) ? $settings['verify_tls'] : true);
        $this->connectTimeout = (int) (isset($settings['connect_timeout']) ? $settings['connect_timeout'] : 5);
        $this->timeout = (int) (isset($settings['timeout']) ? $settings['timeout'] : 20);
        $this->provisionTimeout = (int) (isset($settings['provision_timeout']) ? $settings['provision_timeout'] : 180);
        $this->debug = (bool) (isset($settings['debug']) ? $settings['debug'] : false);

        $caBundle = isset($settings['ca_bundle']) ? $settings['ca_bundle'] : null;
        $this->caBundle = (is_string($caBundle) && $caBundle !== '') ? $caBundle : null;

        if ($this->port < 1 || $this->port > 65535) {
            throw CwpException::config('invalid API port: ' . $this->port);
        }
    }

    /**
     * Build a client from the parameters WHMCS passes every module function.
     *
     * @param array<string,mixed> $params
     *
     * @return self
     *
     * @throws CwpException
     */
    public static function fromParams(array $params)
    {
        $config = self::loadConfig();

        $host = (string) (isset($params['serverhostname']) ? $params['serverhostname'] : '');
        if (trim($host) === '') {
            $host = (string) (isset($params['serverip']) ? $params['serverip'] : '');
        }

        $settings = $config['defaults'];

        $hostKey = self::normaliseHost($host);
        if ($hostKey !== null && isset($config['servers'][$hostKey]) && is_array($config['servers'][$hostKey])) {
            $settings = array_merge($settings, $config['servers'][$hostKey]);
        }

        $settings['host'] = $host;
        $settings['key'] = (string) (isset($params['serveraccesshash']) ? $params['serveraccesshash'] : '');

        $serverPort = (int) (isset($params['serverport']) ? $params['serverport'] : 0);
        if ($serverPort > 0) {
            $settings['api_port'] = $serverPort;
        }

        return new self($settings);
    }

    /**
     * Issue one API call.
     *
     * @param string               $function CWP function, e.g. 'accountdetail'
     * @param string               $action   'list' | 'add' | 'udp' | 'del' | 'susp' | 'unsp'
     * @param array<string,scalar> $fields   Additional POST fields
     *
     * @return array<string,mixed> Decoded response, status verified as OK
     *
     * @throws CwpException
     */
    public function call(string $function, string $action, array $fields = []): array
    {
        // $function lands in the URL path.
        if (preg_match('/^[a-z0-9_]+$/', $function) !== 1) {
            throw CwpException::config('illegal CWP function name: ' . $function);
        }

        if (preg_match('/^[a-z_]+$/', $action) !== 1) {
            throw CwpException::config('illegal CWP action: ' . $action);
        }

        $url = sprintf('https://%s:%d/v1/%s', $this->host, $this->port, $function);

        $post = $fields;
        $post['key'] = $this->key;
        $post['action'] = $action;

        if ($this->debug) {
            $post['debug'] = '1';
        }

        $started = microtime(true);
        list($body, $httpCode, $curlErrno, $curlError, $primaryIp) = $this->execute(
            $url,
            $post,
            $this->timeoutFor($action)
        );
        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

        $context = [
            'function' => $function,
            'action' => $action,
            'http_code' => $httpCode,
            'elapsed_ms' => $elapsedMs,
            'curl_errno' => $curlErrno,
            'resolved_ip' => $primaryIp,
        ];

        try {
            $decoded = $this->interpret($body, $httpCode, $curlErrno, $curlError, $context);
        } catch (CwpException $e) {
            $this->log($function . '/' . $action, $url, $post, $body, $e->getMessage());
            throw $e;
        }

        $this->log($function . '/' . $action, $url, $post, $body, $decoded);

        return $decoded;
    }

    /**
     * Reachability check for TestConnection.
     *
     * @return array{ok:bool, endpoint:string, error:CwpException|null}
     */
    public function ping(): array
    {
        try {
            $this->call(self::PROBE_FUNCTION, 'list');

            return ['ok' => true, 'endpoint' => self::PROBE_FUNCTION, 'error' => null];
        } catch (CwpException $primary) {
            // Only an unrecognised endpoint or a permission refusal warrants the fallback.
            if ($primary->getKind() === CwpException::KIND_TRANSPORT
                || $primary->getKind() === CwpException::KIND_CONFIG
            ) {
                return ['ok' => false, 'endpoint' => self::PROBE_FUNCTION, 'error' => $primary];
            }

            try {
                $this->call(self::PROBE_FALLBACK, 'list');

                return ['ok' => true, 'endpoint' => self::PROBE_FALLBACK, 'error' => null];
            } catch (CwpException $fallback) {
                return ['ok' => false, 'endpoint' => self::PROBE_FALLBACK, 'error' => $fallback];
            }
        }
    }

    public function getHost(): string
    {
        return $this->host;
    }

    /** Base URL of the CWP end-user panel. */
    public function getPanelUrl(): string
    {
        return sprintf('https://%s:%d', $this->host, $this->panelPort);
    }

    /** Base URL of the CWP admin panel. */
    public function getAdminUrl(): string
    {
        return sprintf('https://%s:%d', $this->host, $this->adminPort);
    }

    /**
     * A setting from config.php, after per-server overrides have been merged.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public function getOption(string $key, $default = null)
    {
        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }

    /**
     * The message/payload a response carries, under either supported key.
     *
     * @param array<string,mixed> $decoded
     *
     * @return mixed Null when the response carries neither key.
     */
    public static function payload(array $decoded)
    {
        foreach (self::MESSAGE_KEYS as $key) {
            if (array_key_exists($key, $decoded)) {
                return $decoded[$key];
            }
        }

        return null;
    }

    /**
     * The payload as a list of rows, for endpoints returning a collection.
     *
     * @param array<string,mixed> $decoded
     *
     * @return array<int,array<string,mixed>>
     */
    public static function rows(array $decoded): array
    {
        $payload = self::payload($decoded);

        if (!is_array($payload)) {
            return [];
        }

        // An associative array is a single row, not a list of rows.
        if ($payload !== [] && !array_key_exists(0, $payload)) {
            return [$payload];
        }

        $rows = [];
        foreach ($payload as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** True for RFC1918, loopback, link-local and other non-routable addresses. */
    public static function isPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * CWP returns its message as a string on some functions and an array on others.
     *
     * @param mixed $msg
     */
    public static function flattenMessage($msg): string
    {
        if (is_string($msg)) {
            return $msg;
        }

        if (is_scalar($msg)) {
            return (string) $msg;
        }

        if (is_array($msg)) {
            $flat = json_encode($msg);

            return is_string($flat) ? $flat : 'unserialisable message';
        }

        return 'unknown error';
    }

    /**
     * How long a given action may run.
     *
     * Reads answer in milliseconds. Provisioning does not: creating an account builds a
     * user, home directory, vhost, DNS zone and mail configuration, and CWP keeps working
     * after a client gives up — so a short budget produces an account on the server and a
     * failed service in WHMCS.
     */
    public function timeoutFor(string $action): int
    {
        return $action === 'list' ? $this->timeout : $this->provisionTimeout;
    }

    /**
     * @param array<string,scalar> $post
     *
     * @return array{0:string|null, 1:int, 2:int, 3:string, 4:string}
     *
     * @throws CwpException
     */
    private function execute(string $url, array $post, int $timeout): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw CwpException::transport('could not initialise cURL');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        // The API key is in the POST body; a redirect could replay it elsewhere.
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
        } elseif (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyTls);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifyTls ? 2 : 0);

        if ($this->verifyTls && $this->caBundle !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
        }

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);

        curl_close($ch);

        return [is_string($body) ? $body : null, $httpCode, $errno, $error, $primaryIp];
    }

    /**
     * Turn a raw reply into a verified-OK response array, or throw.
     *
     * @param string|null         $body
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>
     *
     * @throws CwpException
     */
    private function interpret(
        $body,
        int $httpCode,
        int $curlErrno,
        string $curlError,
        array $context
    ): array {
        if ($curlErrno !== 0) {
            if (in_array($curlErrno, self::TLS_VERIFY_ERRORS, true)) {
                throw CwpException::transport(
                    'TLS verification failed (' . $curlError . '). Check the certificate on '
                    . 'the API port: if it is self-signed, export it and set "ca_bundle" in '
                    . 'config.php; if it is from a real CA, this server\'s CA store is the '
                    . 'problem. Do not disable verification.',
                    $context
                );
            }

            $detail = $curlError . ' (errno ' . $curlErrno . ')';

            $ip = isset($context['resolved_ip']) ? (string) $context['resolved_ip'] : '';

            if ($ip !== '' && $ip !== $this->host) {
                $detail .= ' — ' . $this->host . ' resolved to ' . $ip;

                // Only where the connection itself failed. On a timeout the socket opened,
                // so where the name pointed is not the problem.
                $connectFailed = ($curlErrno === 6 || $curlErrno === 7);

                if ($connectFailed && self::isPrivateIp($ip)) {
                    $detail .= ', a private (RFC1918) address. That only works if WHMCS is on '
                        . 'the same network as CWP; otherwise this hostname resolves elsewhere '
                        . 'from the WHMCS server than it does from your workstation';
                }
            }

            throw CwpException::transport($detail, $context);
        }

        if ($body === null || $body === '') {
            throw CwpException::protocol('empty response body', $context);
        }

        if ($httpCode !== 200) {
            throw CwpException::protocol('HTTP ' . $httpCode, $context);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            if (strncmp(ltrim($body), '<', 1) === 0) {
                throw CwpException::config(
                    'CWP returned XML. Set this API key\'s response format to JSON in '
                    . 'CWP -> Settings -> API Manager.',
                    $context
                );
            }

            throw CwpException::protocol(
                'response was not JSON: ' . $this->redact(substr($body, 0, 200)),
                $context
            );
        }

        $status = (string) (isset($decoded['status']) ? $decoded['status'] : '');

        if (strcasecmp($status, 'OK') !== 0) {
            $message = self::payload($decoded);
            if ($message === null) {
                $message = ($status !== '') ? $status : 'no status field';
            }

            // CWP echoes the submitted key inside some errors, and this text reaches the
            // admin UI.
            $detail = $this->redact(self::flattenMessage($message));

            // The API Manager grid is per function and per action, so name both.
            if (isset($context['function'], $context['action'])) {
                $detail .= ' [' . $context['function'] . '/' . $context['action'] . ']';
            }

            throw CwpException::api($detail, $context);
        }

        return $decoded;
    }

    /** Remove the API key from text that originated at CWP. */
    private function redact(string $text): string
    {
        if ($this->key === '') {
            return $text;
        }

        return str_replace($this->key, '[api key redacted]', $text);
    }

    /**
     * Write to the WHMCS Module Log with credentials masked.
     *
     * @param array<string,scalar> $post
     * @param string|null          $rawResponse
     * @param mixed                $processed
     */
    private function log(string $action, string $url, array $post, $rawResponse, $processed): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }

        $safePost = $post;
        $safePost['key'] = '***';
        if (isset($safePost['pass'])) {
            $safePost['pass'] = '***';
        }

        $replaceVars = [$this->key];
        if (isset($post['pass']) && $post['pass'] !== '') {
            $replaceVars[] = (string) $post['pass'];
        }

        logModuleCall(
            'cwp7',
            $action,
            $url . ' ' . http_build_query($safePost),
            $rawResponse === null ? '' : $this->redact($rawResponse),
            is_string($processed) ? $this->redact($processed) : $processed,
            $replaceVars
        );
    }

    /**
     * Reduce whatever is in the server entry to a bare host.
     *
     * Accepts a pasted URL. Returns null if nothing usable remains.
     *
     * @return string|null
     */
    private static function normaliseHost(string $raw)
    {
        $host = trim($raw);
        if ($host === '') {
            return null;
        }

        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host);
        if ($host === null) {
            return null;
        }

        $parts = explode('/', $host, 2);
        $host = $parts[0];

        // Strip a trailing :port, but leave bare IPv6 alone.
        if (substr_count($host, ':') === 1) {
            $bits = explode(':', $host, 2);
            $host = $bits[0];
        }

        $host = trim($host);
        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
            return $host;
        }

        return null;
    }

    /**
     * Optional. Every key has a default in the constructor, so an absent config.php
     * is a supported state.
     *
     * @return array{defaults:array<string,mixed>, servers:array<string,array<string,mixed>>}
     */
    private static function loadConfig(): array
    {
        static $config = null;

        if ($config === null) {
            $path = __DIR__ . '/../config.php';
            $loaded = is_readable($path) ? require $path : [];

            $config = [
                'defaults' => (is_array($loaded) && isset($loaded['defaults']) && is_array($loaded['defaults']))
                    ? $loaded['defaults'] : [],
                'servers' => (is_array($loaded) && isset($loaded['servers']) && is_array($loaded['servers']))
                    ? $loaded['servers'] : [],
            ];
        }

        return $config;
    }
}
