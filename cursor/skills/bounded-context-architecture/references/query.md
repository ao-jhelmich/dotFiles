# Query + handler (CQRS read side)

Location: `src/<Context>/Application/Query/`.

## Rules

- One folder per use case: `Application/Query/Get<What>[By<Criteria>]/` holding the query and its handler.
- Query: `final readonly`, implements `PayNL\Bundle\CommandBusBundle\Query\QueryInterface`, public promoted criteria. No transaction or lock interfaces.
- Handler: `final readonly`, implements `QueryHandlerInterface`, single `__invoke(<Query>)`.
- Returns an Application DTO, or `Generator<Dto>` for collections. Never an entity, never an Infrastructure output DTO.
- Single item → repository `getX()` (not-found exception surfaces as 404). Collection → repository `getAll()`/generator, transformed lazily.
- No state changes and no side effects.

## Single item

```php
final readonly class GetOrderByIdQuery implements QueryInterface
{
    public function __construct(
        public string $id,
    ) {
    }
}
```

```php
final readonly class GetOrderByIdQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private OrderToOrderDtoTransformer $transformer,
    ) {
    }

    public function __invoke(GetOrderByIdQuery $query): OrderDto
    {
        $order = $this->orderRepository->getById($query->id);

        return $this->transformer->transform($order);
    }
}
```

## Collection

```php
final readonly class GetAllOrdersQuery implements QueryInterface
{
}
```

```php
/** @return Generator<OrderDto> */
public function __invoke(GetAllOrdersQuery $query): Generator
{
    foreach ($this->orderRepository->getAll() as $order) {
        yield $this->transformer->transform($order);
    }
}
```

## Caller side

The bus returns `mixed`; narrow with a PHPDoc var at the call site:

```php
/** @phpstan-var OrderDto $orderDto */
$orderDto = $this->queryBus->query(new GetOrderByIdQuery($orderId));
```

## Read model

By default, queries read through the write repository and the aggregate. Add a dedicated read repository or projection (`Domain/Model/Read/`) only when a query cannot be served efficiently from the aggregate.
