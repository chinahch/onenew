# Argo TLS offload regression repair
Approved scope: restore upstream plaintext origin behavior without a new switch; preserve subscription TLS and Cloudflare tunnel rules.
1. Reproduce TLS=1 VLESS/WS + effective certificate mode none being blocked by certificateReady.
2. Add a pinned source overlay: permit optional-TLS protocols only with effective none policy and no failed/pending certificate; retain strict certificate validation for configured issuance/material. Publish an atomic local plaintext-port snapshot for probes.
3. Normalize only matching loopback self-probes to the actual plaintext listener. Preserve remote exit probes and REALITY.
4. Test failure-to-plaintext and plaintext-to-certificate transitions, dual-kernel listener building, probe credential/transport preservation, and installer upgrades.
5. Bump immutable native release to node-v0.1.4, update panel installer URL, verify CI on exact reviewed commit, merge and verify release checksums.
