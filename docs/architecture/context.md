# System Context

Who talks to what, and which money/data crosses each line.

```mermaid
flowchart LR
    C((Customer<br/>Persian, non-expert)) -->|browse, buy, top-up, manage| W[Reseller WordPress site<br/>+ Arvan Reseller plugin]
    RA((Reseller admin)) -->|configure, price, operate| W
    W <-->|start / verify payment<br/>IRT, customer↔reseller| PG[Payment gateway<br/>sandbox now, PSPs later]
    W <-->|provision, status, delete<br/>reseller credential| AC[(ArvanCloud APIs<br/>napi.arvancloud.ir + storage.arvanapis.ir)]
    V((Hackathon judge)) -->|Demo Mode: full flow,<br/>no external credentials| W
```

Boundary notes:
- Money flows **customer ↔ reseller site** only; reseller ↔ Arvan settlement is out of scope (no API exists — spec §14).
- The customer never needs the ArvanCloud panel for the purchase lifecycle; the only upstream-panel touchpoint is Object-Storage S3 key issuance (documented API gap).
- In Demo Mode both external arrows are simulated at the boundary; every internal arrow is real (ADR-0010).
