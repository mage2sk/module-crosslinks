<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Config reader for the Crosslinks module. All admin toggles live
 * under `panth_crosslinks/general/*`.
 */
class Config
{
    public const XML_ENABLED              = 'panth_crosslinks/general/crosslinks_enabled';
    public const XML_MAX_LINKS_PER_PAGE   = 'panth_crosslinks/general/max_links_per_page';
    public const XML_EXCLUDED_TAGS        = 'panth_crosslinks/general/excluded_tags';
    public const XML_TIME_ACTIVATION      = 'panth_crosslinks/general/crosslink_time_activation';

    public const DEFAULT_EXCLUDED_TAGS = 'h1,h2,h3,h4,h5,h6,a,button,script,style';
    public const DEFAULT_MAX_LINKS_PER_PAGE = 5;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_ENABLED, $storeId);
    }

    public function isCrosslinksEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId);
    }

    public function getMaxLinksPerPage(?int $storeId = null): int
    {
        $value = $this->value(self::XML_MAX_LINKS_PER_PAGE, $storeId);
        $int = (int) $value;
        return $int > 0 ? $int : self::DEFAULT_MAX_LINKS_PER_PAGE;
    }

    public function getExcludedTags(?int $storeId = null): string
    {
        $value = (string) $this->value(self::XML_EXCLUDED_TAGS, $storeId);
        return $value !== '' ? $value : self::DEFAULT_EXCLUDED_TAGS;
    }

    public function isTimeActivationEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_TIME_ACTIVATION, $storeId);
    }

    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function value(string $path, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
