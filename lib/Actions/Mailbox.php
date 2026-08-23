<?php
/**
 * CWP Hosting Module for WHMCS — email accounts.
 *
 * Drives CWP's /v1/email endpoint so mailboxes can be listed and managed from the client
 * area rather than the panel.
 *
 * The contract here was established by observation, not documentation — CWP's Interactive
 * Documentation for this endpoint has never been available to us. Reading works. Writing
 * is confirmed as far as one live creation proved: `address` carries the local part and
 * CWP appends the domain itself. The remaining field names in FIELDS are still taken from
 * CWP's conventions elsewhere, which is why writes stay behind `mailbox_management` in
 * config.php. Correcting any of them is one edit to that map.
 *
 * @package cwp7
 * @version 2.4.0
 * @author  Booysen Logistics <bradley@booysenlogistics.co.za>
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

declare(strict_types=1);

namespace Cwp7\Actions;

use Cwp7\CwpClient;
use Cwp7\CwpException;
use Cwp7\Validate;

final class Mailbox
{
    /** The CWP function this endpoint lives under, as the URL path segment. */
    const FUNCTION = 'email';

    /**
     * The names CWP is expected to use on the wire.
     *
     * **Unconfirmed.** Correct these against Interactive Documentation before turning
     * `mailbox_management` on. `account` is the hosting account the mailbox belongs to,
     * which every CWP endpoint calls `user`; the rest follow the same style CWP uses on
     * account/add.
     *
     * @var array<string,string>
     */
    const FIELDS = [
        'account'  => 'user',
        'address'  => 'email',
        'domain'   => 'domain',
        'password' => 'pass',
        'quota'    => 'quota',
    ];

    /**
     * `address` carries the part before the @, never the whole address.
     *
     * Confirmed live on 23 August 2026: sent `test@example.co.za` with
     * `domain=example.co.za`, CWP dropped the @ and appended the domain to what was
     * left, producing `testexample.co.za@example.co.za`. It builds the address itself.
     */
    const ADDRESS_IS_LOCAL_PART = true;

    /** Quota in megabytes given to a new mailbox when the customer names none. */
    const DEFAULT_QUOTA = 1024;

    /** @var CwpClient */
    private $client;

    /** @var string The hosting account, from WHMCS — never from the request. */
    private $account;

    public function __construct(CwpClient $client, string $account)
    {
        $this->client = $client;
        $this->account = $account;
    }

    /**
     * Every mailbox on the account.
     *
     * @return array<int,array<string,mixed>>
     *
     * @throws CwpException
     */
    public function all(): array
    {
        return self::rows(
            CwpClient::rows($this->client->call(self::FUNCTION, 'list', [
                self::FIELDS['account'] => $this->account,
            ]))
        );
    }

    /**
     * Reduce whatever shape CWP returns into what the client area draws.
     *
     * CWP names the same thing differently between endpoints, so each column is read
     * from a list of candidates the way Usage::pick() does. Separated from the call so
     * it can be tested against a captured response.
     *
     * @param array<int,mixed> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    public static function rows(array $rows): array
    {
        $mailboxes = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $address = Usage::pick($row, ['email', 'email_account', 'address', 'user', 'username']);

            if ($address === null) {
                continue;
            }

            $quota = Usage::pick($row, ['quota', 'quota_mb', 'disk_quota', 'limit']);
            $used = Usage::pick($row, ['used', 'usage', 'disk_used', 'space_usage']);

            $quota = $quota === null ? null : (float) preg_replace('/[^0-9.\-]/', '', (string) $quota);

            $mailboxes[] = [
                'address' => strtolower(trim((string) $address)),
                // CWP writes 0 and -1 alike for "no limit", as it does on packages.
                'quota' => ($quota === null || $quota <= 0) ? null : $quota,
                'used' => $used === null ? null : (float) preg_replace('/[^0-9.\-]/', '', (string) $used),
            ];
        }

        usort($mailboxes, function (array $a, array $b) {
            return strcmp($a['address'], $b['address']);
        });

        return $mailboxes;
    }

    /**
     * Create a mailbox.
     *
     * $localPart and $domain come from the customer and are validated here; $detail is
     * this account's own accountdetail payload, which decides whether the domain is one
     * they may use at all.
     *
     * @param array<string,mixed> $detail
     *
     * @throws CwpException
     */
    public function create(array $detail, string $localPart, string $domain, string $password, string $quota = ''): string
    {
        $localPart = Validate::localPart($localPart);
        $domain = Validate::ownedDomain($detail, $domain);

        $this->client->call(self::FUNCTION, 'add', $this->createFields($localPart, $domain, $password, $quota));

        return $localPart . '@' . $domain;
    }

    /**
     * The POST fields for email/add.
     *
     * Public and free of side effects so the contract can be asserted in tests rather
     * than discovered from a failed live call.
     *
     * @return array<string,string>
     *
     * @throws CwpException
     */
    public function createFields(string $localPart, string $domain, string $password, string $quota = ''): array
    {
        return [
            self::FIELDS['account'] => $this->account,
            self::FIELDS['address'] => $localPart,
            self::FIELDS['domain'] => $domain,
            self::FIELDS['password'] => Validate::password($password),
            self::FIELDS['quota'] => self::quota($quota, (string) self::DEFAULT_QUOTA),
        ];
    }

    /**
     * A mailbox size in megabytes.
     *
     * @throws CwpException
     */
    public static function quota(string $quota, string $default = ''): string
    {
        $quota = trim($quota);

        if ($quota === '') {
            return $default;
        }

        if (!ctype_digit($quota) || (int) $quota < 1) {
            throw CwpException::input('Enter a mailbox size in megabytes, or leave it blank.');
        }

        return $quota;
    }

    /**
     * Split a stored address back into the parts CWP wants on the wire.
     *
     * @return array{local:string, domain:string}
     *
     * @throws CwpException
     */
    public static function split(string $address): array
    {
        $at = strrpos($address, '@');

        if ($at === false || $at === 0 || $at === strlen($address) - 1) {
            throw CwpException::input('That mailbox address is not one this page can work with.');
        }

        return [
            'local' => substr($address, 0, $at),
            'domain' => substr($address, $at + 1),
        ];
    }

    /**
     * Change a mailbox's password, its size, or both.
     *
     * A blank password leaves the password alone, and a blank size leaves the size
     * alone; asking for neither is refused rather than sent as an empty update.
     *
     * @param array<int,array<string,mixed>> $existing this account's mailboxes
     *
     * @throws CwpException
     */
    public function update(array $existing, string $address, string $password, string $quota): void
    {
        $this->client->call(
            self::FUNCTION,
            'udp',
            $this->updateFields(self::assertOwned($existing, $address), $password, $quota)
        );
    }

    /**
     * The POST fields for email/udp.
     *
     * @return array<string,string>
     *
     * @throws CwpException
     */
    public function updateFields(string $address, string $password, string $quota): array
    {
        $parts = self::split($address);

        $fields = [
            self::FIELDS['account'] => $this->account,
            self::FIELDS['address'] => $parts['local'],
            self::FIELDS['domain'] => $parts['domain'],
        ];

        if (trim($password) !== '') {
            $fields[self::FIELDS['password']] = Validate::password($password);
        }

        $quota = self::quota($quota);

        if ($quota !== '') {
            $fields[self::FIELDS['quota']] = $quota;
        }

        if (count($fields) === 3) {
            throw CwpException::input('Enter a new password or a new size.');
        }

        return $fields;
    }

    /**
     * Delete a mailbox.
     *
     * @param array<int,array<string,mixed>> $existing this account's mailboxes
     *
     * @throws CwpException
     */
    public function delete(array $existing, string $address): void
    {
        $parts = self::split(self::assertOwned($existing, $address));

        $this->client->call(self::FUNCTION, 'del', [
            self::FIELDS['account'] => $this->account,
            self::FIELDS['address'] => $parts['local'],
            self::FIELDS['domain'] => $parts['domain'],
        ]);
    }

    /**
     * Refuse an address that is not already on this account.
     *
     * The account is fixed by WHMCS, but the address is not — and the key behind it
     * reaches every mailbox on the server. Checking the address against what the account
     * actually holds is what stops a request naming somebody else's.
     *
     * @param array<int,array<string,mixed>> $existing
     *
     * @throws CwpException
     */
    public static function assertOwned(array $existing, string $address): string
    {
        $address = strtolower(trim($address));

        if ($address === '') {
            throw CwpException::input('Choose a mailbox.');
        }

        foreach ($existing as $mailbox) {
            if (isset($mailbox['address']) && $mailbox['address'] === $address) {
                return $address;
            }
        }

        // Says nothing about whether the mailbox exists elsewhere on the server.
        throw CwpException::input('That mailbox is not on this hosting account.');
    }
}
