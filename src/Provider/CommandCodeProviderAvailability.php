<?php

declare(strict_types=1);

namespace WordPress\CommandCodeAiProvider\Provider;

use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

/**
 * Class to check availability for the Command Code provider.
 *
 * The availability check lists models via the provider's models endpoint,
 * which requires valid credentials. Failures are logged with the underlying
 * exception so that connector validation problems (invalid key, plan
 * restrictions, network errors) are diagnosable from the WordPress debug log
 * instead of surfacing only as the generic core validation error.
 *
 * @since 0.1.1
 */
class CommandCodeProviderAvailability implements ProviderAvailabilityInterface
{
    /**
     * The model metadata directory used to check availability.
     *
     * @var ModelMetadataDirectoryInterface
     */
    private ModelMetadataDirectoryInterface $modelMetadataDirectory;

    /**
     * Constructor.
     *
     * @since 0.1.1
     *
     * @param ModelMetadataDirectoryInterface $modelMetadataDirectory The model metadata directory to use.
     */
    public function __construct(ModelMetadataDirectoryInterface $modelMetadataDirectory)
    {
        $this->modelMetadataDirectory = $modelMetadataDirectory;
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.1
     */
    public function isConfigured(): bool
    {
        try {
            // Attempt to list models to check if the provider is available.
            $this->modelMetadataDirectory->listModelMetadata();
            return true;
        } catch (\Throwable $e) {
            $this->logFailure($e);
            return false;
        }
    }

    /**
     * Logs an availability check failure to the WordPress debug log.
     *
     * @since 0.1.1
     *
     * @param \Throwable $e The exception caught during the availability check.
     * @return void
     */
    private function logFailure(\Throwable $e): void
    {
        if (!function_exists('error_log')) {
            return;
        }
        error_log(
            sprintf(
                '[ai-provider-for-commandcode] Provider availability check failed: %s: %s',
                get_class($e),
                $e->getMessage()
            )
        );
    }
}
