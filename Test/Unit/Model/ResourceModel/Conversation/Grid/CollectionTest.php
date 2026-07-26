<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Model\ResourceModel\Conversation\Grid;

use PHPUnit\Framework\TestCase;
use Yu\AiChat\Model\ResourceModel\Conversation\Grid\Collection;

class CollectionTest extends TestCase
{
    /**
     * Regression guard for a real bug hit while building the grid: filtering
     * or sorting by "ID" threw "Column 'conversation_id' ... ambiguous"
     * because the joined msg_agg subquery (added in _initSelect()) also
     * exposes a conversation_id column. The fix maps the field explicitly
     * to main_table in _construct(), which this test exercises directly via
     * reflection — constructing the real object would require Magento's
     * static ObjectManager singleton (SearchResult::getResourceConnection()
     * falls back to ObjectManager::getInstance()), which isn't bootstrapped
     * in this unit-test harness.
     */
    public function testConstructMapsConversationIdToMainTableToAvoidAmbiguousColumn(): void
    {
        $reflectionClass = new \ReflectionClass(Collection::class);
        $collection = $reflectionClass->newInstanceWithoutConstructor();

        $construct = $reflectionClass->getMethod('_construct');
        $construct->setAccessible(true);
        $construct->invoke($collection);

        $mapProperty = (new \ReflectionClass(\Magento\Framework\Data\Collection\AbstractDb::class))->getProperty('_map');
        $mapProperty->setAccessible(true);
        $map = $mapProperty->getValue($collection);

        $this->assertSame('main_table.conversation_id', $map['fields']['conversation_id']);
    }
}
