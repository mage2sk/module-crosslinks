<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Panth\Crosslinks\Ui\Component\Listing\Column\CrosslinkActions;
use PHPUnit\Framework\TestCase;

class CrosslinkActionsTest extends TestCase
{
    private function column(): CrosslinkActions
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn($route, $params = []) => $route . '/id/' . $params['id']
        );

        return new CrosslinkActions(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $url,
            [],
            ['name' => 'actions']
        );
    }

    public function testDataSourceWithoutItemsIsUnchanged(): void
    {
        $source = ['data' => ['totalRecords' => 0]];

        $this->assertSame($source, $this->column()->prepareDataSource($source));
    }

    public function testEditAndDeleteActionsAreAdded(): void
    {
        $result = $this->column()->prepareDataSource(['data' => ['items' => [['crosslink_id' => 5]]]]);
        $actions = $result['data']['items'][0]['actions'];

        $this->assertSame(CrosslinkActions::URL_PATH_EDIT . '/id/5', $actions['edit']['href']);
        $this->assertSame('Edit', $actions['edit']['label']);
        $this->assertSame(CrosslinkActions::URL_PATH_DELETE . '/id/5', $actions['delete']['href']);
        $this->assertTrue($actions['delete']['post']);
        $this->assertSame('Delete crosslink', $actions['delete']['confirm']['title']);
    }

    public function testRowsWithoutIdAreSkipped(): void
    {
        $result = $this->column()->prepareDataSource(['data' => ['items' => [['keyword' => 'x']]]]);

        $this->assertArrayNotHasKey('actions', $result['data']['items'][0]);
    }
}
