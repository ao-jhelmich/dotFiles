# Exceptions per layer

| Layer | Base | Location | Meaning |
| --- | --- | --- | --- |
| Domain | `abstract <Context>Exception extends RuntimeException` | `Domain/Exception/` | business rule violated |
| Application | `abstract ApplicationException extends Exception` | `Application/Exception/` | use case cannot run (e.g. unknown enum value) |
| Infrastructure | `RuntimeException` | `Infrastructure/Exception/` | technical failure (corrupt stored value, missing id) |

`PayNL\Exceptions` stays out of every context's Deptrac ruleset. The client-facing PAY code, message and HTTP status come from the mapped bundle class, never from the context exception.

## Domain exception shape

Marker class: `final`, no constructor, no message, empty body. Throw it without arguments.

```php
final class OrderAlreadyShippedException extends OrderingException
{
}

throw new OrderAlreadyShippedException();
```

Named constructors with `sprintf` messages (`::forOrderId($id)`) are legacy. Strip the message when you touch one.

## Mapping (`config/packages/api.yaml`)

Every Domain, Application and Infrastructure exception that can reach a client is listed under `exception_mapping`, grouped per context and layer:

```yaml
# Ordering Domain Exceptions
App\Ordering\Domain\Exception\OrderNotFoundException: PayNL\Exceptions\Exception\Http\NotFoundException
App\Ordering\Domain\Exception\OrderAlreadyExistsException: PayNL\Exceptions\Exception\Http\ConflictException
App\Ordering\Domain\Exception\UndeliverableAddressException: PayNL\Exceptions\Exception\Http\BadRequestException

# Ordering Application Exceptions
App\Ordering\Application\Exception\CommandValidationException: PayNL\Exceptions\Exception\Http\BadRequestException

# Ordering Infrastructure Exceptions
App\Ordering\Infrastructure\Exception\UnsupportedCarrierException: PayNL\Exceptions\Exception\Http\InternalServerErrorException
```

| Situation | Map to |
| --- | --- |
| `*NotFoundException` of an aggregate | `Http\NotFoundException` |
| uniqueness violated | `Http\ConflictException` |
| invalid input that passed DTO validation | `Http\BadRequestException` |
| technical / should-not-happen | `Http\InternalServerErrorException` |

An unmapped exception must never reach a client. Map it in the same change that makes it reachable from an endpoint.

Prefer an `Assert\*` constraint on the input DTO over a domain exception when bad input should never reach the Domain.
