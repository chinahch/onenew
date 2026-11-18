# UDP mux packet headroom crash fix

Goal: repair the reproduced panic in trackedPacketConn -> serverMuxPacketConn.WritePacket without bypassing bandwidth limits/counters or swallowing panic.

Evidence: bandwidth overlay returns an anonymous PacketWriter from UnwrapPacketWriter, hiding underlying FrontHeadroom/RearHeadroom; sing NewReadWaitOptions allocates with start=0 while mux WritePacket requires16+ header bytes. Preserve writer requirements in a non-replaceable limiter-safe adapter. Verify real pooled copy, IPv4/IPv6 destinations, payload+accounting and ratewait remain unchanged; regression must panic before fix and pass after.

Ship immutable nodev0.1.14 through exact clean baseline patch/build path, full Go/dualarch/upgrade rollback checks. Merge only passingPR, verify real released assetSHA, then addverified14 to panel upgradecatalog. Existing13 files must never be overwritten. User authorized fix and release; no production configuration changes without explicit action.
