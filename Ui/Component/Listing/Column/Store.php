<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Ui\Component\Listing\Column;

use Magento\Store\Ui\Component\Listing\Column\Store as BaseStore;

class Store extends BaseStore
{
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                if (array_key_exists('store_id', $item) && !is_array($item['store_id'])) {
                    $item['store_id'] = [(int) $item['store_id']];
                }
            }
            unset($item);
        }
        return parent::prepareDataSource($dataSource);
    }

    protected function prepareItem(array $item)
    {
        $stores = $item[$this->storeKey] ?? null;
        if ($stores === null || $stores === '' || $stores === []) {
            return '';
        }
        if (!is_array($stores)) {
            $stores = explode(',', (string) $stores);
        }
        $stores = array_map('intval', $stores);
        if (in_array(0, $stores, true)) {
            return (string) __('All Store Views');
        }
        $labels = [];
        foreach ($this->systemStore->getStoresStructure(false, $stores) as $website) {
            foreach ($website['children'] ?? [] as $group) {
                foreach ($group['children'] ?? [] as $store) {
                    $labels[] = $this->escaper->escapeHtml($store['label']);
                }
            }
        }
        return implode('<br/>', $labels);
    }
}
