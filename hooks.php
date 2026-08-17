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
 * @version 2.0.3
 * @author  Booysen Logistics <bradley@booysenlogistics.co.za>
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/CwpException.php';
require_once __DIR__ . '/lib/CwpClient.php';

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

/** Is the feature switched on for this server's configuration? */
function cwp7_hookEnabled()
{
    $path = __DIR__ . '/config.php';
    $config = is_readable($path) ? require $path : [];

    return is_array($config)
        && isset($config['defaults']['apply_package_on_service_save'])
        && $config['defaults']['apply_package_on_service_save'] === true;
}

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
