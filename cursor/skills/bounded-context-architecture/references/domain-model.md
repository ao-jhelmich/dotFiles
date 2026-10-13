# Domain model

Location: `src/<Context>/Domain/`.

## Aggregate root

- Extends `PayNL\Domain\AggregateRoot`; not `final` (Doctrine proxies).
- Constructor generates the id, sets initial state, delegates shared invariants to a behaviour method, records the creation event.
- Collections are `Doctrine\Common\Collections\Collection` (allowed in Domain by Deptrac).
- Expose intent-revealing methods (`changeCarrier`, `cancel`), not setters.

```php
class Order extends AggregateRoot
{
    private string $customerId;
    private Carrier $carrier;
    private Status $status;
    private ShippingAddress $shippingAddress;

    /** @var Collection<int, OrderLine> */
    private Collection $lines;

    /** @throws UndeliverableAddressException */
    public function __construct(
        ?string $id,
        string $customerId,
        Carrier $carrier,
        ShippingAddress $shippingAddress,
    ) {
        parent::__construct($id ?? UuidV7::generate());

        $this->customerId = $customerId;
        $this->status     = Status::PLACED;
        $this->lines      = new ArrayCollection();
        $this->changeCarrier($carrier, $shippingAddress);

        $this->recordEvent(new Placed($this));
    }

    /**
     * An address must belong to the country the carrier serves.
     *
     * @throws UndeliverableAddressException
     */
    public function changeCarrier(Carrier $carrier, ShippingAddress $shippingAddress): void
    {
        if ($carrier->serves($shippingAddress->getCountry()) === false) {
            throw new UndeliverableAddressException();
        }

        $this->carrier         = $carrier;
        $this->shippingAddress = $shippingAddress;
    }

    public function addLine(string $productId, int $quantity): void
    {
        if ($this->hasLine($productId)) {
            return;
        }

        $this->lines->add(new OrderLine(order: $this, productId: $productId, quantity: $quantity));
    }

    public function cancel(): void
    {
        if ($this->status === Status::SHIPPED) {
            throw new OrderAlreadyShippedException();
        }

        $this->status = Status::CANCELLED;
    }
}
```

## Child entity (`Domain/Model/Write/`)

- Extends `PayNL\Domain\Entity`, gets the root injected, implements `provideAggregateRoot()`.
- Only the root creates it. Other contexts are referenced by string id (`productId`).
- Invariants of the child itself are guarded in its constructor.

```php
class OrderLine extends Entity
{
    public function __construct(
        private readonly Order $order,
        private readonly string $productId,
        private readonly int $quantity,
    ) {
        if ($this->quantity < 1) {
            throw new InvalidQuantityException();
        }

        parent::__construct(aggregateRoot: $order, id: UuidV7::generate());
    }

    protected function provideAggregateRoot(): AggregateRoot
    {
        return $this->order;
    }
}
```

## Value object

- `final readonly`, implements `Arrayable`, no setters, no id.
- When fields vary per type/vendor, keep one class with an options array keyed by name and let an enum discriminate, instead of one class per vendor.

```php
final readonly class ShippingAddress implements Arrayable
{
    /** @param array<string, string|null> $extra */
    public function __construct(
        private string $street,
        private string $postalCode,
        private string $country,
        private array $extra = [],
    ) {
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function get(string $key): ?string
    {
        return $this->extra[$key] ?? null;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'street'     => $this->street,
            'postalCode' => $this->postalCode,
            'country'    => $this->country,
            ...$this->extra,
        ];
    }
}
```

## Enum and event

```php
enum Status: string
{
    case PLACED    = 'placed';
    case SHIPPED   = 'shipped';
    case CANCELLED = 'cancelled';
}

final class Placed extends DomainEvent
{
}
```

Events that another context must react to go in `src/Shared/Domain/Event/` (see `cross-context.md`).
