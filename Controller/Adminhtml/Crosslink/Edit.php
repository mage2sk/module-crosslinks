<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Controller\Adminhtml\Crosslink;

use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Panth\Crosslinks\Controller\Adminhtml\AbstractAction;

class Edit extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_Crosslinks::crosslinks';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_Crosslinks::manage');
        $page->getConfig()->getTitle()->prepend(
            $id ? __('Edit Crosslink #%1', $id) : __('New Crosslink')
        );
        return $page;
    }
}
