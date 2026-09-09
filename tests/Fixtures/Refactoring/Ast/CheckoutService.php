<?php

namespace Fixtures\Checkout;

use Fixtures\Payments\PaymentApproved;
use Fixtures\Payments\PaymentService as Payments;
use Fixtures\Payments\ProcessPayment;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

final class CheckoutService
{
    public function __construct(private Payments $payments) {}

    public function checkout(Payments $service): void
    {
        $this->payments->charge(100);
        $service->charge(200);
        $local = new Payments();
        $local->charge(300);
        Payments::status();
        Payments::CURRENCY;
        app(Payments::class)->charge(400);
        resolve(Payments::class)->charge(500);
        app()->make(Payments::class)->charge(600);
        event(new PaymentApproved());
        Event::dispatch(new PaymentApproved());
        dispatch(new ProcessPayment());
        ProcessPayment::dispatch();
        Bus::dispatch(new ProcessPayment());
        Log::info('paid');
        $unknown->charge(700);
    }
}
