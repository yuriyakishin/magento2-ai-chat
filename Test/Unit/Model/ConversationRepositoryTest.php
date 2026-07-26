<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Yu\AiChat\Model\Conversation;
use Yu\AiChat\Model\ConversationOwner;
use Yu\AiChat\Model\ConversationRepository;
use Yu\AiChat\Model\ResourceModel\Conversation as ConversationResource;
use Yu\AiChat\Model\ResourceModel\Conversation\Collection;
use Yu\AiChat\Model\ResourceModel\Conversation\CollectionFactory;

class ConversationRepositoryTest extends TestCase
{
    public function testSaveDelegatesToResourceAndReturnsTheSameEntity(): void
    {
        $resource = $this->createMock(ConversationResource::class);
        $conversation = $this->createMock(Conversation::class);
        $resource->expects($this->once())->method('save')->with($conversation);

        $repository = new ConversationRepository($resource, $this->createMock(CollectionFactory::class));

        $this->assertSame($conversation, $repository->save($conversation));
    }

    public function testGetByIdFiltersByConversationIdAndIgnoresOwner(): void
    {
        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getId')->willReturn(5);
        $collection = $this->makeCollection();
        $collection->expects($this->once())->method('addFieldToFilter')->with('conversation_id', 5);
        $collection->method('getFirstItem')->willReturn($conversation);

        $repository = new ConversationRepository(
            $this->createMock(ConversationResource::class),
            $this->makeCollectionFactory($collection)
        );

        $this->assertSame($conversation, $repository->getById(5));
    }

    public function testGetByIdThrowsWhenConversationDoesNotExist(): void
    {
        $missing = $this->createMock(Conversation::class);
        $missing->method('getId')->willReturn(null);
        $collection = $this->makeCollection();
        $collection->method('getFirstItem')->willReturn($missing);

        $repository = new ConversationRepository(
            $this->createMock(ConversationResource::class),
            $this->makeCollectionFactory($collection)
        );

        $this->expectException(NoSuchEntityException::class);

        $repository->getById(999);
    }

    public function testGetByUuidForOwnerFiltersByCustomerIdWhenOwnerIsACustomer(): void
    {
        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getId')->willReturn(1);
        $collection = $this->makeCollection();
        $collection->expects($this->exactly(2))->method('addFieldToFilter')->withConsecutive(
            ['uuid', 'the-uuid'],
            ['customer_id', 42]
        );
        $collection->method('getFirstItem')->willReturn($conversation);

        $repository = new ConversationRepository(
            $this->createMock(ConversationResource::class),
            $this->makeCollectionFactory($collection)
        );

        $repository->getByUuidForOwner('the-uuid', new ConversationOwner(42, null));
    }

    public function testGetByUuidForOwnerFiltersByGuestTokenWhenOwnerIsAGuest(): void
    {
        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getId')->willReturn(1);
        $collection = $this->makeCollection();
        $collection->expects($this->exactly(2))->method('addFieldToFilter')->withConsecutive(
            ['uuid', 'the-uuid'],
            ['guest_token', 'guest-abc']
        );
        $collection->method('getFirstItem')->willReturn($conversation);

        $repository = new ConversationRepository(
            $this->createMock(ConversationResource::class),
            $this->makeCollectionFactory($collection)
        );

        $repository->getByUuidForOwner('the-uuid', new ConversationOwner(null, 'guest-abc'));
    }

    public function testGetByUuidForOwnerThrowsWhenConversationBelongsToAnotherOwner(): void
    {
        $missing = $this->createMock(Conversation::class);
        $missing->method('getId')->willReturn(null);
        $collection = $this->makeCollection();
        $collection->method('getFirstItem')->willReturn($missing);

        $repository = new ConversationRepository(
            $this->createMock(ConversationResource::class),
            $this->makeCollectionFactory($collection)
        );

        $this->expectException(NoSuchEntityException::class);

        $repository->getByUuidForOwner('the-uuid', new ConversationOwner(null, 'guest-abc'));
    }

    public function testGetListForOwnerOrdersByUpdatedAtDescAndAppliesLimit(): void
    {
        $conversation = $this->createMock(Conversation::class);
        $collection = $this->makeCollection();
        $collection->expects($this->once())->method('addFieldToFilter')->with('customer_id', 42);
        $collection->expects($this->once())->method('setOrder')->with('updated_at', 'DESC');
        $collection->expects($this->once())->method('setPageSize')->with(10);
        $collection->method('getItems')->willReturn([$conversation]);

        $repository = new ConversationRepository(
            $this->createMock(ConversationResource::class),
            $this->makeCollectionFactory($collection)
        );

        $this->assertSame([$conversation], $repository->getListForOwner(new ConversationOwner(42, null), 10));
    }

    public function testGetListForOwnerFiltersByGuestTokenWhenOwnerIsAGuest(): void
    {
        $collection = $this->makeCollection();
        $collection->expects($this->once())->method('addFieldToFilter')->with('guest_token', 'guest-abc');
        $collection->method('getItems')->willReturn([]);

        $repository = new ConversationRepository(
            $this->createMock(ConversationResource::class),
            $this->makeCollectionFactory($collection)
        );

        $this->assertSame([], $repository->getListForOwner(new ConversationOwner(null, 'guest-abc')));
    }

    /**
     * @return Collection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeCollection()
    {
        return $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @param Collection&\PHPUnit\Framework\MockObject\MockObject $collection
     * @return CollectionFactory&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeCollectionFactory($collection)
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        return $factory;
    }
}
