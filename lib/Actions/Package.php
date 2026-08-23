<?php
/**
 * CWP Hosting Module for WHMCS — package definitions.
 *
 * Pushes a WHMCS product's limits to CWP as a package, so packages need not be created
 * by hand on every server.
 *
 * @package cwp7
 * @version 2.1.1
 * @author  Booysen Logistics <bradley@booysenlogistics.co.za>
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

declare(strict_types=1);

namespace Cwp7\Actions;

use Cwp7\CwpClient;
use Cwp7\CwpException;

final class Package
{
    /**
     * CWP package field to product config option slot.
     *
     * Slots 1-5 are the provisioning settings and predate this feature, so the package
     * definition starts at 6. Fixed for the same reason as cwp7_ConfigOptions(): WHMCS
     * stores these by position.
     */
    const FIELD_SLOTS = [
        'disk_quota' => 6,
        'bandwidth' => 7,
        'ftp_accounts' => 8,
        'email_accounts' => 9,
        'email_lists' => 10,
        'databases' => 11,
        'sub_domains' => 12,
        'parked_domains' => 13,
        'addons_domains' => 14,
        'hourly_emails' => 15,
    ];

    /**
     * Build the CWP package fields from a product's config options.
     *
     * Empty options are omitted rather than sent as zero: on CWP an absent limit is the
     * package default, while zero means none allowed.
     *
     * @param array<string,mixed> $options configoption1..24 as WHMCS supplies them
     *
     * @return array<string,string>
     *
     * @throws CwpException
     */
    public static function definition(string $name, array $options): array
    {
        $name = trim($name);

        if ($name === '') {
            throw CwpException::config(
                'the product has no CWP Package name, so there is nothing to create on the server'
            );
        }

        $fields = ['package_name' => $name];

        foreach (self::FIELD_SLOTS as $field => $slot) {
            $value = isset($options['configoption' . $slot])
                ? trim((string) $options['configoption' . $slot])
                : '';

            if ($value !== '') {
                $fields[$field] = $value;
            }
        }

        // CWP refuses with "You must specify the disk size", which does not say which
        // product it meant.
        if (!isset($fields['disk_quota'])) {
            throw CwpException::config(
                'package "' . $name . '" has no Disk Quota set, and CWP requires one'
            );
        }

        return $fields;
    }

    /**
     * Create the package, or update it if the server already has one by that name.
     *
     * The choice is made from the package list rather than from CWP's error text, which
     * varies by version and locale. `udp` identifies a package by name — there is no id
     * field — so the name is the key throughout.
     *
     * @param array<string,string> $definition
     *
     * @return string 'created' or 'updated'
     *
     * @throws CwpException
     */
    public static function push(CwpClient $client, array $definition): string
    {
        $existing = CwpClient::rows($client->call('packages', 'list'));

        if (self::has($existing, $definition['package_name'])) {
            $client->call('packages', 'udp', $definition);

            return 'updated';
        }

        $client->call('packages', 'add', $definition);

        return 'created';
    }

    /**
     * Does the server already carry a package with this name?
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public static function has(array $rows, string $name): bool
    {
        $name = trim($name);

        foreach (Account::packageIdentifiers($rows)['names'] as $known) {
            if (strcasecmp($known, $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
