<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Model\ResourceModel\Crosslink;

use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\Crosslinks\Model\Crosslink\Crosslink;
use Panth\Crosslinks\Model\ResourceModel\Crosslink as CrosslinkResource;

class Collection extends AbstractCollection implements SearchResultInterface
{
    protected $_idFieldName = 'crosslink_id';

    private ?AggregationInterface $aggregations = null;

    protected function _construct(): void
    {
        $this->_init(Crosslink::class, CrosslinkResource::class);
    }

    public function getAggregations()
    {
        return $this->aggregations;
    }

    public function setAggregations($aggregations)
    {
        $this->aggregations = $aggregations;
        return $this;
    }

    public function getSearchCriteria()
    {
        return null;
    }

    public function setSearchCriteria(?SearchCriteriaInterface $searchCriteria = null)
    {
        return $this;
    }

    public function getTotalCount()
    {
        return $this->getSize();
    }

    public function setTotalCount($totalCount)
    {
        return $this;
    }

    public function setItems(?array $items = null)
    {
        return $this;
    }
}
