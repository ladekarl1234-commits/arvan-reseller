<?php
namespace ArvanReseller\Arvan;

use ArvanReseller\Audit\Audit;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Crypto;
use ArvanReseller\Support\Helpers;

defined('ABSPATH') || exit;

/**
 * Multiple ArvanCloud API credential entries (spec: multi-credential).
 * Tokens are sodium-encrypted at rest, masked in UI, never returned by REST
 * (SEC-5).
 *
 * Selection is a money-bearing routing decision, so it is deliberately strict:
 * a credential the admin scoped to one product is NEVER handed to another.
 * See select_for().
 */
final class Credentials
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_credentials';
    }

    /**
     * Create or update. $plain_token empty on update = keep existing token.
     * @return int credential ID
     */
    public static function save(array $data, string $plain_token = '', int $id = 0): int
    {
        global $wpdb;
        $row = [
            'name'       => sanitize_text_field($data['name'] ?? ''),
            'enabled'    => !empty($data['enabled']) ? 1 : 0,
            // Catalog::PRODUCTS, never a literal copy: a fourth product added
            // there would otherwise be sellable but silently unassignable.
            'products'   => implode(',', array_intersect(
                array_map('sanitize_key', (array) ($data['products'] ?? [])),
                Catalog::PRODUCTS
            )),
            'priority'   => max(0, (int) ($data['priority'] ?? 10)),
            'is_default' => !empty($data['is_default']) ? 1 : 0,
            'notes'      => sanitize_textarea_field($data['notes'] ?? ''),
            'updated_at' => Helpers::now(),
        ];
        if ($plain_token !== '') {
            $row['token_enc']   = Crypto::encrypt($plain_token);
            $row['token_last4'] = substr($plain_token, -4);
        }

        if ($row['is_default']) {
            $wpdb->query('UPDATE ' . self::table() . ' SET is_default = 0'); // single default
        }

        if ($id > 0) {
            $wpdb->update(self::table(), $row, ['id' => $id]);
        } else {
            $row['created_at'] = Helpers::now();
            if (empty($row['token_enc'])) {
                return 0; // a new credential without a token is meaningless
            }
            $wpdb->insert(self::table(), $row);
            $id = (int) $wpdb->insert_id;
        }
        Audit::log(0, $id ? 'credential.saved' : 'credential.create_failed', 'credential', (string) $id, [
            'name' => $row['name'], 'token_changed' => $plain_token !== '',
        ]);
        return $id;
    }

    /**
     * There are no foreign keys, so referential integrity is enforced here or
     * nowhere. A credential with live services still holds the only pointer to
     * the ArvanCloud account those resources are billed to — deleting it makes
     * per-credential reconciliation silently wrong. Disable it instead.
     *
     * @return bool false when the delete was refused because services depend on it
     */
    public static function delete(int $id): bool
    {
        global $wpdb;
        if ($id <= 0) {
            return false;
        }
        $in_use = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Services::table() .
            " WHERE credential_id = %d AND status IN ('active','at_risk','suspended')",
            $id
        ));
        if ($in_use > 0) {
            Audit::log(0, 'credential.delete_refused', 'credential', (string) $id, ['services' => $in_use], 'warning');
            return false;
        }
        // Terminated/cancelled services keep their history; the dangling id is
        // cleared so reconciliation groups them as "unknown account" honestly.
        $wpdb->query($wpdb->prepare('UPDATE ' . Services::table() . ' SET credential_id = 0 WHERE credential_id = %d', $id));
        $wpdb->delete(self::table(), ['id' => $id]);
        Audit::log(0, 'credential.deleted', 'credential', (string) $id);
        return true;
    }

    /** All rows WITHOUT decrypted tokens; token_last4 only (UI-safe). */
    public static function all(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT id, name, token_last4, enabled, products, priority, is_default, notes, last_ok_at, last_error, created_at
             FROM ' . self::table() . ' ORDER BY priority ASC, id ASC',
            ARRAY_A
        ) ?: [];
        foreach ($rows as &$r) {
            // One mask format, one implementation (Crypto::mask), so changing
            // it cannot apply in some places and not others.
            $r['token_masked'] = Crypto::mask((string) $r['token_last4']);
            unset($r['token_last4']);
        }
        return $rows;
    }

    /**
     * Pick the credential row (with decrypted token) for a product.
     *
     * Precedence, in order, and there is no fourth rung:
     *   1. a credential that explicitly lists this product;
     *   2. an unrestricted credential (empty `products`);
     *   3. nothing.
     *
     * A credential restricted to `cdn` is never returned for `cloud_server`,
     * even when it is the only row: provisioning against an account the admin
     * deliberately scoped away is worse than failing loudly, because the
     * mis-routing is then baked into services.credential_id and into every
     * per-credential reconciliation report afterwards.
     *
     * @return array{id:int,token:string}|null
     */
    public static function select_for(string $product): ?array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT id, token_enc, products, is_default, priority FROM ' . self::table() .
            ' WHERE enabled = 1 ORDER BY is_default DESC, priority ASC, id ASC',
            ARRAY_A
        ) ?: [];

        $unrestricted = null;
        foreach ($rows as $r) {
            $products = array_values(array_filter(array_map('trim', explode(',', (string) $r['products']))));
            $token    = Crypto::decrypt((string) $r['token_enc']);
            if ($token === null) {
                continue; // undecryptable (salt rotation) — skip, surfaced in health
            }
            $candidate = ['id' => (int) $r['id'], 'token' => $token];
            if ($product !== '' && in_array($product, $products, true)) {
                return $candidate; // explicit product match wins outright
            }
            if ($unrestricted === null && !$products) {
                $unrestricted = $candidate;
            }
        }
        return $unrestricted;
    }

    public static function has_verified_credential(): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var(
            'SELECT id FROM ' . self::table() . ' WHERE enabled = 1 AND last_ok_at IS NOT NULL LIMIT 1'
        );
    }

    /** Record a connection-test outcome (health page + demo-mode forcing). */
    public static function record_test(int $id, bool $ok, string $error = ''): void
    {
        global $wpdb;
        $wpdb->update(self::table(), $ok
            ? ['last_ok_at' => Helpers::now(), 'last_error' => null, 'updated_at' => Helpers::now()]
            : ['last_error' => substr($error, 0, 500), 'updated_at' => Helpers::now()],
            ['id' => $id]);
    }
}
