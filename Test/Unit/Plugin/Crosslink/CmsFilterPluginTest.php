<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Plugin\Crosslink;

use Magento\Cms\Model\Template\FilterProvider;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Crosslinks\Helper\Config;
use Panth\Crosslinks\Model\Crosslink\ReplacementService;
use Panth\Crosslinks\Plugin\Crosslink\CmsFilterPlugin;
use Panth\Crosslinks\Plugin\Crosslink\CrosslinkFilterDecorator;
use PHPUnit\Framework\TestCase;

class CmsFilterPluginTest extends TestCase
{
    private function plugin(bool $enabled, ?ReplacementService $service = null): CmsFilterPlugin
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(2);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        return new CmsFilterPlugin(
            $service ?? $this->createStub(ReplacementService::class),
            $storeManager,
            $config
        );
    }

    public function testDisabledModuleReturnsOriginalFilters(): void
    {
        $plugin = $this->plugin(false);
        $filter = new \stdClass();
        $provider = $this->createStub(FilterProvider::class);

        $this->assertSame($filter, $plugin->afterGetPageFilter($provider, $filter));
        $this->assertSame($filter, $plugin->afterGetBlockFilter($provider, $filter));
    }

    public function testEnabledModuleWrapsPageAndBlockFilters(): void
    {
        $plugin = $this->plugin(true);
        $provider = $this->createStub(FilterProvider::class);

        $this->assertInstanceOf(
            CrosslinkFilterDecorator::class,
            $plugin->afterGetPageFilter($provider, new \stdClass())
        );
        $this->assertInstanceOf(
            CrosslinkFilterDecorator::class,
            $plugin->afterGetBlockFilter($provider, new \stdClass())
        );
    }

    public function testWrappedFilterProcessesContentAsCmsPage(): void
    {
        $inner = new class {
            public function filter($value)
            {
                return strtoupper((string) $value);
            }
        };
        $service = $this->createMock(ReplacementService::class);
        $service->expects($this->once())
            ->method('processContent')
            ->with('HELLO', 'cms', 2)
            ->willReturn('done');

        $decorated = $this->plugin(true, $service)
            ->afterGetBlockFilter($this->createStub(FilterProvider::class), $inner);

        $this->assertSame('done', $decorated->filter('hello'));
    }
}
