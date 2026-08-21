//! Fail-closed outbound egress policy shared by tenant execution surfaces.
//!
//! The denylist is generic infrastructure policy and carries no tenant data:
//! upstreams must never reach loopback, link-local, cloud-metadata, CGNAT,
//! RFC1918/ULA ranges, or Spacefast-internal hosts. It is a denylist and not a
//! per-tenant allowlist on purpose — making people declare every API they call
//! is exactly the hand-holding the product rules out.
//!
//! Hostname decisions here are purely lexical: no name is resolved. A caller
//! that treats [`host_allowed`] as a complete SSRF gate is exploitable through
//! DNS rebinding. Resolving every address, rejecting the host when any answer
//! fails [`address_allowed`], and connecting only to those pinned addresses is
//! the caller's job, and it is the pin that closes the window between the
//! decision and the connect.

use std::collections::BTreeSet;
use std::net::{IpAddr, Ipv4Addr, Ipv6Addr};
use std::str::FromStr;

/// Redirects are where SSRF actually lands: a permitted public host answers 302
/// to a metadata address. Following is never automatic — the caller drives the
/// hop loop and re-applies the whole policy on every hop.
pub const EGRESS_MAX_REDIRECT_HOPS: u32 = 3;

pub const DENIED_IPV4_NETWORKS: &[(Ipv4Addr, u8)] = &[
    // this network
    (Ipv4Addr::new(0, 0, 0, 0), 8),
    // RFC1918
    (Ipv4Addr::new(10, 0, 0, 0), 8),
    // CGNAT
    (Ipv4Addr::new(100, 64, 0, 0), 10),
    // loopback
    (Ipv4Addr::new(127, 0, 0, 0), 8),
    // link-local incl. cloud metadata 169.254.169.254
    (Ipv4Addr::new(169, 254, 0, 0), 16),
    // RFC1918
    (Ipv4Addr::new(172, 16, 0, 0), 12),
    // IETF protocol assignments
    (Ipv4Addr::new(192, 0, 0, 0), 24),
    // TEST-NET-1
    (Ipv4Addr::new(192, 0, 2, 0), 24),
    // RFC1918
    (Ipv4Addr::new(192, 168, 0, 0), 16),
    // benchmarking
    (Ipv4Addr::new(198, 18, 0, 0), 15),
    // TEST-NET-2
    (Ipv4Addr::new(198, 51, 100, 0), 24),
    // TEST-NET-3
    (Ipv4Addr::new(203, 0, 113, 0), 24),
    // multicast + reserved + broadcast
    (Ipv4Addr::new(224, 0, 0, 0), 3),
];

pub const DENIED_IPV6_NETWORKS: &[(Ipv6Addr, u8)] = &[
    // unspecified + loopback + deprecated IPv4-compatible ::a.b.c.d
    (Ipv6Addr::new(0, 0, 0, 0, 0, 0, 0, 0), 96),
    // IPv4-mapped, denied raw (mapped forms are unwrapped first)
    (Ipv6Addr::new(0, 0, 0, 0, 0, 0xffff, 0, 0), 96),
    // NAT64 well-known prefix
    (Ipv6Addr::new(0x64, 0xff9b, 0, 0, 0, 0, 0, 0), 96),
    // discard-only
    (Ipv6Addr::new(0x100, 0, 0, 0, 0, 0, 0, 0), 64),
    // documentation
    (Ipv6Addr::new(0x2001, 0xdb8, 0, 0, 0, 0, 0, 0), 32),
    // ULA incl. cloud metadata fd00:ec2::254
    (Ipv6Addr::new(0xfc00, 0, 0, 0, 0, 0, 0, 0), 7),
    // link-local
    (Ipv6Addr::new(0xfe80, 0, 0, 0, 0, 0, 0, 0), 10),
    // multicast
    (Ipv6Addr::new(0xff00, 0, 0, 0, 0, 0, 0, 0), 8),
];

pub const SERVING_INTERNAL_HOSTS: &[&str] = &["view.fast", "atomicsites.net"];

