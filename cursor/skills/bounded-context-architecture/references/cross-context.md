# Cross-context communication

Deptrac lets no context depend on another (`Ordering_*` may not see `Catalog_*`, `Customer_*`, ...). `src/Shared/` is the only common ground, and Shared never imports a feature context.

## Option 1 — shared domain event (push)

The producing context dispatches an event defined in Shared. The consuming context reacts in an Infrastructure listener that dispatches its own command.

| Piece | Location | Example |
| --- | --- | --- |
| Event (primitive payload only) | `src/Shared/Domain/Event/` | `OrderPlaced` |
| Recorded by | producing aggregate | `Ordering\...\Order` |
| Dispatched by | producing repository `save()` | `OrderRepository` |
| Listener | `src/<Consumer>/Infrastructure/Event/Listener/` | `Invoicing\...\OrderPlacedListener` |

```php
final readonly class OrderPlacedListener
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    #[AsEventListener]
    public function onOrderPlaced(OrderPlaced $event): void
    {
        $this->commandBus->dispatch(new CreateInvoiceCommand(
            orderId:    $event->getOrderId(),
            customerId: $event->getCustomerId(),
        ));
    }
}
```

The listener translates and dispatches. It holds no business logic.

## Option 2 — query port (pull)

A consuming context needs to read data owned by another context.

| Piece | Location | Example |
| --- | --- | --- |
| Port interface | `src/Shared/<Layer>/Port/` | `ProductQueryPortInterface` |
| Return value object (primitives) | `src/Shared/Domain/ValueObject/` | `ProductInfo` |
| Implementation | owning context `Infrastructure/Port/` | `Catalog\Infrastructure\Port\ProductQueryPort` |

```php
interface ProductQueryPortInterface
{
    public function getProductById(string $productId): ?ProductInfo;
}
```

The port's layer decides who may use it. A port in `Shared/Infrastructure` is reachable only from the consumer's Infrastructure, so a handler that needs the data then needs its own Domain port (see `adapter.md`) implemented by the shared one. A port in `Shared/Application` can be injected into handlers directly. Pick one per project; see `deptrac.md`.

## Choosing

- Reacting to something that happened elsewhere → shared event.
- Reading current state owned elsewhere → query port.
- Never share entities or repositories across contexts. Store the foreign id as a string (`OrderLine::$productId`).
