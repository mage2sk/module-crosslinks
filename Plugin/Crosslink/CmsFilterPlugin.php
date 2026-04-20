<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Plugin\Crosslink;

use Magento\Cms\Model\Template\FilterProvider;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Crosslinks\Helper\Config as CrosslinksConfig;
use Panth\Crosslinks\Model\Crosslink\ReplacementService;

/**
 * After-plugin on CMS Template FilterProvider to inject crosslink anchors
 * into CMS page/block content after Magento processes widgets and directives.
 */
class CmsFilterPlugin
{
    public function __construct(
        private readonly ReplacementService $replacementService,
        private readonly StoreManagerInterface $storeManager,
        private readonly CrosslinksConfig $config
    ) {
    }

    /**
     * Intercept getPageFilter to wrap the returned filter with crosslink processing.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetPageFilter(FilterProvider $subject, object $result): object
    {
        if (!$this->isEnabled()) {
            return $result;
        }

        return new CrosslinkFilterDecorator(
            $result,
            $this->replacementService,
            $this->storeManager,
            'cms'
        );
    }

    /**
     * Intercept getBlockFilter to wrap the returned filter with crosslink processing.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetBlockFilter(FilterProvider $subject, object $result): object
    {
        if (!$this->isEnabled()) {
            return $result;
        }

        return new CrosslinkFilterDecorator(
            $result,
            $this->replacementService,
            $this->storeManager,
            'cms'
        );
    }

    private function isEnabled(): bool
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        return $this->config->isEnabled($storeId);
    }
}