/// Egress surfaces share address and hostname policy but have deliberately
/// different transport requirements.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum EgressProfile {
    /// New tenant execution through the broker only permits encrypted upstreams.
    TenantFetch,
    /// Existing serving proxy routes retain HTTP and HTTPS upstream support.
    ProxyRoute,
}

impl EgressProfile {
    #[must_use]
    pub const fn allowed_schemes(self) -> &'static [&'static str] {
        match self {
            Self::TenantFetch => &["https"],
            Self::ProxyRoute => &["http", "https"],
        }
    }

    fn permits_scheme(self, scheme: &str) -> bool {
        self.allowed_schemes().contains(&scheme)
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum EgressDenial {
    Scheme,
    Credentials,
    Host,
    InternalHost,
    Address,
    RedirectLimit,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct EgressTarget {
    pub host: String,
    pub port: u16,
}

/// Spacefast-internal hosts, supplied by the caller rather than baked in: the
/// serving provider suffixes are fixed, but the management hostname and the API
/// host are resolved from configuration at runtime. An entry covers its own
/// subdomains.
#[derive(Debug, Default, Clone)]
pub struct InternalHosts {
    hosts: BTreeSet<String>,
}

impl InternalHosts {
    #[must_use]
    pub fn from_hosts<I, S>(hosts: I) -> Self
    where
        I: IntoIterator<Item = S>,
        S: AsRef<str>,
    {
        Self {
            hosts: hosts
                .into_iter()
                .map(|host| normalize_host(host.as_ref()))
                .filter(|host| !host.is_empty())
                .collect(),
        }
    }

    #[must_use]
    pub fn matches(&self, host: &str) -> bool {
        let host = normalize_host(host);
        self.hosts
            .iter()
            .any(|internal| host == *internal || host.ends_with(&format!(".{internal}")))
    }
}

/// Infrastructure ranges stay blocked even when callers supply a literal IP.
#[must_use]
pub fn address_allowed(address: IpAddr) -> bool {
    match address {
        IpAddr::V4(address) => ipv4_allowed(address),
        IpAddr::V6(address) => address
            .to_ipv4_mapped()
            .map_or_else(|| ipv6_allowed(address), ipv4_allowed),
    }
}

/// Lexical only — a hostname that is not an IP literal is allowed here and must
/// still be resolved and pinned by the caller.
pub fn host_allowed(host: &str, internal: &InternalHosts) -> Result<(), EgressDenial> {
    let host = normalize_host(host);
    if host.is_empty() || host == "localhost" || host.ends_with(".localhost") {
        return Err(EgressDenial::Host);
    }
    if internal.matches(&host) {
        return Err(EgressDenial::InternalHost);
    }
    if let Ok(address) = IpAddr::from_str(&host) {
        return address_allowed(address)
            .then_some(())
            .ok_or(EgressDenial::Address);
    }
    Ok(())
}

/// URL credentials are refused so a tenant cannot smuggle authority into an
/// upstream it does not own.
pub fn target_allowed(
    profile: EgressProfile,
    url: &str,
    internal: &InternalHosts,
) -> Result<EgressTarget, EgressDenial> {
    let url = url::Url::parse(url).map_err(|_| EgressDenial::Host)?;
    if !profile.permits_scheme(url.scheme()) {
        return Err(EgressDenial::Scheme);
    }
    if !url.username().is_empty() || url.password().is_some() {
        return Err(EgressDenial::Credentials);
    }
    let host = url.host_str().ok_or(EgressDenial::Host)?;
    host_allowed(host, internal)?;
    Ok(EgressTarget {
        host: normalize_host(host),
        port: url.port_or_known_default().unwrap_or(443),
    })
}

fn normalize_host(host: &str) -> String {
    host.trim_matches(|character| {
        matches!(
            character,
            '[' | ']' | ' ' | '\t' | '\n' | '\r' | '\0' | '\x0B' | '.'
        )
    })
    .to_ascii_lowercase()
}

fn ipv4_allowed(address: Ipv4Addr) -> bool {
    let address = address.octets();
    !DENIED_IPV4_NETWORKS
        .iter()
        .any(|(network, prefix)| cidr_contains(&address, &network.octets(), *prefix))
}

fn ipv6_allowed(address: Ipv6Addr) -> bool {
    let address = address.octets();
    !DENIED_IPV6_NETWORKS
        .iter()
        .any(|(network, prefix)| cidr_contains(&address, &network.octets(), *prefix))
}

fn cidr_contains<const N: usize>(address: &[u8; N], network: &[u8; N], prefix: u8) -> bool {
    let whole_bytes = usize::from(prefix / 8);
    let remainder = prefix % 8;
    address[..whole_bytes] == network[..whole_bytes]
        && (remainder == 0
            || address[whole_bytes] & (u8::MAX << (8 - remainder))
                == network[whole_bytes] & (u8::MAX << (8 - remainder)))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn ip(value: &str) -> IpAddr {
        value.parse().unwrap()
    }

    #[test]
    fn ipv4_denylist_boundaries() {
        for (first, last, below, above) in [
            ("0.0.0.0", "0.255.255.255", None, Some("1.0.0.0")),
            (
                "10.0.0.0",
                "10.255.255.255",
                Some("9.255.255.255"),
                Some("11.0.0.0"),
            ),
            (
                "100.64.0.0",
                "100.127.255.255",
                Some("100.63.255.255"),
                Some("100.128.0.0"),
            ),
            (
                "127.0.0.0",
                "127.255.255.255",
                Some("126.255.255.255"),
                Some("128.0.0.0"),
            ),
            (
                "169.254.0.0",
                "169.254.255.255",
                Some("169.253.255.255"),
                Some("169.255.0.0"),
            ),
            (
                "172.16.0.0",
                "172.31.255.255",
                Some("172.15.255.255"),
                Some("172.32.0.0"),
            ),
            (
                "192.0.0.0",
                "192.0.0.255",
                Some("191.255.255.255"),
                Some("192.0.1.0"),
            ),
            (
                "192.0.2.0",
                "192.0.2.255",
                Some("192.0.1.255"),
                Some("192.0.3.0"),
            ),
            (
                "192.168.0.0",
                "192.168.255.255",
                Some("192.167.255.255"),
                Some("192.169.0.0"),
            ),
            (
                "198.18.0.0",
                "198.19.255.255",
                Some("198.17.255.255"),
                Some("198.20.0.0"),
            ),
            (
                "198.51.100.0",
                "198.51.100.255",
                Some("198.51.99.255"),
                Some("198.51.101.0"),
            ),
            (
                "203.0.113.0",
                "203.0.113.255",
                Some("203.0.112.255"),
                Some("203.0.114.0"),
            ),
            (
                "224.0.0.0",
                "255.255.255.255",
                Some("223.255.255.255"),
                None,
            ),
        ] {
            assert!(!address_allowed(ip(first)), "{first}");
            assert!(!address_allowed(ip(last)), "{last}");
            if let Some(below) = below {
                assert!(address_allowed(ip(below)), "{below}");
            }
            if let Some(above) = above {
                assert!(address_allowed(ip(above)), "{above}");
            }
        }
    }

    #[test]
    fn ipv6_denylist_boundaries() {
        for (first, last, below, above) in [
            ("::", "::ffff:ffff", None, Some("::1:0:0")),
            (
                "::ffff:0:0",
                "::ffff:ffff:ffff",
                Some("::fffe:ffff:ffff"),
                Some("::1:0:0:0"),
            ),
            (
                "64:ff9b::",
                "64:ff9b::ffff:ffff",
                Some("64:ff9a:ffff:ffff:ffff:ffff:ffff:ffff"),
                Some("64:ff9b:0:1::"),
            ),
            (
                "100::",
                "100::ffff:ffff:ffff:ffff",
                Some("ff:ffff:ffff:ffff:ffff:ffff:ffff:ffff"),
                Some("100:0:0:1::"),
            ),
            (
                "2001:db8::",
                "2001:db8:ffff:ffff:ffff:ffff:ffff:ffff",
                Some("2001:db7:ffff:ffff:ffff:ffff:ffff:ffff"),
                Some("2001:db9::"),
            ),
            (
                "fc00::",
                "fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff",
                Some("fbff:ffff:ffff:ffff:ffff:ffff:ffff:ffff"),
                Some("fe00::"),
            ),
            (
                "fe80::",
                "febf:ffff:ffff:ffff:ffff:ffff:ffff:ffff",
                Some("fe7f:ffff:ffff:ffff:ffff:ffff:ffff:ffff"),
                Some("fec0::"),
            ),
            (
                "ff00::",
                "ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff",
                Some("feff:ffff:ffff:ffff:ffff:ffff:ffff:ffff"),
                None,
            ),
        ] {
            assert!(!address_allowed(ip(first)), "{first}");
            assert!(!address_allowed(ip(last)), "{last}");
            if let Some(below) = below {
                assert!(address_allowed(ip(below)), "{below}");
            }
            if let Some(above) = above {
                assert!(address_allowed(ip(above)), "{above}");
            }
        }
    }

    #[test]
    fn literals_and_host_normalization_are_fail_closed() {
        let internal = InternalHosts::from_hosts(["view.fast", "atomicsites.net"]);
        for host in [
            "localhost",
            "sub.localhost",
            "",
            " .[LOCALHOST]. ",
            "\t[ sub.localhost ].\r",
        ] {
            assert_eq!(
                host_allowed(host, &internal),
                Err(EgressDenial::Host),
                "{host:?}"
            );
        }
        assert_eq!(
            host_allowed("site.view.fast", &internal),
            Err(EgressDenial::InternalHost)
        );
        assert_eq!(
            host_allowed("view.fast", &internal),
            Err(EgressDenial::InternalHost)
        );
        assert_eq!(host_allowed("notview.fast", &internal), Ok(()));
        assert!(!address_allowed(ip("169.254.169.254")));
        assert!(!address_allowed(ip("fd00:ec2::254")));
        assert!(!address_allowed(ip("::ffff:127.0.0.1")));
        assert!(address_allowed(ip("::ffff:8.8.8.8")));
        assert!(address_allowed(ip("8.8.8.8")));
        assert!(address_allowed(ip("2606:4700:4700::1111")));
    }

    #[test]
    fn egress_profiles_share_address_policy_and_only_differ_by_scheme() {
        let internal = InternalHosts::from_hosts(["view.fast"]);
        for target in [
            "https://user:pass@example.com",
            "https://127.0.0.1",
            "https://[::1]",
            "https://site.view.fast",
        ] {
            assert_eq!(
                target_allowed(EgressProfile::TenantFetch, target, &internal),
                target_allowed(EgressProfile::ProxyRoute, target, &internal),
                "{target}"
            );
        }
        assert_eq!(
            target_allowed(EgressProfile::TenantFetch, "http://example.com", &internal),
            Err(EgressDenial::Scheme)
        );
        assert!(target_allowed(EgressProfile::ProxyRoute, "http://example.com", &internal).is_ok());
        for profile in [EgressProfile::TenantFetch, EgressProfile::ProxyRoute] {
            assert_eq!(
                target_allowed(profile, "ftp://example.com/files", &internal),
                Err(EgressDenial::Scheme)
            );
            assert_eq!(
                target_allowed(profile, "https://user:pass@example.com", &internal),
                Err(EgressDenial::Credentials)
            );
        }
        assert_eq!(
            target_allowed(EgressProfile::TenantFetch, "https://example.com", &internal)
                .unwrap()
                .port,
            443
        );
        assert_eq!(
            target_allowed(EgressProfile::ProxyRoute, "http://example.com", &internal)
                .unwrap()
                .port,
            80
        );
        assert_eq!(
            target_allowed(
                EgressProfile::TenantFetch,
                "https://example.com:8443",
                &internal
            )
            .unwrap()
            .port,
            8443
        );
    }
}
