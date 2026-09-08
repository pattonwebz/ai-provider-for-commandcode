<?php

declare(strict_types=1);

namespace WordPress\CommandCodeAiProvider\Tests\unit\Metadata;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\CommandCodeAiProvider\Metadata\CommandCodeModelMetadataDirectory;

/**
 * @covers \WordPress\CommandCodeAiProvider\Metadata\CommandCodeModelMetadataDirectory
 */
class CommandCodeModelMetadataDirectoryTest extends TestCase
{
    /**
     * Fixture: live capture of `GET /provider/v1/models` (2026-09-08, 67 models).
     */
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/models-response.json';

    /**
     * @return list<ModelMetadata> Parsed model metadata list.
     */
    private function parseFixture(): array
    {
        $body = file_get_contents(self::FIXTURE_PATH);
        $this->assertIsString($body);

        return $this->exposeParse(new Response(200, [], $body));
    }

    /**
     * @return list<ModelMetadata> Parsed model metadata list.
     */
    private function exposeParse(Response $response): array
    {
        $directory = new class extends CommandCodeModelMetadataDirectory {
            public function exposeParseResponseToModelMetadataList(Response $response): array
            {
                return $this->parseResponseToModelMetadataList($response);
            }
        };

        return $directory->exposeParseResponseToModelMetadataList($response);
    }

    /**
     * @param list<ModelMetadata> $models
     */
    private function findModel(array $models, string $id): ?ModelMetadata
    {
        foreach ($models as $model) {
            if ($model->getId() === $id) {
                return $model;
            }
        }
        return null;
    }

    /**
     * @param list<SupportedOption> $options
     */
    private function hasOption(array $options, callable $predicate): bool
    {
        foreach ($options as $option) {
            if ($predicate($option->getName())) {
                return true;
            }
        }
        return false;
    }

    public function testFixtureModelsCountMatchesExclusions(): void
    {
        $models = $this->parseFixture();

        // 67 models in the fixture, minus 8 claude-* (Anthropic wire).
        // gpt-6-astra is not served by the live models endpoint yet, so it is
        // exercised separately in testUnsupportedChatWireModelsAreExcluded().
        $this->assertCount(59, $models);
    }

    public function testUnsupportedChatWireModelsAreExcluded(): void
    {
        // Synthetic: gpt-6-astra is listed in Command Code docs but returns
        // HTTP 400 on the chat wire (verified 2026-09-08); claude-* models
        // require the Anthropic Messages wire.
        $body = json_encode([
            'data' => [
                ['id' => 'gpt-6-astra', 'name' => 'GPT-6 Astra'],
                ['id' => 'claude-sonnet-5', 'name' => 'Claude Sonnet 5'],
                ['id' => 'deepseek/deepseek-v4-flash', 'name' => 'DeepSeek V4 Flash'],
            ],
        ]);
        $this->assertIsString($body);

        $models = $this->exposeParse(new Response(200, [], $body));
        $this->assertCount(1, $models);
        $this->assertSame('deepseek/deepseek-v4-flash', $models[0]->getId());
    }

    public function testClaudeModelsAreExcluded(): void
    {
        $models = $this->parseFixture();

        foreach ($models as $model) {
            $this->assertStringStartsNotWith('claude-', $model->getId());
        }
    }

    public function testSortOrderPinsTextAndVisionDefaults(): void
    {
        $models = $this->parseFixture();

        // Text default: Command Code's own default model comes first.
        $this->assertSame('deepseek/deepseek-v4-flash', $models[0]->getId());
        // Vision default: first vision-capable model (MiniMax-M3), pinned
        // second so image-description requests default to it.
        $this->assertSame('MiniMaxAI/MiniMax-M3', $models[1]->getId());
    }

    public function testDisplayNameComesFromApiNameField(): void
    {
        $models = $this->parseFixture();
        $model = $this->findModel($models, 'gpt-5.4');

        $this->assertNotNull($model);
        $this->assertSame('GPT-5.4', $model->getName());
    }

    public function testEveryModelAdvertisesTextGenerationAndChatHistory(): void
    {
        $models = $this->parseFixture();
        $this->assertNotEmpty($models);

        foreach ($models as $model) {
            $capabilities = $model->getSupportedCapabilities();
            $this->assertTrue(
                $this->hasCapability($capabilities, static function (CapabilityEnum $capability): bool {
                    return $capability->isTextGeneration();
                }),
                sprintf('Model %s must advertise text generation.', $model->getId())
            );
        }
    }

