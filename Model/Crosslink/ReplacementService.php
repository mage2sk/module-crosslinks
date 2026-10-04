<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Model\Crosslink;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Crosslinks\Helper\Config as CrosslinksConfig;
use Panth\Crosslinks\Model\Config\Source\CrosslinkReferenceType;
use Panth\Crosslinks\Model\ResourceModel\Crosslink\CollectionFactory;

class ReplacementService
{
    private const ALWAYS_EXCLUDED_TAGS = [
        'a', 'button', 'iframe', 'noscript', 'object', 'script',
        'select', 'style', 'svg', 'template', 'textarea', 'title',
    ];

    private const RAW_TEXT_TAGS = ['iframe', 'noscript', 'script', 'style', 'textarea', 'title'];

    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    private const MARKUP_PATTERN = '/<!--.*?(?:-->|\z)|<![^>]*+(?:>|\z)|<\?.*?(?:>|\z)'
        . '|<\/?[a-zA-Z](?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>|<\/?[a-zA-Z].*\z/s';

    private array $crosslinkCache = [];

    private array $resolvedUrlCache = [];

    private array $pageLinkCounts = [];

    private array $pageKeywordCounts = [];

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly CrosslinksConfig $config,
        private readonly ResourceConnection $resource,
        private readonly RequestInterface $request
    ) {
    }

    public function processContent(string $html, string $pageType, int $storeId): string
    {
        if ($html === '') {
            return $html;
        }

        $crosslinks = $this->loadCrosslinks($pageType, $storeId);
        if (empty($crosslinks)) {
            return $html;
        }

        $maxLinksPerPage = $this->config->getMaxLinksPerPage($storeId);
        $perContent = $this->config->isLimitPerContent($storeId);
        $totalReplacements = $perContent ? 0 : ($this->pageLinkCounts[$storeId] ?? 0);
        if ($totalReplacements >= $maxLinksPerPage) {
            return $html;
        }

        $excludedTags = $this->getExcludedTags($storeId);
        $keywordReplacementCounts = $perContent ? [] : ($this->pageKeywordCounts[$storeId] ?? []);

        $segments = $this->splitHtmlSegments($html, $excludedTags);

        $result = '';
        foreach ($segments as $segment) {
            if ($totalReplacements >= $maxLinksPerPage || $segment['type'] !== 'text') {
                $result .= $segment['content'];
                continue;
            }

            $text = $segment['content'];

            $placeholders = [];
            $placeholderIndex = 0;

            foreach ($crosslinks as $crosslink) {
                if ($totalReplacements >= $maxLinksPerPage) {
                    break;
                }

                $crosslinkId = (int) $crosslink->getCrosslinkId();
                $maxPerKeyword = $crosslink->getMaxReplacements();
                $usedForKeyword = $keywordReplacementCounts[$crosslinkId] ?? 0;
                $remainingForKeyword = $maxPerKeyword - $usedForKeyword;

                if ($remainingForKeyword <= 0) {
                    continue;
                }

                $remainingForPage = $maxLinksPerPage - $totalReplacements;
                $allowed = min($remainingForKeyword, $remainingForPage);

                $keyword = $crosslink->getKeyword();
                if ($keyword === '') {
                    continue;
                }
                $escapedKeyword = preg_quote($keyword, '/');
                $pattern = '/\x00PCL\d+\x00|&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]*);|(?<![\w])(' . $escapedKeyword . ')(?![\w])/iu';

                $count = 0;
                $replaced = preg_replace_callback(
                    $pattern,
                    function (array $matches) use (
                        $crosslink,
                        $allowed,
                        $storeId,
                        &$count,
                        &$placeholders,
                        &$placeholderIndex
                    ): string {
                        if (!isset($matches[1]) || $matches[1] === '' || $count >= $allowed) {
                            return $matches[0];
                        }
                        $anchor = $this->buildAnchor($crosslink, $matches[1], $storeId);
                        if ($anchor === $matches[1]) {
                            return $matches[0];
                        }
                        $count++;
                        $token = "\x00PCL" . $placeholderIndex++ . "\x00";
                        $placeholders[$token] = $anchor;
                        return $token;
                    },
                    $text
                );
                if ($replaced !== null) {
                    $text = $replaced;
                }

                $keywordReplacementCounts[$crosslinkId] = $usedForKeyword + $count;
                $totalReplacements += $count;
            }

            if (!empty($placeholders)) {
                $text = strtr($text, $placeholders);
            }

            $result .= $text;
        }

        if (!$perContent) {
            $this->pageLinkCounts[$storeId] = $totalReplacements;
            $this->pageKeywordCounts[$storeId] = $keywordReplacementCounts;
        }

        return $result;
    }

    private function splitHtmlSegments(string $html, array $excludedTags): array
    {
        $segments = [];
        $offset = 0;
        $length = strlen($html);
        $openExcluded = [];
        $depth = 0;

        while ($offset < $length) {
            if (!preg_match(self::MARKUP_PATTERN, $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $segments[] = ['type' => $depth > 0 ? 'excluded' : 'text', 'content' => substr($html, $offset)];
                break;
            }

            $tag = $m[0][0];
            $tagPos = (int) $m[0][1];

            if ($tagPos > $offset) {
                $segments[] = [
                    'type' => $depth > 0 ? 'excluded' : 'text',
                    'content' => substr($html, $offset, $tagPos - $offset),
                ];
            }
            $offset = $tagPos + strlen($tag);

            if (!preg_match('/^<(\/?)([a-zA-Z][a-zA-Z0-9:\-]*)/', $tag, $nameMatch)) {
                $segments[] = ['type' => 'tag', 'content' => $tag];
                continue;
            }

            $isClosing = $nameMatch[1] === '/';
            $name = strtolower($nameMatch[2]);

            if (!$isClosing && in_array($name, self::RAW_TEXT_TAGS, true)) {
                $closePattern = '/<\/' . preg_quote($name, '/') . '(?=[\s\/>])[^>]*+(?:>|\z)/i';
                if (preg_match($closePattern, $html, $close, PREG_OFFSET_CAPTURE, $offset)) {
                    $end = (int) $close[0][1] + strlen($close[0][0]);
                } else {
                    $end = $length;
                }
                $segments[] = ['type' => 'excluded', 'content' => $tag . substr($html, $offset, $end - $offset)];
                $offset = $end;
                continue;
            }

            if (in_array($name, $excludedTags, true)) {
                if ($isClosing) {
                    if (($openExcluded[$name] ?? 0) > 0) {
                        $openExcluded[$name]--;
                        $depth--;
                    }
                } elseif (!in_array($name, self::VOID_ELEMENTS, true)
                    && !str_ends_with(rtrim($tag), '/>')
                ) {
                    $openExcluded[$name] = ($openExcluded[$name] ?? 0) + 1;
                    $depth++;
                }
            }

            $segments[] = ['type' => 'tag', 'content' => $tag];
        }

        return $segments;
    }

    private function buildAnchor(Crosslink $crosslink, string $matchedText, int $currentStoreId): string
    {
        $resolvedUrl = $this->resolveUrl($crosslink, $currentStoreId);
        if ($resolvedUrl === null || $resolvedUrl === '') {
            return $matchedText;
        }

        $resolvedUrl = trim($resolvedUrl);
        if (!UrlPolicy::isAllowed($resolvedUrl)) {
            return $matchedText;
        }

        if ($this->isSelfReferencingUrl($resolvedUrl)) {
            return $matchedText;
        }

        $url = htmlspecialchars($resolvedUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = htmlspecialchars($crosslink->getUrlTitle(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $attrs = 'href="' . $url . '" class="panth-crosslink"';
        if ($title !== '') {
            $attrs .= ' title="' . $title . '"';
        }
        if ($crosslink->isNofollow()) {
            $attrs .= ' rel="nofollow"';
        }

        return '<a ' . $attrs . '>' . htmlspecialchars($matchedText, ENT_QUOTES | ENT_HTML5, 'UTF-8', false) . '</a>';
    }

    private function isSelfReferencingUrl(string $resolvedUrl): bool
    {
        $currentPath = $this->normalisePath((string) $this->request->getOriginalPathInfo());
        $resolvedPath = $this->normalisePath(parse_url($resolvedUrl, PHP_URL_PATH) ?? $resolvedUrl);

        return $currentPath !== '' && $currentPath === $resolvedPath;
    }

    private function normalisePath(string $path): string
    {
        $path = strtok($path, '?#');
        $path = preg_replace('#/index\.php#', '', (string) $path);
        $path = '/' . ltrim((string) $path, '/');
        $path = rtrim($path, '/');
        return strtolower($path);
    }

    private function resolveUrl(Crosslink $crosslink, int $currentStoreId): ?string
    {
        $referenceType = $crosslink->getReferenceType();
        $referenceValue = $crosslink->getReferenceValue();

        if ($referenceType === CrosslinkReferenceType::TYPE_URL || $referenceType === '') {
            return $crosslink->getUrl();
        }

        $cacheKey = $referenceType . '::' . ($referenceValue ?? '') . '::' . $currentStoreId;
        if (array_key_exists($cacheKey, $this->resolvedUrlCache)) {
            return $this->resolvedUrlCache[$cacheKey];
        }

        $resolvedUrl = match ($referenceType) {
            CrosslinkReferenceType::TYPE_PRODUCT_SKU => $this->resolveProductUrl(
                (string) $referenceValue,
                $currentStoreId
            ),
            CrosslinkReferenceType::TYPE_CATEGORY_ID => $this->resolveCategoryUrl(
                (int) $referenceValue,
                $currentStoreId
            ),
            default => $crosslink->getUrl(),
        };

        $this->resolvedUrlCache[$cacheKey] = $resolvedUrl;

        return $resolvedUrl;
    }

    private function resolveProductUrl(string $sku, int $storeId): ?string
    {
        if ($sku === '') {
            return null;
        }

        $connection = $this->resource->getConnection();
        $productTable = $this->resource->getTableName('catalog_product_entity');
        $rewriteTable = $this->resource->getTableName('url_rewrite');

        $entityId = $connection->fetchOne(
            $connection->select()
                ->from($productTable, ['entity_id'])
                ->where('sku = ?', $sku)
                ->limit(1)
        );

        if ($entityId === false) {
            return null;
        }

        $requestPath = $connection->fetchOne(
            $connection->select()
                ->from($rewriteTable, ['request_path'])
                ->where('entity_type = ?', 'product')
                ->where('entity_id = ?', (int) $entityId)
                ->where('store_id IN (?)', [0, $storeId])
                ->where('redirect_type = ?', 0)
                ->order('store_id DESC')
                ->limit(1)
        );

        if ($requestPath === false || $requestPath === '') {
            return null;
        }

        return '/' . ltrim((string) $requestPath, '/');
    }

    private function resolveCategoryUrl(int $categoryId, int $storeId): ?string
    {
        if ($categoryId <= 0) {
            return null;
        }

        $connection = $this->resource->getConnection();
        $rewriteTable = $this->resource->getTableName('url_rewrite');

        $requestPath = $connection->fetchOne(
            $connection->select()
                ->from($rewriteTable, ['request_path'])
                ->where('entity_type = ?', 'category')
                ->where('entity_id = ?', $categoryId)
                ->where('store_id IN (?)', [0, $storeId])
                ->where('redirect_type = ?', 0)
                ->order('store_id DESC')
                ->limit(1)
        );

        if ($requestPath === false || $requestPath === '') {
            return null;
        }

        return '/' . ltrim((string) $requestPath, '/');
    }

    private function loadCrosslinks(string $pageType, int $storeId): array
    {
        $cacheKey = $storeId . '_' . $pageType;
        if (isset($this->crosslinkCache[$cacheKey])) {
            return $this->crosslinkCache[$cacheKey];
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter('store_id', [['eq' => $storeId], ['eq' => 0]]);

        $placementField = match ($pageType) {
            'product'  => 'in_product',
            'category' => 'in_category',
            'cms'      => 'in_cms',
            default    => null,
        };

        if ($placementField !== null) {
            $collection->addFieldToFilter($placementField, 1);
        }

        if ($this->config->isTimeActivationEnabled($storeId)) {
            $collection->getSelect()
                ->where('active_from IS NULL OR active_from <= NOW()')
                ->where('active_to IS NULL OR active_to >= NOW()');
        }

        $collection->setOrder('priority', 'DESC');

        $items = $collection->getItems();
        $this->crosslinkCache[$cacheKey] = array_values($items);

        return $this->crosslinkCache[$cacheKey];
    }

    private function getExcludedTags(int $storeId): array
    {
        $tags = self::ALWAYS_EXCLUDED_TAGS;
        foreach (explode(',', $this->config->getExcludedTags($storeId)) as $tag) {
            $tag = strtolower(trim($tag));
            if ($tag !== '' && preg_match('/^[a-z][a-z0-9:\-]*$/', $tag)) {
                $tags[] = $tag;
            }
        }

        return array_values(array_unique($tags));
    }
}
