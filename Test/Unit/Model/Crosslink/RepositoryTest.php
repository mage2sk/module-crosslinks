<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Model\Crosslink;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\CouldNotSaveException;
use Panth\Crosslinks\Model\Crosslink\Repository;
use PHPUnit\Framework\TestCase;

class RepositoryTest extends TestCase
{
    private function resource(AdapterInterface $connection): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function selectingConnection(bool $tableExists, $row): AdapterInterface
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn($tableExists);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn($row);
        return $connection;
    }

    public function testGetByIdReturnsNullWhenTableIsMissing(): void
    {
        $repo = new Repository($this->resource($this->selectingConnection(false, ['crosslink_id' => 1])));

        $this->assertNull($repo->getById(1));
    }

    public function testGetByIdReturnsRow(): void
    {
        $row = ['crosslink_id' => 4, 'keyword' => 'shoes'];
        $repo = new Repository($this->resource($this->selectingConnection(true, $row)));

        $this->assertSame($row, $repo->getById(4));
    }

    public function testGetByIdReturnsNullWhenRowIsMissing(): void
    {
        $repo = new Repository($this->resource($this->selectingConnection(true, false)));

        $this->assertNull($repo->getById(99));
    }

    public function testSaveRejectsUnsupportedEntity(): void
    {
        $repo = new Repository($this->resource($this->createStub(AdapterInterface::class)));

        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessage('Unsupported crosslink entity.');
        $repo->save('not-an-entity');
    }

    public function testSaveRequiresKeywordForNewEntity(): void
    {
        $repo = new Repository($this->resource($this->createStub(AdapterInterface::class)));

        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessage('Keyword is required.');
        $repo->save(['keyword' => '   ', 'url' => '/x']);
    }

    public function testSaveInsertsOnlyKnownColumnsAndReturnsNewId(): void
    {
        $connection = $this->createMock(Mysql::class);
        $connection->expects($this->once())
            ->method('insert')
            ->with('panth_seo_crosslink', ['keyword' => 'shoes', 'url' => '/s']);
        $connection->expects($this->never())->method('update');
        $connection->method('lastInsertId')->willReturn('31');

        $result = (new Repository($this->resource($connection)))->save([
            'keyword' => 'shoes',
            'url' => '/s',
            'form_key' => 'abc',
            'evil_column' => 'x',
        ]);

        $this->assertSame(31, $result['crosslink_id']);
        $this->assertSame('shoes', $result['keyword']);
    }

    public function testSaveUpdatesExistingRowById(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())
            ->method('update')
            ->with('panth_seo_crosslink', ['priority' => 5], ['crosslink_id = ?' => 8]);
        $connection->expects($this->never())->method('insert');

        $result = (new Repository($this->resource($connection)))->save(['crosslink_id' => '8', 'priority' => 5]);

        $this->assertSame(8, $result['crosslink_id']);
    }

    public function testSaveSkipsUpdateWhenNoKnownColumnsChanged(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('update');
        $connection->expects($this->never())->method('insert');

        $result = (new Repository($this->resource($connection)))->save(['crosslink_id' => 3]);

        $this->assertSame(3, $result['crosslink_id']);
    }

    public function testSaveAcceptsDataObjectAndSetsId(): void
    {
        $connection = $this->createStub(Mysql::class);
        $connection->method('lastInsertId')->willReturn('12');
        $entity = new DataObject(['keyword' => 'boots']);

        $result = (new Repository($this->resource($connection)))->save($entity);

        $this->assertSame($entity, $result);
        $this->assertSame(12, $entity->getData('crosslink_id'));
    }

    public function testSaveWrapsDatabaseErrors(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insert')->willThrowException(new \RuntimeException('Duplicate entry'));

        try {
            (new Repository($this->resource($connection)))->save(['keyword' => 'shoes']);
            $this->fail('Expected CouldNotSaveException');
        } catch (CouldNotSaveException $e) {
            $this->assertSame('Could not save the crosslink: Duplicate entry', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function testDeleteByIdReturnsFalseWhenTableMissing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(false);
        $connection->expects($this->never())->method('delete');

        $this->assertFalse((new Repository($this->resource($connection)))->deleteById(1));
    }

    public function testDeleteByIdReportsWhetherARowWasRemoved(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->expects($this->exactly(2))
            ->method('delete')
            ->with('panth_seo_crosslink', ['crosslink_id = ?' => 6])
            ->willReturnOnConsecutiveCalls(1, 0);

        $repo = new Repository($this->resource($connection));

        $this->assertTrue($repo->deleteById(6));
        $this->assertFalse($repo->deleteById(6));
    }
}
