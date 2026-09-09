<?php

namespace Fixtures\Payments;

interface AdvancedPaymentGateway extends PaymentGateway {}

class BasePaymentService
{
    public const CODE = 'base';

    public static function boot(): void {}
}

final class ExtendedPaymentService extends BasePaymentService
{
    public function compare(self $self, parent $parent): static
    {
        self::CODE;
        parent::CODE;
        static::boot();

        return $this;
    }
}

enum PaymentStatus: string
{
    case APPROVED = 'approved';
}
