#!/usr/bin/python3
"""Publish eso-letswifi certificate revocations as one small SQLite deny-list per realm.

Runs on the portal host. Each realm gets <publish-dir>/<realm>.sqlite, which the portal
container serves at https://<portal>/revocations/<realm>.sqlite for that realm's
FreeRADIUS hosts to pull (eso-tools' letswifi/fetch-revocations.py). A realm's file contains only that
realm's revocations: certificate CN, serial, issuing LW Root, revocation and expiry
times. No usernames, IP addresses or CSRs.

Safety rules:
  * The portal database is read through SQLite's online backup API, so every export is
    a consistent point-in-time copy even while the portal is writing.
  * letswifi never un-revokes a certificate. If a revocation published earlier is
    missing now and the certificate has not expired, the source database was probably
    restored from an old backup. That realm's export is refused and its previous file
    stays in place (exit code 2); other realms are still published. After investigating,
    rerun with --allow-shrink REALM.
  * Each file is written beside the old one, integrity-checked, fsynced and renamed into
    place, so the web server only ever serves a complete file.
  * Every run rewrites every file with a new generated_at, which fetchers use to detect
    a stalled exporter.

Exit codes: 0 all realms published, 1 error (for one realm, or before anything was
published), 2 one or more realms refused.
Compatible with the RHEL 9 system Python (3.9).
"""

import argparse
import datetime
import fcntl
import os
import re
import sqlite3
import sys
import tempfile
import time
import urllib.parse

FORMAT_VERSION = "2"
# letswifi stores timestamps as UTC text in this format, which sorts correctly as text
DATE_FORMAT = "%Y-%m-%d %H:%M:%S"
REQUIRED_COLUMNS = {"serial", "realm", "ca_sub", "ident", "expires", "revoked"}
# Realm names become file names and URL paths
MAX_PASSES = 5  # re-export if the portal wrote during a pass, up to this many times
REALM_PATTERN = re.compile(r"^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$")

OUTPUT_SCHEMA = """
CREATE TABLE revoked (
    ident   TEXT PRIMARY KEY NOT NULL,  -- certificate CN, e.g. x7k2...@realm
    serial  INTEGER NOT NULL,           -- X.509 serial number (decimal)
    ca_sub  TEXT NOT NULL,              -- issuing LW Root, e.g. CN=eduroam Support Organization LW Root (...)
    revoked TEXT NOT NULL,              -- UTC, YYYY-MM-DD HH:MM:SS
    expires TEXT NOT NULL               -- UTC, YYYY-MM-DD HH:MM:SS
);
CREATE INDEX revoked_ca_serial ON revoked (ca_sub, serial);
CREATE TABLE meta (key TEXT PRIMARY KEY NOT NULL, value TEXT NOT NULL);
"""


def log(message):
    print(message, file=sys.stderr, flush=True)


def open_ro(path):
    uri = "file:%s?mode=ro" % urllib.parse.quote(os.path.abspath(path))
    return sqlite3.connect(uri, uri=True, timeout=30)


def snapshot_source(source_path, scratch_dir):
    """Copy the live portal database to a private snapshot using the backup API."""
    if not os.path.isfile(source_path):
        raise RuntimeError("source database not found: %s" % source_path)
    source = open_ro(source_path)
    snapshot = sqlite3.connect(os.path.join(scratch_dir, "snapshot.sqlite"))
    try:
        source.backup(snapshot)
    finally:
        source.close()
    return snapshot


def check_source_schema(db):
    columns = {row[1] for row in db.execute('PRAGMA table_info("realm_signing_log")')}
    if not columns:
        raise RuntimeError("source has no realm_signing_log table; is this a letswifi database?")
    missing = REQUIRED_COLUMNS - columns
    if missing:
        raise RuntimeError("source realm_signing_log is missing columns: %s" % ", ".join(sorted(missing)))


