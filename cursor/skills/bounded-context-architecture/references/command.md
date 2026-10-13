# Command + handler (CQRS write side)

Location: `src/<Context>/Application/Command/`.

## Rules

- One folder per use case: `Application/Command/<Verb><Aggregate>/` holding exactly the command and its handler.
- Command: `final readonly`, public promoted properties, implements `PayNL\Bundle\CommandBusBundle\Command\CommandInterface`.
- Add `TransactionalMessageInterface` for every state change (`getConnectionNames(): ['default']`).
- Add `LockableMessageInterface` when the command targets an existing aggregate (lock on its id).
- Payload is primitives or Application DTOs, never Domain objects.
- Handler: `final readonly`, implements `CommandHandlerInterface`, single `__invoke(<Command>)`.
- Handler orchestrates only: load → check cross-aggregate rules (uniqueness) → call Domain behaviour → validate via Domain port → save.
- Return `void`. Exception: a create returns the new id (`string`) so the caller can query the result.

## Command for a new aggregate

```php
final readonly class PlaceOrderCommand implements CommandInterface, TransactionalMessageInterface
{
    public function __construct(
        public OrderDto $orderDto,
    ) {
    }

    /** @return string[] */
    public function getConnectionNames(): array
    {
        return ['default'];
    }
}
```

```php
final readonly class PlaceOrderCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private OrderRepositoryInterface $repository,
        private OrderDtoToOrderTransformer $transformer,
        private CarrierGatewayInterface $carrierGateway,
    ) {
    }

    public function __invoke(PlaceOrderCommand $command): string
    {
        $existingOrder = $this->repository->findByReference($command->orderDto->getReference());

        if ($existingOrder instanceof Order) {
            throw new OrderAlreadyExistsException();
        }

        $order = $this->transformer->transform($command->orderDto);
        $this->carrierGateway->assertDeliverable($order->getCarrier(), $order->getShippingAddress());
        $this->repository->save($order);

        return $order->getId();
    }
}
```

## Command on an existing aggregate (locked)

```php
final readonly class CancelOrderCommand implements
    CommandInterface,
    TransactionalMessageInterface,
    LockableMessageInterface
{
    public function __construct(
        public string $id,
    ) {
    }

    /** @return string[] */
    public function getConnectionNames(): array
    {
        return ['default'];
    }

    public function getLockKey(): string
    {
        return $this->id;
    }

    public function getLockTtl(): int
    {
        return 1;
    }

    public function waitForLockMs(): int
    {
        return 1000;
    }
}
```

```php
final readonly class CancelOrderCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private OrderRepositoryInterface $repository,
    ) {
    }

    /** @throws OrderNotFoundException */
    public function __invoke(CancelOrderCommand $command): void
    {
        $order = $this->repository->getById($command->id);
        $order->cancel();
        $this->repository->save($order);
    }
}
```

The "cannot cancel a shipped order" rule lives in `Order::cancel()`, not here.

## Anti-patterns

- Business rules in the handler (`if ($order->getStatus() === Status::SHIPPED)`) → move to the aggregate.
- Returning a DTO or entity from a command → dispatch a query afterwards.
- Injecting an Infrastructure class → depend on a Domain interface.
