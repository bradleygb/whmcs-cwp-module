<?php
/**
 * CWP Hosting Module for WHMCS — disk and bandwidth import.
 *
 * @package cwp7
 * @version 2.0.0
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
    /** Response keys CWP has used for each figure, in lookup order. */
    const FIELD_CANDIDATES = [
        'diskusage' => ['diskusage', 'disk_used', 'disk_usage', 'used_disk'],
        'disklimit' => ['disklimit', 'disk_limit', 'disk_quota', 'quota'],
        'bwusage' => ['bandwidth', 'bwusage', 'bw_used', 'bandwidth_used'],
        'bwlimit' => ['bwlimit', 'bw_limit', 'bandwidth_limit'],
    ];

    /**
     * Pull every account from the server and write the figures onto matching services.
     *
     * @return array{seen:int, updated:int, skipped:int}
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

        $seen = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $seen++;

            $domain = trim((string) self::pick($row, ['domain', 'main_domain', 'primary_domain']));
            $username = trim((string) self::pick($row, ['username', 'user', 'account']));

            if ($domain === '' && $username === '') {
                $skipped++;
                continue;
            }

            $values = [
                'diskusage' => self::numeric(self::pick($row, self::FIELD_CANDIDATES['diskusage'])),
                'disklimit' => self::numeric(self::pick($row, self::FIELD_CANDIDATES['disklimit'])),
                'bwusage' => self::numeric(self::pick($row, self::FIELD_CANDIDATES['bwusage'])),
                'bwlimit' => self::numeric(self::pick($row, self::FIELD_CANDIDATES['bwlimit'])),
                'lastupdate' => \WHMCS\Database\Capsule::raw('now()'),
            ];

            try {
                $query = \WHMCS\Database\Capsule::table('tblhosting')->where('server', $serverId);

                if ($domain !== '') {
                    $query->where('domain', $domain);
                } else {
                    $query->where('username', $username);
                }

                $affected = $query->update($values);

                if ($affected > 0) {
                    $updated += (int) $affected;
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $skipped++;

                if (function_exists('logModuleCall')) {
                    logModuleCall(
                        'cwp7',
                        'UsageUpdate row failed',
                        ['server' => $serverId, 'domain' => $domain, 'username' => $username],
                        $e->getMessage()
                    );
                }
            }
        }

        return ['seen' => $seen, 'updated' => $updated, 'skipped' => $skipped];
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
     * Coerce a CWP figure into a number WHMCS can store. Values pass through in the
     * units CWP reports them in; no conversion is applied.
     *
     * @param mixed $value
     */
    public static function numeric($value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);

        if (!is_string($clean) || $clean === '' || !is_numeric($clean)) {
            return 0.0;
        }

        return (float) $clean;
    }
}
