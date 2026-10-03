<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Store\Model\System\Store as SystemStore;
use Panth\Crosslinks\Ui\Component\Listing\Column\Store;
use PHPUnit\Framework\TestCase;

class StoreTest extends TestCase
{
    private array $requestedStores = [];

    private function column(): Store
    {
        $systemStore = $this->createStub(SystemStore::class);
        $systemStore->method('getStoresStructure')->willReturnCallback(function ($isAll, $ids) {
            $this->requestedStores = $ids;
            return [
                ['children' => [
                    ['children' => [
                        ['label' => 'English'],
                        ['label' => 'French & Co'],
                    ]],
                ]],
                ['children' => []],
            ];
        });
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(
            static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES)
        );

        return new Store(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $systemStore,
            $escaper,
            [],
            ['name' => 'store_label']
        );
    }

    private function label(array $item): string
    {
        $result = $this->column()->prepareDataSource(['data' => ['items' => [$item]]]);
        return $result['data']['items'][0]['store_label'];
    }

    public function testScalarStoreZeroMeansAllStoreViews(): void
    {
        $this->assertSame('All Store Views', $this->label(['store_id' => '0']));
    }

    public function testScalarStoreIdIsNormalisedToArray(): void
    {
        $column = $this->column();
        $result = $column->prepareDataSource(['data' => ['items' => [['store_id' => '2']]]]);

        $this->assertSame([2], $result['data']['items'][0]['store_id']);
        $this->assertSame([2], $this->requestedStores);
        $this->assertSame('English<br/>French &amp; Co', $result['data']['items'][0]['store_label']);
    }

    public function testArrayContainingZeroMeansAllStoreViews(): void
    {
        $this->assertSame('All Store Views', $this->label(['store_id' => ['1', '0']]));
    }

    public function testMissingOrEmptyStoreGivesEmptyLabel(): void
    {
        $this->assertSame('', $this->label(['crosslink_id' => 1]));
        $this->assertSame('', $this->label(['store_id' => []]));
    }

    public function testDataSourceWithoutItemsIsUnchanged(): void
    {
        $this->assertSame(['data' => []], $this->column()->prepareDataSource(['data' => []]));
    }
}
