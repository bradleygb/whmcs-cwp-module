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
 * @version 2.4.0
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
         * Create or update the CWP package when a product is saved, on every CWP server
         * in that product's server group.
         *
         * Saves building the same package by hand on each server. The product's CWP
         * Package field is the package name — CWP's update endpoint identifies packages
         * by name, and each server assigns its own local id, so a name is the only
         * identifier that stays stable across a group.
         *
         * Off by default, and enabling it is a decision about ownership: WHMCS becomes
         * the source of truth, and a package edited in CWP is overwritten the next time
         * its product is saved.
         *
         * Requires ADD and UPD on Packages in addition to LIST.
         */
        'push_packages_on_product_save' => false,

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
         * Let customers create, delete and re-password their own mailboxes from the
         * client area, rather than only listing them.
         *
         * OFF, and it should stay off until the field names CWP expects on email/add,
         * email/udp and email/del have been read out of its Interactive Documentation
         * and checked against Mailbox::FIELDS. Those names are currently taken from
         * CWP's conventions on other endpoints, not from its documentation, and a
         * half-correct write request is worse than no write at all.
         *
         * Listing mailboxes does not depend on this and is always available.
         *
         * Requires ADD, UPD and DEL on Emails in addition to LIST.
         */
        'mailbox_management' => false,

        /**
         * Offer customers a Delete action on their mailboxes.
         *
         * OFF because CWP's email/del endpoint does not work. Every request shape
         * answered HTTP 500 with the same unhandled PHP notice — "Undefined offset: 1"
         * in app/routes/modules/email.php — with `user`, `email` and `domain` each
         * varied independently. The file is ionCube-encoded, so the parameter it is
         * actually looking for cannot be found by reading it.
         *
         * Turn this on only to retest after a CWP update. Creating and listing
         * mailboxes are unaffected and work.
         */
        'mailbox_delete' => false,

        /**
         * Apply the product's inode, open-file and process limits after a package
         * change, through account/udp.
         *
         * CWP checks that call as `accout_upd`, a broader grant than the package change
         * itself, and some servers refuse it whether or not Account/UPD is granted in
         * API Manager. A refusal is already non-fatal — the package still moves — but it
         * writes a failure to the WHMCS Module Log on every package change.
         *
         * Set to false on such a server to stop making the call. The three limits then
         * come from the CWP package alone and must be set in the panel if they matter.
         */
        'apply_resource_limits' => true,

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
         * WARNING: that log records the API key IN PLAINTEXT, along with account data.
         * The key is administrative over every account on the server, and the file sits
         * outside WHMCS's retention and access controls entirely.
         *
         * Switch this on only to diagnose a specific call, then switch it off, delete
         * the log, and treat the key as disclosed — rotate it. Never leave it on.
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
