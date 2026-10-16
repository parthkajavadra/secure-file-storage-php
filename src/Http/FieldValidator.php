<?php

namespace App\Http;

/**
 * Small helper that reproduces the DRF serializer field checks and error messages the API relies on.
 * Errors are collected per field: ["field" => ["message", ...]].
 */
final class FieldValidator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    /** DRF CharField(required=True|False, max_length=...), whitespace trimmed. */
    public function string(string $field, bool $required = true, ?int $maxLength = null, ?string $default = null): ?string
    {
        if (!\array_key_exists($field, $this->data)) {
            if ($required) {
                $this->addError($field, 'This field is required.');
            }

            return $default;
        }
        if (null === $this->data[$field]) {
            if ($required) {
                $this->addError($field, 'This field may not be null.');
            }

            return $default;
        }

        $value = $this->data[$field];
        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
            $this->addError($field, 'Not a valid string.');

            return null;
        }

        $value = trim((string) $value);
        if ('' === $value && $required) {
            $this->addError($field, 'This field may not be blank.');

            return null;
        }
        if (null !== $maxLength && mb_strlen($value) > $maxLength) {
            $this->addError($field, sprintf('Ensure this field has no more than %d characters.', $maxLength));

            return null;
        }

        return $value;
    }

    /** DRF IntegerField(required=True). Accepts ints and integer-like strings. */
    public function integer(string $field, ?int $min = null, ?int $max = null): ?int
    {
        if (!\array_key_exists($field, $this->data)) {
            $this->addError($field, 'This field is required.');

            return null;
        }

        $value = self::toInt($this->data[$field]);
        if (null === $value) {
            $this->addError($field, 'A valid integer is required.');

            return null;
        }
        if (null !== $min && $value < $min) {
            $this->addError($field, sprintf('Ensure this value is greater than or equal to %d.', $min));

            return null;
        }
        if (null !== $max && $value > $max) {
            $this->addError($field, sprintf('Ensure this value is less than or equal to %d.', $max));

            return null;
        }

        return $value;
    }

    /** DRF ChoiceField(required=False). */
    public function choice(string $field, array $choices, string $default): ?string
    {
        if (!\array_key_exists($field, $this->data) || '' === $this->data[$field]) {
            return $default;
        }
        $value = $this->data[$field];
        if (!\is_string($value) || !\in_array($value, $choices, true)) {
            $this->addError($field, sprintf('"%s" is not a valid choice.', \is_scalar($value) ? $value : get_debug_type($value)));

            return null;
        }

        return $value;
    }

    public static function toInt(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_float($value) && floor($value) === $value && abs($value) < \PHP_INT_MAX) {
            return (int) $value;
        }
        if (\is_string($value) && preg_match('/^\s*[-+]?\d{1,18}\s*$/', $value)) {
            return (int) trim($value);
        }

        return null;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function hasErrors(): bool
    {
        return [] !== $this->errors;
    }

    /** @return array<string, list<string>> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
