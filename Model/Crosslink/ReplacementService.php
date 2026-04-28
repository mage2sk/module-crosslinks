<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Model\Crosslink;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Crosslinks\Helper\Config as CrosslinksConfig;
use Panth\Crosslinks\Model\Config\Source\CrosslinkReferenceType;
use Panth\Crosslinks\Model\ResourceModel\Crosslink\CollectionFactory;

/**
 * Core crosslink replacement engine.
 *
 * Injects internal link anchors into HTML content for active crosslink keywords.
 * Operates ONLY on visible text nodes — never modifies content inside excluded
 * HTML tags (anchors, headings, buttons, scripts, styles, etc.).
 */
class ReplacementService
{
    /** @var array<string, Crosslink[]>  Runtime cache keyed by "{storeId}_{pageType}" */
    private array $crosslinkCache = [];

    /** @var array<string, string|null> Runtime cache for resolved reference URLs */
    private array $resolvedUrlCache = [];

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly CrosslinksConfig $config,
        private readonly ResourceConnection $resource,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * Process HTML content and inject crosslink anchors.
     *
     * @param string $html       Raw HTML to process.
     * @param string $pageType   One of 'product', 'category', 'cms'.
     * @param int    $storeId    Current store view ID.
     * @return string Modified HTML with crosslink anchors injected.
     */
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
        $excludedTags = $this->getExcludedTags($storeId);
        $totalReplacements = 0;

        /** @var array<int, int> $keywordReplacementCounts crosslink_id => count */
        $keywordReplacementCounts = [];

        $segments = $this->splitHtmlSegments($html, $excludedTags);

