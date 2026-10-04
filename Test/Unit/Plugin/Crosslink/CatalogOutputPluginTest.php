<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Plugin\Crosslink;

use Magento\Catalog\Helper\Output;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Crosslinks\Helper\Config;
use Panth\Crosslinks\Model\Crosslink\ReplacementService;
use Panth\Crosslinks\Plugin\Crosslink\CatalogOutputPlugin;
use PHPUnit\Framework\TestCase;

class CatalogOutputPluginTest extends TestCase
{
    private function storeManager(int $storeId = 3): StoreManagerInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return $storeManager;
    }

    private function config(bool $enabled): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        return $config;
    }

    public function testProductDescriptionIsProcessedAsProductPage(): void
    {
        $service = $this->createMock(ReplacementService::class);
        $service->expects($this->once())
            ->method('processContent')
            ->with('<p>desc</p>', 'product', 3)
            ->willReturn('<p>linked</p>');

        $plugin = new CatalogOutputPlugin($service, $this->storeManager(), $this->config(true));

        $this->assertSame(
            '<p>linked</p>',
            $plugin->afterProductAttribute(
                $this->createStub(Output::class),
                '<p>desc</p>',
                $this->createStub(Product::class),
                '<p>desc</p>',
                'description'
            )
        );
    }

    public function testShortDescriptionIsAlsoProcessed(): void
    {
        $service = $this->createMock(ReplacementService::class);
        $service->expects($this->once())->method('processContent')->willReturn('x');

        $plugin = new CatalogOutputPlugin($service, $this->storeManager(), $this->config(true));

        $this->assertSame('x', $plugin->afterProductAttribute(
            $this->createStub(Output::class),
            'short',
            $this->createStub(Product::class),
            'short',
            'short_description'
        ));
    }

    public function testOtherProductAttributesAreUntouched(): void
    {
        $service = $this->createMock(ReplacementService::class);
        $service->expects($this->never())->method('processContent');

        $plugin = new CatalogOutputPlugin($service, $this->storeManager(), $this->config(true));

        $this->assertSame('Shoe', $plugin->afterProductAttribute(
            $this->createStub(Output::class),
            'Shoe',
            $this->createStub(Product::class),
            'Shoe',
            'name'
        ));
    }

    public function testEmptyOrNullResultIsReturnedAsIs(): void
    {
        $service = $this->createMock(ReplacementService::class);
        $service->expects($this->never())->method('processContent');

        $plugin = new CatalogOutputPlugin($service, $this->storeManager(), $this->config(true));
        $output = $this->createStub(Output::class);

        $this->assertNull($plugin->afterProductAttribute($output, null, $this->createStub(Product::class), '', 'description'));
        $this->assertSame('', $plugin->afterCategoryAttribute($output, '', $this->createStub(Category::class), '', 'description'));
    }

    public function testDisabledModuleSkipsProcessing(): void
    {
        $service = $this->createMock(ReplacementService::class);
        $service->expects($this->never())->method('processContent');

        $plugin = new CatalogOutputPlugin($service, $this->storeManager(), $this->config(false));
        $output = $this->createStub(Output::class);

        $this->assertSame('desc', $plugin->afterProductAttribute($output, 'desc', $this->createStub(Product::class), 'desc', 'description'));
        $this->assertSame('desc', $plugin->afterCategoryAttribute($output, 'desc', $this->createStub(Category::class), 'desc', 'description'));
    }

    public function testCategoryDescriptionIsProcessedAsCategoryPage(): void
    {
        $service = $this->createMock(ReplacementService::class);
        $service->expects($this->once())
            ->method('processContent')
            ->with('cat desc', 'category', 5)
            ->willReturn('cat linked');

        $plugin = new CatalogOutputPlugin($service, $this->storeManager(5), $this->config(true));

        $this->assertSame('cat linked', $plugin->afterCategoryAttribute(
            $this->createStub(Output::class),
            'cat desc',
            $this->createStub(Category::class),
            'cat desc',
            'description'
        ));
    }

    public function testOtherCategoryAttributesAreUntouched(): void
    {
        $service = $this->createMock(ReplacementService::class);
        $service->expects($this->never())->method('processContent');

        $plugin = new CatalogOutputPlugin($service, $this->storeManager(), $this->config(true));

        $this->assertSame('Shoes', $plugin->afterCategoryAttribute(
            $this->createStub(Output::class),
            'Shoes',
            $this->createStub(Category::class),
            'Shoes',
            'name'
        ));
    }
}
