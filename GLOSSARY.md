# MySQL replication

## Language

**Binlog event decoding**:
Interpreting an unframed binlog response, applying required replication-position and table-map updates plus configured filters, and producing at most one DTO for subscriber dispatch.

**Replication progress (`BinLogCurrent`)**:
Replication progress (`BinLogCurrent`) is shared live state rather than a snapshot. An event's position records its own header position, while its shared progress reference reflects updates from later events as well.

**Row event decoding**:
Tracking per-table schema mappings across table-map and rotation events, enforcing database/table filters and schema metadata lookup, and decoding row-change binary column payloads into `TableMapDTO` or row-change DTOs without altering `BinLogCurrent`.
