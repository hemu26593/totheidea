<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Exceptions\AiSchemaValidationException;
use JsonException;

/**
 * Model output is a PROPOSAL. This is what decides whether it is usable.
 *
 * The contract is narrow on purpose. It decodes JSON and checks it against
 * the schema the prompt version declared, and it returns a plain PHP array.
 * It does not evaluate, interpolate, include, unserialize or call anything -
 * output arrives as inert data and leaves as inert data. There is no code
 * path from here to PHP, JavaScript, SQL, a shell, a migration or a class
 * name, which is what "AI must never execute generated code" means once it is
 * written down rather than promised.
 *
 * A DELIBERATELY SMALL SCHEMA SUBSET. type, required, properties, items,
 * enum, additionalProperties and the length/count bounds cover every shape
 * this application asks a model for. A full JSON Schema implementation would
 * be a dependency and a much larger surface, in exchange for expressiveness
 * nothing here needs. Anything the subset does not understand is REFUSED
 * rather than ignored, so a schema cannot silently stop constraining.
 */
class SchemaValidator
{
    /**
     * The keywords this validator understands. Encountering any other keyword
     * is an error: silently skipping it would leave the author believing a
     * constraint applies when it does not.
     *
     * @var array<int, string>
     */
    private const SUPPORTED = [
        'type', 'required', 'properties', 'items', 'enum', 'additionalProperties',
        'minItems', 'maxItems', 'minLength', 'maxLength', 'description', 'nullable',
    ];

    /**
     * Decode and validate raw model output.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     *
     * @throws AiSchemaValidationException
     */
    public function validate(string $rawOutput, array $schema): array
    {
        $decoded = $this->decode($rawOutput);

        $violations = $this->check($decoded, $schema, '$');

        if ($violations !== []) {
            throw AiSchemaValidationException::withViolations($violations);
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiSchemaValidationException
     */
    private function decode(string $rawOutput): array
    {
        $trimmed = $this->stripCodeFence(trim($rawOutput));

        if ($trimmed === '') {
            throw AiSchemaValidationException::notJson('the output was empty');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw AiSchemaValidationException::notJson($e->getMessage());
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw AiSchemaValidationException::notJson('the output was not a JSON object');
        }

        return $decoded;
    }

    /**
     * Models routinely wrap JSON in a markdown fence. Removing it is
     * presentation handling, not interpretation - the content inside is still
     * parsed as data and still has to validate.
     */
    private function stripCodeFence(string $output): string
    {
        if (! str_starts_with($output, '```')) {
            return $output;
        }

        $withoutOpening = (string) preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $output);

        return trim((string) preg_replace('/```\s*$/', '', $withoutOpening));
    }

    /**
     * @param  mixed  $value
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function check($value, array $schema, string $path): array
    {
        $unsupported = array_diff(array_keys($schema), self::SUPPORTED);

        if ($unsupported !== []) {
            return [sprintf('%s: schema uses unsupported keyword(s) [%s]', $path, implode(', ', $unsupported))];
        }

        if ($value === null && ($schema['nullable'] ?? false) === true) {
            return [];
        }

        $type = $schema['type'] ?? null;

        if ($type !== null && ! $this->matchesType($value, (string) $type)) {
            return [sprintf('%s: expected %s, got %s', $path, $type, $this->describe($value))];
        }

        return match ($type) {
            'object' => $this->checkObject($value, $schema, $path),
            'array' => $this->checkArray($value, $schema, $path),
            'string' => $this->checkString($value, $schema, $path),
            default => $this->checkEnum($value, $schema, $path),
        };
    }

    /**
     * @param  mixed  $value
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function checkObject($value, array $schema, string $path): array
    {
        /** @var array<string, mixed> $value */
        $violations = [];

        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'] ?? [];

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists((string) $required, $value)) {
                $violations[] = sprintf('%s: missing required property [%s]', $path, (string) $required);
            }
        }

        // Refusing unknown properties by default is the safer failure: an
        // extra key is a sign the model answered a different question, and
        // accepting it would carry unvalidated content forward.
        if (($schema['additionalProperties'] ?? false) !== true) {
            foreach (array_keys($value) as $key) {
                if (! array_key_exists((string) $key, $properties)) {
                    $violations[] = sprintf('%s: unexpected property [%s]', $path, (string) $key);
                }
            }
        }

        foreach ($properties as $key => $childSchema) {
            if (! array_key_exists((string) $key, $value) || ! is_array($childSchema)) {
                continue;
            }

            $violations = array_merge(
                $violations,
                $this->check($value[$key], $childSchema, $path.'.'.$key),
            );
        }

        return $violations;
    }

    /**
     * @param  mixed  $value
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function checkArray($value, array $schema, string $path): array
    {
        /** @var array<int, mixed> $value */
        $violations = [];

        if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
            $violations[] = sprintf('%s: expected at least %d item(s), got %d', $path, (int) $schema['minItems'], count($value));
        }

        if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
            $violations[] = sprintf('%s: expected at most %d item(s), got %d', $path, (int) $schema['maxItems'], count($value));
        }

        $itemSchema = $schema['items'] ?? null;

        if (is_array($itemSchema)) {
            foreach ($value as $index => $item) {
                $violations = array_merge(
                    $violations,
                    $this->check($item, $itemSchema, $path.'['.$index.']'),
                );
            }
        }

        return $violations;
    }

    /**
     * @param  mixed  $value
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function checkString($value, array $schema, string $path): array
    {
        $violations = $this->checkEnum($value, $schema, $path);
        $length = mb_strlen((string) $value);

        if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
            $violations[] = sprintf('%s: shorter than the minimum of %d characters', $path, (int) $schema['minLength']);
        }

        if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
            $violations[] = sprintf('%s: longer than the maximum of %d characters', $path, (int) $schema['maxLength']);
        }

        return $violations;
    }

    /**
     * @param  mixed  $value
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function checkEnum($value, array $schema, string $path): array
    {
        if (! isset($schema['enum'])) {
            return [];
        }

        $allowed = (array) $schema['enum'];

        if (in_array($value, $allowed, true)) {
            return [];
        }

        return [sprintf(
            '%s: [%s] is not one of the permitted values (%s)',
            $path,
            is_scalar($value) ? (string) $value : $this->describe($value),
            implode(', ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '?', $allowed)),
        )];
    }

    /**
     * @param  mixed  $value
     */
    private function matchesType($value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && ! array_is_list($value),
            'array' => is_array($value) && (array_is_list($value) || $value === []),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    /**
     * @param  mixed  $value
     */
    private function describe($value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => array_is_list($value) ? 'array' : 'object',
            default => 'unknown',
        };
    }
}
