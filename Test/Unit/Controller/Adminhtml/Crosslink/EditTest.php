<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Controller\Adminhtml\Crosslink;

use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Panth\Crosslinks\Controller\Adminhtml\Crosslink\Edit;
use Panth\Crosslinks\Test\Unit\Controller\Adminhtml\ControllerTestCase;

class EditTest extends ControllerTestCase
{
    private function renderTitle(array $params): string
    {
        $prepended = [];
        $title = $this->createStub(Title::class);
        $title->method('prepend')->willReturnCallback(function ($text) use (&$prepended) {
            $prepended[] = (string) $text;
        });
        $config = $this->createStub(Config::class);
        $config->method('getTitle')->willReturn($title);

        $page = $this->createMock(Page::class);
        $page->expects($this->once())->method('setActiveMenu')->with('Panth_Crosslinks::manage')->willReturnSelf();
        $page->method('getConfig')->willReturn($config);

        $pageFactory = $this->createStub(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);

        $result = (new Edit($this->buildContext($params), $pageFactory))->execute();
        $this->assertSame($page, $result);

        return $prepended[0];
    }

    public function testExistingCrosslinkTitleIncludesId(): void
    {
        $this->assertSame('Edit Crosslink #12', $this->renderTitle(['id' => '12']));
    }

    public function testNewCrosslinkTitle(): void
    {
        $this->assertSame('New Crosslink', $this->renderTitle([]));
    }
}
