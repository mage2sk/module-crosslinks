<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Model\Config\Source;

use Panth\Crosslinks\Helper\Config;
use Panth\Crosslinks\Model\Config\Source\CrosslinkReferenceType;
use Panth\Crosslinks\Model\Config\Source\LimitScope;
use PHPUnit\Framework\TestCase;

class SourceModelsTest extends TestCase
{
    public function testReferenceTypeOptions(): void
    {
        $options = (new CrosslinkReferenceType())->toOptionArray();

        $this->assertSame(
            [
                CrosslinkReferenceType::TYPE_URL,
                CrosslinkReferenceType::TYPE_PRODUCT_SKU,
                CrosslinkReferenceType::TYPE_CATEGORY_ID,
            ],
            array_column($options, 'value')
        );
        $this->assertSame('Custom URL', (string) $options[0]['label']);
        $this->assertSame('Product by SKU', (string) $options[1]['label']);
        $this->assertSame('Category by ID', (string) $options[2]['label']);
    }

    public function testLimitScopeOptionsMatchConfigConstants(): void
    {
        $options = (new LimitScope())->toOptionArray();

        $this->assertSame(
            [Config::LIMIT_SCOPE_PAGE, Config::LIMIT_SCOPE_CONTENT],
            array_column($options, 'value')
        );
        $this->assertSame('Page Request', (string) $options[0]['label']);
        $this->assertSame('Content Block', (string) $options[1]['label']);
    }
}
