<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Controller\Adminhtml;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Shared admin controller wiring: records the redirect target and flash messages.
 */
abstract class ControllerTestCase extends TestCase
{
    protected array $redirect = [];

    protected array $messages = ['success' => [], 'error' => []];

    protected array $invalidated = [];

    protected function cacheTypeList(): TypeListInterface
    {
        $this->invalidated = [];
        $list = $this->createStub(TypeListInterface::class);
        $list->method('invalidate')->willReturnCallback(function ($types) {
            $this->invalidated[] = (array) $types;
        });
        return $list;
    }

    protected function buildContext(array $params = [], array $post = [], bool $isPost = true): Context
    {
        $this->redirect = [];
        $this->messages = ['success' => [], 'error' => []];

        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(
            static fn($key, $default = null) => $params[$key] ?? $default
        );
        $request->method('getPostValue')->willReturn($post);
        $request->method('isPost')->willReturn($isPost);

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(
            function ($path, $args = []) use (&$redirect) {
                $this->redirect = ['path' => $path, 'params' => $args];
                return $redirect;
            }
        );
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $messageManager = $this->createStub(ManagerInterface::class);
        foreach (['success', 'error'] as $type) {
            $messageManager->method('add' . ucfirst($type) . 'Message')->willReturnCallback(
                function ($message) use ($type, &$messageManager) {
                    $this->messages[$type][] = (string) $message;
                    return $messageManager;
                }
            );
        }

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($messageManager);

        return $context;
    }

    protected function resource(AdapterInterface $connection): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }
}
