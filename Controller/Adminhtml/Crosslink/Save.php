<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Controller\Adminhtml\Crosslink;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\Filter\Date as DateFilter;
use Panth\Crosslinks\Controller\Adminhtml\AbstractAction;
use Panth\Crosslinks\Model\Config\Source\CrosslinkReferenceType;
use Panth\Crosslinks\Model\Crosslink\UrlPolicy;

class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Crosslinks::crosslinks';

    public const PERSIST_KEY = 'panth_crosslink';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly DateFilter $dateFilter,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly TypeListInterface $cacheTypeList
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$request->isPost()) {
            return $resultRedirect->setPath('*/*/');
        }

        $formKeyParam = (string) $request->getParam('form_key');
        if ($formKeyParam === '') {
            $this->messageManager->addErrorMessage(__('Invalid form key. Please refresh the page and try again.'));
            return $resultRedirect->setPath('*/*/');
        }

        $data = (array) $request->getPostValue();
        if (empty($data)) {
            return $resultRedirect->setPath('*/*/');
        }

        $id = (int) ($data['crosslink_id'] ?? 0);

        $referenceType = (string) ($data['reference_type'] ?? CrosslinkReferenceType::TYPE_URL);
        $allowedRefTypes = [
            CrosslinkReferenceType::TYPE_URL,
            CrosslinkReferenceType::TYPE_PRODUCT_SKU,
            CrosslinkReferenceType::TYPE_CATEGORY_ID,
        ];
        if (!in_array($referenceType, $allowedRefTypes, true)) {
            $referenceType = CrosslinkReferenceType::TYPE_URL;
        }

        try {
            $activeFrom = $this->normaliseDate($data['active_from'] ?? null);
            $activeTo = $this->normaliseDate($data['active_to'] ?? null);
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Active From and Active To must be valid dates.'));
            return $this->backToEdit($resultRedirect, $id, $data);
        }
        if ($activeFrom !== null && $activeTo !== null && substr($activeTo, 0, 10) < substr($activeFrom, 0, 10)) {
            $this->messageManager->addErrorMessage(__('Active To must not be earlier than Active From.'));
            return $this->backToEdit($resultRedirect, $id, $data);
        }

        $row = [
            'keyword'          => trim((string) ($data['keyword'] ?? '')),
            'url'              => trim((string) ($data['url'] ?? '')),
            'url_title'        => trim((string) ($data['url_title'] ?? '')),
            'max_replacements' => max(1, (int) ($data['max_replacements'] ?? 1)),
            'nofollow'         => (int) !empty($data['nofollow']) ? 1 : 0,
            'priority'         => max(0, (int) ($data['priority'] ?? 0)),
            'is_active'        => (int) !empty($data['is_active']) ? 1 : 0,
            'in_product'       => (int) !empty($data['in_product']) ? 1 : 0,
            'in_category'      => (int) !empty($data['in_category']) ? 1 : 0,
            'in_cms'           => (int) !empty($data['in_cms']) ? 1 : 0,
            'store_id'         => max(0, (int) ($data['store_id'] ?? 0)),
            'reference_type'   => $referenceType,
            'reference_value'  => trim((string) ($data['reference_value'] ?? '')),
            'active_from'      => $activeFrom,
            'active_to'        => $activeTo,
        ];

        if ($row['keyword'] === '') {
            $this->messageManager->addErrorMessage(__('Keyword is required.'));
            return $this->backToEdit($resultRedirect, $id, $data);
        }
        if ($referenceType === CrosslinkReferenceType::TYPE_URL && $row['url'] === '') {
            $this->messageManager->addErrorMessage(__('URL is required when reference type is "Custom URL".'));
            return $this->backToEdit($resultRedirect, $id, $data);
        }
        if ($referenceType !== CrosslinkReferenceType::TYPE_URL && $row['reference_value'] === '') {
            $this->messageManager->addErrorMessage(__('Reference value is required when using product SKU or category ID reference type.'));
            return $this->backToEdit($resultRedirect, $id, $data);
        }
        if ($row['url'] !== '' && !UrlPolicy::isAllowed($row['url'])) {
            $this->messageManager->addErrorMessage(
                __('URL must be a relative path or use the http, https, mailto or tel scheme.')
            );
            return $this->backToEdit($resultRedirect, $id, $data);
        }
        if (str_contains($row['keyword'], '<') || str_contains($row['keyword'], '>')) {
            $this->messageManager->addErrorMessage(__('Keyword must not contain HTML angle brackets.'));
            return $this->backToEdit($resultRedirect, $id, $data);
        }

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_crosslink');

            if ($id > 0) {
                $connection->update($table, $row, ['crosslink_id = ?' => $id]);
            } else {
                $row['created_at'] = $this->dateTime->gmtDate();
                $connection->insert($table, $row);
                $id = (int) $connection->lastInsertId($table);
            }

            $this->messageManager->addSuccessMessage(__('Crosslink saved.'));
            $this->dataPersistor->clear(self::PERSIST_KEY);
            $this->cacheTypeList->invalidate(['full_page', 'block_html']);

            if ($request->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->backToEdit($resultRedirect, $id, $data);
        }

        return $resultRedirect->setPath('*/*/');
    }

    private function backToEdit($resultRedirect, int $id, array $data)
    {
        $this->dataPersistor->set(self::PERSIST_KEY, $data);
        return $resultRedirect->setPath('*/*/edit', $id > 0 ? ['id' => $id] : []);
    }

    private function normaliseDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value)) {
            return $value;
        }

        return (string) $this->dateFilter->filter($value);
    }
}
