<?php
/**
 * CWP Hosting Module for WHMCS — optional hooks.
 *
 * Applies a package change to CWP when an admin changes a service's Product/Service and
 * saves, rather than requiring the Change Package button afterwards.
 *
 * Off unless 'apply_package_on_service_save' is true in config.php. Reconfiguring a live
 * hosting account because someone corrected a mis-assigned product record is a surprise,
 * so it is opt-in.
 *
 * WHMCS registers a module's hook file when the module is activated. On an existing
 * install, open any cwp7 product's Module Settings tab and press Save Changes once for
 * this file to be picked up.
 *
 * @package cwp7
 * @version 2.1.0
 * @author  Booysen Logistics <bradley@booysenlogistics.co.za>
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/CwpException.php';
require_once __DIR__ . '/lib/CwpClient.php';
require_once __DIR__ . '/lib/Actions/Account.php';
require_once __DIR__ . '/lib/Actions/Package.php';

/** Service states whose CWP account is gone, so a package change is meaningless. */
const CWP7_HOOK_DEAD_STATUSES = ['Terminated', 'Cancelled', 'Fraud'];

/**
 * The product a service is on, plus the module it uses, or null if unavailable.
 *
 * @return array{packageid:int, servertype:string, status:string}|null
 */
function cwp7_hookServiceSnapshot($serviceId)
{
    $serviceId = (int) $serviceId;

    if ($serviceId <= 0 || !class_exists('\WHMCS\Database\Capsule')) {
        return null;
    }

    try {
        $row = \WHMCS\Database\Capsule::table('tblhosting')
            ->leftJoin('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
            ->where('tblhosting.id', $serviceId)
            ->first(['tblhosting.packageid', 'tblhosting.domainstatus', 'tblproducts.servertype']);
    } catch (\Exception $e) {
        return null;
    }

    if ($row === null) {
        return null;
    }

    $row = (array) $row;

    return [
        'packageid' => (int) $row['packageid'],
        'servertype' => (string) $row['servertype'],
        'status' => (string) $row['domainstatus'],
    ];
}

/**
 * Is the feature switched on for this server's configuration?
 *
 * Cached: these hooks run on every admin page load, and re-reading the file each time
 * would be three stat-and-include cycles per request for a value that cannot change
 * mid-request.
 */
function cwp7_hookEnabled()
{
    static $enabled = null;

    if ($enabled === null) {
        $path = __DIR__ . '/config.php';
        $config = is_readable($path) ? require $path : [];

        $enabled = is_array($config)
            && isset($config['defaults']['apply_package_on_service_save'])
            && $config['defaults']['apply_package_on_service_save'] === true;
    }

    return $enabled;
}

/** Is package pushing switched on? Cached for the same reason as cwp7_hookEnabled(). */
function cwp7_hookPushPackagesEnabled()
{
    static $enabled = null;

    if ($enabled === null) {
        $path = __DIR__ . '/config.php';
        $config = is_readable($path) ? require $path : [];

        $enabled = is_array($config)
            && isset($config['defaults']['push_packages_on_product_save'])
            && $config['defaults']['push_packages_on_product_save'] === true;
    }

    return $enabled;
}

/**
 * Every CWP server a product could provision onto.
 *
 * A product targets a server group, so a package has to exist on each member — CWP
 * assigns its own local id per server, which is why the package name is the key rather
 * than an id.
 *
 * @return array<int,array{id:int,hostname:string,ip:string,port:int,accesshash:string}>
 */
function cwp7_hookCwpServers($groupId)
{
    if (!class_exists('\WHMCS\Database\Capsule')) {
        return [];
    }

    try {
        $query = \WHMCS\Database\Capsule::table('tblservers')
            ->where('tblservers.type', 'cwp7')
            ->where('tblservers.disabled', 0);

        // Group 0 means the product names no group, so every CWP server is a candidate.
        if ((int) $groupId > 0) {
            $query->join('tblservergroupsrel', 'tblservergroupsrel.serverid', '=', 'tblservers.id')
                ->where('tblservergroupsrel.groupid', (int) $groupId);
        }

        $rows = $query->get([
            'tblservers.id',
            'tblservers.hostname',
            'tblservers.ipaddress',
            'tblservers.port',
            'tblservers.accesshash',
        ]);
    } catch (\Exception $e) {
        return [];
    }

    $servers = [];

    foreach ($rows as $row) {
        $row = (array) $row;

        $servers[] = [
            'id' => (int) $row['id'],
            'hostname' => (string) $row['hostname'],
            'ip' => (string) $row['ipaddress'],
            'port' => (int) $row['port'],
            'accesshash' => (string) $row['accesshash'],
        ];
    }

    return $servers;
}

/**
 * A server's API key as stored in tblservers.accesshash.
 *
 * WHMCS does not encrypt this field on every install. Where it holds the key verbatim,
 * running it through DecryptPassword does not fail — it "succeeds" and returns binary
 * noise, which CWP then rejects as "No special characters are allowed!".
 *
 * CWP keys are alphanumeric, so a value that already looks like one is used as-is and
 * only anything else is decrypted.
 */
function cwp7_hookServerKey($accessHash)
{
    $accessHash = trim((string) $accessHash);

    if ($accessHash === '') {
        return '';
    }

    if (preg_match('/^[A-Za-z0-9]+$/', $accessHash) === 1) {
        return $accessHash;
    }

    if (!function_exists('localAPI')) {
        return '';
    }

    $result = localAPI('DecryptPassword', ['password2' => $accessHash]);

    return isset($result['password']) ? (string) $result['password'] : '';
}

/**
 * Should the Change Package button be hidden on this page?
 *
 * Separated from the hook so the decision is testable.
 *
 * @param string $page       WHMCS's filename for the current admin page
 * @param string $serverType The module the viewed service uses
 */
function cwp7_hookShouldHideButton($page, $serverType)
{
    // Tolerant of an unexpected or absent filename: only bail when we positively know
    // this is some other page.
    if ($page !== '' && $page !== 'clientsservices') {
        return false;
    }

    return $serverType === 'cwp7';
}

/**
 * Hide WHMCS's Change Package button once the dropdown does the same job.
 *
 * The button is hidden rather than removed: WHMCS draws it because
 * cwp7_ChangePackage() exists, and that function must stay — a paid upgrade or
 * downgrade order calls it, and dropping it would bill a customer for a package the
 * server never applies.
 *
 * Gated on the setting, so turning the dropdown behaviour off brings the button back and
 * never leaves a service with no way to change its package. If a future WHMCS release
 * renames the element, the rule simply stops matching and the button reappears.
 */
add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    if (!cwp7_hookEnabled()) {
        return '';
    }

    $page = isset($vars['filename']) ? (string) $vars['filename'] : '';
    $serviceId = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;

    if ($serviceId <= 0) {
        return '';
    }

    $service = cwp7_hookServiceSnapshot($serviceId);

    if ($service === null || !cwp7_hookShouldHideButton($page, $service['servertype'])) {
        return '';
    }

    return '<style>#btnChange_Package{display:none !important;}</style>';
});

