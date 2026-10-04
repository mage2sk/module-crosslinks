<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\Crosslinks\Helper\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function config(array $values = [], array $flags = []): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn(string $path) => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn(string $path) => (bool) ($flags[$path] ?? false)
        );

        return new Config($scopeConfig);
    }

    public function testEnabledFlagIsReadFromConfig(): void
    {
        $this->assertTrue($this->config([], [Config::XML_ENABLED => true])->isEnabled(1));
        $this->assertFalse($this->config()->isEnabled(1));
    }

    public function testCrosslinksEnabledAliasMirrorsIsEnabled(): void
    {
        $this->assertTrue($this->config([], [Config::XML_ENABLED => true])->isCrosslinksEnabled(2));
        $this->assertFalse($this->config()->isCrosslinksEnabled(2));
    }

    public function testEnabledFlagIsReadInStoreScopeForTheGivenStore(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with(Config::XML_ENABLED, ScopeInterface::SCOPE_STORE, 7)
            ->willReturn(true);

        $this->assertTrue((new Config($scopeConfig))->isEnabled(7));
    }

    public function testMaxLinksPerPageUsesConfiguredPositiveValue(): void
    {
        $this->assertSame(12, $this->config([Config::XML_MAX_LINKS_PER_PAGE => '12'])->getMaxLinksPerPage());
    }

    public function testMaxLinksPerPageFallsBackToDefaultForMissingZeroNegativeOrText(): void
    {
        $this->assertSame(Config::DEFAULT_MAX_LINKS_PER_PAGE, $this->config()->getMaxLinksPerPage());
        foreach (['0', '-3', 'abc'] as $value) {
            $this->assertSame(
                Config::DEFAULT_MAX_LINKS_PER_PAGE,
                $this->config([Config::XML_MAX_LINKS_PER_PAGE => $value])->getMaxLinksPerPage(),
                'value: ' . $value
            );
        }
    }

    public function testExcludedTagsUseConfiguredValue(): void
    {
        $this->assertSame(
            'strong,em',
            $this->config([Config::XML_EXCLUDED_TAGS => 'strong,em'])->getExcludedTags()
        );
    }

    public function testExcludedTagsFallBackToDefaultWhenEmpty(): void
    {
        $this->assertSame(Config::DEFAULT_EXCLUDED_TAGS, $this->config()->getExcludedTags());
        $this->assertSame(
            Config::DEFAULT_EXCLUDED_TAGS,
            $this->config([Config::XML_EXCLUDED_TAGS => ''])->getExcludedTags()
        );
    }

    public function testTimeActivationFlag(): void
    {
        $this->assertTrue($this->config([], [Config::XML_TIME_ACTIVATION => true])->isTimeActivationEnabled());
        $this->assertFalse($this->config()->isTimeActivationEnabled());
    }

    public function testLimitPerContentOnlyForContentScope(): void
    {
        $this->assertTrue(
            $this->config([Config::XML_LIMIT_SCOPE => Config::LIMIT_SCOPE_CONTENT])->isLimitPerContent()
        );
        $this->assertFalse(
            $this->config([Config::XML_LIMIT_SCOPE => Config::LIMIT_SCOPE_PAGE])->isLimitPerContent()
        );
        $this->assertFalse($this->config()->isLimitPerContent());
    }
}
