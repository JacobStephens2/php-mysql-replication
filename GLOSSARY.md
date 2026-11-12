# MySQL replication

## Language

**Binlog event decoding**:
Interpreting an unframed binlog response, applying required replication-position and table-map updates plus configured filters, and producing at most one DTO for subscriber dispatch.

**Replication progress (`BinLogCurrent`)**:
Replication progress (`BinLogCurrent`) is shared live state rather than a snapshot. An event's position records its own header position, while its shared progress reference reflects updates from later events as well.
