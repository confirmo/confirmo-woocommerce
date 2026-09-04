<?php

/**
 * The debug log holds Confirmo API responses and order references, so reading or
 * clearing it belongs to whoever administers the store. Both are `admin_post`
 * actions, which means the capability and the nonce are what decide that — there
 * is no screen in front of them doing it first.
 */
class DebugLogAccessTest extends ConfirmoTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);
        unset($_POST['confirmo_download_logs'], $_POST['action'], $_POST['_wpnonce']);

        parent::tearDown();
    }

    public function testACustomerCannotDownloadTheDebugLog(): void
    {
        wp_set_current_user($this->makeCustomer());

        $_POST['confirmo_download_logs'] = '1';
        $_POST['_wpnonce'] = wp_create_nonce('confirmo_download_logs');

        self::assertSame(403, $this->statusFrom('downloadLogs'));
    }

    public function testACustomerCannotDeleteTheDebugLog(): void
    {
        wp_set_current_user($this->makeCustomer());

        $_POST['action'] = 'confirmo_delete_logs';
        $_POST['_wpnonce'] = wp_create_nonce('confirmo_delete_logs');

        self::assertSame(403, $this->statusFrom('deleteLogs'));
    }

    public function testALoggedOutVisitorCannotDownloadTheDebugLog(): void
    {
        wp_set_current_user(0);

        $_POST['confirmo_download_logs'] = '1';

        self::assertSame(403, $this->statusFrom('downloadLogs'));
    }

    /** A capability alone does not establish intent; the nonce is required too. */
    public function testAnAdministratorStillNeedsAValidNonceToDelete(): void
    {
        wp_set_current_user($this->makeAdministrator());

        $_POST['action'] = 'confirmo_delete_logs';
        $_POST['_wpnonce'] = 'forged';

        self::assertNotSame(200, $this->statusFrom('deleteLogs'), 'a forged nonce must not be accepted');
    }

    private function makeAdministrator(): int
    {
        $unique = wp_generate_password(8, false);
        $userId = wp_insert_user([
            'user_login' => 'admin-' . $unique,
            'user_email' => 'admin-' . $unique . '@example.com',
            'user_pass' => wp_generate_password(),
            'role' => 'administrator',
        ]);

        if (is_wp_error($userId)) {
            $this->fail('could not create the administrator: ' . $userId->get_error_message());
        }

        return (int) $userId;
    }

    /** @return int the HTTP status the handler answered with */
    private function statusFrom(string $method): int
    {
        $gateway = new WC_Confirmo_Gateway();

        try {
            $gateway->{$method}();
        } catch (ConfirmoWpDie $e) {
            return $e->status;
        }

        $this->fail($method . '() returned without refusing the request');
    }
}
