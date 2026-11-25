//! The part of `oxc-browserslist` 5.0.1 that oxc_compat compiles against. See Cargo.toml.
use std::{cmp::Ordering, fmt, num::ParseIntError, str::FromStr};

/// An engine version, as in `oxc-browserslist`: `major.minor.patch`.
#[derive(PartialEq, Eq, PartialOrd, Ord, Default, Debug, Copy, Clone)]
pub struct Version(pub u16, pub u16, pub u16);

impl Version {
    #[inline]
    pub const fn major(self) -> u16 {
        self.0
    }

    #[inline]
    pub const fn minor(self) -> u16 {
        self.1
    }

    #[inline]
    pub const fn patch(self) -> u16 {
        self.2
    }

    /// `"1"`, `"1.2"` or `"1.2.3"`; missing parts are 0.
    pub fn parse(s: &str) -> Result<Self, ParseIntError> {
        let mut segments = s.split('.');
        let mut segment = || segments.next().map_or(Ok(0), str::parse);
        Ok(Self(segment()?, segment()?, segment()?))
    }

    pub fn loose_compare(self, b: &str) -> Ordering {
        let mut b = b.split('.');
        let Some(first) = b.next() else {
            return Ordering::Equal;
        };
        self.0.cmp(&first.parse().unwrap_or_default()).then_with(|| match b.next() {
            Some(second) => self.1.cmp(&second.parse().unwrap_or_default()),
            None => Ordering::Equal,
        })
    }
}

impl FromStr for Version {
    type Err = ParseIntError;

    /// Takes the lower end of a range such as `4.4.3-4.4.4`.
    fn from_str(s: &str) -> Result<Self, Self::Err> {
        Self::parse(s.split_once('-').map_or(s, |(v, _)| v))
    }
}

impl fmt::Display for Version {
    fn fmt(&self, f: &mut fmt::Formatter) -> fmt::Result {
        write!(f, "{}.{}.{}", self.0, self.1, self.2)
    }
}

/// Query options; accepted and ignored.
#[derive(Clone, Debug, Default, serde::Deserialize)]
#[serde(rename_all = "camelCase", default)]
pub struct Opts {
    pub mobile_to_desktop: bool,
    pub ignore_unknown_versions: bool,
}

/// A browser and version a query resolved to. Never constructed: every query is refused.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Distrib(String, String);

impl Distrib {
    #[must_use]
    pub fn name(&self) -> &str {
        &self.0
    }

    #[must_use]
    pub fn version(&self) -> &str {
        &self.1
    }
}

impl fmt::Display for Distrib {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{} {}", self.0, self.1)
    }
}

/// Why a query failed.
#[derive(Debug, PartialEq, Eq, Clone)]
pub enum Error {
    /// SpacePress compiles for fixed targets and has no browserslist data.
    Unsupported(String),
}

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        let Self::Unsupported(query) = self;
        write!(f, "browserslist queries are not supported in SpacePress: '{query}'")
    }
}

impl std::error::Error for Error {}

/// Refuses every query.
pub fn resolve<S: AsRef<str>>(queries: &[S], _opts: &Opts) -> Result<Vec<Distrib>, Error> {
    let query: Vec<&str> = queries.iter().map(AsRef::as_ref).collect();
    Err(Error::Unsupported(query.join(",")))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn versions_parse_as_the_real_crate_does() {
        assert_eq!(Ok(Version(1, 0, 0)), "1".parse());
        assert_eq!(Ok(Version(1, 2, 3)), "1.2.3".parse());
        assert_eq!(Ok(Version(1, 2, 0)), "1.2-1.3".parse());
        assert!("tp".parse::<Version>().is_err());
        assert_eq!(Version(9, 1, 0).to_string(), "9.1.0");
    }

    #[test]
    fn every_query_is_refused() {
        let e = resolve(&["defaults"], &Opts::default()).unwrap_err();
        assert_eq!(e.to_string(), "browserslist queries are not supported in SpacePress: 'defaults'");
    }
}
