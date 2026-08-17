<?php
/**
 * CWP Hosting Module for WHMCS — optional server-scoped settings.
 *
 * Copy to config.php to use. Without it the module runs on the defaults below, so most
 * installations need no config.php at all. Keeping your copy under a different name to
 * this sample means module upgrades never overwrite it.
 *
 * Credentials do not belong here. The API key is the server entry's Access Hash, which
 * WHMCS stores encrypted.
 *
 * @package cwp7
 * @version 2.0.2
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

return [

    'defaults' => [

        /** CWP external API port. Overridden by the port on the server entry, if set. */
        'api_port' => 2304,

        /** CWP end-user panel port (2082 plain, 2083 SSL). */
        'panel_port' => 2083,

        /** CWP admin panel port (2030 plain, 2031 SSL; 2086/2087 are the alternates). */
        'admin_port' => 2031,

        /**
         * Verify the CWP TLS certificate.
         *
         * This connection carries an API key with administrative scope over every
         * account on the server. With verification off, anything able to intercept it
         * can present its own certificate and take that key.
         *
         * If CWP serves a certificate from a public CA on the API port, this works as
         * shipped. If it serves a self-signed certificate, export it and set
         * 'ca_bundle' rather than turning this off.
         */
        'verify_tls' => true,

        /**
         * Absolute path to a CA bundle or a pinned self-signed certificate (PEM).
         * null uses the system CA store.
         */
        'ca_bundle' => null,

        /** Seconds to establish the connection. */
        'connect_timeout' => 5,

        /** Seconds for a read (action=list). Some of these run during a page render. */
        'timeout' => 20,

        /**
         * Seconds for anything that changes the server: create, suspend, terminate,
         * package and password changes.
         *
         * Creating an account builds a user, home directory, vhost, DNS zone and mail
         * configuration, which takes far longer than a read. CWP also keeps working after
         * a client gives up, so too short a budget leaves an account on the server and a
         * failed service in WHMCS.
         */
        'provision_timeout' => 180,

        /**
         * Apply a package change to CWP when an admin changes a service's
         * Product/Service and saves, instead of requiring the Change Package button.
         *
         * Off by default: reconfiguring a live hosting account because someone corrected
         * a mis-assigned product record is a surprise. Requires hooks.php, which WHMCS
         * registers when the module is activated — on an existing install, open any cwp7
         * product's Module Settings tab and press Save Changes once.
         */
        'apply_package_on_service_save' => false,

        /**
         * Use the hostname CWP returns in an autologin URL, rather than rewriting it to
         * the host on the server entry.
         *
         * Off by default: the session token is in the path and query, so rewriting the
         * host keeps the link working while preventing a redirect to any host that was
         * not configured. Turn this on only if the panel certificate is issued to CWP's
         * own FQDN and the redirect must land on it.
         */
        'autologin_trust_returned_host' => false,

        /**
         * Read real disk usage during the daily usage import.
         *
         * CWP's account list reports a placeholder rather than actual consumption, so
         * accurate figures need one extra call per account. That call is made only for
         * accounts matching a WHMCS service on the server, so the cost is proportional
         * to services rather than to every account on the box.
         *
         * Set to false on a server with many hundreds of accounts if you would rather
         * have a single cheap call and accept the placeholder figure.
         */
        'usage_detail_lookup' => true,

        /**
         * Send debug=1 with every call, making CWP write request detail to
         * /var/log/cwp/cwp_api.log on the CWP server.
         *
         * Useful when diagnosing an endpoint. Leave off in production: that log is
         * outside WHMCS's retention and access controls.
         */
        'debug' => false,
    ],

    /**
     * Per-server overrides, keyed by the hostname or IP on the WHMCS server entry,
     * merged over 'defaults'.
     *
     *   'cwp1.example.com' => [
     *       'ca_bundle' => '/etc/ssl/certs/cwp1-pinned.pem',
     *   ],
     */
    'servers' => [],
];
