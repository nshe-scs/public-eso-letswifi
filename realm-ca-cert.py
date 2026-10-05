#!/usr/bin/python3
"""Print the value for a FreeRADIUS container's FR_LETSWIFI_CA_CERT_BASE64 (eso-tools, letswifi mode).

Run on the portal host:  /app/library/eso-letswifi/realm-ca-cert.py <realm>

FreeRADIUS must trust every CA that signed one of the realm's certificates that are still
valid: the realm's current signing CA, plus any older one during a root CA changeover.
This finds them from the realm's config and the portal's certificate log, takes only the
certificates (the portal stores each CA's private key in the same file), and prints them
base64-encoded on one line. Details for checking go to stderr; only the line goes to stdout.
Compatible with the RHEL 9 system Python (3.9).
"""

import argparse
import base64
import datetime
import os
import re
import sqlite3
import subprocess
import sys
import urllib.parse

REALM_PATTERN = re.compile(r"^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$")
CERT_PATTERN = re.compile(r"-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----", re.S)


def fail(message):
    print("error: " + message, file=sys.stderr)
    sys.exit(1)


def current_signer(realm_file):
    """The realm's 'signer' from its config file (a PHP single-quoted string)."""
    with open(realm_file) as handle:
        match = re.search(r"'signer'\s*=>\s*'((?:[^'\\]|\\.)*)'", handle.read())
    if not match:
        fail("%s has no signer" % realm_file)
    return re.sub(r"\\(['\\])", r"\1", match.group(1))


def signers_of_valid_certs(db_file, realm):
    """{CA subject: latest expiry} for the realm's unexpired, unrevoked certificates."""
    if not os.path.exists(db_file):
        return {}
    db = sqlite3.connect("file:%s?mode=ro" % urllib.parse.quote(os.path.abspath(db_file)), uri=True, timeout=30)
    try:
        now = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        return dict(db.execute(
            "SELECT ca_sub, MAX(expires) FROM realm_signing_log"
            " WHERE realm = ? AND revoked IS NULL AND expires >= ? GROUP BY ca_sub", (realm, now)))
    finally:
        db.close()


def certificate(certs_dir, subject):
    """The CA's certificate only, as PEM, from the portal's cert+key file."""
    path = os.path.join(certs_dir, subject + ".pem")
    try:
        with open(path) as handle:
            match = CERT_PATTERN.search(handle.read())
    except OSError:
        return None
    if not match:
        return None
    return subprocess.run(["openssl", "x509"], input=match.group(0), capture_output=True, text=True, check=True).stdout


def describe(pem):
    out = subprocess.run(["openssl", "x509", "-noout", "-enddate", "-fingerprint", "-sha256"],
                         input=pem, capture_output=True, text=True, check=True).stdout
    expires = re.search(r"notAfter=(.*)", out).group(1).strip()
    fingerprint = re.search(r"Fingerprint=(.*)", out).group(1).strip()
    return expires, fingerprint


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("realm", help="the realm, i.e. the FreeRADIUS container's FR_IDP_REALM")
    parser.add_argument("--portal-dir", default=os.path.dirname(os.path.abspath(__file__)),
                        help="the eso-letswifi directory (default: this script's directory)")
    args = parser.parse_args()
    if not REALM_PATTERN.match(args.realm):
        fail("%r isn't a valid realm name" % args.realm)

    config = os.path.join(args.portal_dir, "vols", "letswifi-config")
    realm_file = os.path.join(config, "realms", args.realm + ".conf.php")
    if not os.path.exists(realm_file):
        fail("no realm %r in this portal (no %s)" % (args.realm, realm_file))

    signer = current_signer(realm_file)
    older = signers_of_valid_certs(os.path.join(args.portal_dir, "vols", "letswifi-var", "letswifi.sqlite"), args.realm)
    older.pop(signer, None)
    subjects = [signer] + sorted(older)

    pems = []
    for subject in subjects:
        pem = certificate(os.path.join(config, "certs"), subject)
        if pem is None:
            needed = "it's this realm's current signing CA" if subject == signer else \
                "%s's unexpired certificates were signed by it" % args.realm
            fail("can't find the certificate for %s (%s). Restore it from your vault." % (subject, needed))
        pems.append(pem)

    print("Realm %s: %d CA certificate(s) for FR_LETSWIFI_CA_CERT_BASE64:" % (args.realm, len(pems)), file=sys.stderr)
    for subject, pem in zip(subjects, pems):
        expires, fingerprint = describe(pem)
        role = "current signing CA" if subject == signer else \
            "older CA, still needed until %s UTC" % older[subject]
        print("  %s (%s)\n    expires %s\n    SHA-256 %s" % (subject, role, expires, fingerprint), file=sys.stderr)
    if len(pems) > 1:
        print("NOTE: These certificates are combined on purpose. That's expected during a root CA changeover:\n"
              "  devices enrolled before the change still use certificates signed by the older CA. Run this again\n"
              "  after the date shown to get a line with only the current CA.", file=sys.stderr)
    print("Paste this line into the FreeRADIUS container's custom.env as FR_LETSWIFI_CA_CERT_BASE64:", file=sys.stderr)
    sys.stderr.flush()
    print(base64.b64encode("".join(pems).encode()).decode())


if __name__ == "__main__":
    main()
