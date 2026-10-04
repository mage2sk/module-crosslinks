<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Plugin\Crosslink;

use Magento\Store\Model\StoreManagerInterface;
use Panth\Crosslinks\Model\Crosslink\ReplacementService;

class CrosslinkFilterDecorator
{
    public function __construct(
        private readonly object $innerFilter,
        private readonly ReplacementService $replacementService,
        private readonly StoreManagerInterface $storeManager,
        private readonly string $pageType
    ) {
    }

    public function filter($value): string
    {
        $result = $this->innerFilter->filter($value);
        $storeId = (int) $this->storeManager->getStore()->getId();

        return $this->replacementService->processContent((string) $result, $this->pageType, $storeId);
    }

    public function setVariables(array $variables): static
    {
        $this->innerFilter->setVariables($variables);
        return $this;
    }

    public function setStoreId($storeId): static
    {
        $this->innerFilter->setStoreId($storeId);
        return $this;
    }

    public function setStrictMode(bool $strictMode): bool
    {
        return $this->innerFilter->setStrictMode($strictMode);
    }

    public function isStrictMode(): bool
    {
        return $this->innerFilter->isStrictMode();
    }

    public function __call(string $method, array $args): mixed
    {
        $result = $this->innerFilter->$method(...$args);
        return $result === $this->innerFilter ? $this : $result;
    }
}
