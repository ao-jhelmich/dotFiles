---
name: bounded-context-architecture
description: Places classes inside a bounded context of a PayNL Symfony project following hexagonal DDD + CQRS conventions (PayNL CommandBus, PayNL\Domain, API Platform, Doctrine XML mapping) and Deptrac layer rules. Use when adding or moving a command, query, handler, aggregate, entity, value object, repository, DTO, transformer, API Platform resource, state processor/provider, adapter, domain event, exception, or when setting up a new bounded context.
---

# Bounded context architecture

Every bounded context has the same three-layer shape. The references use one running example, an `Ordering` context with an `Order` aggregate. Replace the names; keep the shape.

## Layer map — what goes where

```
src/<Context>/
├── Domain/                         # pure model, no framework
│   ├── Model/<Aggregate>.php       # aggregate root (extends PayNL\Domain\AggregateRoot)
│   ├── Model/Write/<Child>.php     # child entities owned by the root (extends PayNL\Domain\Entity)
│   ├── ValueObject/                # final readonly, Arrayable
│   ├── Enum/                       # backed string enums
│   ├── Event/                      # extends PayNL\Domain\DomainEvent
│   ├── Exception/                  # marker exceptions, mapped in config/packages/api.yaml
│   ├── Repository/<Aggregate>RepositoryInterface.php
│   └── Service/<Port>Interface.php # outbound ports the Domain needs
├── Application/
│   ├── Command/<UseCase>/<UseCase>Command.php + <UseCase>CommandHandler.php
│   ├── Query/<UseCase>/<UseCase>Query.php     + <UseCase>QueryHandler.php
│   ├── Dto/                        # transport between Application and the outside
│   ├── Transformer/                # Dto <-> Domain mapping
│   ├── Service/                    # application services/helpers
│   └── Exception/                  # use-case failures
└── Infrastructure/
    ├── Adapter/<Vendor>/           # implementations of Domain\Service ports
    ├── Framework/ApiPlatform/ApiResource/       # #[ApiResource] declarations only
    ├── Framework/ApiPlatform/Dto/Input|Output/  # HTTP shape + Symfony Validator constraints
    ├── Framework/ApiPlatform/Factory/           # InputDto -> Application Dto
    ├── Framework/Doctrine/Type/    # custom DBAL types for value objects
    ├── Port/Api/StateProcessor/    # writes: dispatch command, then query result
    ├── Port/Api/StateProvider/     # reads: dispatch query
    ├── Event/Listener/             # #[AsEventListener] reacting to (shared) domain events
    ├── Repository/<Aggregate>Repository.php
    ├── Transformer/                # Application Dto -> OutputDto
    ├── Exception/                  # technical failures
    └── Resources/config/doctrine/model/Model.<Name>.orm.xml
```

## DDD agreements

- **Aggregate root guards invariants.** Rules live in root methods (`Order::changeCarrier()`), never in a handler. Child entities are created only through root methods (`Order::addLine()`).
- **Child entities** live in `Domain/Model/Write/`, receive the root in their constructor, and implement `provideAggregateRoot()`. They reference other contexts by **string id only**, never by object.
- **Value objects** are `final readonly`, immutable, implement `PayNL\Util\Arr\Interfaces\Arrayable`, and are persisted through a custom DBAL JSON type.
- **Ids** are `UuidV7::generate()` in the constructor; `?string $id` allows a caller-supplied id.
- **Domain events** are recorded in the aggregate (`recordEvent`) and dispatched by the repository on `save()`.
- **Mapping is XML** in Infrastructure. Domain classes carry no ORM attributes.
- **CQRS:** commands change state and return `void` (or the new id on create). Queries never change state and return Application DTOs (or a `Generator` of DTOs), never entities.
- **Repositories:** `findX(): ?Entity`, `getX(): Entity` that throws `<Aggregate>NotFoundException`, `getAll(): Generator`.
- **Validation:** shape/format checks on the Infrastructure input DTO (`Assert\*`); business rules in the aggregate; external validity through a Domain port.
- **API Platform resources are thin:** operations only, wired to processors/providers. Operation names follow `<aggregate>_<action>_<http_method>` (`order_browse_get`, `order_create_post`).

## Dependency rules (Deptrac)

One Deptrac file per context (`config/deptrac/<context>.yml`):

| Layer | May depend on |
| --- | --- |
| Domain | `PayNL_Domain`, `Doctrine_Common` (Collections), `PayNL_Util_Array`, `Symfony_Component_UUID`, `SharedKernel_Domain` |
| Application | Domain + `PayNL_Bundles_CommandBus`, `SharedKernel_Application` (+ Domain's list) |
| Infrastructure | Application, Domain, `SharedKernel_*`, `Symfony_Component_Validator_Constraints`, `Vendor` |

Consequences:
- **No context depends on another context.** Cross-context communication goes through `src/Shared/` only.
- `PayNL\Exceptions` stays out of every context ruleset → never throw or extend the bundle exceptions inside a context; map in `api.yaml`.
- Event dispatchers are vendor code → only Infrastructure dispatches events.
- No Symfony Validator/Serializer in Application or Domain.

## Workflow for a new use case

1. Domain behaviour first: add/extend a method on the aggregate root, plus a marker exception if a rule can fail.
2. Command or Query + Handler in its own `Application/<Command|Query>/<UseCase>/` folder.
3. Repository method if needed (interface in Domain, implementation in Infrastructure).
4. HTTP: input/output DTO, factory, state processor/provider, operation on the ApiResource.
5. Map every new domain/application exception in `config/packages/api.yaml`.
6. Run `vendor/bin/deptrac analyse`, `vendor/bin/phpstan analyse`, `vendor/bin/phpcs`, `vendor/bin/phpunit`.

## References

Read only the one you need:

| Topic | File |
| --- | --- |
| Aggregate root, child entity, value object, enum, event | [references/domain-model.md](references/domain-model.md) |
| Command + handler (transaction, locking) | [references/command.md](references/command.md) |
| Query + handler | [references/query.md](references/query.md) |
| Application DTOs and transformers | [references/dto-transformer.md](references/dto-transformer.md) |
| Repository, XML mapping, DBAL value-object type | [references/repository.md](references/repository.md) |
| API Platform resource, input/output DTO, processor, provider | [references/api-platform.md](references/api-platform.md) |
| Domain port + Infrastructure adapter registry | [references/adapter.md](references/adapter.md) |
| Exceptions per layer and `api.yaml` mapping | [references/exceptions.md](references/exceptions.md) |
| Cross-context communication via `src/Shared` | [references/cross-context.md](references/cross-context.md) |
| Deptrac setup for a new context + decisions to settle | [references/deptrac.md](references/deptrac.md) |
