<?php
/**
 * CWP Hosting Module for WHMCS — the client area dashboard.
 *
 * Turns one accountdetail response into everything the client area draws, so a customer
 * sees their account without opening the panel. A pure transform: it takes the decoded
 * payload and opens no socket, which is what lets the whole model be tested against a
 * captured response.
 *
 * @package cwp7
 * @version 2.3.0
 * @author  Booysen Logistics <bradley@booysenlogistics.co.za>
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

declare(strict_types=1);

namespace Cwp7\Actions;

final class Dashboard
{
    /**
     * The metered resources, in display order: [used key, limit key, label].
     *
     * Both are megabytes, which is what CWP reports throughout.
     */
    const METERS = [
        ['space_usage', 'space_disk', 'Disk Space'],
        ['bandwidth_used', 'bandwidth', 'Bandwidth'],
    ];

    /**
     * The counted allowances, in display order: [used key, limit key, label].
     */
    const ALLOWANCES = [
        ['email_accounts_used', 'email_accounts', 'Email Accounts'],
        ['ftp_accounts_used', 'ftp_accounts', 'FTP Accounts'],
        ['db_used', 'db_max', 'Databases'],
        ['sub_domains_used', 'sub_domains', 'Subdomains'],
        ['addons_domains_used', 'addons_domains', 'Addon Domains'],
    ];

    /**
     * The whole display model for one account.
     *
     * @param array<string,mixed> $detail accountdetail's decoded payload
     *
     * @return array<string,mixed>
     */
    public static function from(array $detail): array
    {
        $info = self::accountInfo($detail);

        $meters = [];

        foreach (self::METERS as $meter) {
            $meters[] = self::meter($info, $meter[0], $meter[1], $meter[2]);
        }

        $allowances = [];

        foreach (self::ALLOWANCES as $allowance) {
            $allowances[] = self::allowance($info, $allowance[0], $allowance[1], $allowance[2]);
        }

        return [
            'package' => trim((string) self::value($info, 'package_name')),
            'state' => trim((string) self::value($info, 'state')),
            'directory' => trim((string) self::value($info, 'directory')),
            'meters' => $meters,
            'allowances' => $allowances,
            'domains' => self::domains($detail),
            'subdomains' => self::subdomains($detail),
            'databases' => self::databases($detail),
        ];
    }

    /**
     * A resource measured in megabytes.
     *
     * @param array<string,mixed> $info
     *
     * @return array<string,mixed>
     */
    public static function meter(array $info, string $usedKey, string $limitKey, string $label): array
    {
        $row = self::gauge($info, $usedKey, $limitKey, $label);

        $row['text'] = self::describe(
            $row,
            self::formatMegabytes($row['used']),
            self::formatMegabytes($row['limit'])
        );

        return $row;
    }

    /**
     * A resource measured as a count.
     *
     * @param array<string,mixed> $info
     *
     * @return array<string,mixed>
     */
    public static function allowance(array $info, string $usedKey, string $limitKey, string $label): array
    {
        $row = self::gauge($info, $usedKey, $limitKey, $label);

        $row['text'] = self::describe(
            $row,
            number_format($row['used']),
            number_format($row['limit'])
        );

        return $row;
    }

    /**
     * The shared arithmetic behind both.
     *
     * Two values CWP uses that must not be confused. `-1` is unlimited. `0` is none
     * allowed — and an account can hold more than none anyway: a live account reports
     * `ftp_accounts: 0` with one in use, and `db_max: 0` with two databases. So `over`
     * exists to be stated in words, and `percent` is capped rather than drawn past the
     * end of a bar.
     *
     * @param array<string,mixed> $info
     *
     * @return array<string,mixed>
     */
    private static function gauge(array $info, string $usedKey, string $limitKey, string $label): array
    {
        $used = self::figure($info, $usedKey);
        $limit = self::figure($info, $limitKey);
        $unlimited = $limit < 0;
        $percent = null;

        if (!$unlimited && $limit > 0) {
            $percent = (int) min(100, round($used / $limit * 100));
        }

        return [
            'label' => $label,
            'used' => $used,
            'limit' => $unlimited ? 0.0 : $limit,
            'unlimited' => $unlimited,
            'none' => !$unlimited && $limit <= 0,
            'over' => !$unlimited && $used > $limit,
            'percent' => $percent,
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function describe(array $row, string $used, string $limit): string
    {
        if ($row['unlimited']) {
            return $used . ' used of unlimited';
        }

        if ($row['none']) {
            return $row['used'] > 0 ? $used . ' used, none included' : 'None included';
        }

        return $used . ' of ' . $limit;
    }

    /**
     * A figure as CWP reports it, sign intact.
     *
     * Usage::numeric() clamps negatives to zero because that is what WHMCS stores, which
     * would erase the difference between unlimited and none. Here the sign is the
     * meaning.
     *
     * @param array<string,mixed> $row
     */
    private static function figure(array $row, string $key): float
    {
        if (!isset($row[$key]) || $row[$key] === '' || $row[$key] === null) {
            return 0.0;
        }

        $value = $row[$key];

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return is_string($clean) && is_numeric($clean) ? (float) $clean : 0.0;
    }

    /**
     * Megabytes throughout, never scaled to gigabytes.
     *
     * CWP defines packages in megabytes and displays them that way, so a quota a
     * customer reads here matches the one their host set. Scaling by magnitude would
     * also let a used figure and its limit land in different units — "1.23 GB of
     * 1,000 MB" — which is how this first came out.
     */
    public static function formatMegabytes(float $mb): string
    {
        return number_format($mb) . ' MB';
    }

    /**
     * @param array<string,mixed> $detail
     *
     * @return array<int,array<string,string>>
     */
    public static function domains(array $detail): array
    {
        $rows = [];

        foreach (self::rows($detail, ['domains']) as $row) {
            if (!isset($row['domain'])) {
                continue;
            }

            $rows[] = [
                'domain' => strtolower(trim((string) $row['domain'])),
                'path' => isset($row['path']) ? (string) $row['path'] : '',
                'email' => isset($row['email']) ? (string) $row['email'] : '',
            ];
        }

        return $rows;
    }

    /**
     * Note `subdomins`: CWP's spelling, and the only one it currently sends. Both are
     * read so a corrected build does not silently empty this list.
     *
     * @param array<string,mixed> $detail
     *
     * @return array<int,array<string,string>>
     */
    public static function subdomains(array $detail): array
    {
        $rows = [];

        foreach (self::rows($detail, ['subdomins', 'subdomains']) as $row) {
            if (!isset($row['subdomain'], $row['domain'])) {
                continue;
            }

            $rows[] = [
                'name' => strtolower(trim((string) $row['subdomain'] . '.' . (string) $row['domain'])),
                'path' => isset($row['path']) ? (string) $row['path'] : '',
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $detail
     *
     * @return array<int,array<string,string>>
     */
    public static function databases(array $detail): array
    {
        $rows = [];

        foreach (self::rows($detail, ['databases']) as $row) {
            if (!isset($row['database'])) {
                continue;
            }

            $rows[] = [
                'database' => (string) $row['database'],
                'user' => isset($row['user']) ? (string) $row['user'] : '',
                'host' => isset($row['host']) ? (string) $row['host'] : '',
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $detail
     * @param array<int,string>   $keys
     *
     * @return array<int,array<string,mixed>>
     */
    private static function rows(array $detail, array $keys): array
    {
        $found = [];

        foreach ($keys as $key) {
            if (!isset($detail[$key]) || !is_array($detail[$key])) {
                continue;
            }

            foreach ($detail[$key] as $row) {
                if (is_array($row)) {
                    $found[] = $row;
                }
            }
        }

        return $found;
    }

    /**
     * @param array<string,mixed> $detail
     *
     * @return array<string,mixed>
     */
    private static function accountInfo(array $detail): array
    {
        if (isset($detail['account_info']) && is_array($detail['account_info'])) {
            return $detail['account_info'];
        }

        return $detail;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return mixed
     */
    private static function value(array $row, string $key)
    {
        return isset($row[$key]) ? $row[$key] : '';
    }
}
