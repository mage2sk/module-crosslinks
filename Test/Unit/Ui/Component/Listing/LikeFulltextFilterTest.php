<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Ui\Component\Listing;

use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\Crosslinks\Ui\Component\Listing\LikeFulltextFilter;
use PHPUnit\Framework\TestCase;

class LikeFulltextFilterTest extends TestCase
{
    private array $wheres = [];

    private function dbCollection(): AbstractDb
    {
        $this->wheres = [];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn($c) => '`' . $c . '`');
        $connection->method('quoteInto')->willReturnCallback(
            static fn($text, $value) => str_replace('?', "'" . $value . "'", $text)
        );
        $select = $this->createStub(Select::class);
        $select->method('where')->willReturnCallback(function ($cond) use (&$select) {
            $this->wheres[] = $cond;
            return $select;
        });

        $collection = $this->createStub(AbstractDb::class);
        $collection->method('getConnection')->willReturn($connection);
        $collection->method('getSelect')->willReturn($select);
        return $collection;
    }

    private function filter($value): Filter
    {
        $filter = $this->createStub(Filter::class);
        $filter->method('getValue')->willReturn($value);
        return $filter;
    }

    public function testBuildsOrLikeConditionOverStringColumns(): void
    {
        $collection = $this->dbCollection();

        (new LikeFulltextFilter(['keyword', 5, 'url']))->apply($collection, $this->filter('  shoe  '));

        $this->assertSame(["`keyword` LIKE '%shoe%' OR `url` LIKE '%shoe%'"], $this->wheres);
    }

    public function testWildcardsAreEscaped(): void
    {
        $collection = $this->dbCollection();

        (new LikeFulltextFilter(['keyword']))->apply($collection, $this->filter('50%_off'));

        $this->assertSame(["`keyword` LIKE '%50\\%\\_off%'"], $this->wheres);
    }

    public function testSearchValueIsTruncatedTo200Characters(): void
    {
        $collection = $this->dbCollection();

        (new LikeFulltextFilter(['keyword']))->apply($collection, $this->filter(str_repeat('a', 300)));

        $this->assertSame("`keyword` LIKE '%" . str_repeat('a', 200) . "%'", $this->wheres[0]);
    }

    public function testEmptyOrNonScalarValuesAreIgnored(): void
    {
        $collection = $this->dbCollection();
        $applier = new LikeFulltextFilter(['keyword']);

        $applier->apply($collection, $this->filter('   '));
        $applier->apply($collection, $this->filter(['x']));

        $this->assertSame([], $this->wheres);
    }

    public function testNoColumnsOrNonDbCollectionIsIgnored(): void
    {
        $collection = $this->dbCollection();
        (new LikeFulltextFilter([]))->apply($collection, $this->filter('shoe'));
        $this->assertSame([], $this->wheres);

        $plain = $this->createMock(Collection::class);
        $plain->expects($this->never())->method('getSize');
        (new LikeFulltextFilter(['keyword']))->apply($plain, $this->filter('shoe'));
        $this->assertSame([], $this->wheres);
    }
}
