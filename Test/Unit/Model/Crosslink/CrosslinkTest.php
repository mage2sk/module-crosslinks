<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Model\Crosslink;

use PHPUnit\Framework\TestCase;

class CrosslinkTest extends TestCase
{
    use CrosslinkFactoryTrait;

    public function testCrosslinkIdIsCastToIntOrNull(): void
    {
        $this->assertSame(15, $this->crosslink(['crosslink_id' => '15'])->getCrosslinkId());
        $this->assertNull($this->crosslink([])->getCrosslinkId());
    }

    public function testMaxReplacementsDefaultsToOneWhenEmptyOrZero(): void
    {
        $this->assertSame(1, $this->crosslink([])->getMaxReplacements());
        $this->assertSame(1, $this->crosslink(['max_replacements' => 0])->getMaxReplacements());
        $this->assertSame(4, $this->crosslink(['max_replacements' => '4'])->getMaxReplacements());
    }

    public function testReferenceTypeDefaultsToUrl(): void
    {
        $this->assertSame('url', $this->crosslink([])->getReferenceType());
        $this->assertSame('url', $this->crosslink(['reference_type' => ''])->getReferenceType());
        $this->assertSame(
            'product_sku',
            $this->crosslink(['reference_type' => 'product_sku'])->getReferenceType()
        );
    }

    public function testNullableDateAndReferenceFields(): void
    {
        $model = $this->crosslink([]);
        $this->assertNull($model->getActiveFrom());
        $this->assertNull($model->getActiveTo());
        $this->assertNull($model->getReferenceValue());
        $this->assertNull($model->getCreatedAt());

        $model = $this->crosslink([
            'active_from' => '2026-01-01',
            'active_to' => '2026-02-01',
            'reference_value' => 42,
            'created_at' => '2025-12-31 10:00:00',
        ]);
        $this->assertSame('2026-01-01', $model->getActiveFrom());
        $this->assertSame('2026-02-01', $model->getActiveTo());
        $this->assertSame('42', $model->getReferenceValue());
        $this->assertSame('2025-12-31 10:00:00', $model->getCreatedAt());
    }

    public function testBooleanFlagsAreCast(): void
    {
        $model = $this->crosslink([
            'nofollow' => '1',
            'is_active' => '0',
            'in_product' => 1,
            'in_category' => null,
            'in_cms' => '1',
        ]);
        $this->assertTrue($model->isNofollow());
        $this->assertFalse($model->isActive());
        $this->assertTrue($model->isInProduct());
        $this->assertFalse($model->isInCategory());
        $this->assertTrue($model->isInCms());
    }

    public function testSettersAreFluentAndRoundTrip(): void
    {
        $model = $this->crosslink([]);
        $this->assertSame($model, $model->setKeyword('shoes')->setUrl('/shoes')->setPriority(3)->setStoreId(2));
        $this->assertSame('shoes', $model->getKeyword());
        $this->assertSame('/shoes', $model->getUrl());
        $this->assertSame(3, $model->getPriority());
        $this->assertSame(2, $model->getStoreId());
    }
}
