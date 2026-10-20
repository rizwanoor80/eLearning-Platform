<?php

use App\Models\Payment;
use App\Services\Payments\FakePaymentGateway;
use App\Support\Money;

/**
 * 23c (R255): the fake driver's two 23b methods were unpinned — forcing `verifyWebhook` to true left the
 * whole suite green. These tests fail if the fake ever accepts a webhook or loses its refund shape.
 */
it('never accepts a webhook, signed or not', function () {
    $gateway = new FakePaymentGateway;

    expect($gateway->verifyWebhook('{"id":"evt_1"}', []))->toBeFalse()
        ->and($gateway->verifyWebhook('{"id":"evt_1"}', ['stripe-signature' => 't=1,v1=abc']))->toBeFalse()
        ->and($gateway->verifyWebhook('', ['x-signature' => 'valid']))->toBeFalse();
});

it('refunds with a fake reference derived from the idempotency key and the amount asked for', function () {
    $payment = Payment::factory()->create(['gateway' => 'fake']);

    $result = (new FakePaymentGateway)->refund($payment, Money::fils(2500), 'refund-key-1');

    expect($result->gatewayRef)->toStartWith('fake_re_refund-key-1_')
        ->and($result->rawResponse)->toMatchArray(['driver' => 'fake', 'idempotency_key' => 'refund-key-1', 'amount_fils' => 2500, 'refund' => true]);
});
