<?php
/**
 * CWP Hosting Module for WHMCS — account lifecycle.
 *
 * Covers /v1/account, /v1/changepass and /v1/accountdetail. Methods throw
 * CwpException on failure; the dispatcher converts that into WHMCS's return contract.
 *
 * @package cwp7
 * @version 2.1.0
 * @author  Booysen Logistics <bradley@booysenlogistics.co.za>
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

declare(strict_types=1);

namespace Cwp7\Actions;

use Cwp7\CwpClient;
use Cwp7\CwpException;

final class Account
{
    /**
     * Username length cap applied to new accounts. Overridable per product
     * (config option 5; 0 disables the cap).
     */
    const DEFAULT_USERNAME_MAX = 8;

    /** @var CwpClient */
    private $client;

    /** @var array<string,mixed> */
    private $params;

    /**
     * @param array<string,mixed> $params
     */
    public function __construct(CwpClient $client, array $params)
    {
        $this->client = $client;
        $this->params = $params;
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return self
     *
     * @throws CwpException
     */
    public static function fromParams(array $params)
    {
        return new self(CwpClient::fromParams($params), $params);
    }

    /**
     * Provision a new account.
     *
     * @throws CwpException
     */
    public function create(): void
    {
        $username = $this->provisionUsername();
        $domain = trim((string) $this->param('domain'));

        if ($domain === '') {
            throw CwpException::config('no domain on the service — CWP cannot create an account without one');
        }

        $this->assertPackageExists($this->packageValue());

        try {
            $this->client->call('account', 'add', $this->createFields($username, $domain));
        } catch (CwpException $e) {
            // CWP keeps building after the module gives up, so a timeout is not proof
            // that nothing happened.
            if (!$this->createCompletedAfterTimeout($e, $username)) {
                throw $e;
            }
        }
    }

    /**
     * The POST fields for `account`/`add`, per Interactive Documentation.
     *
     * Note `limit_nofile` and `limit_nproc`: `udp` calls the same two limits `openfiles`
     * and `processes`, and the original module used `nofile` and `nproc`, which neither
     * endpoint accepts — so those limits were silently never applied. Public so the
     * contract can be asserted in tests rather than rediscovered.
     *
     * @return array<string,string>
     *
     * @throws CwpException
     */
    public function createFields(string $username, string $domain): array
    {
        return [
            'domain' => $domain,
            'user' => $username,
            'pass' => (string) $this->param('password'),
            'email' => $this->clientEmail(),
            'package' => $this->packageValue(),
            'inode' => (string) $this->param('configoption2', '0'),
            'limit_nofile' => (string) $this->param('configoption3', '100'),
            'limit_nproc' => (string) $this->param('configoption4', '40'),
            'server_ips' => (string) $this->param('serverip'),
        ];
    }

    /**
     * Refuse a package the server does not have, before anything is changed.
     *
     * `changepack` answers `status OK` for an ID that does not exist and leaves the
     * account pointing at nothing, so the post-change check catches it only after the
     * damage. This catches it first.
     *
     * Fails open: the check needs LIST on Packages, and a key without it — or a response
     * shape this cannot read — must not block package changes that would otherwise work.
     *
     * @throws CwpException
     */
    private function assertPackageExists(string $package): void
    {
        try {
            $rows = CwpClient::rows($this->client->call('packages', 'list'));
        } catch (CwpException $e) {
            return;
        }

        $known = self::packageIdentifiers($rows);

        if ($known['ids'] === [] && $known['names'] === []) {
            return;
        }

        foreach (array_merge($known['ids'], $known['names']) as $candidate) {
            if (strcasecmp($candidate, $package) === 0) {
                return;
            }
        }

        throw CwpException::config(sprintf(
            'CWP has no package "%s" on this server. Known IDs: %s. Set the product\'s '
            . 'CWP Package field to one of these.',
            $package,
            $known['ids'] === [] ? '(none reported)' : implode(', ', array_slice($known['ids'], 0, 25))
        ));
    }

    /**
     * The IDs and names CWP reports for its packages.
     *
     * Separated from the call so the matching can be tested without a server.
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array{ids:array<int,string>, names:array<int,string>}
     */
    public static function packageIdentifiers(array $rows): array
    {
        $ids = [];
        $names = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach (['id', 'idpackage', 'package_id'] as $key) {
                if (isset($row[$key]) && $row[$key] !== '') {
                    $ids[] = trim((string) $row[$key]);
                    break;
                }
            }

            foreach (['package_name', 'name', 'package'] as $key) {
                if (isset($row[$key]) && $row[$key] !== '') {
                    $names[] = trim((string) $row[$key]);
                    break;
                }
            }
        }

        return ['ids' => $ids, 'names' => $names];
    }

    /**
     * The POST fields for `changepack`/`udp` — the dedicated package-change endpoint.
     *
     * `package` is the bare ID here. The `@` prefix belongs to `account`/`udp`, which is
     * a different call: a full account update rather than a package change.
     *
     * @return array<string,string>
     *
     * @throws CwpException
     */
    public function changePackFields(string $username): array
    {
        return [
            'user' => $username,
            'package' => $this->packageValue(),
        ];
    }

    /**
     * Apply the product's inode, open-file and process limits.
     *
     * Best effort. These ride on `account`/`udp`, which is a broader grant than the
     * package change itself — CWP checks it as `accout_upd`, separate from the
     * "Account pack change" permission. A key without it still gets the package moved,
     * which is the part that matters.
     */
    private function applyResourceLimits(string $username): void
    {
        try {
            $this->client->call('account', 'udp', $this->packageFields($username));
        } catch (CwpException $e) {
            if (function_exists('logModuleCall')) {
                logModuleCall(
                    'cwp7',
                    'resource limits not applied',
                    ['user' => $username],
                    $e->getMessage() . ' — the package changed, but inode/openfiles/processes '
                    . 'need UPD on the Account function.'
                );
            }
        }
    }

    /**
     * The POST fields for `account`/`udp`, per Interactive Documentation.
     *
     * The `@` prefixes the package value here — CWP documents "Package name or ID with @
     * front" — and `email` is required. Public for the same reason as createFields().
     *
     * @return array<string,string>
     *
     * @throws CwpException
     */
    public function packageFields(string $username): array
    {
        $email = $this->clientEmail();

        if ($email === '') {
            throw CwpException::config(
                'the client has no email address — CWP rejects a package change without one'
            );
        }

        return [
            'user' => $username,
            'email' => $email,
            'package' => '@' . $this->packageValue(),
            'inode' => (string) $this->param('configoption2', '0'),
            'openfiles' => (string) $this->param('configoption3', '100'),
            'processes' => (string) $this->param('configoption4', '40'),
            'server_ips' => (string) $this->param('serverip'),
        ];
    }

    /**
     * Did a creation that timed out actually finish?
     *
     * @throws CwpException
     */
    private function createCompletedAfterTimeout(CwpException $e, string $username): bool
    {
        $context = $e->getContext();
        $errno = isset($context['curl_errno']) ? (int) $context['curl_errno'] : 0;

        if ($e->getKind() !== CwpException::KIND_TRANSPORT || $errno !== 28) {
            return false;
        }

        try {
            $info = $this->accountInfo($username);
        } catch (CwpException $ignored) {
            $info = null;
        }

        if ($info === null) {
            return false;
        }

        if (function_exists('logModuleCall')) {
            logModuleCall(
                'cwp7',
                'CreateAccount completed after timeout',
                ['user' => $username],
                'The request timed out but CWP finished building the account.'
            );
        }

        return true;
    }

    /**
     * @throws CwpException
     */
    public function suspend(): void
    {
        $this->client->call('account', 'susp', ['user' => $this->resolveUsername()]);
    }

    /**
     * @throws CwpException
     */
    public function unsuspend(): void
    {
        $this->client->call('account', 'unsp', ['user' => $this->resolveUsername()]);
    }

    /**
     * @throws CwpException
     */
    public function terminate(): void
    {
        $this->client->call('account', 'del', ['user' => $this->resolveUsername()]);
    }

    /**
     * @throws CwpException
     */
    public function changePassword(string $password): void
    {
        if ($password === '') {
            throw CwpException::config('no new password supplied');
        }

        $username = $this->resolveUsername();

        $this->client->call('changepass', 'udp', [
            'user' => $username,
            'username' => $username,
            'pass' => $password,
        ]);
    }

    /**
     * Apply the new product's package and resource limits.
     *
     * @throws CwpException
     */
    public function changePackage(): void
    {
        $username = $this->resolveUsername();
        $package = $this->packageValue();

        $this->assertPackageExists($package);

        // The dedicated endpoint for this one operation, gated by the narrow
        // "Account pack change" permission.
        $this->client->call('changepack', 'udp', $this->changePackFields($username));

        $this->applyResourceLimits($username);
        $this->assertPackageApplied($package);
    }

    /**
     * Confirm CWP actually moved the account onto the requested package.
     *
     * `status OK` alone is not evidence: a change that silently fails to apply would
     * otherwise be reported to the admin as a success.
     *
     * @throws CwpException
     */
    private function assertPackageApplied(string $requested): void
    {
        try {
            $info = $this->accountInfo($this->resolveUsername());
        } catch (CwpException $e) {
            // The change may well have worked; not being able to confirm it is not a
            // reason to report failure.
            if (function_exists('logModuleCall')) {
                logModuleCall('cwp7', 'ChangePackage unverified', $requested, $e->getMessage());
            }

            return;
        }

        if ($info === null) {
            return;
        }

        $id = isset($info['package_id']) ? trim((string) $info['package_id']) : '';
        $name = isset($info['package_name']) ? trim((string) $info['package_name']) : '';
        $wanted = trim($requested);

        // CWP accepts either form, so either matching means the change landed.
        if (strcasecmp($id, $wanted) === 0 || strcasecmp($name, $wanted) === 0) {
            return;
        }

        throw CwpException::api(sprintf(
            'the package did not change — CWP still reports %s (#%s) after a request for %s',
            $name !== '' ? $name : 'an unnamed package',
            $id !== '' ? $id : '?',
            $wanted
        ));
    }

    /**
     * The account_info block from accountdetail, or null when absent.
     *
     * @return array<string,mixed>|null
     *
     * @throws CwpException
     */
    private function accountInfo(string $username)
    {
        $payload = CwpClient::payload(
            $this->client->call('accountdetail', 'list', ['user' => $username])
        );

        if (!is_array($payload)) {
            return null;
        }

        if (isset($payload['account_info']) && is_array($payload['account_info'])) {
            return $payload['account_info'];
        }

        return $payload;
    }

    /**
     * Per-account detail for the admin service tab.
     *
     * @return array<string,mixed>
     *
     * @throws CwpException
     */
    public function detail(): array
    {
        $response = $this->client->call('accountdetail', 'list', [
            'user' => $this->resolveUsername(),
        ]);

        $payload = CwpClient::payload($response);

        return is_array($payload) ? $payload : $response;
    }

    /**
     * Every account on the server. Backs UsageUpdate and ListAccounts.
     *
     * @return array<int,array<string,mixed>>
     *
     * @throws CwpException
     */
    public function listAll(): array
    {
        return CwpClient::rows($this->client->call('account', 'list'));
    }

    /**
     * The username of an existing account, used verbatim.
     *
     * Normalising here would re-address accounts created before this version, or by an
     * admin who chose a longer name.
     *
     * @throws CwpException
     */
    public function resolveUsername(): string
    {
        $username = trim((string) $this->param('username'));

        if ($username === '') {
            throw CwpException::config('the service has no username');
        }

        return $username;
    }

    /**
     * The username for a new account, corrected to CWP's rules and written back to the
     * service so WHMCS and CWP agree.
     *
     * @throws CwpException
     */
    public function provisionUsername(): string
    {
        $raw = trim((string) $this->param('username'));
        $normalised = self::normaliseUsername($raw, $this->usernameMaxLength());

        if ($normalised === '') {
            throw CwpException::config(
                'the service has no usable username (got ' . var_export($raw, true) . ')'
            );
        }

        if ($normalised !== $raw) {
            $this->persistUsername($normalised);
        }

        return $normalised;
    }

    /** Lowercase, alphanumeric, must start with a letter, length-capped. */
    public static function normaliseUsername(string $raw, int $maxLength): string
    {
        $username = strtolower(trim($raw));
        $username = preg_replace('/[^a-z0-9]/', '', $username);

        if (!is_string($username) || $username === '') {
            return '';
        }

        // CWP rejects a leading digit.
        $username = ltrim($username, '0123456789');

        if ($maxLength > 0) {
            $username = substr($username, 0, $maxLength);
        }

        return $username;
    }

    private function persistUsername(string $username): void
    {
        $serviceId = (int) $this->param('serviceid');

        if ($serviceId <= 0 || !class_exists('\WHMCS\Database\Capsule')) {
            return;
        }

        try {
            \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->update(['username' => $username]);
        } catch (\Exception $e) {
            // Non-fatal: the caller proceeds with the corrected name regardless.
            if (function_exists('logModuleCall')) {
                logModuleCall('cwp7', 'persistUsername', $serviceId, $e->getMessage());
            }
        }
    }

    private function usernameMaxLength(): int
    {
        $configured = trim((string) $this->param('configoption5', ''));

        if ($configured === '' || !ctype_digit($configured)) {
            return self::DEFAULT_USERNAME_MAX;
        }

        return (int) $configured;
    }

    /**
     * Passed through verbatim, so the product option may hold either a package ID or a
     * package name depending on what the server accepts.
     *
     * @throws CwpException
     */
    private function packageValue(): string
    {
        $package = trim((string) $this->param('configoption1'));

        if ($package !== '') {
            return $package;
        }

        // Blank means "named after the product", matching what package pushing creates.
        $product = $this->productName();

        if ($product !== '') {
            return $product;
        }

        throw CwpException::config(
            'no CWP package set on this product — set it in Products/Services -> Module Settings'
        );
    }

    /**
     * The WHMCS product's name, used as the package name when none is set explicitly.
     *
     * Read from tblproducts rather than from $params['model'], whose shape is not part
     * of the module API.
     */
    private function productName(): string
    {
        $productId = (int) $this->param('packageid', $this->param('pid', 0));

        if ($productId <= 0 || !class_exists('\WHMCS\Database\Capsule')) {
            return '';
        }

        try {
            $row = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', $productId)
                ->first(['name']);
        } catch (\Exception $e) {
            return '';
        }

        if ($row === null) {
            return '';
        }

        $row = (array) $row;

        return trim((string) $row['name']);
    }

    private function clientEmail(): string
    {
        $details = $this->param('clientsdetails');

        if (is_array($details) && isset($details['email'])) {
            return (string) $details['email'];
        }

        return '';
    }

    /**
     * @param mixed $default
     *
     * @return mixed
     */
    private function param(string $key, $default = '')
    {
        return isset($this->params[$key]) ? $this->params[$key] : $default;
    }
}
