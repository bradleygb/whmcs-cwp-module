<?php
/**
 * CWP Hosting Module for WHMCS — panel shortcuts offered in the client area.
 *
 * The allow-list of CWP panel modules a customer may be sent to, and the labels shown
 * for them. Nothing outside this list is reachable through single sign-on.
 *
 * @package cwp7
 * @version 2.2.0
 * @author  Booysen Logistics <bradley@booysenlogistics.co.za>
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

declare(strict_types=1);

namespace Cwp7\Actions;

final class PanelApp
{
    /**
     * key => [module, label, icon]
     *
     * `module` is the name CWP's own panel menu uses in `?module=`. `icon` is a Font
     * Awesome name that is spelled the same in versions 4 and 5, since WHMCS templates
     * ship different ones; the label carries the meaning if neither is loaded.
     *
     * Confined to features present on every CWP installation. Paid add-ons — Softaculous,
     * SitePad, SpamExperts, Cloudflare and the rest — are deliberately absent, since a
     * tile for something the server does not have is worse than no tile.
     */
    const APPS = [
        'email' => ['module' => 'email_accounts', 'label' => 'Email Accounts', 'icon' => 'envelope'],
        'forwarders' => ['module' => 'forwarders_email', 'label' => 'Forwarders', 'icon' => 'share'],
        'autoresponders' => ['module' => 'mail_autoreply', 'label' => 'Autoresponders', 'icon' => 'reply'],
        'filters' => ['module' => 'email_filters', 'label' => 'Email Filters', 'icon' => 'filter'],
        'ftp' => ['module' => 'ftp_accounts', 'label' => 'FTP Accounts', 'icon' => 'folder'],
        'backups' => ['module' => 'backups', 'label' => 'Backups', 'icon' => 'download'],
        'disk' => ['module' => 'disk_usage', 'label' => 'Disk Usage', 'icon' => 'folder-open'],
        'cron' => ['module' => 'crontab', 'label' => 'Cron Jobs', 'icon' => 'calendar'],
        'mysql' => ['module' => 'mysql_manager', 'label' => 'MySQL Databases', 'icon' => 'database'],
        'phpmyadmin' => ['module' => 'pma', 'label' => 'phpMyAdmin', 'icon' => 'table'],
        'domains' => ['module' => 'domains', 'label' => 'Domains', 'icon' => 'globe'],
        'subdomains' => ['module' => 'subdomains', 'label' => 'Subdomains', 'icon' => 'sitemap'],
        'dns' => ['module' => 'dns_zone_editor', 'label' => 'DNS Zone Editor', 'icon' => 'list'],
        'ssl' => ['module' => 'sslwizard', 'label' => 'SSL Certificates', 'icon' => 'lock'],
        'errorlog' => ['module' => 'error_log', 'label' => 'Error Log', 'icon' => 'file'],
        'statistics' => ['module' => 'statistics', 'label' => 'Statistics', 'icon' => 'signal'],
    ];

    /**
     * The CWP panel module for a shortcut key, or '' when the key is not offered.
     *
     * The empty string is the safe answer rather than an error: single sign-on then
     * lands on the panel dashboard, which is where it went before shortcuts existed.
     * Without this check the request parameter would redirect to any panel URL a caller
     * cared to name.
     */
    public static function moduleFor(string $key): string
    {
        $key = strtolower(trim($key));

        return isset(self::APPS[$key]) ? self::APPS[$key]['module'] : '';
    }

    /**
     * Every shortcut, in display order.
     *
     * @return array<int,array{key:string, label:string, icon:string}>
     */
    public static function all(): array
    {
        $tiles = [];

        foreach (self::APPS as $key => $app) {
            $tiles[] = [
                'key' => $key,
                'label' => $app['label'],
                'icon' => $app['icon'],
            ];
        }

        return $tiles;
    }
}
