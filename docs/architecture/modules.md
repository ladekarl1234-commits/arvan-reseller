# Module View

See [`ARCHITECTURE.md`](../../ARCHITECTURE.md) for the ownership table and dependency rules — this page adds the picture and the reasoning shorthand.

```mermaid
flowchart TD
    subgraph Presentation
        Front[Front: shortcodes/templates/assets]
        Admin[Admin: menu/actions/templates]
        Rest[Rest: routes]
        Onb[Onboarding: wizard]
    end
    subgraph Application
        Orders --> Pricing
        Payments --> Orders
        Payments --> Wallet
        Payments --> Provisioning
        Provisioning --> Services
        Usage --> Wallet
        Usage --> Policies
        Usage --> Services
        Pricing --> CustomersR[Customers.Rules]
        Notifications
    end
    subgraph Infrastructure
        Arvan[Arvan: client/providers/credentials/catalog]
        Jobs
        Install
        Support[Support: crypto/options/helpers]
        Audit
        Licensing
        Identity
    end
    Presentation --> Application
    Application --> Infrastructure
    Provisioning --> Arvan
    Usage --> Arvan
    Payments --> Jobs
```

Reading order for a new engineer: `Plugin.php` (wiring) → `Orders\StateMachine` (the invariants) → `Payments\PaymentService` (the critical pipeline) → `Provisioning\Provisioner` → `Wallet\Ledger` → one template. That path covers 80% of the system's ideas in ~600 lines.
