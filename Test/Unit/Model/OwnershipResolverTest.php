<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Model;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Math\Random;
use PHPUnit\Framework\TestCase;
use Yu\AiChat\Api\Data\ConversationOwnerInterfaceFactory;
use Yu\AiChat\Model\ConversationOwner;
use Yu\AiChat\Model\OwnershipResolver;

class OwnershipResolverTest extends TestCase
{
    private function createConversationOwnerFactory(): ConversationOwnerInterfaceFactory
    {
        $factory = $this->createMock(ConversationOwnerInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $data) => new ConversationOwner(
                $data['customerId'] ?? null,
                $data['guestToken'] ?? null
            )
        );

        return $factory;
    }

    public function testResolveReturnsLoggedInCustomerOwner(): void
    {
        $session = $this->createMock(CustomerSession::class);
        $session->method('isLoggedIn')->willReturn(true);
        $session->method('getCustomerId')->willReturn('42');

        $resolver = new OwnershipResolver($session, $this->createMock(Random::class), $this->createConversationOwnerFactory());
        $owner = $resolver->resolve();

        $this->assertSame(42, $owner->getCustomerId());
        $this->assertNull($owner->getGuestToken());
    }

    public function testResolveReturnsExistingGuestTokenWhenNotLoggedIn(): void
    {
        $session = $this->createMock(CustomerSession::class);
        $session->method('isLoggedIn')->willReturn(false);
        $session->method('getData')->with('yu_aichat_guest_token')->willReturn('existing-token');

        $resolver = new OwnershipResolver($session, $this->createMock(Random::class), $this->createConversationOwnerFactory());
        $owner = $resolver->resolve();

        $this->assertNull($owner->getCustomerId());
        $this->assertSame('existing-token', $owner->getGuestToken());
    }

    public function testResolveReturnsNullWhenNoCustomerAndNoGuestToken(): void
    {
        $session = $this->createMock(CustomerSession::class);
        $session->method('isLoggedIn')->willReturn(false);
        $session->method('getData')->with('yu_aichat_guest_token')->willReturn(null);

        $resolver = new OwnershipResolver($session, $this->createMock(Random::class), $this->createConversationOwnerFactory());

        $this->assertNull($resolver->resolve());
    }

    public function testResolveOrCreateReturnsExistingOwnerWithoutGeneratingToken(): void
    {
        $session = $this->createMock(CustomerSession::class);
        $session->method('isLoggedIn')->willReturn(true);
        $session->method('getCustomerId')->willReturn('7');
        $random = $this->createMock(Random::class);
        $random->expects($this->never())->method('getRandomString');

        $resolver = new OwnershipResolver($session, $random, $this->createConversationOwnerFactory());
        $owner = $resolver->resolveOrCreate();

        $this->assertSame(7, $owner->getCustomerId());
    }

    public function testResolveOrCreateGeneratesAndPersistsNewGuestTokenWhenNoneExists(): void
    {
        // setData() is resolved via SessionManager::__call(), not a real declared
        // method, so it must be added explicitly instead of relying on createMock().
        $session = $this->getMockBuilder(CustomerSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isLoggedIn', 'getData'])
            ->addMethods(['setData'])
            ->getMock();
        $session->method('isLoggedIn')->willReturn(false);
        $session->method('getData')->with('yu_aichat_guest_token')->willReturn(null);
        $session->expects($this->once())
            ->method('setData')
            ->with('yu_aichat_guest_token', 'new-random-token');
        $random = $this->createMock(Random::class);
        $random->method('getRandomString')->with(32)->willReturn('new-random-token');

        $resolver = new OwnershipResolver($session, $random, $this->createConversationOwnerFactory());
        $owner = $resolver->resolveOrCreate();

        $this->assertNull($owner->getCustomerId());
        $this->assertSame('new-random-token', $owner->getGuestToken());
    }
}
