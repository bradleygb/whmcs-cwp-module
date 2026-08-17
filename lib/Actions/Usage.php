<?php
/**
 * CWP Hosting Module for WHMCS — disk and bandwidth import.
 *
 * @package cwp7
 * @version 2.0.3
 * @author  Booysen Logistics <bradley@booysenlogistics.co.za>
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

declare(strict_types=1);

namespace Cwp7\Actions;

use Cwp7\CwpClient;
use Cwp7\CwpException;

final class Usage
{
    /**
     * Response keys CWP has used for each figure, in lookup order. All are megabytes.
     *
     * `bandwidth` is last in the usage list on purpose: account/list uses it for
     * bandwidth consumed, but accountdetail uses the same name for the limit. The
     * specific names are checked first so the ambiguous one is only a fallback.
     */
    const FIELD_CANDIDATES = [
        'diskusage' => ['diskusage', 'diskused', 'space_usage', 'disk_used', 'disk_usage', 'used_disk'],
        'disklimit' => ['disklimit', 'space_disk', 'disk_limit', 'disk_quota', 'quota'],
        'bwusage' => ['bwusage', 'bandwidth_used', 'bw_used', 'bandwidth'],
        'bwlimit' => ['bwlimit', 'bw_limit', 'bandwidth_limit'],
    ];

    /** Disk-usage keys on accountdetail, which is the accurate source. */
    const DETAIL_DISK_KEYS = ['space_usage', 'diskusage', 'disk_used'];

    /**
     * Pull the account roster and write the figures onto matching services.
     *
     * account/list supplies limits and bandwidth. Disk usage comes from accountdetail,
     * because account/list reports a placeholder rather than real consumption. The
     * detail call is made only for accounts that match a service on this server, so the
     * cost is proportional to services rather than to accounts on the box.
     *
     * @return array{seen:int, updated:int, skipped:int, detail_calls:int}
     *
     * @throws CwpException
     */
    public static function apply(CwpClient $client, int $serverId): array
    {
        if ($serverId <= 0) {
            throw CwpException::config('UsageUpdate ran without a server id');
        }

        if (!class_exists('\WHMCS\Database\Capsule')) {
            throw CwpException::config('WHMCS database layer is unavailable');
        }

        $rows = CwpClient::rows($client->call('account', 'list'));
        $services = self::servicesOnServer($serverId);
        $useDetail = (bool) $client->getOption('usage_detail_lookup', true);

        $seen = 0;
        $updated = 0;
        $skipped = 0;
        $detailCalls = 0;

        foreach ($rows as $row) {
            $seen++;

            $domain = strtolower(trim((string) self::pick($row, ['domain', 'main_domain', 'primary_domain'])));
            $username = trim((string) self::pick($row, ['username', 'user', 'account']));

            $serviceId = self::matchService($services, $domain, $username);

            // No WHMCS service for this account: skip before spending a detail call.
            if ($serviceId === null) {
                $skipped++;
                continue;
            }

            $diskUsage = self::numeric(self::pick($row, self::FIELD_CANDIDATES['diskusage']));

            if ($useDetail && $username !== '') {
                $detailCalls++;
                $accurate = self::detailDiskUsage($client, $username);

                if ($accurate !== null) {
                    $diskUsage = $accurate;
                }
            }

            $values = [
                'diskusage' => $diskUsage,
                'disklimit' => self::numeric(self::pick($row, self::FIELD_CANDIDATES['disklimit'])),
                'bwusage' => self::numeric(self::pick($row, self::FIELD_CANDIDATES['bwusage'])),
                'bwlimit' => self::numeric(self::pick($row, self::FIELD_CANDIDATES['bwlimit'])),
                'lastupdate' => \WHMCS\Database\Capsule::raw('now()'),
            ];

            try {
                // By primary key: one service per account, and a domain shared by two
                // services cannot have both rewritten.
                \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->update($values);
                $updated++;
            } catch (\Exception $e) {
                $skipped++;

                if (function_exists('logModuleCall')) {
                    logModuleCall(
                        'cwp7',
                        'UsageUpdate row failed',
                        ['server' => $serverId, 'service' => $serviceId, 'domain' => $domain],
                        $e->getMessage()
                    );
                }
            }
        }

        return [
            'seen' => $seen,
            'updated' => $updated,
            'skipped' => $skipped,
            'detail_calls' => $detailCalls,
        ];
    }

    /** Service states with no account on the server, so nothing to import. */
    const DEAD_STATUSES = ['Terminated', 'Cancelled', 'Fraud'];

    /**
     * Services on this server, indexed by domain and by username.
     *
     * One query, so matching an account costs nothing per row. Terminated and cancelled
     * services are excluded: their account is gone, and leaving them in would let a dead
     * service shadow a live one that reuses the same domain.
     *
     * @return array{domain:array<string,int>, username:array<string,int>}
     */
    private static function servicesOnServer(int $serverId): array
    {
        $map = ['domain' => [], 'username' => []];

        $rows = \WHMCS\Database\Capsule::table('tblhosting')
            ->where('server', $serverId)
            ->whereNotIn('domainstatus', self::DEAD_STATUSES)
            ->get(['id', 'domain', 'username']);

        foreach ($rows as $row) {
            $service = (array) $row;
            $id = (int) $service['id'];

            $domain = strtolower(trim((string) $service['domain']));
            if ($domain !== '' && !isset($map['domain'][$domain])) {
                $map['domain'][$domain] = $id;
            }

            $username = strtolower(trim((string) $service['username']));
            if ($username !== '' && !isset($map['username'][$username])) {
                $map['username'][$username] = $id;
            }
        }

        return $map;
    }

    /**
     * @param array{domain:array<string,int>, username:array<string,int>} $services
     *
     * @return int|null
     */
    public static function matchService(array $services, string $domain, string $username)
    {
        if ($domain !== '' && isset($services['domain'][$domain])) {
            return $services['domain'][$domain];
        }

        $username = strtolower(trim($username));

        if ($username !== '' && isset($services['username'][$username])) {
            return $services['username'][$username];
        }

        return null;
    }

    /**
     * Real disk usage for one account.
     *
     * A failure here returns null so the caller falls back to the roster figure — one
     * unreachable account must not abort the whole run.
     *
     * @return float|null
     */
    private static function detailDiskUsage(CwpClient $client, string $username)
    {
        try {
            $response = $client->call('accountdetail', 'list', ['user' => $username]);
        } catch (CwpException $e) {
            if (function_exists('logModuleCall')) {
                logModuleCall(
                    'cwp7',
                    'UsageUpdate detail failed',
                    ['user' => $username],
                    $e->getMessage()
                );
            }

            return null;
        }

        return self::diskUsageFrom(CwpClient::payload($response));
    }

    /**
     * Read disk usage out of an accountdetail payload, which nests the figures under
     * account_info.
     *
     * @param mixed $payload
     *
     * @return float|null
     */
    public static function diskUsageFrom($payload)
    {
        if (!is_array($payload)) {
            return null;
        }

        $info = (isset($payload['account_info']) && is_array($payload['account_info']))
            ? $payload['account_info']
            : $payload;

        $value = self::pick($info, self::DETAIL_DISK_KEYS);

        return $value === null ? null : self::numeric($value);
    }

    /**
     * First present, non-empty value among several candidate keys.
     *
     * @param array<string,mixed> $row
     * @param array<int,string>   $keys
     *
     * @return mixed
     */
    public static function pick(array $row, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && $row[$key] !== '' && $row[$key] !== null) {
                return $row[$key];
            }
        }

        return null;
    }

    /**
     * Coerce a CWP figure into a number WHMCS can store.
     *
     * CWP reports these in megabytes, which is what WHMCS stores, so no conversion is
     * applied. CWP uses -1 for "unlimited"; WHMCS reads 0 that way, and a negative
     * limit would otherwise render as a negative bar.
     *
     * @param mixed $value
     */
    public static function numeric($value): float
    {
        if (is_int($value) || is_float($value)) {
            return $value < 0 ? 0.0 : (float) $value;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);

        if (!is_string($clean) || $clean === '' || !is_numeric($clean)) {
            return 0.0;
        }

        return (float) $clean < 0 ? 0.0 : (float) $clean;
    }
}
