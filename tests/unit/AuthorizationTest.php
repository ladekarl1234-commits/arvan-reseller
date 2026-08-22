<?php
/**
 * Negative authentication and authorization (EX-071).
 *
 * The suite used to prove only that customer B sees customer A's data as an
 * empty list. It never observed an anonymous or non-customer principal being
 * refused, never exercised a `check_admin_referer()` and never asserted that a
 * customer cannot reach an admin action — all one-token regressions.
 *
 * `check_admin_referer()` and `wp_safe_redirect()` are raised as exceptions by
 * the bootstrap (WordPress ends the request there), which is what lets each
 * test prove a guard fired BEFORE the effect it protects.
 */

defined('ABSPATH') || exit;

use ArvanReseller\Admin\Actions;
use ArvanReseller\Billing\Renewals;
use ArvanReseller\Front\FormActions;
use ArvanReseller\Identity\Customers;
use ArvanReseller\Notifications\Notifier;
use ArvanReseller\Orders\OrderService;
use ArvanReseller\Rest\Routes;
use ArvanReseller\Services\Services;
use ArvanReseller\Wallet\Ledger;

final class AuthorizationTest extends Arvrs_DbTestCase
{
    private const CUSTOMER_ROUTES = [
        'arvan-reseller/v1/checkout',
        'arvan-reseller/v1/me/summary',
        'arvan-reseller/v1/me/orders',
        'arvan-reseller/v1/me/services',
        'arvan-reseller/v1/me/ledger',
        'arvan-reseller/v1/me/services/(?P<id>\d+)',
        'arvan-reseller/v1/me/topup',
        'arvan-reseller/v1/orders/(?P<id>\d+)/state',
    ];

    /** @return array<string,array> route => registration args */
    private function routes(): array
    {
        Routes::register();
        return $GLOBALS['__arvrs_rest_routes'];
    }

    /* ------------------------------------------------------------ REST */

    public function test_every_customer_route_refuses_an_anonymous_caller(): void
    {
        $routes = $this->routes();
        wp_set_current_user(0);

        foreach (self::CUSTOMER_ROUTES as $route) {
            $this->assertArrayHasKey($route, $routes, $route . ' must be registered');
            $callback = $routes[$route]['permission_callback'];
            $this->assertIsCallable($callback);
            $this->assertFalse(call_user_func($callback), $route . ' must refuse a logged-out caller');
        }
    }

    public function test_a_logged_in_non_customer_is_refused_by_every_customer_route(): void
    {
        $routes = $this->routes();
        arvrs_test_add_user(500, ['roles' => ['subscriber']]); // logged in, but not a customer
        wp_set_current_user(500);

        foreach (self::CUSTOMER_ROUTES as $route) {
            $this->assertFalse(
                call_user_func($routes[$route]['permission_callback']),
                $route . ' must refuse a subscriber without the customer role'
            );
        }
    }

    public function test_a_customer_is_allowed_so_the_refusals_above_are_not_vacuous(): void
    {
        $routes = $this->routes();
        arvrs_test_add_user(501, ['roles' => [Customers::ROLE]]);
        wp_set_current_user(501);

        foreach (self::CUSTOMER_ROUTES as $route) {
            $this->assertTrue(call_user_func($routes[$route]['permission_callback']), $route);
        }
    }

    /**
     * The gateway callback is deliberately public — its authenticity comes
     * from the provider's `verify()`, not from a session. That is a decision,
     * so it is pinned rather than left to be re-derived by a future reader.
     */
    public function test_only_the_catalog_and_the_gateway_callback_are_public(): void
    {
        $public = [];
        foreach ($this->routes() as $route => $args) {
            if ($args['permission_callback'] === '__return_true') {
                $public[] = $route;
            }
        }
        sort($public);
        $this->assertSame([
            'arvan-reseller/v1/catalog/(?P<product>[a-z_]+)',
            'arvan-reseller/v1/payment/callback',
        ], $public);
    }

    public function test_every_route_declares_an_args_schema(): void
    {
        foreach ($this->routes() as $route => $args) {
            $this->assertArrayHasKey('args', $args, $route . ' must declare an args schema');
            foreach ($args['args'] as $name => $schema) {
                $this->assertArrayHasKey('type', $schema, $route . ' arg ' . $name);
                $this->assertTrue(
                    isset($schema['sanitize_callback']) || isset($schema['validate_callback']) || isset($schema['enum']),
                    $route . ' arg ' . $name . ' must be sanitised, validated or enumerated'
                );
            }
        }
    }

    /* -------------------------------------------------- object ownership */

    public function test_one_customer_cannot_read_anothers_service_order_or_wallet(): void
    {
        $alice = $this->customer(601);
        $bob   = $this->customer(602);
        [$order_id] = $this->seed_order($alice, 1200000, 'active');
        $service_id = $this->seed_service($alice, $order_id);
        Ledger::append($alice, 'topup', 5000000, 'topup', 'TOP-ALICE', 'x');

        $this->assertNull(Services::get_owned($service_id, $bob), 'the owner check is the object-level choke point');
        $this->assertNotNull(Services::get_owned($service_id, $alice));
        $this->assertSame([], OrderService::list($bob));
        $this->assertSame([], Ledger::entries($bob));
        $this->assertSame(0, Ledger::balance($bob)['available']);
    }

