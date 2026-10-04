<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Model\Crosslink;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\CouldNotSaveException;
use Panth\Crosslinks\Api\CrosslinkRepositoryInterface;

class Repository implements CrosslinkRepositoryInterface
{
    private const COLUMNS = [
        'keyword', 'url', 'url_title', 'max_replacements', 'nofollow', 'priority',
        'is_active', 'in_product', 'in_category', 'in_cms', 'store_id',
        'active_from', 'active_to', 'reference_type', 'reference_value',
    ];

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function getById(int $id)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_crosslink');
        if (!$connection->isTableExists($table)) {
            return null;
        }
        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('crosslink_id = ?', $id)
        );
        return $row ?: null;
    }

    public function save($entity)
    {
        if ($entity instanceof DataObject) {
            $data = $entity->getData();
        } elseif (is_array($entity)) {
            $data = $entity;
        } else {
            throw new CouldNotSaveException(__('Unsupported crosslink entity.'));
        }

        $row = array_intersect_key($data, array_flip(self::COLUMNS));
        $id = (int) ($data['crosslink_id'] ?? 0);

        if ($id <= 0 && trim((string) ($row['keyword'] ?? '')) === '') {
            throw new CouldNotSaveException(__('Keyword is required.'));
        }

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_crosslink');

            if ($id > 0) {
                if (!empty($row)) {
                    $connection->update($table, $row, ['crosslink_id = ?' => $id]);
                }
            } else {
                $connection->insert($table, $row);
                $id = (int) $connection->lastInsertId($table);
            }
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(__('Could not save the crosslink: %1', $e->getMessage()), $e);
        }

        if ($entity instanceof DataObject) {
            $entity->setData('crosslink_id', $id);
            return $entity;
        }

        $entity['crosslink_id'] = $id;
        return $entity;
    }

    public function deleteById(int $id): bool
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_crosslink');
        if (!$connection->isTableExists($table)) {
            return false;
        }
        return (bool) $connection->delete($table, ['crosslink_id = ?' => $id]);
    }
}
