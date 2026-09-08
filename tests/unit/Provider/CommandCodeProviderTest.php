<?php

declare(strict_types=1);

namespace WordPress\CommandCodeAiProvider\Tests\unit\Provider;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\AiClient;
use WordPress\CommandCodeAiProvider\Provider\CommandCodeProvider;

/**
 * @covers \WordPress\CommandCodeAiProvider\Provider\CommandCodeProvider
 */
class CommandCodeProviderTest extends TestCase
{
    public function testMetadataIdAndName(): void
    {
        $metadata = CommandCodeProvider::metadata();

        $this->assertSame('commandcode', $metadata->getId());
        $this->assertSame('Command Code', $metadata->getName());
    }

    public function testMetadataAuthentication(): void
    {
        $metadata = CommandCodeProvider::metadata();
        $authMethod = $metadata->getAuthenticationMethod();

        $this->assertNotNull($authMethod);
        $this->assertTrue($authMethod->isApiKey());
    }

    public function testMetadataCredentialsUrl(): void
    {
        $metadata = CommandCodeProvider::metadata();

        $this->assertSame('https://commandcode.ai/studio', $metadata->getCredentialsUrl());
    }

    public function testLogoPathExistsWhenSupportedBySdk(): void
    {
        if (version_compare(AiClient::VERSION, '1.3.0', '<')) {
            $this->markTestSkipped('Provider logoPath support requires PHP AI Client 1.3.0 or later.');
        }

        $metadata = CommandCodeProvider::metadata();
        $logoPath = $metadata->getLogoPath();

        $this->assertNotNull($logoPath);
        $this->assertFileExists($logoPath);
    }

    public function testUrlJoinsBaseAndPath(): void
    {
        $this->assertSame(
            'https://api.commandcode.ai/provider/v1',
            CommandCodeProvider::url()
        );
        $this->assertSame(
            'https://api.commandcode.ai/provider/v1/chat/completions',
            CommandCodeProvider::url('chat/completions')
        );
    }

    public function testProviderRegistersInDefaultRegistry(): void
    {
        $registry = AiClient::defaultRegistry();
        if (!$registry->hasProvider(CommandCodeProvider::class)) {
            $registry->registerProvider(CommandCodeProvider::class);
        }

        $this->assertTrue($registry->hasProvider(CommandCodeProvider::class));
        $this->assertTrue($registry->hasProvider('commandcode'));
    }
}
