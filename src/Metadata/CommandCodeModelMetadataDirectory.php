<?php

declare(strict_types=1);

namespace WordPress\CommandCodeAiProvider\Metadata;

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use WordPress\CommandCodeAiProvider\Provider\CommandCodeProvider;

/**
 * Class for the Command Code model metadata directory.
 *
 * Model metadata is fetched live from Command Code's `GET /provider/v1/models`
 * endpoint and classified into capabilities and supported options. The Command
 * Code API does not return capability information, so the classification is
 * hardcoded here. Every rule in this file was verified against the live API on
 * 2026-09-08:
 *
 * - All models except the `claude-*` family are served via the OpenAI-compatible
 *   Chat Completions wire (`/provider/v1/chat/completions`). `claude-*` models
 *   require the Anthropic Messages wire and are therefore excluded.
 * - `temperature`/`top_p` were accepted by every tested family (including the
 *   GPT-5.x and Gemini lines, which reject them on their native APIs).
 * - `presence_penalty`/`frequency_penalty` were rejected only by the Google
 *   Gemini family.
 * - Vision (image input) support varies per model and is whitelisted in
 *   {@see self::isVisionCapable()}. Note: "vision" here means the model
 *   *describes* an image (image in, text out) — Command Code offers no
 *   image-generation models.
 *
 * @since 0.1.0
 *
 * @phpstan-type ModelsResponseData array{
 *     data: list<array{
 *         id: string,
 *         object?: string,
 *         created?: int,
 *         owned_by?: string,
 *         name?: string,
 *         context_length?: int
 *     }>
 * }
 */
class CommandCodeModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * Regular expression matching model IDs served on the Anthropic Messages
     * wire (`/provider/v1/messages`), which this provider does not implement.
     *
     * Verified 2026-09-08: sending a `claude-*` model to
     * `/provider/v1/chat/completions` returns HTTP 400 `unsupported_model`.
     *
     * @since 0.1.0
     *
     * @var string
     */
    private const ANTHROPIC_WIRE_MODEL_ID_PATTERN = '/^claude-/';

    /**
     * Regular expressions matching model IDs that are listed by the models
     * endpoint but are not callable on the Chat Completions wire.
     *
     * Verified 2026-09-08: `gpt-6-astra` returns HTTP 400 "not available on
     * this endpoint yet" on `/provider/v1/chat/completions`. Remove an entry
     * here once Command Code enables the model on the chat wire.
     *
     * @since 0.1.0
     *
     * @var list<string>
     */
    private const UNSUPPORTED_CHAT_WIRE_MODEL_ID_PATTERNS = [
        '/^gpt-6-astra$/',
    ];

    /**
     * Regular expressions matching model IDs verified (2026-09-08) to accept
     * image input on the Chat Completions wire, i.e. vision models that
     * describe or analyze an image and return text.
     *
     * These models advertise the `[text, image]` input modality combination;
     * all other models advertise text-only input. New Command Code models are
     * treated as text-only until verified — under-advertising is safe, while
     * over-advertising vision causes API errors.
     *
     * @since 0.1.0
     *
     * @var list<string>
     */
    private const VISION_MODEL_ID_PATTERNS = [
        // Verified: described a 1x1 image (200).
        '/^deepseek\/deepseek-v4-flash-vision-exp$/',
        // Verified (3.8 flash); remaining Gemini flash lines are multimodal per Command Code docs.
        '/^google\/gemini-3\./',
        // Verified: described a 1x1 image (200). Qwen3.8-Max rejected image input.
        '/^Qwen\/Qwen3\.8-27B$/',
        // Verified (gpt-5.4). GPT-5.x chat models are vision-capable upstream;
        // 5.3-codex and the 5.6 line are intentionally not matched.
        '/^gpt-5(?:\.\d+)?(?:-mini)?$/',
        // Verified (K2.6, K3); K2.5/K2.7-Code(-Highspeed) advertise vision in docs.
        '/^moonshotai\/Kimi-K2\.[567]|^moonshotai\/Kimi-K3$/',
        // Verified: described a 1x1 image (200). MiniMax M2.5/M2.7 are text-only.
        '/^MiniMaxAI\/MiniMax-M3$/',
        // Verified: described a 1x1 image (200).
        '/^thinkingmachines\/inkling(?:-small)?$/',
        // Verified: described a 1x1 image (200).
        '/^z-ai\/glm-5\.3-flash$/',
    ];

    /**
     * Regular expressions matching model IDs that accept
     * `presence_penalty`/`frequency_penalty`. Verified 2026-09-08: the Google
     * Gemini family is the only tested family that rejects them (HTTP 400).
     *
     * @since 0.1.0
     *
     * @var list<string>
     */
    private const PENALTY_SUPPORTING_MODEL_ID_PATTERNS = [
        '/^(?!google\/).*$/',
    ];

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        return new Request(
            $method,
            CommandCodeProvider::url($path),
            $headers,
            $data
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        /** @var ModelsResponseData $responseData */
        $responseData = $response->getData();
        if (!isset($responseData['data']) || !$responseData['data']) {
            throw ResponseException::fromMissingData('Command Code', 'data');
        }

        $textCapabilities = [
            CapabilityEnum::textGeneration(),
            CapabilityEnum::chatHistory(),
        ];
        $baseOptions = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']),
            new SupportedOption(OptionEnum::outputSchema()),
            new SupportedOption(OptionEnum::functionDeclarations()),
            new SupportedOption(OptionEnum::customOptions()),
        ];
        $penaltyOptions = [
            new SupportedOption(OptionEnum::presencePenalty()),
            new SupportedOption(OptionEnum::frequencyPenalty()),
        ];

        $modelsData = (array) $responseData['data'];

        $models = array_values(
            array_map(
                static function (array $modelData) use (
                    $textCapabilities,
                    $baseOptions,
                    $penaltyOptions
                ): ?ModelMetadata {
                    if (!isset($modelData['id']) || !is_string($modelData['id'])) {
                        return null;
                    }
                    $modelId = $modelData['id'];

                    if (self::isExcludedModel($modelId)) {
                        return null;
                    }

                    // The API provides a display name when available.
                    $hasDisplayName = isset($modelData['name'])
                        && is_string($modelData['name'])
                        && '' !== $modelData['name'];
                    $displayName = $hasDisplayName ? $modelData['name'] : $modelId;

                    $modelOptions = $baseOptions;

                    // Penalties are only advertised for families verified to accept them.
                    if (self::supportsPenalties($modelId)) {
                        $modelOptions = array_merge($modelOptions, $penaltyOptions);
                    }

                    // Input modalities: text-only by default, image input for verified vision models.
                    $inputModalities = [[ModalityEnum::text()]];
                    if (self::isVisionCapable($modelId)) {
                        $inputModalities[] = [ModalityEnum::text(), ModalityEnum::image()];
                    }
                    $modelOptions[] = new SupportedOption(OptionEnum::inputModalities(), $inputModalities);
                    $modelOptions[] = new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]);

                    return new ModelMetadata(
                        $modelId,
                        $displayName,
                        $textCapabilities,
                        $modelOptions
                    );
                },
                $modelsData
            )
        );

        // Remove entries skipped by the classifier (excluded models).
        $models = array_values(array_filter($models));

        usort($models, [$this, 'modelSortCallback']);

        return $this->applyModelFilters($models);
    }

    /**
     * Applies the `ai_provider_for_commandcode_models` filter to the model list.
     *
     * Sites can use this filter to restrict the catalog to preferred models
     * (e.g. an allowlist of models they want to expose or pay for). The filter
     * must return a list of {@see ModelMetadata} instances. Filtering here
     * affects everything that reads the provider's catalog: automatic model
     * selection, model pickers in consuming plugins, and model preferences.
     *
     * Usage example — allowlist:
     *
     *     add_filter( 'ai_provider_for_commandcode_models', static function ( array $models ): array {
     *         $allowed = array( 'deepseek/deepseek-v4-flash', 'MiniMaxAI/MiniMax-M3' );
     *         return array_values( array_filter(
     *             $models,
     *             static function ( ModelMetadata $model ) use ( $allowed ): bool {
     *                 return in_array( $model->getId(), $allowed, true );
     *             }
     *         ) );
     *     } );
     *
     * @since 0.1.1
     *
     * @param list<ModelMetadata> $models The sorted model metadata list.
     * @return list<ModelMetadata> The filtered model metadata list.
     */
    protected function applyModelFilters(array $models): array
    {
        if (!function_exists('apply_filters')) {
            return $models;
        }

        $filtered = apply_filters('ai_provider_for_commandcode_models', $models);
        if (!is_array($filtered)) {
            return $models;
        }

        return array_values(array_filter(
            $filtered,
            static function ($model): bool {
                return $model instanceof ModelMetadata;
            }
        ));
    }

    /**
     * Checks whether a model ID is served by this provider at all.
     *
     * @since 0.1.0
     *
     * @param string $modelId The model ID.
     * @return bool True if the model should be excluded from the catalog.
     */
    private static function isExcludedModel(string $modelId): bool
    {
        if (preg_match(self::ANTHROPIC_WIRE_MODEL_ID_PATTERN, $modelId)) {
            return true;
        }

        foreach (self::UNSUPPORTED_CHAT_WIRE_MODEL_ID_PATTERNS as $pattern) {
            if (preg_match($pattern, $modelId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether a model supports image input (vision).
     *
     * Vision means the model accepts an image in the prompt and returns a text
     * description or analysis. It does not mean image generation, which the
     * Command Code API does not offer.
     *
     * @since 0.1.0
     *
     * @param string $modelId The model ID.
     * @return bool True if the model is verified vision-capable, false otherwise.
     */
    private static function isVisionCapable(string $modelId): bool
    {
        foreach (self::VISION_MODEL_ID_PATTERNS as $pattern) {
            if (preg_match($pattern, $modelId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether a model accepts `presence_penalty` and `frequency_penalty`.
     *
     * @since 0.1.0
     *
     * @param string $modelId The model ID.
     * @return bool True if the model supports the penalty options.
     */
    private static function supportsPenalties(string $modelId): bool
    {
        foreach (self::PENALTY_SUPPORTING_MODEL_ID_PATTERNS as $pattern) {
            if (preg_match($pattern, $modelId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Callback function for sorting models by ID, to be used with `usort()`.
     *
     * The ordering intentionally drives the AI Client's automatic model
     * selection defaults: `deepseek/deepseek-v4-flash` (Command Code's default
     * model) is pinned first for text tasks, and `MiniMaxAI/MiniMax-M3` is
     * pinned second as the default vision model — text-only models drop out of
     * the vision candidate pool via input-modality filtering, so one global
     * order can bias both defaults independently.
     *
     * @since 0.1.0
     *
     * @param ModelMetadata $a First model.
     * @param ModelMetadata $b Second model.
     * @return int Comparison result.
     */
    protected function modelSortCallback(ModelMetadata $a, ModelMetadata $b): int
    {
        $aId = $a->getId();
        $bId = $b->getId();

        $aRank = self::modelRank($aId);
        $bRank = self::modelRank($bId);
        if ($aRank !== $bRank) {
            return $aRank <=> $bRank;
        }

        // Prefer non-preview models over preview models.
        if (false !== strpos($aId, '-preview') && false === strpos($bId, '-preview')) {
            return 1;
        }
        if (false !== strpos($bId, '-preview') && false === strpos($aId, '-preview')) {
            return -1;
        }

        return strcmp($aId, $bId);
    }

    /**
     * Returns the sort rank for a model ID.
     *
     * @since 0.1.0
     *
     * @param string $modelId The model ID.
     * @return int The sort rank (lower sorts first).
     */
    private static function modelRank(string $modelId): int
    {
        if ('deepseek/deepseek-v4-flash' === $modelId) {
            return 0;
        }
        if ('MiniMaxAI/MiniMax-M3' === $modelId) {
            return 1;
        }
        return 2;
    }
}
