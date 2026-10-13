# API Platform: resource, processor, provider

## Resource (`Infrastructure/Framework/ApiPlatform/ApiResource/`)

Declaration only. No logic, no properties besides operation-name constants.

```php
#[ApiResource(operations: [
    new GetCollection(
        uriTemplate: '/orders',
        output:      OrderOutputDto::class,
        name:        self::ORDER_BROWSE_GET,
        provider:    GetOrderCollectionStateProvider::class,
    ),
    new Get(
        uriTemplate: '/orders/{id}',
        output:      OrderOutputDto::class,
        name:        self::ORDER_GET_GET,
        provider:    GetOrderStateProvider::class,
    ),
    new Post(
        uriTemplate: '/orders',
        status:      201,
        input:       OrderInputDto::class,
        output:      OrderOutputDto::class,
        name:        self::ORDER_CREATE_POST,
        processor:   PlaceOrderStateProcessor::class,
    ),
    new Patch(
        uriTemplate: '/orders/{id}',
        status:      200,
        input:       OrderInputDto::class,
        output:      OrderOutputDto::class,
        name:        self::ORDER_UPDATE_PATCH,
        processor:   UpdateOrderStateProcessor::class,
        provider:    GetOrderStateProvider::class,
    ),
    new Delete(
        uriTemplate: '/orders/{id}',
        status:      204,
        input:       false,
        output:      false,
        name:        self::ORDER_DELETE_DELETE,
        processor:   DeleteOrderStateProcessor::class,
        provider:    GetOrderStateProvider::class,
    ),
])]
final class OrderApiResource
{
    public const ORDER_BROWSE_GET    = 'order_browse_get';
    public const ORDER_GET_GET       = 'order_get_get';
    public const ORDER_CREATE_POST   = 'order_create_post';
    public const ORDER_UPDATE_PATCH  = 'order_update_patch';
    public const ORDER_DELETE_DELETE = 'order_delete_delete';
}
```

Operation names: `<aggregate>_<action>_<http_method>`; `browse` for collections. `Patch`/`Delete` need a provider so API Platform can 404 before the processor runs.

## Input DTO (`Dto/Input/`)

Format validation belongs here. Enum choices come from an Application service (`Application/Service/Carriers::values()` returning `array_column(Carrier::cases(), 'value')`), so the input DTO does not import the Domain enum:

```php
final class OrderInputDto
{
    #[Assert\NotBlank, Assert\Length(max: 64)]
    public string $reference;

    #[Assert\NotBlank, Assert\Choice(callback: [Carriers::class, 'values'])]
    public string $carrier;

    #[Assert\NotNull, Assert\Valid]
    public ShippingAddressInputDto $shippingAddress;
}
```

## State processor (`Port/Api/StateProcessor/`) — writes

Dispatch the command, then re-read through a query. Never return the entity or build the output from the input.

```php
/** @implements ProcessorInterface<OrderInputDto, OrderOutputDto> */
final readonly class UpdateOrderStateProcessor implements ProcessorInterface
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private QueryBusInterface $queryBus,
        private OrderDtoFactory $factory,
        private OrderDtoToOrderOutputDtoTransformer $transformer,
    ) {
    }

    /**
     * @param array<mixed> $uriVariables
     * @param array<mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OrderOutputDto
    {
        $orderId = ArrayData::create($uriVariables)->getNonEmptyString('id');

        $this->commandBus->dispatch(new UpdateOrderCommand(
            id:       $orderId,
            orderDto: $this->factory->createFromOrderInputDto($data),
        ));

        /** @phpstan-var OrderDto $orderDto */
        $orderDto = $this->queryBus->query(new GetOrderByIdQuery($orderId));

        return $this->transformer->transform($orderDto);
    }
}
```

## State provider (`Port/Api/StateProvider/`) — reads

```php
/** @implements ProviderInterface<Generator<OrderOutputDto>> */
final readonly class GetOrderCollectionStateProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private OrderDtoToOrderOutputDtoTransformer $transformer,
    ) {
    }

    /** @return Generator<OrderOutputDto> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Generator
    {
        /** @var Generator<OrderDto> $orderDtos */
        $orderDtos = $this->queryBus->query(new GetAllOrdersQuery());

        foreach ($orderDtos as $orderDto) {
            yield $this->transformer->transform($orderDto);
        }
    }
}
```

## Rules

- URI variables and filters are read through `ArrayData::create(...)`, never by casting.
- Processors and providers only talk to `CommandBusInterface` / `QueryBusInterface`. Never inject a repository or touch the Domain.
- Functional test per resource in `tests/<Context>/Functional/Infrastructure/Port/Api/`.
