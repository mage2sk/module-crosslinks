<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Block\Adminhtml;

use Magento\Framework\App\Request\Http;
use Magento\Framework\UrlInterface;
use Panth\Crosslinks\Block\Adminhtml\GenericBackButton;
use Panth\Crosslinks\Block\Adminhtml\GenericDeleteButton;
use PHPUnit\Framework\TestCase;

class ButtonsTest extends TestCase
{
    private function url(): UrlInterface
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn($route, $params = []) => 'https://admin.test/' . $route
                . (isset($params['id']) ? '/id/' . $params['id'] : '')
        );
        return $url;
    }

    private function request(array $params): Http
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(static fn($key) => $params[$key] ?? null);
        $request->method('getRouteName')->willReturn('panth_crosslinks');
        $request->method('getControllerName')->willReturn('crosslink');
        return $request;
    }

    public function testBackButtonPointsToGrid(): void
    {
        $data = (new GenericBackButton($this->url()))->getButtonData();

        $this->assertSame("location.href = 'https://admin.test/*/*/';", $data['on_click']);
        $this->assertSame('Back', (string) $data['label']);
        $this->assertSame(10, $data['sort_order']);
    }

    public function testDeleteButtonIsHiddenForNewRecord(): void
    {
        $this->assertSame([], (new GenericDeleteButton($this->url(), $this->request([])))->getButtonData());
    }

    public function testDeleteButtonUsesCurrentRouteAndId(): void
    {
        $data = (new GenericDeleteButton($this->url(), $this->request(['id' => '6'])))->getButtonData();

        $this->assertSame('delete', $data['class']);
        $this->assertStringContainsString(
            "'https://admin.test/panth_crosslinks/crosslink/delete/id/6'",
            $data['on_click']
        );
        $this->assertStringStartsWith(
            "deleteConfirm('Are you sure you want to delete this item?'",
            $data['on_click']
        );
    }
}
