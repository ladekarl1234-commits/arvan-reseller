# Deployment View

## Now (hackathon / Stage A)

```mermaid
flowchart LR
    U((Users)) --> WEB[Any PHP host<br/>Apache/Nginx + PHP-FPM 7.4+<br/>WordPress + plugin]
    WEB --> DB[(MySQL/MariaDB)]
    WEB <-->|HTTPS| AC[(ArvanCloud)]
    subgraph Dev[Development sandbox in this repo]
        CLI[wp-cli + PHP built-in server] --> SQLITE[(SQLite via official<br/>integration plugin)]
    end
```

One artifact (plugin ZIP), one host, zero extra services. The dev sandbox (DEVELOPMENT.md) proves the plugin also runs on the SQLite integration — useful for CI and judges without MySQL.

## Production hardening (Stage B — configuration only)

- `DISABLE_WP_CRON` + real cron `*/1` hitting `wp cron event run --due-now`
- Redis object-cache drop-in (accelerates catalog cache + rate limiting cluster-wide)
- HTTPS everywhere; standard WP hardening applies

## Future scalable shape (Stage C — extraction, same tables)

```mermaid
flowchart LR
    U((Users)) --> LB[LB] --> W1[WP node] & W2[WP node]
    W1 & W2 --> DB[(MySQL primary)]
    DB --> RR[(Read replica<br/>admin reporting)]
    PW[Provisioning worker] --> DB
    UW[Usage-ingestion worker] --> DB
    PW & UW <--> AC[(ArvanCloud)]
    W1 & W2 --> REDIS[(Redis)]
```

Workers consume the same `arvrs_jobs` / `arvrs_usage_records` tables the plugin writes today — extraction adds processes, not schema (ADR-0011; triggers in SCALABILITY).
