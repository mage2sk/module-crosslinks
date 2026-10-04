<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Panth\Crosslinks\Helper\Config;

class LimitScope implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::LIMIT_SCOPE_PAGE, 'label' => __('Page Request')],
            ['value' => Config::LIMIT_SCOPE_CONTENT, 'label' => __('Content Block')],
        ];
    }
}
