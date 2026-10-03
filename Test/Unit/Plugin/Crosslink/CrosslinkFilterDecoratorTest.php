<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Plugin\Crosslink;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Crosslinks\Model\Crosslink\ReplacementService;
use Panth\Crosslinks\Plugin\Crosslink\CrosslinkFilterDecorator;
use PHPUnit\Framework\TestCase;

class CrosslinkFilterDecoratorTest extends TestCase
{
    private object $inner;

    protected function setUp(): void
    {
        $this->inner = new class {
            public array $calls = [];
            public bool $strict = false;

            public function filter($value)
            {
                $this->calls[] = ['filter', $value];
                return $value === null ? null : '[' . $value . ']';
            }

            public function setVariables(array $variables)
            {
                $this->calls[] = ['setVariables', $variables];
                return $this;
            }

            public function setStoreId($storeId)
            {
                $this->calls[] = ['setStoreId', $storeId];
                return $this;
            }

            public function setStrictMode(bool $strictMode): bool
            {
                $previous = $this->strict;
                $this->strict = $strictMode;
                return $previous;
            }

            public function isStrictMode(): bool
            {
                return $this->strict;
            }

            public function chain()
            {
                return $this;
            }

            public function sum(int $a, int $b): int
            {
                return $a + $b;
            }
        };
    }

    private function decorator(?ReplacementService $service = null, string $pageType = 'cms'): CrosslinkFilterDecorator
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('4');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        if ($service === null) {
            $service = $this->createStub(ReplacementService::class);
            $service->method('processContent')->willReturnArgument(0);
        }

        return new CrosslinkFilterDecorator($this->inner, $service, $storeManager, $pageType);
    }

    public function testFilterRunsInnerFilterThenReplacement(): void
    {
        $service = $this->createMock(ReplacementService::class);
        $service->expects($this->once())
            ->method('processContent')
            ->with('[text]', 'cms', 4)
            ->willReturn('[linked]');

        $this->assertSame('[linked]', $this->decorator($service)->filter('text'));
        $this->assertSame([['filter', 'text']], $this->inner->calls);
    }

    public function testNullInnerResultIsCastToEmptyString(): void
    {
        $service = $this->createMock(ReplacementService::class);
        $service->expects($this->once())->method('processContent')->with('', 'blog', 4)->willReturn('');

        $this->assertSame('', $this->decorator($service, 'blog')->filter(null));
    }

    public function testFluentSettersReturnDecoratorAndDelegate(): void
    {
        $decorator = $this->decorator();

        $this->assertSame($decorator, $decorator->setVariables(['a' => 1]));
        $this->assertSame($decorator, $decorator->setStoreId(9));
        $this->assertSame([['setVariables', ['a' => 1]], ['setStoreId', 9]], $this->inner->calls);
    }

    public function testStrictModeIsDelegated(): void
    {
        $decorator = $this->decorator();

        $this->assertFalse($decorator->setStrictMode(true));
        $this->assertTrue($decorator->isStrictMode());
        $this->assertTrue($decorator->setStrictMode(false));
        $this->assertFalse($decorator->isStrictMode());
    }

    public function testMagicCallsAreForwardedAndInnerSelfIsReplacedByDecorator(): void
    {
        $decorator = $this->decorator();

        $this->assertSame(5, $decorator->sum(2, 3));
        $this->assertSame($decorator, $decorator->chain());
    }
}
