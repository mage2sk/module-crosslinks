<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Ui\Component\Form\DataProvider;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Panth\Crosslinks\Model\ResourceModel\Crosslink\CollectionFactory;

class CrosslinkFormDataProvider extends AbstractDataProvider
{
    public const PERSIST_KEY = 'panth_crosslink';

    private const DEFAULTS = [
        'is_active'        => 1,
        'in_product'       => 1,
        'in_category'      => 1,
        'in_cms'           => 1,
        'max_replacements' => 1,
        'nofollow'         => 0,
        'priority'         => 0,
        'store_id'         => 0,
        'reference_type'   => 'url',
    ];

    private ?array $loadedData = null;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly DataPersistorInterface $dataPersistor,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }

        $this->loadedData = [];
        $items = $this->collection->getItems();

        foreach ($items as $item) {
            $this->loadedData[$item->getId()] = $item->getData();
        }

        if (empty($this->loadedData)) {
            $this->loadedData[''] = self::DEFAULTS;
        }

        $persisted = $this->dataPersistor->get(self::PERSIST_KEY);
        if (is_array($persisted) && $persisted !== []) {
            $id = (int) ($persisted['crosslink_id'] ?? 0);
            $key = $id > 0 ? $id : '';
            $this->loadedData[$key] = array_merge($this->loadedData[$key] ?? self::DEFAULTS, $persisted);
            $this->dataPersistor->clear(self::PERSIST_KEY);
        }

        return $this->loadedData;
    }
}