/**
 * Remember which product the service was on before the save.
 */
add_hook('PreServiceEdit', 1, function ($vars) {
    if (!cwp7_hookEnabled()) {
        return;
    }

    $serviceId = isset($vars['serviceid']) ? (int) $vars['serviceid'] : 0;
    $before = cwp7_hookServiceSnapshot($serviceId);

    if ($before !== null) {
        $GLOBALS['cwp7_package_before'][$serviceId] = $before['packageid'];
    }
});

/**
 * If the product changed, push the new package to CWP.
 *
 * Routed through WHMCS's own ModuleChangePackage API so the module receives parameters
 * built exactly as WHMCS builds them everywhere else.
 */
add_hook('ServiceEdit', 1, function ($vars) {
    if (!cwp7_hookEnabled()) {
        return;
    }

    $serviceId = isset($vars['serviceid']) ? (int) $vars['serviceid'] : 0;

    if ($serviceId <= 0 || !isset($GLOBALS['cwp7_package_before'][$serviceId])) {
        return;
    }

    $before = (int) $GLOBALS['cwp7_package_before'][$serviceId];
    unset($GLOBALS['cwp7_package_before'][$serviceId]);

    $after = cwp7_hookServiceSnapshot($serviceId);

    if ($after === null || $after['servertype'] !== 'cwp7') {
        return;
    }

    if ($after['packageid'] === $before) {
        return;
    }

    if (in_array($after['status'], CWP7_HOOK_DEAD_STATUSES, true)) {
        return;
    }

    if (!function_exists('localAPI')) {
        return;
    }

    $result = localAPI('ModuleChangePackage', ['serviceid' => $serviceId]);

    if (function_exists('logModuleCall')) {
        logModuleCall(
            'cwp7',
            'package change on service save',
            ['serviceid' => $serviceId, 'from' => $before, 'to' => $after['packageid']],
            isset($result['result']) ? $result['result'] : 'no result',
            isset($result['message']) ? $result['message'] : ''
        );
    }
});

/**
 * Push a product's package definition to every CWP server it can provision onto.
 *
 * Saves creating the same package by hand on each server. The CWP Package field on the
 * product is the package name — CWP's update endpoint identifies packages by name, and a
 * name is the only identifier that stays stable across servers, since each assigns its
 * own local id.
 *
 * Opt-in via 'push_packages_on_product_save'. Enabling it makes WHMCS the source of
 * truth: a package edited in CWP is overwritten on the next product save.
 */
add_hook('ProductEdit', 1, function ($vars) {
    if (!cwp7_hookPushPackagesEnabled()) {
        return;
    }

    if (!isset($vars['servertype']) || $vars['servertype'] !== 'cwp7') {
        return;
    }

    // Blank means "name it after the product" — with pushing enabled WHMCS owns the
    // package name, so there is nothing to be gained from typing it twice.
    $name = isset($vars['configoption1']) ? trim((string) $vars['configoption1']) : '';

    if ($name === '' && isset($vars['name'])) {
        $name = trim((string) $vars['name']);
    }

    try {
        $definition = \Cwp7\Actions\Package::definition($name, $vars);
    } catch (\Cwp7\CwpException $e) {
        // A product with no package name or no disk quota simply is not ready to push.
        if (function_exists('logModuleCall')) {
            logModuleCall('cwp7', 'package push skipped', ['product' => $name], $e->getMessage());
        }

        return;
    }

    $servers = cwp7_hookCwpServers(isset($vars['servergroup']) ? (int) $vars['servergroup'] : 0);

    foreach ($servers as $server) {
        $outcome = 'failed';
        $detail = '';

        try {
            $client = \Cwp7\CwpClient::fromParams([
                'serverhostname' => $server['hostname'],
                'serverip' => $server['ip'],
                'serverport' => $server['port'],
                'serveraccesshash' => cwp7_hookServerKey($server['accesshash']),
            ]);

            $outcome = \Cwp7\Actions\Package::push($client, $definition);
        } catch (\Cwp7\CwpException $e) {
            $detail = $e->getMessage();
        } catch (\Throwable $e) {
            $detail = $e->getMessage();
        }

        // One unreachable server must not stop the others.
        if (function_exists('logModuleCall')) {
            logModuleCall(
                'cwp7',
                'package ' . $outcome,
                ['server' => $server['id'], 'host' => $server['hostname'], 'package' => $name],
                $detail === '' ? $definition : $detail
            );
        }
    }
});
