<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Model\Crosslink;

use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Registry;
use Panth\Crosslinks\Model\Crosslink\Crosslink;

/**
 * Builds real crosslink models from plain data arrays.
 */
trait CrosslinkFactoryTrait
{
    /**
     * @param array $data
     * @return Crosslink
     */
    private function crosslink(array $data): Crosslink
    {
        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('crosslink_id');

        return new Crosslink(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            $resource,
            null,
            $data
        );
    }
}
