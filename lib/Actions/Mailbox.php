<?php
/**
 * CWP Hosting Module for WHMCS — email accounts.
 *
 * Drives CWP's /v1/email endpoint so mailboxes can be listed and managed from the client
 * area rather than the panel.
 *
 * READING is confirmed against a live server. WRITING is not: the field names CWP expects
 * on add, udp and del are taken from its conventions elsewhere and have never been read
 * out of its Interactive Documentation. They are gathered in FIELDS below so a correction
 * is one edit, and every write is gated behind `mailbox_management` in config.php, which
 * is off until the contract is confirmed. Guessing a contract has cost this module a
 * release once already — changepack takes an id and answers a bare Error to a name.
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

            $mailboxes[] = [
                'address' => strtolower(trim((string) $address)),
                'quota' => $quota === null ? null : (float) preg_replace('/[^0-9.\-]/', '', (string) $quota),
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
        $address = Validate::localPart($localPart) . '@' . Validate::ownedDomain($detail, $domain);

        $this->client->call(self::FUNCTION, 'add', $this->createFields($address, $domain, $password, $quota));

        return $address;
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
    public function createFields(string $address, string $domain, string $password, string $quota = ''): array
    {
        $quota = trim($quota);

        if ($quota !== '' && (!ctype_digit($quota) || (int) $quota < 1)) {
            throw CwpException::input('Enter a mailbox size in megabytes, or leave it blank.');
        }

        return [
            self::FIELDS['account'] => $this->account,
            self::FIELDS['address'] => $address,
            self::FIELDS['domain'] => $domain,
            self::FIELDS['password'] => Validate::password($password),
            self::FIELDS['quota'] => $quota === '' ? (string) self::DEFAULT_QUOTA : $quota,
        ];
    }

    /**
     * Change a mailbox password.
     *
     * @param array<int,array<string,mixed>> $existing this account's mailboxes
     *
     * @throws CwpException
     */
    public function changePassword(array $existing, string $address, string $password): void
    {
        $address = self::assertOwned($existing, $address);

        $this->client->call(self::FUNCTION, 'udp', [
            self::FIELDS['account'] => $this->account,
            self::FIELDS['address'] => $address,
            self::FIELDS['password'] => Validate::password($password),
        ]);
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
        $address = self::assertOwned($existing, $address);

        $this->client->call(self::FUNCTION, 'del', [
            self::FIELDS['account'] => $this->account,
            self::FIELDS['address'] => $address,
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
