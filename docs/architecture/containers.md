# Container / Runtime View

Everything runs inside one WordPress deployment; "containers" here are runtime responsibilities, not Docker images.

```mermaid
flowchart TB
    B[Browser<br/>RTL server-rendered pages + 9KB vanilla JS]
    subgraph WP[WordPress + plugin]
        TPL[Shortcode templates<br/>storefront · dashboard · gateway page]
        ADM[wp-admin pages + wizard<br/>admin-post actions]
        API[REST arvan-reseller/v1<br/>permission_callback + schema args]
        CORE[Application modules<br/>src/*]
        RUN[Job runner<br/>arvrs_minutely cron hook]
    end
    DB[(MySQL/MariaDB<br/>11 arvrs_ tables + wp_*)]
    CRON[WP-Cron trigger<br/>traffic-based; real cron in production]
    AC[(ArvanCloud APIs)]
    PG[Payment gateway]

    B --> TPL & API
    B --> ADM
    TPL & ADM & API --> CORE
    CRON --> RUN --> CORE
    CORE --> DB
    CORE <--> AC
    B <--> PG
    PG -->|callback POST| API
```

Runtime facts worth knowing:
- Assets enqueue only on plugin pages (checked via `has_shortcode` / hook suffix).
- No external HTTP occurs during ordinary page render (catalog cached 6 h).
- The job runner executes inside a WP-Cron request at Stage A; Stage B/C move the trigger, never the code (ADR-0004, SCALABILITY).
