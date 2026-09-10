<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domain\Ai\SchemaValidator;
use App\Exceptions\AiSchemaValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Model output is a proposal. These tests are about what it takes to become
 * anything more than that.
 */
class AiSchemaValidationTest extends TestCase
{
    private SchemaValidator $validator;

    /** @var array<string, mixed> */
    private array $schema = [
        'type' => 'object',
        'required' => ['title', 'items'],
        'properties' => [
            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
            'items' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => [
                    'type' => 'object',
                    'required' => ['label'],
                    'properties' => [
                        'label' => ['type' => 'string'],
                        'kind' => ['type' => 'string', 'enum' => ['a', 'b']],
                    ],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new SchemaValidator;
    }

    #[Test]
    public function conforming_output_is_returned_as_a_plain_array(): void
    {
        $result = $this->validator->validate(
            '{"title":"Intake","items":[{"label":"One","kind":"a"}]}',
            $this->schema,
        );

        $this->assertSame('Intake', $result['title']);
        $this->assertSame('One', $result['items'][0]['label']);
    }

    #[Test]
    public function a_markdown_fence_around_the_json_is_tolerated(): void
    {
        $result = $this->validator->validate(
            "```json\n{\"title\":\"Intake\",\"items\":[{\"label\":\"One\"}]}\n```",
            $this->schema,
        );

        $this->assertSame('Intake', $result['title']);
    }

    #[Test]
    public function output_that_is_not_json_is_refused(): void
    {
        $this->expectException(AiSchemaValidationException::class);

        $this->validator->validate('Here is the form you asked for!', $this->schema);
    }

    #[Test]
    public function a_missing_required_property_is_refused_and_named(): void
    {
        try {
            $this->validator->validate('{"items":[{"label":"One"}]}', $this->schema);
            $this->fail('Missing required properties must be refused.');
        } catch (AiSchemaValidationException $e) {
            $this->assertStringContainsString('missing required property [title]', $e->getMessage());
        }
    }

    #[Test]
    public function a_wrong_type_is_refused(): void
    {
        try {
            $this->validator->validate('{"title":7,"items":[{"label":"One"}]}', $this->schema);
            $this->fail('A wrong type must be refused.');
        } catch (AiSchemaValidationException $e) {
            $this->assertStringContainsString('expected string, got integer', $e->getMessage());
        }
    }

    #[Test]
    public function a_value_outside_the_permitted_set_is_refused(): void
    {
        try {
            $this->validator->validate(
                '{"title":"Intake","items":[{"label":"One","kind":"c"}]}',
                $this->schema,
            );
            $this->fail('An out-of-vocabulary value must be refused.');
        } catch (AiSchemaValidationException $e) {
            $this->assertStringContainsString('not one of the permitted values', $e->getMessage());
        }
    }

    #[Test]
    public function an_unexpected_property_is_refused_rather_than_carried_forward(): void
    {
        // Accepting an unknown key would carry unvalidated content into the
        // application, and usually means the model answered a different
        // question.
        try {
            $this->validator->validate(
                '{"title":"Intake","items":[{"label":"One"}],"run":"rm -rf /"}',
                $this->schema,
            );
            $this->fail('Unexpected properties must be refused.');
        } catch (AiSchemaValidationException $e) {
            $this->assertStringContainsString('unexpected property [run]', $e->getMessage());
        }
    }

    #[Test]
    public function a_schema_keyword_the_validator_does_not_understand_is_refused_not_ignored(): void
    {
        // Silently skipping an unrecognised keyword would leave the author
        // believing a constraint applies when it does not.
        try {
            $this->validator->validate('{"a":1}', [
                'type' => 'object',
                'properties' => ['a' => ['type' => 'integer', 'multipleOf' => 2]],
            ]);
            $this->fail('An unsupported keyword must be refused.');
        } catch (AiSchemaValidationException $e) {
            $this->assertStringContainsString('unsupported keyword', $e->getMessage());
        }
    }

    #[Test]
    public function bounds_on_length_and_item_count_are_applied(): void
    {
        try {
            $this->validator->validate(
                '{"title":"'.str_repeat('x', 40).'","items":[]}',
                $this->schema,
            );
            $this->fail('Bounds must be applied.');
        } catch (AiSchemaValidationException $e) {
            $this->assertStringContainsString('longer than the maximum', $e->getMessage());
            $this->assertStringContainsString('at least 1 item', $e->getMessage());
        }
    }

    #[Test]
    public function validation_returns_inert_data_and_never_evaluates_it(): void
    {
        // The validator's whole job is to hand back a PHP array. Nothing it
        // returns is a callable, an object, or anything that could be invoked
        // - which is what makes "generated code is never executed" a property
        // of the type rather than a promise.
        $result = $this->validator->validate(
            '{"title":"<?php echo 1; ?>","items":[{"label":"system(\'ls\')"}]}',
            $this->schema,
        );

        $this->assertIsArray($result);
        $this->assertIsString($result['title']);
        $this->assertSame('<?php echo 1; ?>', $result['title']);
        $this->assertSame("system('ls')", $result['items'][0]['label']);
    }
}
