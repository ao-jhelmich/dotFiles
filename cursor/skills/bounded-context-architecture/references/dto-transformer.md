# DTOs and transformers

Three DTO kinds, three places. Never let one cross the layer boundary of another.

| DTO | Location | Purpose | Validation |
| --- | --- | --- | --- |
| Input DTO | `Infrastructure/Framework/ApiPlatform/Dto/Input/` | HTTP request body | `Assert\*` constraints |
| Application DTO | `Application/Dto/` | command payload, query result | none (already validated) |
| Output DTO | `Infrastructure/Framework/ApiPlatform/Dto/Output/` | HTTP response body | none |

## Flow

```
Write:  InputDto --Factory--> Application Dto --Command--> Handler --Transformer--> Aggregate
Read:   Aggregate --Transformer--> Application Dto --Query result--> Infra Transformer --> OutputDto
```

| Mapping | Class kind | Location |
| --- | --- | --- |
| InputDto → Application Dto | `*DtoFactory` | `Infrastructure/Framework/ApiPlatform/Factory/` |
| Application Dto → Domain | `<Dto>To<Aggregate>Transformer` | `Application/Transformer/` |
| Domain → Application Dto | `<Aggregate>To<Dto>Transformer` | `Application/Transformer/` |
| Application Dto → OutputDto | `<Dto>To<OutputDto>Transformer` | `Infrastructure/Transformer/` |

## Application DTO

```php
final readonly class ShippingAddressDto
{
    public function __construct(
        public string $street,
        public string $postalCode,
        public string $country,
        public ?string $pickupPointId = null,
    ) {
    }
}
```

Audit fields (created/updated at and by) come from a shared trait in `src/Shared/Application/Dto/Traits/` when the project has one.

## Application Dto → Domain (type dispatch with `match`)

```php
public function createShippingAddress(OrderDto $order): ShippingAddress
{
    $carrier = $this->resolveCarrier($order);
    $dto     = $order->getShippingAddress();

    return match ($carrier) {
        Carrier::POSTNL => new ShippingAddress($dto->street, $dto->postalCode, $dto->country, [
            'pickupPointId' => $dto->pickupPointId,
        ]),
        Carrier::DHL => new ShippingAddress($dto->street, $dto->postalCode, $dto->country),
    };
}

private function resolveCarrier(OrderDto $order): Carrier
{
    return Carrier::tryFrom($order->getCarrier())
        ?? throw CommandValidationException::forInvalidCarrier($order->getCarrier());
}
```

An exhaustive `match` on the enum makes PHPStan flag every place that needs a new arm when a case is added.

## Application Dto → OutputDto

```php
final class OrderDtoToOrderOutputDtoTransformer
{
    public function transform(OrderDto $orderDto): OrderOutputDto
    {
        return new OrderOutputDto(
            id:     $orderDto->getId() ?? throw new MissingOrderIdException(),
            status: $orderDto->getStatus(),
        );
    }
}
```

Output DTOs expose only what the client may see. Secrets and credentials never go into an output DTO.
