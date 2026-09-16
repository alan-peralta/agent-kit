<?php

namespace Fixtures\Payments;

use Attribute;
use Fixtures\Contracts\Auditor as PaymentAuditor;

#[Attribute]
final class PaymentService implements PaymentGateway
{
    use LogsPayments;

    public const CURRENCY = 'BRL';

    public function __construct(
        private PaymentAuditor $auditor,
        protected ?Receipt $lastReceipt = null,
    ) {}

    public function charge(int $amount, PaymentGateway&PaymentAuditor $gateway): Receipt|Failure
    {
        return new Receipt();
    }
}
