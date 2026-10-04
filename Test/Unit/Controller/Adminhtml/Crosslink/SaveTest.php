<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Controller\Adminhtml\Crosslink;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\Filter\Date as DateFilter;
use Panth\Crosslinks\Controller\Adminhtml\Crosslink\Save;
use Panth\Crosslinks\Test\Unit\Controller\Adminhtml\ControllerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SaveTest extends ControllerTestCase
{
    private ?array $inserted = null;

    private ?array $updated = null;

    private array $persisted = [];

    private function controller(
        array $post,
        array $params = [],
        bool $isPost = true,
        ?\Throwable $dbError = null
    ): Save {
        $this->inserted = null;
        $this->updated = null;

        $connection = $this->createStub(Mysql::class);
        $connection->method('insert')->willReturnCallback(function ($table, $row) use ($dbError) {
            if ($dbError) {
                throw $dbError;
            }
            $this->inserted = ['table' => $table, 'row' => $row];
            return 1;
        });
        $connection->method('update')->willReturnCallback(function ($table, $row, $where) use ($dbError) {
            if ($dbError) {
                throw $dbError;
            }
            $this->updated = ['table' => $table, 'row' => $row, 'where' => $where];
            return 1;
        });
        $connection->method('lastInsertId')->willReturn('77');

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-10-03 12:00:00');

        return new Save(
            $this->buildContext($params + ['form_key' => 'key'], $post, $isPost),
            $this->resource($connection),
            $dateTime,
            $this->dateFilter(),
            $this->persistor(),
            $this->cacheTypeList()
        );
    }

    private function persistor(): DataPersistorInterface
    {
        $this->persisted = ['set' => null, 'cleared' => false];
        $persistor = $this->createStub(DataPersistorInterface::class);
        $persistor->method('set')->willReturnCallback(function ($key, $value) {
            $this->persisted['set'] = [$key, $value];
        });
        $persistor->method('clear')->willReturnCallback(function ($key) {
            $this->persisted['cleared'] = $key;
        });
        return $persistor;
    }

    private function dateFilter(): DateFilter
    {
        $filter = $this->createStub(DateFilter::class);
        $filter->method('filter')->willReturnCallback(function ($value) {
            $parsed = \DateTime::createFromFormat('!n/j/Y', (string) $value);
            if ($parsed === false) {
                throw new \Exception('Invalid input date format');
            }
            return $parsed->format('Y-m-d');
        });
        return $filter;
    }

    private function validPost(array $overrides = []): array
    {
        return $overrides + [
            'keyword' => '  shoes ',
            'url' => ' /shoes.html ',
            'url_title' => ' Shoes ',
            'max_replacements' => '3',
            'nofollow' => '1',
            'priority' => '4',
            'is_active' => '1',
            'in_product' => '1',
            'in_category' => '0',
            'in_cms' => '',
            'store_id' => '2',
            'reference_type' => 'url',
        ];
    }

    public function testNonPostRequestRedirectsToGrid(): void
    {
        $this->controller($this->validPost(), [], false)->execute();

        $this->assertSame('*/*/', $this->redirect['path']);
        $this->assertNull($this->inserted);
    }

    public function testMissingFormKeyIsRejected(): void
    {
        $this->controller($this->validPost(), ['form_key' => ''])->execute();

        $this->assertSame('*/*/', $this->redirect['path']);
        $this->assertStringContainsString('Invalid form key', $this->messages['error'][0]);
        $this->assertNull($this->inserted);
    }

    public function testEmptyPostRedirectsToGrid(): void
    {
        $this->controller([])->execute();

        $this->assertSame('*/*/', $this->redirect['path']);
        $this->assertSame([], $this->messages['error']);
    }

    public function testNewCrosslinkIsInsertedWithNormalisedValues(): void
    {
        $this->controller($this->validPost())->execute();

        $this->assertSame('panth_seo_crosslink', $this->inserted['table']);
        $this->assertSame(
            [
                'keyword' => 'shoes',
                'url' => '/shoes.html',
                'url_title' => 'Shoes',
                'max_replacements' => 3,
                'nofollow' => 1,
                'priority' => 4,
                'is_active' => 1,
                'in_product' => 1,
                'in_category' => 0,
                'in_cms' => 0,
                'store_id' => 2,
                'reference_type' => 'url',
                'reference_value' => '',
                'active_from' => null,
                'active_to' => null,
                'created_at' => '2026-10-03 12:00:00',
            ],
            $this->inserted['row']
        );
        $this->assertSame(['Crosslink saved.'], $this->messages['success']);
        $this->assertSame('*/*/', $this->redirect['path']);
    }

    public function testNumericValuesAreClamped(): void
    {
        $this->controller($this->validPost([
            'max_replacements' => '-5',
            'priority' => '-1',
            'store_id' => '-3',
        ]))->execute();

        $this->assertSame(1, $this->inserted['row']['max_replacements']);
        $this->assertSame(0, $this->inserted['row']['priority']);
        $this->assertSame(0, $this->inserted['row']['store_id']);
    }

    public function testExistingCrosslinkIsUpdatedWithoutCreatedAt(): void
    {
        $this->controller($this->validPost([
            'crosslink_id' => '9',
            'active_from' => '2026-01-01',
            'active_to' => '2026-12-31',
        ]))->execute();

        $this->assertNull($this->inserted);
        $this->assertSame(['crosslink_id = ?' => 9], $this->updated['where']);
        $this->assertArrayNotHasKey('created_at', $this->updated['row']);
        $this->assertSame('2026-01-01', $this->updated['row']['active_from']);
        $this->assertSame('2026-12-31', $this->updated['row']['active_to']);
    }

    public function testLocaleFormattedDatesAreStoredAsIsoDates(): void
    {
        $this->controller($this->validPost([
            'crosslink_id' => '5',
            'active_from' => '01/1/2027',
            'active_to' => '12/31/2027',
        ]))->execute();

        $this->assertSame('2027-01-01', $this->updated['row']['active_from']);
        $this->assertSame('2027-12-31', $this->updated['row']['active_to']);
        $this->assertSame(['Crosslink saved.'], $this->messages['success']);
    }

    public function testIsoDatesWithTimeAreKept(): void
    {
        $this->controller($this->validPost(['active_from' => '2027-01-01 00:00:00', 'active_to' => '']))->execute();

        $this->assertSame('2027-01-01 00:00:00', $this->inserted['row']['active_from']);
        $this->assertNull($this->inserted['row']['active_to']);
    }

    public function testInvalidDateIsRejectedWithoutSaving(): void
    {
        $this->controller($this->validPost(['crosslink_id' => '5', 'active_from' => 'not a date']))->execute();

        $this->assertNull($this->updated);
        $this->assertSame('*/*/edit', $this->redirect['path']);
        $this->assertSame(['Active From and Active To must be valid dates.'], $this->messages['error']);
    }

    public function testActiveToBeforeActiveFromIsRejected(): void
    {
        $this->controller($this->validPost([
            'crosslink_id' => '5',
            'active_from' => '12/31/2027',
            'active_to' => '01/1/2027',
        ]))->execute();

        $this->assertNull($this->updated);
        $this->assertSame(['Active To must not be earlier than Active From.'], $this->messages['error']);
    }

    public function testValidationErrorOnNewRecordKeepsEnteredDataAndOpensNewForm(): void
    {
        $post = $this->validPost(['url' => 'javascript:alert(1)', 'nofollow' => '0']);
        $this->controller($post)->execute();

        $this->assertNull($this->inserted);
        $this->assertSame('*/*/edit', $this->redirect['path']);
        $this->assertSame([], $this->redirect['params']);
        $this->assertSame(['panth_crosslink', $post], $this->persisted['set']);
        $this->assertFalse($this->persisted['cleared']);
        $this->assertSame([], $this->invalidated);
    }

    public function testSuccessfulSaveClearsPersistedData(): void
    {
        $this->controller($this->validPost())->execute();

        $this->assertNull($this->persisted['set']);
        $this->assertSame('panth_crosslink', $this->persisted['cleared']);
        $this->assertSame([['full_page', 'block_html']], $this->invalidated);
    }

    public function testBackParamRedirectsToEditWithNewId(): void
    {
        $this->controller($this->validPost(), ['back' => 'edit'])->execute();

        $this->assertSame('*/*/edit', $this->redirect['path']);
        $this->assertSame(['id' => 77], $this->redirect['params']);
    }

    public function testUnknownReferenceTypeFallsBackToUrl(): void
    {
        $this->controller($this->validPost(['reference_type' => 'evil']))->execute();

        $this->assertSame('url', $this->inserted['row']['reference_type']);
    }

    public function testProductReferenceDoesNotRequireUrl(): void
    {
        $this->controller($this->validPost([
            'url' => '',
            'reference_type' => 'product_sku',
            'reference_value' => ' SKU-1 ',
        ]))->execute();

        $this->assertSame('product_sku', $this->inserted['row']['reference_type']);
        $this->assertSame('SKU-1', $this->inserted['row']['reference_value']);
    }

    public static function invalidPosts(): array
    {
        return [
            'missing keyword' => [['keyword' => '   '], 'Keyword is required.'],
            'missing url' => [['url' => ''], 'URL is required'],
            'missing reference value' => [
                ['reference_type' => 'category_id', 'reference_value' => ' '],
                'Reference value is required',
            ],
            'javascript url' => [['url' => 'javascript:alert(1)'], 'URL must be a relative path'],
            'angle brackets' => [['keyword' => '<b>shoes</b>'], 'must not contain HTML angle brackets'],
        ];
    }

    #[DataProvider('invalidPosts')]
    public function testValidationErrorsRedirectBackToEdit(array $overrides, string $message): void
    {
        $this->controller($this->validPost($overrides + ['crosslink_id' => '5']))->execute();

        $this->assertNull($this->inserted);
        $this->assertNull($this->updated);
        $this->assertSame('*/*/edit', $this->redirect['path']);
        $this->assertSame(['id' => 5], $this->redirect['params']);
        $this->assertStringContainsString($message, $this->messages['error'][0]);
    }

    public function testDatabaseErrorIsReportedAndRedirectsToEdit(): void
    {
        $this->controller($this->validPost(), [], true, new \RuntimeException('DB down'))->execute();

        $this->assertSame(['DB down'], $this->messages['error']);
        $this->assertSame([], $this->messages['success']);
        $this->assertSame('*/*/edit', $this->redirect['path']);
        $this->assertSame([], $this->redirect['params']);
    }
}
