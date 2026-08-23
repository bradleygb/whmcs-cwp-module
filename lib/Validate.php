<?php
/**
 * CWP Hosting Module for WHMCS — input rules for customer-supplied values.
 *
 * Everything a customer types passes through here before it reaches the API. The key
 * driving that API is administrative over every account on the server, so these are
 * allow-lists: a value is refused unless it matches a shape known to be safe.
 *
 * Every method throws CwpException::input(), whose message is written to be shown to the
 * customer rather than logged.
 *
 * @package cwp7
 * @version 2.3.0
 * @author  Booysen Logistics <bradley@booysenlogistics.co.za>
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

declare(strict_types=1);

namespace Cwp7;

final class Validate
{
    /** The local part of an address, per RFC 5321's practical limit. */
    const MAX_LOCAL_PART = 64;

    /**
     * MySQL allows 32 characters for a user name and CWP prefixes the account name and
     * an underscore, so the part a customer supplies has to leave room for it.
     */
    const MAX_ACCOUNT_NAME = 16;

    const MIN_PASSWORD = 10;
    const MAX_PASSWORD = 64;

    /**
     * The part of an email address before the @.
     *
     * Deliberately narrower than RFC 5321, which permits quoted strings containing
     * spaces, quotes and backslashes. Those are legal, unusable in practice, and would
     * be passed into a form-encoded API request.
     *
     * @throws CwpException
     */
    public static function localPart(string $value): string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            throw CwpException::input('Enter a mailbox name.');
        }

        if (strlen($value) > self::MAX_LOCAL_PART) {
            throw CwpException::input(
                'That mailbox name is too long — the limit is ' . self::MAX_LOCAL_PART . ' characters.'
            );
        }

        if (preg_match('/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/', $value) !== 1
            || strpos($value, '..') !== false
        ) {
            throw CwpException::input(
                'A mailbox name may contain letters, numbers, dots, hyphens and underscores, '
                . 'and must begin and end with a letter or number.'
            );
        }

        return $value;
    }

    /**
     * An FTP, database or database-user name, as the customer supplies it.
     *
     * CWP prefixes the account name itself, so this is only the part after that.
     *
     * @throws CwpException
     */
    public static function accountName(string $value, string $what = 'name'): string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            throw CwpException::input('Enter a ' . $what . '.');
        }

        if (strlen($value) > self::MAX_ACCOUNT_NAME) {
            throw CwpException::input(
                'That ' . $what . ' is too long — the limit is ' . self::MAX_ACCOUNT_NAME . ' characters.'
            );
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $value) !== 1) {
            throw CwpException::input(
                'A ' . $what . ' may contain lowercase letters, numbers and underscores, '
                . 'and must begin with a letter.'
            );
        }

        return $value;
    }

    /**
     * A password the customer chose.
     *
     * Printable ASCII only. A password is form-encoded into an API request and then
     * handled by a panel whose own rules are not published, so anything outside that
     * range is refused here rather than failing somewhere less explicable. CWP may apply
     * further rules of its own; its refusal is passed through as it comes.
     *
     * @throws CwpException
     */
    public static function password(string $value): string
    {
        $length = strlen($value);

        if ($length < self::MIN_PASSWORD) {
            throw CwpException::input(
                'Use a password of at least ' . self::MIN_PASSWORD . ' characters.'
            );
        }

        if ($length > self::MAX_PASSWORD) {
            throw CwpException::input(
                'That password is too long — the limit is ' . self::MAX_PASSWORD . ' characters.'
            );
        }

        if (preg_match('/^[\x21-\x7e]+$/', $value) !== 1) {
            throw CwpException::input(
                'A password may not contain spaces or accented characters.'
            );
        }

        $classes = 0;

        foreach (['/[a-z]/', '/[A-Z]/', '/[0-9]/', '/[^a-zA-Z0-9]/'] as $class) {
            if (preg_match($class, $value) === 1) {
                $classes++;
            }
        }

        if ($classes < 3) {
            throw CwpException::input(
                'Use a password with at least three of: lowercase letters, capitals, '
                . 'numbers and symbols.'
            );
        }

        return $value;
    }

    /**
     * Every domain and subdomain an accountdetail response says the account holds.
     *
     * Separated from the call so the ownership rule can be tested against fixtures.
     * Note `subdomins` — CWP's spelling, and the only one it sends.
     *
     * @param array<string,mixed> $detail
     *
     * @return array<int,string>
     */
    public static function domainsIn(array $detail): array
    {
        $found = [];

        if (isset($detail['domains']) && is_array($detail['domains'])) {
            foreach ($detail['domains'] as $row) {
                if (is_array($row) && isset($row['domain'])) {
                    $found[] = self::normaliseDomain((string) $row['domain']);
                }
            }
        }

        foreach (['subdomins', 'subdomains'] as $key) {
            if (!isset($detail[$key]) || !is_array($detail[$key])) {
                continue;
            }

            foreach ($detail[$key] as $row) {
                if (!is_array($row) || !isset($row['subdomain'], $row['domain'])) {
                    continue;
                }

                $found[] = self::normaliseDomain(
                    (string) $row['subdomain'] . '.' . (string) $row['domain']
                );
            }
        }

        return array_values(array_unique(array_filter($found)));
    }

    /**
     * Confirm a domain belongs to this account, and return it normalised.
     *
     * Without this a form that names a domain is a way to create mail, or a subdomain,
     * on an account belonging to somebody else — the API key can reach all of them.
     *
     * @param array<string,mixed> $detail accountdetail's payload for this account
     *
     * @throws CwpException
     */
    public static function ownedDomain(array $detail, string $domain): string
    {
        $domain = self::normaliseDomain($domain);

        if ($domain === '') {
            throw CwpException::input('Choose a domain.');
        }

        if (!in_array($domain, self::domainsIn($detail), true)) {
            // Deliberately says nothing about whether the domain exists elsewhere.
            throw CwpException::input('That domain is not on this hosting account.');
        }

        return $domain;
    }

    private static function normaliseDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));

        return rtrim($domain, '.');
    }
}
