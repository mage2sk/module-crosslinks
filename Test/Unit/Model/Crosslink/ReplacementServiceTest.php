<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Model\Crosslink;

use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Crosslinks\Helper\Config;
use Panth\Crosslinks\Model\Crosslink\ReplacementService;
use Panth\Crosslinks\Model\ResourceModel\Crosslink\Collection;
use Panth\Crosslinks\Model\ResourceModel\Crosslink\CollectionFactory;
use PHPUnit\Framework\TestCase;

class ReplacementServiceTest extends TestCase
{
    use CrosslinkFactoryTrait;

    /** @var array<int, mixed> */
    private array $filters = [];

    /** @var string[] */
    private array $selectWheres = [];

    private int $factoryCalls = 0;

    private function link(array $data): \Panth\Crosslinks\Model\Crosslink\Crosslink
    {
        static $id = 0;
        return $this->crosslink($data + ['crosslink_id' => ++$id, 'max_replacements' => 1]);
    }

    private function selectStub(): Select
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        return $select;
    }

    private function service(
        array $crosslinks,
        array $config = [],
        string $currentPath = '/current-page.html',
        ?ResourceConnection $resource = null
    ): ReplacementService {
        $this->filters = [];
        $this->selectWheres = [];
        $this->factoryCalls = 0;

        $select = $this->createStub(Select::class);
        $select->method('where')->willReturnCallback(function ($cond) use (&$select) {
            $this->selectWheres[] = $cond;
            return $select;
        });

        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function ($field, $condition) use (&$collection) {
                $this->filters[$field] = $condition;
                return $collection;
            }
        );
        $collection->method('getSelect')->willReturn($select);
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getItems')->willReturn($crosslinks);

        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($collection) {
            $this->factoryCalls++;
            return $collection;
        });

        $configStub = $this->createStub(Config::class);
        $configStub->method('getMaxLinksPerPage')->willReturn($config['max'] ?? 10);
        $configStub->method('isLimitPerContent')->willReturn($config['per_content'] ?? false);
        $configStub->method('getExcludedTags')->willReturn($config['excluded'] ?? 'h1,h2,h3,h4,h5,h6');
        $configStub->method('isTimeActivationEnabled')->willReturn($config['time'] ?? false);

        $request = $this->createStub(Http::class);
        $request->method('getOriginalPathInfo')->willReturn($currentPath);

        return new ReplacementService(
            $factory,
            $this->createStub(StoreManagerInterface::class),
            $configStub,
            $resource ?? $this->createStub(ResourceConnection::class),
            $request
        );
    }

    public function testEmptyHtmlIsReturnedWithoutLoadingCrosslinks(): void
    {
        $service = $this->service([$this->link(['keyword' => 'shoes', 'url' => '/shoes.html'])]);

        $this->assertSame('', $service->processContent('', 'product', 1));
        $this->assertSame(0, $this->factoryCalls);
    }

    public function testHtmlIsUnchangedWhenNoCrosslinksExist(): void
    {
        $html = '<p>Buy shoes here</p>';
        $this->assertSame($html, $this->service([])->processContent($html, 'cms', 1));
    }

    public function testKeywordIsReplacedWithAnchorCarryingAllAttributes(): void
    {
        $service = $this->service([$this->link([
            'keyword' => 'shoes',
            'url' => '/shoes.html',
            'url_title' => 'All "Shoes"',
            'nofollow' => 1,
        ])]);

        $result = $service->processContent('<p>Buy shoes here</p>', 'product', 1);

        $this->assertSame(
            '<p>Buy <a href="/shoes.html" class="panth-crosslink" title="All &quot;Shoes&quot;" '
            . 'rel="nofollow">shoes</a> here</p>',
            $result
        );
    }

    public function testAnchorOmitsTitleAndRelWhenNotConfigured(): void
    {
        $service = $this->service([$this->link(['keyword' => 'shoes', 'url' => '/shoes.html'])]);

        $this->assertSame(
            'Buy <a href="/shoes.html" class="panth-crosslink">shoes</a>',
            $service->processContent('Buy shoes', 'product', 1)
        );
    }

    public function testMatchIsCaseInsensitiveAndKeepsOriginalCasing(): void
    {
        $service = $this->service([$this->link(['keyword' => 'running shoes', 'url' => '/run'])]);

        $this->assertStringContainsString(
            '>Running Shoes</a>',
            $service->processContent('Great Running Shoes today', 'product', 1)
        );
    }

    public function testOnlyWholeWordsAreMatched(): void
    {
        $service = $this->service([$this->link(['keyword' => 'shoe', 'url' => '/shoe'])]);

        $this->assertSame('snowshoes and shoelaces', $service->processContent('snowshoes and shoelaces', 'cms', 1));
    }

    public function testRegexCharactersInKeywordAreLiteral(): void
    {
        $service = $this->service([$this->link(['keyword' => 'a.b', 'url' => '/ab'])]);

        $this->assertSame('axb', $service->processContent('axb', 'cms', 1));
        $this->assertStringContainsString('>a.b</a>', $service->processContent('see a.b now', 'cms', 2));
    }

    public function testKeywordsStartingOrEndingWithSymbolsAreLinked(): void
    {
        $service = $this->service([
            $this->link(['crosslink_id' => 1, 'keyword' => 'C++', 'url' => '/cpp']),
            $this->link(['crosslink_id' => 2, 'keyword' => '.NET', 'url' => '/dotnet']),
        ]);

        $html = $service->processContent('Learn C++, then .NET basics.', 'cms', 1);

        $this->assertStringContainsString('>C++</a>, then', $html);
        $this->assertStringContainsString('>.NET</a> basics', $html);
    }

    public function testSymbolKeywordIsNotMatchedInsideLongerWords(): void
    {
        $service = $this->service([$this->link(['keyword' => 'C++', 'url' => '/cpp'])]);

        $this->assertSame('ABC++ and C++x', $service->processContent('ABC++ and C++x', 'cms', 1));
    }

    public function testMaxReplacementsPerKeywordIsRespected(): void
    {
        $service = $this->service([$this->link(['keyword' => 'shoes', 'url' => '/s', 'max_replacements' => 2])]);

        $result = $service->processContent('shoes shoes shoes', 'cms', 1);

        $this->assertSame(2, substr_count($result, '<a '));
        $this->assertStringEndsWith(' shoes', $result);
    }

    public function testMaxLinksPerPageLimitsAcrossKeywords(): void
    {
        $service = $this->service(
            [
                $this->link(['keyword' => 'alpha', 'url' => '/a']),
                $this->link(['keyword' => 'beta', 'url' => '/b']),
                $this->link(['keyword' => 'gamma', 'url' => '/c']),
            ],
            ['max' => 2]
        );

        $result = $service->processContent('alpha beta gamma', 'cms', 1);

        $this->assertSame(2, substr_count($result, '<a '));
        $this->assertStringEndsWith(' gamma', $result);
    }

    public function testPageScopeCarriesCountsAcrossCalls(): void
    {
        $service = $this->service([$this->link(['keyword' => 'shoes', 'url' => '/s', 'max_replacements' => 5])], ['max' => 1]);

        $first = $service->processContent('shoes', 'cms', 1);
        $second = $service->processContent('shoes', 'cms', 1);

        $this->assertStringContainsString('<a ', $first);
        $this->assertSame('shoes', $second);
    }

    public function testPageScopeCarriesPerKeywordCountsAcrossCalls(): void
    {
        $service = $this->service([$this->link(['keyword' => 'shoes', 'url' => '/s', 'max_replacements' => 1])]);

        $service->processContent('shoes', 'cms', 1);

        $this->assertSame('shoes again', $service->processContent('shoes again', 'cms', 1));
    }

    public function testPageScopeCountsAreTrackedPerStore(): void
    {
        $service = $this->service([$this->link(['keyword' => 'shoes', 'url' => '/s'])], ['max' => 1]);

        $service->processContent('shoes', 'cms', 1);

        $this->assertStringContainsString('<a ', $service->processContent('shoes', 'cms', 2));
    }

    public function testContentScopeResetsCountsForEachCall(): void
    {
        $service = $this->service(
            [$this->link(['keyword' => 'shoes', 'url' => '/s'])],
            ['max' => 1, 'per_content' => true]
        );

        $this->assertStringContainsString('<a ', $service->processContent('shoes', 'cms', 1));
        $this->assertStringContainsString('<a ', $service->processContent('shoes', 'cms', 1));
    }

    public function testTextInsideAlwaysExcludedTagsIsUntouched(): void
    {
        $service = $this->service([$this->link(['keyword' => 'shoes', 'url' => '/s', 'max_replacements' => 10])]);
        $html = '<a href="/x">shoes</a><button>shoes</button><script>var shoes = 1;</script>'
            . '<style>.shoes{}</style><textarea>shoes</textarea>';

        $this->assertSame($html, $service->processContent($html, 'cms', 1));
    }

    public function testTextInsideConfiguredExcludedTagsIsUntouchedIncludingNested(): void
    {
        $service = $this->service(
            [$this->link(['keyword' => 'shoes', 'url' => '/s', 'max_replacements' => 10])],
            ['excluded' => ' H2 , Strong ,<bad>,1x']
        );

        $result = $service->processContent(
            '<h2>shoes <em>shoes</em></h2><strong>shoes</strong><p>shoes</p>',
            'cms',
            1
        );

        $this->assertSame(
            '<h2>shoes <em>shoes</em></h2><strong>shoes</strong><p><a href="/s" class="panth-crosslink">shoes</a></p>',
            $result
        );
    }

    public function testStrayClosingExcludedTagDoesNotBlockLaterText(): void
    {
        $service = $this->service([$this->link(['keyword' => 'shoes', 'url' => '/s'])], ['excluded' => 'h2']);

        $this->assertStringContainsString('<a ', $service->processContent('</h2>shoes', 'cms', 1));
    }

    public function testSelfClosingExcludedTagDoesNotOpenExclusion(): void
    {
        $service = $this->service([$this->link(['keyword' => 'shoes', 'url' => '/s'])], ['excluded' => 'span']);

        $this->assertStringContainsString('<a ', $service->processContent('<span/>shoes', 'cms', 1));
    }

    public function testUnclosedRawTextTagSwallowsRestOfDocument(): void
    {
        $service = $this->service([$this->link(['keyword' => 'shoes', 'url' => '/s'])]);
        $html = '<script>shoes and more shoes';

        $this->assertSame($html, $service->processContent($html, 'cms', 1));
    }

    public function testAttributesAndCommentsAreNeverRewritten(): void
    {
        $service = $this->service([$this->link(['keyword' => 'shoes', 'url' => '/s', 'max_replacements' => 10])]);
        $html = '<img alt="shoes" src="shoes.png"><!-- shoes -->';

        $this->assertSame($html, $service->processContent($html, 'cms', 1));
    }

    public function testHtmlEntitiesAreNotBrokenByMatching(): void
    {
        $service = $this->service([$this->link(['keyword' => 'amp', 'url' => '/amp'])]);

        $result = $service->processContent('Tom &amp; Jerry amp', 'cms', 1);

        $this->assertSame('Tom &amp; Jerry <a href="/amp" class="panth-crosslink">amp</a>', $result);
    }

    public function testLaterKeywordDoesNotMatchInsideAnEarlierAnchor(): void
    {
        $service = $this->service([
            $this->link(['keyword' => 'running shoes', 'url' => '/run']),
            $this->link(['keyword' => 'shoes', 'url' => '/shoes']),
        ]);

        $result = $service->processContent('running shoes', 'cms', 1);

        $this->assertSame('<a href="/run" class="panth-crosslink">running shoes</a>', $result);
    }

    public function testEmptyKeywordIsIgnored(): void
    {
        $service = $this->service([$this->link(['keyword' => '', 'url' => '/x'])]);

        $this->assertSame('anything', $service->processContent('anything', 'cms', 1));
    }

    public function testDisallowedUrlSchemeProducesNoLink(): void
    {
        $service = $this->service([$this->link(['keyword' => 'shoes', 'url' => 'javascript:alert(1)'])]);

        $this->assertSame('shoes', $service->processContent('shoes', 'cms', 1));
    }

    public function testEmptyUrlProducesNoLinkAndDoesNotConsumeQuota(): void
    {
        $service = $this->service(
            [
                $this->link(['keyword' => 'shoes', 'url' => '']),
                $this->link(['keyword' => 'boots', 'url' => '/boots']),
            ],
            ['max' => 1]
        );

        $this->assertSame(
            'shoes <a href="/boots" class="panth-crosslink">boots</a>',
            $service->processContent('shoes boots', 'cms', 1)
        );
    }

    public function testLinkToTheCurrentPageIsSkipped(): void
    {
        $service = $this->service(
            [$this->link(['keyword' => 'shoes', 'url' => 'https://shop.test/Shoes.html/?ref=1'])],
            [],
            '/index.php/shoes.html'
        );

        $this->assertSame('shoes', $service->processContent('shoes', 'cms', 1));
    }

    public function testHomepagePathNeverCountsAsSelfReference(): void
    {
        $service = $this->service([$this->link(['keyword' => 'home', 'url' => '/'])], [], '/');

        $this->assertStringContainsString('<a href="/"', $service->processContent('home', 'cms', 1));
    }

    public function testUrlIsEscapedInsideHref(): void
    {
        $service = $this->service([$this->link(['keyword' => 'shoes', 'url' => '/s?a=1&b="2"'])]);

        $this->assertStringContainsString(
            'href="/s?a=1&amp;b=&quot;2&quot;"',
            $service->processContent('shoes', 'cms', 1)
        );
    }

    public function testCollectionIsFilteredForProductPagesAndCached(): void
    {
        $service = $this->service([$this->link(['keyword' => 'shoes', 'url' => '/s'])], ['per_content' => true]);

        $service->processContent('shoes', 'product', 3);
        $service->processContent('shoes', 'product', 3);

        $this->assertSame(1, $this->factoryCalls);
        $this->assertSame(1, $this->filters['is_active']);
        $this->assertSame([['eq' => 3], ['eq' => 0]], $this->filters['store_id']);
        $this->assertSame(1, $this->filters['in_product']);
        $this->assertSame([], $this->selectWheres);
    }

    public function testPlacementFieldDependsOnPageType(): void
    {
        $service = $this->service([]);
        $service->processContent('x', 'category', 1);
        $this->assertArrayHasKey('in_category', $this->filters);

        $service = $this->service([]);
        $service->processContent('x', 'cms', 1);
        $this->assertArrayHasKey('in_cms', $this->filters);

        $service = $this->service([]);
        $service->processContent('x', 'blog', 1);
        $this->assertArrayNotHasKey('in_product', $this->filters);
        $this->assertArrayNotHasKey('in_category', $this->filters);
        $this->assertArrayNotHasKey('in_cms', $this->filters);
    }

    public function testTimeActivationAddsDateWindowConditions(): void
    {
        $service = $this->service([], ['time' => true]);

        $service->processContent('x', 'cms', 1);

        $this->assertCount(2, $this->selectWheres);
        $this->assertStringContainsString('active_from', $this->selectWheres[0]);
        $this->assertStringContainsString('active_to', $this->selectWheres[1]);
    }

    public function testProductSkuReferenceResolvesToRewriteAndIsCached(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn() => $this->selectStub());
        $connection->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls('55', 'women/shoe-1.html');

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $service = $this->service(
            [$this->link([
                'keyword' => 'shoe',
                'reference_type' => 'product_sku',
                'reference_value' => 'SKU-1',
                'max_replacements' => 5,
            ])],
            ['per_content' => true],
            '/other',
            $resource
        );

        $first = $service->processContent('shoe', 'product', 1);
        $second = $service->processContent('shoe', 'product', 1);

        $this->assertSame('<a href="/women/shoe-1.html" class="panth-crosslink">shoe</a>', $first);
        $this->assertSame($first, $second);
    }

    public function testUnknownSkuProducesNoLink(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn() => $this->selectStub());
        $connection->method('fetchOne')->willReturn(false);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $service = $this->service(
            [$this->link(['keyword' => 'shoe', 'reference_type' => 'product_sku', 'reference_value' => 'NOPE'])],
            [],
            '/other',
            $resource
        );

        $this->assertSame('shoe', $service->processContent('shoe', 'product', 1));
    }

    public function testSkuWithoutRewriteProducesNoLink(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn() => $this->selectStub());
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('9', '');
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $service = $this->service(
            [$this->link(['keyword' => 'shoe', 'reference_type' => 'product_sku', 'reference_value' => 'S'])],
            [],
            '/other',
            $resource
        );

        $this->assertSame('shoe', $service->processContent('shoe', 'product', 1));
    }

    public function testEmptySkuSkipsDatabaseLookup(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');

        $service = $this->service(
            [$this->link(['keyword' => 'shoe', 'reference_type' => 'product_sku', 'reference_value' => ''])],
            [],
            '/other',
            $resource
        );

        $this->assertSame('shoe', $service->processContent('shoe', 'product', 1));
    }

    public function testCategoryReferenceResolvesToRewrite(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn() => $this->selectStub());
        $connection->method('fetchOne')->willReturn('/gear.html');
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $service = $this->service(
            [$this->link(['keyword' => 'gear', 'reference_type' => 'category_id', 'reference_value' => '12'])],
            [],
            '/other',
            $resource
        );

        $this->assertSame(
            '<a href="/gear.html" class="panth-crosslink">gear</a>',
            $service->processContent('gear', 'category', 1)
        );
    }

    public function testInvalidCategoryIdSkipsDatabaseLookup(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');

        $service = $this->service(
            [$this->link(['keyword' => 'gear', 'reference_type' => 'category_id', 'reference_value' => 'abc'])],
            [],
            '/other',
            $resource
        );

        $this->assertSame('gear', $service->processContent('gear', 'category', 1));
    }

    public function testUnknownReferenceTypeFallsBackToStoredUrl(): void
    {
        $service = $this->service([$this->link([
            'keyword' => 'shoes',
            'url' => '/fallback',
            'reference_type' => 'something_else',
        ])]);

        $this->assertStringContainsString('href="/fallback"', $service->processContent('shoes', 'cms', 1));
    }
}
