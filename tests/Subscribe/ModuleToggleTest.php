<?php

/**
 * With the module toggle off, Confirmo Subscribe must register nothing.
 *
 * That is the promise the toggle exists for, and it is what protects the
 * merchants already running this plugin for Checkout: an alpha module they never
 * asked for cannot change how their store behaves. The whole promise rests on a
 * single early return in `Module::boot()`, and one `add_action` placed above it
 * would break it for every one of them while the rest of the suite stayed green,
 * because every other test runs with the module on.
 *
 * It cannot be asserted by running `boot()` again. By the time a test executes,
 * the module has already booted at `plugins_loaded`: `require_once` will not
 * re-include, and `add_action()` with the same callback and priority replaces
 * rather than appends, so a second boot changes nothing observable no matter
 * what it contains. Asserting on a fresh boot would mean a second WordPress
 * process with the option off.
 *
 * So this reads the source instead. Unusual for a test, and worth it: the risk
 * is someone adding a line in the wrong place, and this fails on exactly that.
 *
 * Extends ConfirmoTestCase rather than SubscribeTestCase — what boot() registers
 * has nothing to do with whether WooCommerce Subscriptions is installed, and
 * this is the last test that should ever skip.
 */
class ModuleToggleTest extends ConfirmoTestCase
{
    /**
     * The settings screen and its connection test are deliberately registered
     * above the early return, so a merchant can check their API key before
     * switching the module on. Nothing else may be.
     */
    private const ALLOWED_ABOVE_THE_RETURN = [
        'WC_Confirmo_Subscribe_Settings',
    ];

    public function testNothingFunctionalIsRegisteredAboveTheEnabledCheck(): void
    {
        $above = $this->bootBodyBeforeTheEnabledCheck();

        // Whole statements, not lines: these calls are routinely wrapped across
        // several lines, and matching line by line reported the opening `(` of a
        // perfectly legitimate one.
        $statements = explode(';', (string) preg_replace('/\s+/', ' ', $above));

        $registrations = [];
        foreach ($statements as $statement) {
            $statement = trim($statement);

            if (!preg_match('/\b(?:add_action|add_filter|require_once|require|include_once|include)\b|::register\(/', $statement)) {
                continue;
            }

            foreach (self::ALLOWED_ABOVE_THE_RETURN as $allowed) {
                if (strpos($statement, $allowed) !== false) {
                    continue 2;
                }
            }

            $registrations[] = $statement;
        }

        self::assertSame(
            [],
            $registrations,
            "These lines run even when the merchant has the Subscribe module switched off, so they change the "
            . "behaviour of a store that never asked for it. Move them below the `if (!self::isEnabled())` return."
        );
    }

    /**
     * The real thing: a WordPress booted with the toggle off, asked what the
     * plugin did. This is what a Checkout-only merchant's store looks like, and
     * anything reported here is something an alpha module put in front of
     * someone who never enabled it.
     *
     * Needs its own process — see the class docblock — and the test-environment
     * helper that answers the toggle before the module reads it. Skipped rather
     * than failed where that helper is not installed, since a WordPress someone
     * brought themselves will not have it.
     */
    public function testAStoreWithTheToggleOffGetsNothingFromTheModule(): void
    {
        $probe = $this->runDisabledBootProbe();

        self::assertTrue(
            $probe['forcedOff'],
            'the test helper did not load, so this run proves nothing about the toggle'
        );
        self::assertFalse($probe['moduleReportsEnabled'], 'the module should have booted disabled');

        self::assertTrue(
            $probe['checkoutGatewayPresent'],
            'the Checkout gateway must still be there — that is the whole point of the toggle'
        );

        self::assertSame([], $probe['subscribeHooks'], 'the module registered hooks while switched off');
        self::assertSame([], $probe['subscribeClasses'], 'the module loaded classes while switched off');
        self::assertFalse($probe['subscribeGateway'], 'the Subscribe gateway reached a store that never enabled it');
    }

    /** The toggle has to read as off both when set to no and when never set at all. */
    public function testTheModuleReportsItselfOffUnlessTurnedOn(): void
    {
        update_option(WC_Confirmo_Subscribe_Settings::OPTION, ['enabled' => 'no']);
        self::assertFalse(WC_Confirmo_Subscribe_Module::isEnabled());

        delete_option(WC_Confirmo_Subscribe_Settings::OPTION);
        self::assertFalse(
            WC_Confirmo_Subscribe_Module::isEnabled(),
            'a store that has never seen this module must read as off'
        );

        update_option(WC_Confirmo_Subscribe_Settings::OPTION, ['enabled' => 'yes']);
        self::assertTrue(WC_Confirmo_Subscribe_Module::isEnabled());
    }

    /** The early return is what everything above depends on; fail loudly if it moves. */
    public function testBootStillGuardsOnTheToggle(): void
    {
        self::assertStringContainsString(
            'if (!self::isEnabled())',
            $this->moduleSource(),
            'the guard this test is built around has been renamed or removed'
        );
    }

    /**
     * @return array<string, mixed> what the probe reported
     */
    private function runDisabledBootProbe(): array
    {
        $helper = ABSPATH . 'wp-content/mu-plugins/confirmo-force-subscribe-off.php';
        if (!file_exists($helper)) {
            self::markTestSkipped('this WordPress has no tests/env helper installed; rebuild with tests/env/up.sh');
        }

        $probe = dirname(__DIR__) . '/disabled-boot-probe.php';
        self::assertFileExists($probe);

        $command = sprintf(
            'CONFIRMO_FORCE_SUBSCRIBE_OFF=1 WP_ROOT=%s %s %s 2>/dev/null',
            escapeshellarg(ABSPATH),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($probe)
        );

        $output = shell_exec($command);
        self::assertNotEmpty($output, 'the probe produced no output; it likely failed to boot WordPress');

        // WordPress notices can precede the payload, so take the JSON object.
        $json = substr((string) $output, (int) strpos((string) $output, '{'));
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded, 'could not read the probe output: ' . substr((string) $output, 0, 300));

        return $decoded;
    }

    private function moduleSource(): string
    {
        $file = (new ReflectionClass(WC_Confirmo_Subscribe_Module::class))->getFileName();
        $source = file_get_contents($file);

        self::assertNotFalse($source, 'could not read ' . $file);

        return $source;
    }

    /** Everything `boot()` does before it checks whether the merchant enabled the module. */
    private function bootBodyBeforeTheEnabledCheck(): string
    {
        $source = $this->moduleSource();

        $start = strpos($source, 'function boot(');
        self::assertNotFalse($start, 'Module::boot() not found');

        $guard = strpos($source, 'if (!self::isEnabled())', $start);
        self::assertNotFalse($guard, 'the enabled check is no longer inside boot()');

        return substr($source, $start, $guard - $start);
    }
}
