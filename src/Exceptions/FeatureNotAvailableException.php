<?php

declare(strict_types=1);

namespace Moffhub\Billing\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class FeatureNotAvailableException extends HttpException
{
    public function __construct(string $message = 'Feature not available', int $statusCode = 403)
    {
        parent::__construct($statusCode, $message);
    }

    public static function noSubscription(): self
    {
        return new self('No active subscription found. Please subscribe to a plan.');
    }

    public static function featureNotIncluded(string $feature): self
    {
        return new self("The feature '{$feature}' is not included in your current plan. Please upgrade or purchase it as an add-on.");
    }

    /**
     * @param  array<string>  $plans
     */
    public static function planRequired(array $plans): self
    {
        $planList = implode(', ', $plans);

        return new self("This action requires one of the following plans: {$planList}.");
    }
}
