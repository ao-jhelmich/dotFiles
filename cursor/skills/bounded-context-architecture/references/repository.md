# Repository, mapping and DBAL types

## Interface (`Domain/Repository/`)

```php
interface OrderRepositoryInterface
{
    /** @return Generator<Order> */
    public function getAll(): Generator;

    public function findByReference(string $reference): ?Order;

    /** @throws OrderNotFoundException */
    public function getByReference(string $reference): Order;

    /** @throws OrderNotFoundException */
    public function getById(string $id): Order;

    public function save(Order $order): void;

    public function delete(Order $order): void;
}
```

`findX` → nullable. `getX` → strict, throws the aggregate's `*NotFoundException`. Build `getX` on top of `findX`.

## Implementation (`Infrastructure/Repository/`)

```php
/** @template-extends ServiceEntityRepository<Order> */
final class OrderRepository extends ServiceEntityRepository implements OrderRepositoryInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
        parent::__construct($registry, Order::class);
    }

    public function getAll(): Generator
    {
        /** @var iterable<Order> $orders */
        $orders = $this->createQueryBuilder('o')->getQuery()->toIterable();

        yield from $orders;
    }

    public function getById(string $id): Order
    {
        return $this->findOneBy(['id' => $id]) ?? throw new OrderNotFoundException();
    }

    public function getByReference(string $reference): Order
    {
        return $this->findByReference($reference) ?? throw new OrderNotFoundException();
    }

    public function save(Order $order): void
    {
        $events = $order->popRecordedEvents();

        $this->getEntityManager()->persist($order);

        foreach ($events as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}
```

`save()` pops recorded events before persisting and dispatches them afterwards. Whether `save()` also calls `flush()` or leaves it to the `TransactionalMessageInterface` middleware is a per-project decision; see `deptrac.md`.

## XML mapping (`Infrastructure/Resources/config/doctrine/model/`)

The file name is `Model.<Namespace path>.orm.xml` relative to the mapping prefix (`Model.Order.orm.xml`, `Model.Write.OrderLine.orm.xml`). Register the context in `config/packages/doctrine.yaml`:

```yaml
Ordering:
    dir: '%kernel.project_dir%/src/Ordering/Infrastructure/Resources/config/doctrine/model'
    prefix: 'App\Ordering\Domain'
    alias: Ordering
```

Root with owned children (cascade + orphan removal):

```xml
<entity name="App\Ordering\Domain\Model\Order" table="orders">
    <unique-constraints>
        <unique-constraint name="uniq_order_reference" columns="reference"/>
    </unique-constraints>
    <field name="reference" length="64"/>
    <field name="carrier" length="32" enum-type="App\Ordering\Domain\Enum\Carrier"/>
    <field name="status" length="24" enum-type="App\Ordering\Domain\Enum\Status"/>
    <field name="shippingAddress" type="shipping_address"/>
    <one-to-many field="lines" target-entity="App\Ordering\Domain\Model\Write\OrderLine"
                 mapped-by="order" orphan-removal="true">
        <cascade><cascade-all/></cascade>
    </one-to-many>
</entity>
```

Put id and audit columns in shared `AggregateRoot.orm.xml` / `Entity.orm.xml` mapped-superclass files under `src/Shared/Infrastructure/Resources/config/doctrine/`.

## Value object as JSON column (`Infrastructure/Framework/Doctrine/Type/`)

```php
final class ShippingAddressType extends JsonType
{
    public const string NAME = 'shipping_address';

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?ShippingAddress
    {
        $data = parent::convertToPHPValue($value, $platform);

        if (is_array($data) === false) {
            return null;
        }

        $data = ArrayData::create($data);

        // collect remaining string keys into $extra ...

        return new ShippingAddress(
            $data->getNonEmptyString('street'),
            $data->getNonEmptyString('postalCode'),
            $data->getNonEmptyString('country'),
            $extra,
        );
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof ShippingAddress) {
            return parent::convertToDatabaseValue($value->toArray(), $platform);
        }

        throw new InvalidShippingAddressValueException();
    }
}
```

Register the type in `config/packages/doctrine.yaml` under `dbal.types` with the same key as `NAME`.