def configured_realms(realms_dir):
    """Realms defined in the portal's config, from vols/letswifi-config/realms/<realm>.conf.php."""
    if realms_dir is None:
        return set()
    if not os.path.isdir(realms_dir):
        raise RuntimeError("realms directory not found: %s" % realms_dir)
    suffix = ".conf.php"
    return {name[:-len(suffix)] for name in os.listdir(realms_dir) if name.endswith(suffix)}


def read_revocations(db, cutoff):
    """{realm: [rows]} of revoked certificates not expired for longer than the grace period,
    plus the number of certificates per realm."""
    by_realm = {}
    for realm, ident, serial, ca_sub, revoked, expires in db.execute(
        """
        SELECT MIN(realm), ident, MIN(serial), MIN(ca_sub), MIN(revoked), MAX(expires)
        FROM realm_signing_log
        WHERE revoked IS NOT NULL AND expires >= ?
        GROUP BY ident
        """,
        (cutoff,),
    ):
        by_realm.setdefault(realm, []).append((ident, serial, ca_sub, revoked, expires))
    counts = dict(db.execute("SELECT realm, COUNT(*) FROM realm_signing_log GROUP BY realm"))
    return by_realm, counts


def read_previous(path):
    """{ident: expires} from the currently published file for a realm, or None."""
    if not os.path.exists(path):
        return None
    db = open_ro(path)
    try:
        try:
            version = db.execute("SELECT value FROM meta WHERE key = 'format_version'").fetchone()
        except sqlite3.DatabaseError:
            version = None
        if version is None or version[0] != FORMAT_VERSION:
            raise RuntimeError("%s is not a format-%s export; move it aside first" % (path, FORMAT_VERSION))
        return dict(db.execute("SELECT ident, expires FROM revoked"))
    finally:
        db.close()


def vanished_revocations(previous, current_idents, cutoff):
    """Previously published revocations that disappeared before their certificate expired."""
    if previous is None:
        return []
    return sorted(ident for ident, expires in previous.items()
                  if ident not in current_idents and expires >= cutoff)


def write_output(path, rows, meta):
    """Write a complete new file next to the old one, verify it, then rename it into place."""
    out_dir = os.path.dirname(os.path.abspath(path))
    fd, tmp_path = tempfile.mkstemp(prefix=".export-", suffix=".tmp", dir=out_dir)
    os.close(fd)
    try:
        db = sqlite3.connect(tmp_path)
        try:
            db.execute("PRAGMA journal_mode = DELETE")  # readers may open this read-only
            db.executescript(OUTPUT_SCHEMA)
            db.executemany("INSERT INTO revoked VALUES (?, ?, ?, ?, ?)", rows)
            db.executemany("INSERT INTO meta VALUES (?, ?)", sorted(meta.items()))
            db.commit()
            result = db.execute("PRAGMA integrity_check").fetchone()[0]
            if result != "ok":
                raise RuntimeError("integrity check of new file failed: %s" % result)
            if db.execute("SELECT COUNT(*) FROM revoked").fetchone()[0] != len(rows):
                raise RuntimeError("new file row count does not match")
        finally:
            db.close()
        os.chmod(tmp_path, 0o644)
        with open(tmp_path, "rb") as handle:
            os.fsync(handle.fileno())
        os.replace(tmp_path, path)
    except BaseException:
        if os.path.exists(tmp_path):
            os.unlink(tmp_path)
        raise


