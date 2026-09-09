<?php

namespace Fixtures\Payments;

interface PaymentGateway
{
    public function charge(int $amount): Receipt;
}
