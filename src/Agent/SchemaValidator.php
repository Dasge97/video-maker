<?php

declare(strict_types=1);

namespace App\Agent;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates the plan against prompts/plan.schema.json. The same file is the input schema of the
 * submit_plan tool, so the agent and the checks follow one contract.
 */
final class SchemaValidator
{
    private Validator $validator;
    /** @var array<string, mixed>|null */
    private ?array $schema = null;

    public function __construct(private readonly string $promptsDir)
    {
        $this->validator = new Validator();
        $this->validator->setMaxErrors(10);
    }

    /**
     * @return array<string, mixed> the decoded schema
     */
    public function schema(): array
    {
        return $this->schema ??= json_decode((string) file_get_contents($this->promptsDir.'/plan.schema.json'), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string> errors keyed by JSON pointer; empty when valid
     */
    public function errors(array $data): array
    {
        // Opis validates decoded JSON objects, not PHP associative arrays.
        $json = [] === $data ? new \stdClass() : json_decode(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), false, 512, \JSON_THROW_ON_ERROR);
        $schema = json_decode(json_encode($this->schema(), \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
        $result = $this->validator->validate($json, $schema);
        if ($result->isValid()) {
            return [];
        }
        $errors = [];
        foreach ((new ErrorFormatter())->format($result->error(), false) as $pointer => $message) {
            $errors[ltrim((string) $pointer, '/')] = \is_array($message) ? implode('; ', $message) : (string) $message;
        }

        return $errors;
    }
}