def fsync_dir(path):
    dir_fd = os.open(path, os.O_RDONLY)
    try:
        os.fsync(dir_fd)
    finally:
        os.close(dir_fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--source", required=True, help="portal database (vols/letswifi-var/letswifi.sqlite)")
    parser.add_argument("--publish-dir", required=True, help="directory the portal serves at /revocations/")
    parser.add_argument("--realms-dir", help="portal realm config (vols/letswifi-config/realms), so realms "
                                             "with no revocations yet still get a file")
    parser.add_argument("--grace-days", type=int, default=30,
                        help="keep revocations this many days past certificate expiry (default 30)")
    parser.add_argument("--allow-shrink", action="append", default=[], metavar="REALM",
                        help="publish REALM even if unexpired revocations disappeared (after investigating!); "
                             "repeat for several realms")
    parser.add_argument("--dry-run", action="store_true", help="check and report, but don't publish")
    args = parser.parse_args()

    if not os.path.isdir(args.publish_dir):
        log("error: publish directory %s does not exist" % args.publish_dir)
        return 1
    # One export at a time (the path unit and the timer can fire together)
    lock = open(os.path.join(args.publish_dir, ".export.lock"), "w")
    fcntl.flock(lock, fcntl.LOCK_EX)

    try:
        for _ in range(MAX_PASSES):
            before = source_fingerprint(args.source)
            status = export_once(args)
            if status == 1 or args.dry_run or source_fingerprint(args.source) == before:
                return status
            # The portal wrote while we were exporting; systemd won't re-trigger a running
            # service, so export again rather than wait for the timer
            log("source changed during export; exporting again")
        return status
    finally:
        lock.close()


def source_fingerprint(path):
    try:
        st = os.stat(path)
        return (st.st_mtime_ns, st.st_size)
    except OSError:
        return None


def export_once(args):
    now = datetime.datetime.now(datetime.timezone.utc).replace(tzinfo=None)
    cutoff = (now - datetime.timedelta(days=args.grace_days)).strftime(DATE_FORMAT)
    export_id = str(time.time_ns())
    refused = failed = False
    try:
        with tempfile.TemporaryDirectory(prefix="letswifi-export-") as scratch:
            snapshot = snapshot_source(args.source, scratch)
            try:
                check_source_schema(snapshot)
                by_realm, counts = read_revocations(snapshot, cutoff)
            finally:
                snapshot.close()

        published = {name[:-len(".sqlite")] for name in os.listdir(args.publish_dir)
                     if name.endswith(".sqlite") and not name.startswith(".")}
        realms = configured_realms(args.realms_dir) | set(counts) | published
        for realm in sorted(realm for realm in realms if not REALM_PATTERN.match(realm)):
            log("error: skipping realm %r: not a safe file name" % realm)
            realms.discard(realm)
            failed = True

        for realm in sorted(realms):
            path = os.path.join(args.publish_dir, realm + ".sqlite")
            rows = by_realm.get(realm, [])
            current = {row[0] for row in rows}
            try:
                previous = read_previous(path)
            except (RuntimeError, sqlite3.Error) as error:
                # One damaged file must not stop the other realms from updating
                log("error %s: %s" % (realm, error))
                failed = True
                continue
            vanished = vanished_revocations(previous, current, cutoff)
            if vanished and realm not in args.allow_shrink:
                log("REFUSED %s: %d revocation(s) published earlier are missing from the source but not "
                    "expired (e.g. %s). The portal database may have been restored from an old backup. "
                    "Previous file left in place; rerun with --allow-shrink %s once explained."
                    % (realm, len(vanished), ", ".join(vanished[:5]), realm))
                refused = True
                continue
            if vanished:
                log("WARNING %s (--allow-shrink): dropping %d unexpired revocation(s)" % (realm, len(vanished)))
            pruned = 0 if previous is None else sum(
                1 for ident, expires in previous.items() if ident not in current and expires < cutoff)
            added = len(current) if previous is None else len(current - set(previous))
            summary = "%s: %d revocation(s) (%d new, %d pruned after expiry) of %d certificate(s)" % (
                realm, len(rows), added, pruned, counts.get(realm, 0))
            if args.dry_run:
                log("dry run: would publish " + summary)
                continue
            write_output(path, rows, {
                "format_version": FORMAT_VERSION,
                "realm": realm,
                "generated_at": now.strftime(DATE_FORMAT),
                "export_id": export_id,  # orders exports; unique even within one second
                "revoked_count": str(len(rows)),
                "grace_days": str(args.grace_days),
            })
            log("published " + summary)
        if not args.dry_run:
            fsync_dir(args.publish_dir)
        return 1 if failed else (2 if refused else 0)
    except (RuntimeError, sqlite3.Error, OSError) as error:
        log("error: %s (previously published files left in place)" % error)
        return 1


if __name__ == "__main__":
    sys.exit(main())
