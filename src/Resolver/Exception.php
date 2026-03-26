<?php

namespace Utopia\Proxy\Resolver;

/**
 * Exception thrown during resolution
 */
class Exception extends \Exception
{
    public const NOT_FOUND = 404;

    public const UNAVAILABLE = 503;

    public const TIMEOUT = 504;

    public const FORBIDDEN = 403;

    public const INTERNAL = 500;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        int $code = self::INTERNAL,
        public readonly array $context = []
    ) {
        parent::__construct($message, $code);
    }
}
