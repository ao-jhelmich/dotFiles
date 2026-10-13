# Domain port + Infrastructure adapters

Use when the Domain or Application needs something only an external system or vendor SDK can answer: validate an address with a carrier, provision a resource, check credentials.

## Layout

| Piece | Location | Example |
| --- | --- | --- |
| Port (vendor-neutral) | `Domain/Service/<Name>Interface.php` | `CarrierGatewayInterface` |
| Tagged adapter contract | `Infrastructure/Adapter/<Name>Interface.php` | `CarrierAdapterInterface` |
| Registry implementing the port | `Infrastructure/Adapter/<Name>Registry.php` | `CarrierGatewayRegistry` |
| One adapter per vendor | `Infrastructure/Adapter/<Vendor>/<Vendor><Name>.php` | `PostNl/PostNlCarrierGateway` |
| Port → registry alias | `config/services.yaml` | see below |

Vendor names appear only under `Infrastructure/Adapter/` and as enum values. Never in a Domain class name.

With a single vendor, skip the registry: alias the port straight to the one adapter.

## Port

```php
interface CarrierGatewayInterface
{
    /** @throws UndeliverableAddressException */
    public function assertDeliverable(Carrier $carrier, ShippingAddress $address): void;
}
```

## Adapter contract + registry

```php
#[AutoconfigureTag('ordering.carrier_gateway')]
interface CarrierAdapterInterface extends CarrierGatewayInterface
{
    public function supports(Carrier $carrier): bool;
}

final readonly class CarrierGatewayRegistry implements CarrierGatewayInterface
{
    /** @param iterable<CarrierAdapterInterface> $adapters */
    public function __construct(
        #[AutowireIterator('ordering.carrier_gateway')]
        private iterable $adapters,
    ) {
    }

    /** @throws UnsupportedCarrierException */
    public function assertDeliverable(Carrier $carrier, ShippingAddress $address): void
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($carrier)) {
                $adapter->assertDeliverable($carrier, $address);

                return;
            }
        }

        throw new UnsupportedCarrierException();
    }
}
```

## Adapter

```php
final class PostNlCarrierGateway implements CarrierAdapterInterface
{
    public function supports(Carrier $carrier): bool
    {
        return $carrier === Carrier::POSTNL;
    }

    public function assertDeliverable(Carrier $carrier, ShippingAddress $address): void
    {
        // call the vendor SDK / API; translate vendor errors into the Domain exception
        throw new UndeliverableAddressException();
    }
}
```

Translate vendor exceptions into Domain exceptions inside the adapter, so no vendor type leaks past Infrastructure.

## Wiring

```yaml
App\Ordering\Domain\Service\CarrierGatewayInterface:
    alias: App\Ordering\Infrastructure\Adapter\CarrierGatewayRegistry
```

Adding a vendor means a new enum case, a new adapter class (auto-tagged through the interface) and new `match` arms in the transformers. The registry does not change.
