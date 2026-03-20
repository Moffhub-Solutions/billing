<?php

declare(strict_types=1);

namespace Moffhub\Billing\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class UsageLimitExceededException extends HttpException
{
    public function __construct(
        string $message = 'Usage limit exceeded',
        public readonly ?string $featureSlug = null,
        public readonly ?int $currentUsage = null,
        public readonly ?int $limit = null,
    ) {
        parent::__construct(429, $message);
    }

    public static function noSubscription(): self
    {
        return new self('No active subscription found.');
    }

    public static function limitReached(string $featureSlug, int $currentUsage, ?int $limit): self
    {
        return new self(
            "Usage limit reached for '{$featureSlug}'. Current: {$currentUsage}, Limit: {$limit}.",
            $featureSlug,
            $currentUsage,
            $limit,
        );
    }
}
