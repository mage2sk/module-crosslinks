<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Controller\Adminhtml\Crosslink;

use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Panth\Crosslinks\Controller\Adminhtml\AbstractAction;

class Index extends AbstractAction
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
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_Crosslinks::manage');
        $page->getConfig()->getTitle()->prepend(__('Crosslinks'));
        return $page;
    }
}
