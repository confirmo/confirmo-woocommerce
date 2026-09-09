<?php

/**
 * The callback endpoint takes no session — Confirmo has none — so the shared
 * callback password is what establishes that a request came from Confirmo. Every
 * path that cannot establish it must answer with a refusal and leave the order
 * exactly as it was.
 */
class CallbackVerificationTest extends ConfirmoTestCase
{
    /**
     * With no password configured there is nothing to verify a request against,
     * so the only safe answer is to refuse. Carrying on regardless would accept
     * whatever arrived.
     */
    public function testACallbackIsRejectedWhenNoCallbackPasswordIsConfigured(): void
    {
        $options = get_option('confirmo_gate_config_options');
        $options['callback_password'] = '';
        update_option('confirmo_gate_config_options', $options);

        $order = $this->makeOrder($this->makeSimpleProduct('25.00'));
        $before = wc_get_order($order->get_id())->get_status();

        $status = $this->postCallback(
            wp_json_encode(['id' => 'inv-1', 'reference' => (string) $order->get_id(), 'status' => 'PAID']),
            ''
        );

        self::assertSame(403, $status);
        self::assertSame($before, wc_get_order($order->get_id())->get_status());
        self::assertFalse(wc_get_order($order->get_id())->is_paid());
    }

    /** A signature over a different body must not be replayable onto this one. */
    public function testASignatureFromAnotherPayloadIsRejected(): void
    {
        $order = $this->makeOrder($this->makeSimpleProduct('25.00'));
        $password = get_option('confirmo_gate_config_options')['callback_password'];

        $otherBody = wp_json_encode(['id' => 'inv-9', 'reference' => '1', 'status' => 'EXPIRED']);
        $stolen = hash('sha256', $otherBody . $password);

        $status = $this->postCallback(
            wp_json_encode(['id' => 'inv-1', 'reference' => (string) $order->get_id(), 'status' => 'PAID']),
            $stolen
        );

        self::assertSame(403, $status);
        self::assertFalse(wc_get_order($order->get_id())->is_paid());
    }

    public function testAnEmptyBodyIsRejected(): void
    {
        self::assertSame(400, $this->postCallback(''));
    }

    public function testABodyThatIsNotJsonIsRejected(): void
    {
        self::assertSame(400, $this->postCallback('not json at all'));
    }
}
