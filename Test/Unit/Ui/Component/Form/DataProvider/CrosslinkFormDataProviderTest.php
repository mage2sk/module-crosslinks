<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Ui\Component\Form\DataProvider;

use Magento\Framework\DataObject;
use Panth\Crosslinks\Model\ResourceModel\Crosslink\Collection;
use Panth\Crosslinks\Model\ResourceModel\Crosslink\CollectionFactory;
use Panth\Crosslinks\Ui\Component\Form\DataProvider\CrosslinkFormDataProvider;
use PHPUnit\Framework\TestCase;

class CrosslinkFormDataProviderTest extends TestCase
{
    private function provider(array $items, ?Collection $collection = null): CrosslinkFormDataProvider
    {
        if ($collection === null) {
            $collection = $this->createStub(Collection::class);
            $collection->method('getItems')->willReturn($items);
        }
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new CrosslinkFormDataProvider('crosslink_form_data_source', 'crosslink_id', 'id', $factory);
    }

    public function testItemsAreKeyedById(): void
    {
        $items = [
            new DataObject(['id' => 3, 'keyword' => 'shoes']),
            new DataObject(['id' => 8, 'keyword' => 'boots']),
        ];

        $data = $this->provider($items)->getData();

        $this->assertSame([3, 8], array_keys($data));
        $this->assertSame('boots', $data[8]['keyword']);
    }

    public function testNewRecordGetsDefaults(): void
    {
        $data = $this->provider([])->getData();

        $this->assertSame([''], array_keys($data));
        $this->assertSame(1, $data['']['is_active']);
        $this->assertSame(1, $data['']['in_product']);
        $this->assertSame(1, $data['']['in_category']);
        $this->assertSame(1, $data['']['in_cms']);
        $this->assertSame(1, $data['']['max_replacements']);
        $this->assertSame(0, $data['']['nofollow']);
        $this->assertSame(0, $data['']['priority']);
        $this->assertSame(0, $data['']['store_id']);
        $this->assertSame('url', $data['']['reference_type']);
    }

    public function testDataIsLoadedOnlyOnce(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())
            ->method('getItems')
            ->willReturn([new DataObject(['id' => 1, 'keyword' => 'x'])]);

        $provider = $this->provider([], $collection);

        $this->assertSame($provider->getData(), $provider->getData());
    }
}
