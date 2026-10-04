<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Controller\Adminhtml\Crosslink;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Ui\Component\MassAction\Filter;
use Panth\Crosslinks\Controller\Adminhtml\Crosslink\MassDelete;
use Panth\Crosslinks\Controller\Adminhtml\Crosslink\MassStatus;
use Panth\Crosslinks\Model\ResourceModel\Crosslink\Collection;
use Panth\Crosslinks\Model\ResourceModel\Crosslink\CollectionFactory;
use Panth\Crosslinks\Test\Unit\Controller\Adminhtml\ControllerTestCase;

class MassActionsTest extends ControllerTestCase
{
    private function filter(array $ids): Filter
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getAllIds')->willReturn($ids);
        $filter = $this->createStub(Filter::class);
        $filter->method('getCollection')->willReturn($collection);
        return $filter;
    }

    private function factory(): CollectionFactory
    {
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($this->createStub(Collection::class));
        return $factory;
    }

    public function testMassDeleteRemovesSelectedIds(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())
            ->method('delete')
            ->with('panth_seo_crosslink', ['crosslink_id IN (?)' => [1, 2, 3]])
            ->willReturn(3);

        (new MassDelete(
            $this->buildContext(),
            $this->filter(['1', '2', '3']),
            $this->factory(),
            $this->resource($connection),
            $this->cacheTypeList()
        ))->execute();

        $this->assertSame(['A total of 3 crosslink(s) have been deleted.'], $this->messages['success']);
        $this->assertSame([['full_page', 'block_html']], $this->invalidated);
        $this->assertSame('*/*/', $this->redirect['path']);
    }

    public function testMassDeleteWithNoSelectionReportsError(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('delete');

        (new MassDelete($this->buildContext(), $this->filter([]), $this->factory(), $this->resource($connection), $this->cacheTypeList()))
            ->execute();

        $this->assertSame(['No crosslinks were selected.'], $this->messages['error']);
        $this->assertSame([], $this->invalidated);
    }

    public function testMassDeleteReportsExceptions(): void
    {
        $filter = $this->createStub(Filter::class);
        $filter->method('getCollection')->willThrowException(new \RuntimeException('bad filter'));

        (new MassDelete(
            $this->buildContext(),
            $filter,
            $this->factory(),
            $this->resource($this->createStub(AdapterInterface::class)),
            $this->cacheTypeList()
        ))->execute();

        $this->assertSame(['bad filter'], $this->messages['error']);
        $this->assertSame('*/*/', $this->redirect['path']);
    }

    public function testMassStatusEnablesSelectedIds(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())
            ->method('update')
            ->with('panth_seo_crosslink', ['is_active' => 1], ['crosslink_id IN (?)' => [4, 5]])
            ->willReturn(2);

        (new MassStatus(
            $this->buildContext(['status' => '1']),
            $this->filter([4, 5]),
            $this->factory(),
            $this->resource($connection),
            $this->cacheTypeList()
        ))->execute();

        $this->assertSame(['A total of 2 crosslink(s) have been enabled.'], $this->messages['success']);
        $this->assertSame([['full_page', 'block_html']], $this->invalidated);
    }

    public function testMassStatusTreatsAnyOtherValueAsDisable(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())
            ->method('update')
            ->with('panth_seo_crosslink', ['is_active' => 0], $this->anything())
            ->willReturn(1);

        (new MassStatus(
            $this->buildContext(['status' => '7']),
            $this->filter([4]),
            $this->factory(),
            $this->resource($connection),
            $this->cacheTypeList()
        ))->execute();

        $this->assertSame(['A total of 1 crosslink(s) have been disabled.'], $this->messages['success']);
    }

    public function testMassStatusWithNoSelectionReportsError(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('update');

        (new MassStatus(
            $this->buildContext(['status' => 1]),
            $this->filter([]),
            $this->factory(),
            $this->resource($connection),
            $this->cacheTypeList()
        ))->execute();

        $this->assertSame(['No crosslinks were selected.'], $this->messages['error']);
        $this->assertSame('*/*/', $this->redirect['path']);
    }

    public function testMassStatusReportsDatabaseErrors(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('update')->willThrowException(new \RuntimeException('deadlock'));

        (new MassStatus(
            $this->buildContext(['status' => 1]),
            $this->filter([1]),
            $this->factory(),
            $this->resource($connection),
            $this->cacheTypeList()
        ))->execute();

        $this->assertSame(['deadlock'], $this->messages['error']);
    }
}
