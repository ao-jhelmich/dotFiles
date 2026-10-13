# Deptrac: new context setup and decisions to settle

Root `deptrac.yaml` defines vendor/org layers (`PayNL_Domain`, `PayNL_Bundles_CommandBus`, `Doctrine_Common`, `Vendor`, ...) and imports one file per context from `config/deptrac/`. Exclude test files (`#.*test.*#`).

## New context

1. Create `config/deptrac/<context>.yml` by copying an existing context file and renaming the layer prefix and namespace regex.
2. Add it to `imports:` in `deptrac.yaml`.
3. Register the Doctrine mapping in `config/packages/doctrine.yaml` (see `repository.md`).
4. Run `vendor/bin/deptrac analyse`.

```yaml
deptrac:
    layers:
        - name: Ordering_Domain
          collectors:
              - type: classNameRegex
                value: '#.*App\\Ordering\\Domain\\.*#'
        - name: Ordering_Application
          collectors:
              - type: classNameRegex
                value: '#.*App\\Ordering\\Application\\.*#'
        - name: Ordering_Infrastructure
          collectors:
              - type: classNameRegex
                value: '#.*App\\Ordering\\Infrastructure\\.*#'
    ruleset:
        Ordering_Domain:
            - Doctrine_Common
            - PayNL_Domain
            - PayNL_Util_Array
            - SharedKernel_Domain
            - Symfony_Component_UUID
        Ordering_Application:
            - Ordering_Domain
            - PayNL_Bundles_CommandBus
            - SharedKernel_Application
            # + everything Domain may use
        Ordering_Infrastructure:
            - Ordering_Application
            - Ordering_Domain
            - SharedKernel_Infrastructure
            - Symfony_Component_Validator_Constraints
            - Vendor
            # + everything Application may use
```

## What stays out of a context ruleset

| Layer | Consequence |
| --- | --- |
| `PayNL_Exceptions` | map exceptions in `api.yaml`; never throw bundle classes |
| `PayNL_UserActions`, `PayNL_Bundles_DoctrineUserActions` | audit/blame via `SharedKernel` only |
| `PayNL_Bundles_SecurityBundle`, `Symfony_Component_Security_*` | authentication lives in `src/Shared/Infrastructure/Framework/Security/` |
| `Symfony_Component_Serializer_*` | no serializer attributes on DTOs; shape via input/output DTO classes |
| other contexts' layers | see `cross-context.md` |

Event dispatchers and validator constraints are allowed in Infrastructure only.

## Decisions to settle per project

A Deptrac config does not answer these by itself. Check how the project handles each; if it doesn't decide, raise it instead of picking silently.

1. **Every ruleset entry has a collector.** A layer named in a ruleset without a matching `layers:` definition matches nothing; Deptrac reports those dependencies as uncovered instead of enforcing them. Run `vendor/bin/deptrac analyse --report-uncovered` and either define the layer or remove it.
2. **One Infrastructure layer or sublayers.** With one `<Context>_Infrastructure` layer, Deptrac cannot enforce "only repositories, adapters, Doctrine types and listeners may import Domain; processors and providers may not". Split into sublayers (`Repository`, `Adapter`, `Framework`, `Port`, `Event`) with separate rules, or enforce by review.
3. **Layer of shared ports.** `Shared/Infrastructure/Port` versus `Shared/Application/Port` decides whether handlers can use cross-context ports directly.
4. **One event dispatcher interface.** Choose between `Psr\EventDispatcher`, `Symfony\Contracts\EventDispatcher` and `Symfony\Component\EventDispatcher` and use it in every repository. Every repository whose aggregate records events must dispatch them.
5. **Flush ownership.** Either `save()` flushes, or the `TransactionalMessageInterface` middleware does. Mixing both makes transaction boundaries unpredictable.
6. **Read model.** Decide when queries stop reading through the write aggregate and get a projection (`Domain/Model/Read/`).
