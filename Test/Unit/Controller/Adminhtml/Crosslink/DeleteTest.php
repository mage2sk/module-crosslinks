<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Controller\Adminhtml\Crosslink;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\Crosslinks\Controller\Adminhtml\Crosslink\Delete;
use Panth\Crosslinks\Test\Unit\Controller\Adminhtml\ControllerTestCase;

class DeleteTest extends ControllerTestCase
{
    public function testDeletesRowAndRedirectsToGrid(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())
            ->method('delete')
            ->with('panth_seo_crosslink', ['crosslink_id = ?' => 14])
            ->willReturn(1);

        (new Delete($this->buildContext(['id' => '14']), $this->resource($connection), $this->cacheTypeList()))->execute();

        $this->assertSame(['Crosslink deleted.'], $this->messages['success']);
        $this->assertSame([['full_page', 'block_html']], $this->invalidated);
        $this->assertSame('*/*/', $this->redirect['path']);
    }

    public function testMissingIdDoesNothing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('delete');

        (new Delete($this->buildContext(), $this->resource($connection), $this->cacheTypeList()))->execute();

        $this->assertSame([], $this->messages['success']);
        $this->assertSame([], $this->messages['error']);
        $this->assertSame('*/*/', $this->redirect['path']);
    }

    public function testDatabaseErrorIsReported(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('delete')->willThrowException(new \RuntimeException('locked'));

        (new Delete($this->buildContext(['id' => 3]), $this->resource($connection), $this->cacheTypeList()))->execute();

        $this->assertSame(['locked'], $this->messages['error']);
        $this->assertSame([], $this->invalidated);
        $this->assertSame([], $this->messages['success']);
        $this->assertSame('*/*/', $this->redirect['path']);
    }
}
