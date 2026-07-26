<?php
declare(strict_types=1);

namespace Yu\AiChat\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class ConversationActions extends Column
{
    private const URL_VIEW = 'aichat/conversation/view';

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        $name = (string)$this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $item[$name] = [
                'view' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_VIEW, ['id' => $item['conversation_id']]),
                    'label' => __('View'),
                ],
            ];
        }
        return $dataSource;
    }
}
