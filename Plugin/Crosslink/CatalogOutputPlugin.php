<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Plugin\Crosslink;

use Magento\Catalog\Helper\Output;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Crosslinks\Helper\Config as CrosslinksConfig;
use Panth\Crosslinks\Model\Crosslink\ReplacementService;

/**
 * After-plugin on Catalog\Helper\Output to inject crosslink anchors
 * into product and category description attributes.
 */
class CatalogOutputPlugin
{
    /** Attributes eligible for crosslink injection */
    private const PRODUCT_ATTRIBUTES = ['description', 'short_description'];
    private const CATEGORY_ATTRIBUTES = ['description'];

    public function __construct(
        private readonly ReplacementService $replacementService,
        private readonly StoreManagerInterface $storeManager,
        private readonly CrosslinksConfig $config
    ) {
    }

    /**
     * Inject crosslinks into product description/short_description.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterProductAttribute(
        Output $subject,
        ?string $result,
        \Magento\Catalog\Model\Product $product,
        $attributeHtml,
        $attributeName
    ): ?string {
        if (!in_array($attributeName, self::PRODUCT_ATTRIBUTES, true)) {
            return $result;
        }

        if ($result === null || $result === '') {
            return $result;
        }

        $storeId = (int) $this->storeManager->getStore()->getId();

        if (!$this->config->isEnabled($storeId)) {
            return $result;
        }

        return $this->replacementService->processContent($result, 'product', $storeId);
    }

    /**
     * Inject crosslinks into category description.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterCategoryAttribute(
        Output $subject,
        ?string $result,
        \Magento\Catalog\Model\Category $category,
        $attributeHtml,
        $attributeName
    ): ?string {
        if (!in_array($attributeName, self::CATEGORY_ATTRIBUTES, true)) {
            return $result;
        }

        if ($result === null || $result === '') {
            return $result;
        }

        $storeId = (int) $this->storeManager->getStore()->getId();

        if (!$this->config->isEnabled($storeId)) {
            return $result;
        }

        return $this->replacementService->processContent($result, 'category', $storeId);
    }
}
