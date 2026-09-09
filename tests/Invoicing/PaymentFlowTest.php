<?php

/**
 * The Checkout gateway's core flow: an order becomes a Confirmo invoice, and the
 * callback Confirmo sends back moves the order.
 *
 * These are the two things that break silently. A wrong amount or currency
 * reaches the customer as a bill; a callback that stops working leaves paid
 * orders sitting unpaid forever.
 */
class PaymentFlowTest extends ConfirmoTestCase
{
    public function testCheckoutCreatesAnInvoiceForTheOrderTotalAndRedirects(): void
    {
        $order = $this->makeOrder($this->makeSimpleProduct('25.00'), 2);

        $this->stubApi('/api/v3/invoices', ['id' => 'inv-1', 'url' => 'https://confirmo.test/invoice/inv-1']);

        $result = (new WC_Confirmo_Gateway())->process_payment($order->get_id());

        self::assertSame('success', $result['result']);
        self::assertSame('https://confirmo.test/invoice/inv-1', $result['redirect']);

        $sent = $this->requestTo('/api/v3/invoices');
        self::assertEquals(50.00, (float) $sent['invoice']['amount'], 'the invoice must bill the order total');
        self::assertSame($order->get_currency(), $sent['invoice']['currencyFrom']);
        self::assertSame((string) $order->get_id(), $sent['reference'], 'the callback finds the order by this reference');
        self::assertSame('USDT', $sent['settlement']['currency']);
        self::assertSame('buyer@example.com', $sent['customerEmail']);

        self::assertSame('pending', wc_get_order($order->get_id())->get_status());
    }

    public function testAPaidCallbackLeavesTheOrderPaid(): void
    {
        $order = $this->makeOrder($this->makeSimpleProduct('25.00'));

        $this->stubApi('/api/v3/invoices/inv-1', ['id' => 'inv-1', 'status' => 'PAID']);

        $status = $this->postCallback(wp_json_encode([
            'id' => 'inv-1',
            'reference' => (string) $order->get_id(),
            'status' => 'PAID',
        ]));

        self::assertSame(200, $status);
        self::assertTrue(wc_get_order($order->get_id())->is_paid(), 'a paid invoice must leave the order paid');
    }

    /**
     * The status is read back from Confirmo, never taken from the callback body,
     * so a forged body cannot mark an order paid.
     */
    public function testTheOrderFollowsConfirmoNotTheCallbackBody(): void
    {
        $order = $this->makeOrder($this->makeSimpleProduct('25.00'));

        $this->stubApi('/api/v3/invoices/inv-1', ['id' => 'inv-1', 'status' => 'EXPIRED']);

        $status = $this->postCallback(wp_json_encode([
            'id' => 'inv-1',
            'reference' => (string) $order->get_id(),
            'status' => 'PAID',
        ]));

        self::assertSame(200, $status);
        self::assertFalse(wc_get_order($order->get_id())->is_paid(), 'Confirmo said EXPIRED; the body said PAID');
    }

    public function testACallbackWithAWrongSignatureIsRejectedAndTheOrderIsUntouched(): void
    {
        $order = $this->makeOrder($this->makeSimpleProduct('25.00'));
        $before = wc_get_order($order->get_id())->get_status();

        $status = $this->postCallback(
            wp_json_encode(['id' => 'inv-1', 'reference' => (string) $order->get_id(), 'status' => 'PAID']),
            'not-the-right-signature'
        );

        self::assertSame(403, $status);
        self::assertSame($before, wc_get_order($order->get_id())->get_status());
    }

    public function testACallbackForAnUnknownOrderIsRejected(): void
    {
        $status = $this->postCallback(wp_json_encode([
            'id' => 'inv-1',
            'reference' => '99999999',
            'status' => 'PAID',
        ]));

        self::assertSame(404, $status);
    }
}
