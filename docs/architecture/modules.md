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
        Orders --> Wallet
        Payments --> Orders
        Payments --> Wallet
        Payments --> Provisioning
        Payments --> Usage
        Provisioning --> Services
        Usage --> Wallet
        Usage --> Policies
        Usage --> Services
        Usage --> Pricing
        Usage --> CustomersR[Customers.Rules]
        Billing --> Orders
        Billing --> Services
        Billing --> Wallet
        Billing --> Usage
        Reports
        Pricing --> CustomersR
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
    Billing --> Jobs
    Plugin[Plugin: composition root] -.demo_mode / provider selection, called back into from Orders/Wallet/Payments/Usage/Arvan.-> Application
```

`Billing` (`Billing\Renewals`, recurring/term charges) and `Reports` (`Reports\Reports`, period revenue/cost/margin/MRR/churn) were added after this diagram was first drawn — see `ARCHITECTURE.md` for the current ownership table, which is the one kept in sync with the source on every doc pass; this picture is illustrative and can lag it.

Reading order for a new engineer: `Plugin.php` (wiring) → `Orders\StateMachine` (the invariants) → `Payments\PaymentService` (the critical pipeline) → `Provisioning\Provisioner` → `Wallet\Ledger` → one template. That path covers 80% of the system's ideas in ~600 lines.