        $result = '';
        foreach ($segments as $segment) {
            if ($totalReplacements >= $maxLinksPerPage) {
                $result .= $segment['content'];
                continue;
            }

            if ($segment['type'] !== 'text') {
                $result .= $segment['content'];
                continue;
            }

            $text = $segment['content'];

            // Placeholders insulate newly-inserted anchors from being re-matched
            // by subsequent keywords. e.g. without this, injecting an anchor for
            // "bag" whose href contains "/gear/" would let the next keyword
            // "gear" match inside the anchor's attribute, producing nested <a>s.
            // We use `__PCL{n}__` — double-underscore wrappers ensure \b word
            // boundaries cannot match any keyword inside the placeholder token.
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
                $pattern = '/\b(' . $escapedKeyword . ')\b/iu';

                $count = 0;
                $replaced = preg_replace_callback(
                    $pattern,
                    function (array $matches) use ($crosslink, $allowed, $storeId, &$count, &$placeholders, &$placeholderIndex): string {
                        if ($count >= $allowed) {
                            return $matches[0];
                        }
                        $anchor = $this->buildAnchor($crosslink, $matches[0], $storeId);
                        if ($anchor === $matches[0]) {
                            return $matches[0];
                        }
                        $count++;
                        $token = '__PCL' . $placeholderIndex++ . '__';
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

        return $result;
    }

    /**
     * Split HTML into segments, tracking which are inside excluded tags.
     *
     * @param string   $html
     * @param string[] $excludedTags
     * @return array<int, array{type: string, content: string}>
     */
    private function splitHtmlSegments(string $html, array $excludedTags): array
    {
        if (empty($excludedTags)) {
            return $this->splitTagsAndText($html);
        }

        $segments = [];
        $offset = 0;
        $length = strlen($html);

        $tagAlternation = implode('|', array_map('preg_quote', $excludedTags));
        $openPattern = '/<(' . $tagAlternation . ')(\s[^>]*)?>/i';

        while ($offset < $length) {
            if (!preg_match($openPattern, $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $remainder = substr($html, $offset);
                if ($remainder !== '' && $remainder !== false) {
                    $segments = array_merge($segments, $this->splitTagsAndText($remainder));
                }
                break;
            }

            $matchPos = (int) $m[0][1];
            $matchedTag = strtolower($m[1][0]);

            if ($matchPos > $offset) {
                $before = substr($html, $offset, $matchPos - $offset);
                $segments = array_merge($segments, $this->splitTagsAndText($before));
            }

            $closingTag = '</' . $matchedTag;
            $searchStart = $matchPos + strlen($m[0][0]);
            $nestLevel = 1;
            $endPos = $searchStart;
            $selfClosingCheck = $m[0][0];

            if (str_ends_with(rtrim($selfClosingCheck), '/>')) {
                $segments[] = ['type' => 'excluded', 'content' => $m[0][0]];
                $offset = $matchPos + strlen($m[0][0]);
                continue;
            }

            $voidElements = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
            if (in_array($matchedTag, $voidElements, true)) {
                $segments[] = ['type' => 'excluded', 'content' => $m[0][0]];
                $offset = $matchPos + strlen($m[0][0]);
                continue;
            }

            $openTag = '<' . $matchedTag;
            $pos = $searchStart;
            $found = false;

            while ($pos < $length) {
                $nextOpen = stripos($html, $openTag, $pos);
                $nextClose = stripos($html, $closingTag, $pos);

                if ($nextClose === false) {
                    $endPos = $length;
                    $found = true;
                    break;
                }

                if ($nextOpen !== false && $nextOpen < $nextClose) {
                    $charAfter = $html[$nextOpen + strlen($openTag)] ?? '';
                    if ($charAfter === ' ' || $charAfter === '>' || $charAfter === '/' || $charAfter === "\t" || $charAfter === "\n") {
                        $nestLevel++;
                    }
                    $pos = $nextOpen + strlen($openTag);
                    continue;
                }

                $nestLevel--;
                if ($nestLevel === 0) {
                    $closeEnd = strpos($html, '>', $nextClose);
                    $endPos = $closeEnd !== false ? $closeEnd + 1 : $nextClose + strlen($closingTag) + 1;
                    $found = true;
                    break;
                }

                $pos = $nextClose + strlen($closingTag);
            }

            if (!$found) {
                $endPos = $length;
            }

            $excludedContent = substr($html, $matchPos, $endPos - $matchPos);
            $segments[] = ['type' => 'excluded', 'content' => $excludedContent];
            $offset = $endPos;
        }

        return $segments;
    }

    /**
     * Split a string of HTML into tag segments and text segments.
     *
     * @param string $html
     * @return array<int, array{type: string, content: string}>
     */
    private function splitTagsAndText(string $html): array
    {
        $parts = preg_split('/(<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [['type' => 'text', 'content' => $html]];
        }

        $segments = [];
        foreach ($parts as $part) {
            if ($part !== '' && $part[0] === '<') {
                $segments[] = ['type' => 'tag', 'content' => $part];
            } elseif ($part !== '') {
                $segments[] = ['type' => 'text', 'content' => $part];
            }
        }

        return $segments;
    }

    /**
     * Build an anchor tag for a crosslink.
     *
     * Resolves the destination URL based on the crosslink's reference type.
     * Strips dangerous URL schemes (javascript:, data:, vbscript:, file:) so
     * admin-curated values cannot inject XSS, and HTML-encodes every piece of
     * user-controlled content written into the rendered anchor.
     */
    private function buildAnchor(Crosslink $crosslink, string $matchedText, int $currentStoreId): string
    {
        $resolvedUrl = $this->resolveUrl($crosslink, $currentStoreId);
        if ($resolvedUrl === null || $resolvedUrl === '') {
            return $matchedText;
        }

        // Defense-in-depth: block dangerous URL schemes in case an admin
        // bypassed controller validation or the row was inserted directly.
        if (preg_match('#^\s*(javascript|data|vbscript|file)\s*:#i', $resolvedUrl)) {
            return $matchedText;
        }

        // Skip self-referencing links: a keyword that resolves to the page
        // currently being rendered just reloads the page and dilutes the SEO
        // signal a crosslink is meant to give.
        if ($this->isSelfReferencingUrl($resolvedUrl)) {
            return $matchedText;
        }

        $url = htmlspecialchars($resolvedUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = htmlspecialchars($crosslink->getUrlTitle(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // `panth-crosslink` class lets themes style injected links independently
        // of other anchors (e.g. to keep an underline when the surrounding prose
        // strips it).
        $attrs = 'href="' . $url . '" class="panth-crosslink"';
        if ($title !== '') {
            $attrs .= ' title="' . $title . '"';
        }
        if ($crosslink->isNofollow()) {
            $attrs .= ' rel="nofollow"';
        }

        return '<a ' . $attrs . '>' . htmlspecialchars($matchedText, ENT_QUOTES | ENT_HTML5, 'UTF-8', false) . '</a>';
    }

    /**
     * True when $resolvedUrl normalises to the same path as the current
     * storefront request — i.e. the crosslink would link the page to itself.
     * Compares paths only, so host/scheme/querystring/fragment are ignored.
     */
    private function isSelfReferencingUrl(string $resolvedUrl): bool
    {
        $currentPath = $this->normalisePath((string) $this->request->getPathInfo());
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

    /**
     * Resolve the final URL for a crosslink based on its reference type.
     *
     * @param Crosslink $crosslink       The crosslink rule to resolve.
     * @param int       $currentStoreId  The store being rendered for — url_rewrite
     *                                   rows are keyed by actual store_id (1, 2, …),
     *                                   never 0, so we always look up against the
     *                                   current store, not the crosslink's scope.
     */
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

    /**
     * Look up a product's storefront URL from `url_rewrite` by SKU.
     */
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

    /**
     * Look up a category's storefront URL from `url_rewrite` by category ID.
     */
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

    /**
     * Load active crosslinks for the given page type and store, ordered by priority DESC.
     *
     * @return Crosslink[]
     */
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

        /** @var Crosslink[] $items */
        $items = $collection->getItems();
        $this->crosslinkCache[$cacheKey] = array_values($items);

        return $this->crosslinkCache[$cacheKey];
    }

    /**
     * Get the list of HTML tags to exclude from crosslink replacement.
     *
     * @return string[]
     */
    private function getExcludedTags(int $storeId): array
    {
        $raw = $this->config->getExcludedTags($storeId);

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
