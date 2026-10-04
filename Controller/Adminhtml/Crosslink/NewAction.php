<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Controller\Adminhtml\Crosslink;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Panth\Crosslinks\Controller\Adminhtml\AbstractAction;

class NewAction extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Panth_Crosslinks::crosslinks';

    public function __construct(
        Context $context,
        private readonly ForwardFactory $forwardFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        return $this->forwardFactory->create()->forward('edit');
    }
}
