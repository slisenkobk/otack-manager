<?php
declare(strict_types=1);
namespace App\Http;

/**
 * Fluent validator for POST/JSON input. Accumulates per-field error codes
 * (machine-readable, like `required`, `email`, `too_short`) and returns
 * either the cleaned data or throws `ValidationException` which controllers
 * can map to a 422 with the field errors payload.
 *
 * Only the FIRST error per field is captured — chained rules short-circuit
 * once a field has an error, so the caller can render one message per field
 * without juggling priority ordering.
 *
 * Example:
 *   $clean = Validator::for($req->post)
 *       ->required('email')->email('email')
 *       ->required('password')->minLength('password', 8)
 *       ->clean();
 */
final class Validator
{
    /** @var array<string,string> field => first error code captured */
    private array $errors = [];

    /** @param array<string,mixed> $data */
    public function __construct(private array $data) {}

    /** @param array<string,mixed> $data */
    public static function for(array $data): self { return new self($data); }

    public function required(string $field): self
    {
        if (!array_key_exists($field, $this->data) || $this->stringValue($field) === '') {
            $this->mark($field, 'required');
        }
        return $this;
    }

    public function email(string $field): self
    {
        if (!isset($this->errors[$field])) {
            $v = $this->stringValue($field);
            if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $this->mark($field, 'invalid_email');
            }
        }
        return $this;
    }

    public function minLength(string $field, int $n): self
    {
        if (!isset($this->errors[$field])) {
            $v = $this->stringValue($field);
            if ($v !== '' && mb_strlen($v) < $n) $this->mark($field, 'too_short');
        }
        return $this;
    }

    public function maxLength(string $field, int $n): self
    {
        if (!isset($this->errors[$field])) {
            $v = $this->stringValue($field);
            if ($v !== '' && mb_strlen($v) > $n) $this->mark($field, 'too_long');
        }
        return $this;
    }

    /** @param list<string> $allowed */
    public function in(string $field, array $allowed): self
    {
        if (!isset($this->errors[$field])) {
            $v = $this->stringValue($field);
            if ($v !== '' && !in_array($v, $allowed, true)) $this->mark($field, 'invalid_choice');
        }
        return $this;
    }

    public function passes(): bool { return empty($this->errors); }
    public function fails(): bool { return !$this->passes(); }
    /** @return array<string,string> */
    public function errors(): array { return $this->errors; }

    /**
     * Return the trimmed data array on success, throw ValidationException on failure.
     * String fields are returned trimmed; non-string fields pass through unchanged.
     * @return array<string,mixed>
     */
    public function clean(): array
    {
        if (!$this->passes()) throw new ValidationException($this->errors);
        $out = $this->data;
        foreach ($out as $k => $v) {
            if (is_string($v)) $out[$k] = trim($v);
        }
        return $out;
    }

    private function stringValue(string $field): string
    {
        $v = $this->data[$field] ?? '';
        return is_string($v) ? trim($v) : '';
    }

    private function mark(string $field, string $code): void
    {
        if (!isset($this->errors[$field])) $this->errors[$field] = $code;
    }
}