    public function test_marking_a_notification_read_is_scoped_to_its_owner(): void
    {
        $alice = $this->customer(601);
        $bob   = $this->customer(602);
        Notifier::customer($alice, 'payment_success', 'عنوان', 'متن');
        $note_id = (int) $this->db->get_var('SELECT id FROM ' . Notifier::table());

        Notifier::mark_read($bob, $note_id);
        $this->assertSame(1, Notifier::unread_count($alice), 'another customer must not be able to clear your notices');

        Notifier::mark_read($alice, $note_id);
        $this->assertSame(0, Notifier::unread_count($alice));
    }

    /* -------------------------------------------------------- CSRF nonces */

    public function test_login_without_a_nonce_never_reaches_wp_signon(): void
    {
        $_POST = ['email' => 'a@example.test', 'password' => 'x'];
        $this->expectException(Arvrs_Test_NonceFailure::class);
        FormActions::login();
    }

    public function test_registration_without_a_nonce_creates_no_account(): void
    {
        $_POST = ['email' => 'new@example.test', 'password' => 'password123'];
        $this->expectException(Arvrs_Test_NonceFailure::class);
        FormActions::register();
    }

    public function test_logout_without_a_nonce_does_not_log_anyone_out(): void
    {
        wp_set_current_user($this->customer(603));
        $_POST = [];
        try {
            FormActions::logout();
            $this->fail('a missing nonce must stop the logout');
        } catch (Arvrs_Test_NonceFailure $e) {
            $this->assertSame(603, get_current_user_id(), 'the session must survive a rejected request');
        }
    }

    public function test_a_forged_nonce_is_rejected_just_like_a_missing_one(): void
    {
        $_POST = ['arvrs_nonce' => 'deadbeef01', 'service_id' => '1'];
        $this->expectException(Arvrs_Test_NonceFailure::class);
        FormActions::cancel_renewal();
    }

    /* -------------------------------------- wrong-owner state-changing POST */

    public function test_a_customer_cannot_cancel_another_customers_renewal(): void
    {
        $alice = $this->customer(601);
        $bob   = $this->customer(602);
        arvrs_test_add_user($bob, ['roles' => [Customers::ROLE]]);
        [$order_id] = $this->seed_order($alice, 1200000, 'active');
        $service_id = $this->seed_service($alice, $order_id);

        wp_set_current_user($bob);
        $_POST = ['arvrs_nonce' => wp_create_nonce('arvrs_cancel_renewal'), 'service_id' => (string) $service_id];

        try {
            FormActions::cancel_renewal();
            $this->fail('the handler always ends in a redirect');
        } catch (Arvrs_Test_Redirect $redirect) {
            $this->assertStringContainsString('arvrs_error', $redirect->url);
        }
        $this->assertNotEmpty(
            Services::get($service_id)['renews_at'],
            "bob holds a valid nonce and is a customer — only the owner check stops him"
        );
    }

    public function test_the_owner_can_cancel_their_own_renewal(): void
    {
        $alice = $this->customer(601);
        arvrs_test_add_user($alice, ['roles' => [Customers::ROLE]]);
        [$order_id] = $this->seed_order($alice, 1200000, 'active');
        $service_id = $this->seed_service($alice, $order_id);

        wp_set_current_user($alice);
        $_POST = ['arvrs_nonce' => wp_create_nonce('arvrs_cancel_renewal'), 'service_id' => (string) $service_id];

        try {
            FormActions::cancel_renewal();
        } catch (Arvrs_Test_Redirect $redirect) {
            $this->assertStringContainsString('arvrs_notice', $redirect->url);
        }
        $this->assertNull(Services::get($service_id)['renews_at']);
        $this->assertSame('cancelled', Renewals::charge($service_id)['kind']);
    }

    /* ---------------------------------------------------- admin capability */

    public function test_a_customer_cannot_reach_an_admin_action(): void
    {
        $alice = $this->customer(601);
        arvrs_test_add_user($alice, ['roles' => [Customers::ROLE]]);
        wp_set_current_user($alice);
        $_POST = ['arvrs_nonce' => wp_create_nonce('arvrs_run_jobs')];

        // wp_die() is how the capability guard terminates; the bootstrap turns
        // it into an exception so the test can observe it at all.
        $this->expectException(\RuntimeException::class);
        Actions::run_jobs();
    }

    public function test_an_admin_action_still_needs_its_nonce(): void
    {
        $admin = $this->customer(604);
        arvrs_test_grant($admin, ['manage_options']);
        wp_set_current_user($admin);
        $_POST = []; // capability yes, nonce no

        $this->expectException(Arvrs_Test_NonceFailure::class);
        Actions::run_jobs();
    }
}
