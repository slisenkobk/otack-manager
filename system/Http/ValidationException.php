<?php
declare(strict_types=1);
namespace App\Http;

/**
 * Thrown by {@see Validator::clean()} when one or more rules failed. Carries
 * the field-by-field error code map so callers can render either flash
 * messages or a 422 JSON body without re-running the rules.
 */
final class ValidationException extends \RuntimeException
{
    /** @param array<string,string> $fields field => machine-readable error code */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('validation_failed');
    }
}
