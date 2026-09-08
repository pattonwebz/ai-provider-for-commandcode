<?php

declare(strict_types=1);

namespace WordPress\CommandCodeAiProvider\Models;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\CommandCodeAiProvider\Provider\CommandCodeProvider;

/**
 * Class for a Command Code text generation model.
 *
 * Command Code exposes an OpenAI-compatible Chat Completions API, so the full
 * request/response handling is inherited from
 * {@see AbstractOpenAiCompatibleTextGenerationModel}. Only the request URL
 * construction is provider specific.
 *
 * @since 0.1.0
 */
class CommandCodeTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel
{
    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        return new Request(
            $method,
            CommandCodeProvider::url($path),
            $headers,
            $data
        );
    }
}
