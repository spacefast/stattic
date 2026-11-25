//! The commands the server runs for a tool: `spacepress find` and the other site commands, Studio's
//! status for `dev_status` (a site with no state file), and the compiler in a process of its own.
//! Each is bounded by the server's timeout and stops when the client cancels the request it belongs
//! to.

use super::Options;
use std::io::Read as _;
use std::process::{Command, Output, Stdio};
use std::sync::mpsc;
use std::time::Duration;

/// After a stop, how long the command's pipes get to close before its output is given up on.
const AFTER_STOP: Duration = Duration::from_secs(5);

pub(super) enum Ran {
    Finished(Output),
    /// Stopped at the timeout.
    TimedOut,
    /// Stopped because the client cancelled the request; whatever is answered is not sent.
    Cancelled,
    Failed(std::io::Error),
}

/// Stops a command and everything it started: it leads a process group of its own.
pub(super) fn stop(pid: u32) {
    let mut command = if cfg!(unix) {
        let mut command = Command::new("/bin/sh");
        // A negative number is the process group. No `--` before it: dash's builtin reads that
        // as the number itself.
        command.args(["-c", "kill -TERM \"-$1\"", "sh", &pid.to_string()]);
        command
    } else {
        let mut command = Command::new("taskkill");
        command.args(["/PID", &pid.to_string(), "/T", "/F"]);
        command
    };
    let _ = command
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status();
}

/// Stops the command's process group if this server dies first. A server killed by its client
/// (SIGTERM, then SIGKILL, as the stdio transport describes) runs no code of its own, so a small
/// shell holds the read end of a pipe whose write end only this process has: when the server is
/// gone for any reason, the read sees end of input and the shell stops the group. It is started
/// once the command is running and stopped when it has finished (see [`Guard`]).
#[cfg(unix)]
fn guard(pid: u32) -> Option<Guard> {
    // Both ends are close-on-exec, so the write end never leaks into a command this server starts
    // later (each would hold the pipe open, and the guardian would never see end of input). The read
    // end reaches the guardian as its stdin, which `spawn` sets up explicitly.
    let (read, write) = std::io::pipe().ok()?;
    let mut shell = Command::new("/bin/sh");
    shell.args([
        "-c",
        // Builtins only: a guardian that needs a program from PATH (the dev acceptance suite runs
        // with almost none) would fail at once and stop the command it guards.
        "while read -r _; do :; done; kill -TERM \"-$1\" 2>/dev/null",
        "sh",
        &pid.to_string(),
    ]);
    shell
        .stdin(Stdio::from(read))
        .stdout(Stdio::null())
        .stderr(Stdio::null());
    std::os::unix::process::CommandExt::process_group(&mut shell, 0);
    let guardian = shell.spawn().ok()?;
    Some(Guard {
        guardian,
        write: Some(write),
    })
}

#[cfg(not(unix))]
fn guard(_pid: u32) -> Option<Guard> {
    None
}

/// The guardian of one command. Dropped once the command has finished: closing the write end makes
/// the guardian stop a group that no longer exists, and the guardian is then reaped.
struct Guard {
    guardian: std::process::Child,
    #[cfg(unix)]
    write: Option<std::io::PipeWriter>,
}

impl Drop for Guard {
    fn drop(&mut self) {
        #[cfg(unix)]
        drop(self.write.take());
        let _ = self.guardian.wait();
    }
}

/// Kills a command's whole process group: for one that ignored [`stop`].
fn stop_hard(pid: u32) {
    if cfg!(unix) {
        let _ = Command::new("/bin/sh")
            .args(["-c", "kill -KILL \"-$1\"", "sh", &pid.to_string()])
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status();
    }
}

/// Runs `command` with no input and its output captured.
pub(super) fn run(options: &Options, mut command: Command) -> Ran {
    command
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());
    #[cfg(unix)]
    std::os::unix::process::CommandExt::process_group(&mut command, 0);
    let mut child = match command.spawn() {
        Ok(child) => child,
        Err(error) => return Ran::Failed(error),
    };
    let pid = child.id();
    let guarded = guard(pid);
    {
        let mut flight = options.flight.lock().expect("flight");
        flight.child = Some(pid);
        // The cancellation arrived before the command started.
        if flight.stop {
            stop(pid);
        }
    }
    // Both pipes are read off this thread: a full pipe never blocks the command, and the wait
    // below can end at the timeout.
    let (send, receive) = mpsc::channel();
    let (mut out, mut err) = (child.stdout.take().expect("piped"), child.stderr.take().expect("piped"));
    std::thread::spawn(move || {
        let errors = std::thread::spawn(move || {
            let mut bytes = Vec::new();
            let _ = err.read_to_end(&mut bytes);
            bytes
        });
        let mut bytes = Vec::new();
        let _ = out.read_to_end(&mut bytes);
        let _ = send.send((bytes, errors.join().unwrap_or_default()));
    });
    let (timed_out, output) = match receive.recv_timeout(options.timeout) {
        Ok(output) => (false, Some(output)),
        Err(_) => {
            stop(pid);
            (true, receive.recv_timeout(AFTER_STOP).ok())
        }
    };
    // From here the reader thread no longer sees the command, so a later cancellation cannot reach
    // a process that took its number. It is not reaped yet, so the number is still its own.
    let cancelled = {
        let mut flight = options.flight.lock().expect("flight");
        flight.child = None;
        flight.stop
    };
    if output.is_none() {
        // The command ignored the stop: kill it and everything it started.
        stop_hard(pid);
        let _ = child.kill();
    }
    let status = child.wait();
    drop(guarded);
    match (cancelled, timed_out, output, status) {
        (true, ..) => Ran::Cancelled,
        (_, true, ..) => Ran::TimedOut,
        (_, _, Some((stdout, stderr)), Ok(status)) => Ran::Finished(Output { status, stdout, stderr }),
        (_, _, _, Err(error)) => Ran::Failed(error),
        (_, _, None, Ok(_)) => Ran::TimedOut,
    }
}