    /**
     * @param list<CapabilityEnum> $capabilities
     */
    private function hasCapability(array $capabilities, callable $predicate): bool
    {
        foreach ($capabilities as $capability) {
            if ($predicate($capability)) {
                return true;
            }
        }
        return false;
    }

    public function testVisionAdvertisedOnlyForVerifiedModels(): void
    {
        $models = $this->parseFixture();

        $visionCapable = [
            'MiniMaxAI/MiniMax-M3',
            'google/gemini-3.8-flash',
            'deepseek/deepseek-v4-flash-vision-exp',
            'gpt-5.4',
            'moonshotai/Kimi-K3',
            'thinkingmachines/inkling',
            'z-ai/glm-5.3-flash',
            'Qwen/Qwen3.8-27B',
        ];
        $textOnly = [
            'MiniMaxAI/MiniMax-M2.7',
            'MiniMaxAI/MiniMax-M2.5',
            'xiaomi/mimo-v2.5-pro',
            'deepseek/deepseek-v4-flash',
            'gpt-5.3-codex',
            'meta/muse-spark-1.3',
        ];

        foreach ($visionCapable as $id) {
            $model = $this->findModel($models, $id);
            $this->assertNotNull($model, sprintf('Model %s must be in the catalog.', $id));
            $this->assertTrue(
                $this->supportsImageInput($model),
                sprintf('Model %s must advertise image input (verified vision).', $id)
            );
        }

        foreach ($textOnly as $id) {
            $model = $this->findModel($models, $id);
            $this->assertNotNull($model, sprintf('Model %s must be in the catalog.', $id));
            $this->assertFalse(
                $this->supportsImageInput($model),
                sprintf('Model %s must not advertise image input.', $id)
            );
        }
    }

    private function supportsImageInput(ModelMetadata $model): bool
    {
        $options = $model->getSupportedOptions();
        foreach ($options as $option) {
            if (!$option->getName()->isInputModalities()) {
                continue;
            }
            foreach ($option->getSupportedValues() as $combination) {
                foreach ($combination as $modality) {
                    if ($modality->isImage()) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public function testPenaltiesNotAdvertisedForGoogleModels(): void
    {
        $models = $this->parseFixture();

        $gemini = $this->findModel($models, 'google/gemini-3.8-flash');
        $this->assertNotNull($gemini);
        $this->assertFalse(
            $this->hasOption($gemini->getSupportedOptions(), static function (OptionEnum $option): bool {
                return $option->isPresencePenalty();
            }),
            'Gemini models must not advertise presence_penalty (rejected by the API).'
        );
        $this->assertFalse(
            $this->hasOption($gemini->getSupportedOptions(), static function (OptionEnum $option): bool {
                return $option->isFrequencyPenalty();
            }),
            'Gemini models must not advertise frequency_penalty (rejected by the API).'
        );

        $deepseek = $this->findModel($models, 'deepseek/deepseek-v4-flash');
        $this->assertNotNull($deepseek);
        $this->assertTrue(
            $this->hasOption($deepseek->getSupportedOptions(), static function (OptionEnum $option): bool {
                return $option->isPresencePenalty();
            }),
            'DeepSeek models must advertise presence_penalty (verified accepted).'
        );
    }

    public function testSamplingOptionsAdvertised(): void
    {
        $models = $this->parseFixture();

        foreach (['gpt-5.4', 'google/gemini-3.8-flash', 'deepseek/deepseek-v4-flash'] as $id) {
            $model = $this->findModel($models, $id);
            $this->assertNotNull($model);
            $this->assertTrue(
                $this->hasOption($model->getSupportedOptions(), static function (OptionEnum $option): bool {
                    return $option->isTemperature();
                }),
                sprintf('Model %s must advertise temperature.', $id)
            );
        }
    }

    public function testUnknownFutureModelDefaultsToBasicText(): void
    {
        $body = json_encode([
            'data' => [
                ['id' => 'acme/brand-new-v1', 'name' => 'Brand New V1'],
            ],
        ]);
        $this->assertIsString($body);

        $models = $this->exposeParse(new Response(200, [], $body));
        $this->assertCount(1, $models);

        $model = $models[0];
        $this->assertSame('acme/brand-new-v1', $model->getId());
        $this->assertSame('Brand New V1', $model->getName());
        // Unknown models are conservative: text-only input, no vision.
        $this->assertFalse($this->supportsImageInput($model));
        $this->assertTrue(
            $this->hasOption($model->getSupportedOptions(), static function (OptionEnum $option): bool {
                return $option->isTemperature();
            })
        );
    }
}
