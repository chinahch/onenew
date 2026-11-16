# Local Chinese IP region lookup

Only the administrator subscription-log read API performs the lookup. Existing records are enriched on read; subscription pulls never wait for lookup or call a third-party API. Canonical IP cache keys are hashed and versioned, cached for one day. Failures show 未知 without breaking the log page. Private/reserved addresses are marked 内网 IP; invalid addresses 无效 IP.

IPv4/IPv6 reader and databases: lionsoul2014/ip2region revision 200f4cc7a6452843ef515a889c72d295281e8da3, Apache-2.0. Unmodified PHP reader and full license in vendor/. Docker downloads both immutable databases with SHA256 verification. No live runtime download is needed. Update the pinned revision, checksums and IpRegion::VERSION together when refreshing data.

Country display names are generated using Node Intl.DisplayNames zh-CN. Chinese province/city data is retained; untranslated foreign subdivision names are omitted rather than showing English. Attribution: ip2region authors, https://github.com/lionsoul2014/ip2region . Data is approximate and may identify proxy/CDN exit locations.
