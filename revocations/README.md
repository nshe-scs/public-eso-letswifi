# Revocation lists

When a certificate is revoked in the portal, every FreeRADIUS server for that realm must stop accepting it. The portal host publishes one small list of revoked certificates per realm. The host of each eso-tools container pulls its realm's list every minute with the fetcher that ships with eso-tools, and the container checks it during each EAP-TLS login.

```
portal host                                  FreeRADIUS host (any number)
vols/letswifi-var/letswifi.sqlite            <container dir>/vols/revocations/revoked.sqlite
  |  export-revocations.py                     ^  eso-tools' letswifi/fetch-revocations.py
  |  (on change, and every 10 minutes)         |  (every minute, per container)
  v                                            |
vols/revocations/<realm>.sqlite  ---->  https://<portal>/revocations/<realm>.sqlite  (IP-restricted)
```

## Set up the portal host

The deployment guide has already created `vols/revocations` and put a placeholder in `LETSWIFI_REVOCATION_CLIENTS`.

1. In `custom.env`, set `LETSWIFI_REVOCATION_CLIENTS` to the FreeRADIUS hosts' outbound IP addresses, separated by spaces, then recreate the container: `podman-compose down && podman-compose up -d`.
2. Install and start the exporter:
   ```sh
   cd /app/library/eso-letswifi
   cp revocations/systemd/letswifi-revocations-export.* /etc/systemd/system/
   systemctl daemon-reload
   systemctl enable --now letswifi-revocations-export.path letswifi-revocations-export.timer
   journalctl -u letswifi-revocations-export -n 5
   ```
   Expect one `published <realm>: ...` line per realm.

If a FreeRADIUS host is also the portal host and its fetch gets `HTTP 403`, its requests are reaching the container from an internal address. Find that address in `podman logs eso-letswifi` and add it to `LETSWIFI_REVOCATION_CLIENTS`.

## Set up each FreeRADIUS host

The fetcher and its systemd timer ship with eso-tools, in `freeradius-idp-container/letswifi/`; see the Let's Wi-Fi section of eso-tools' README. In short: install its two systemd units once per host, then for each container set `FR_LETSWIFI_REVOCATIONS_URL=https://<portal>/revocations` in its `custom.env`, enable its timer, and uncomment its `/revocations` mount.

## How it stays safe

- **Minimal data:** each realm's list holds only that realm's revoked certificates: CN, serial, issuing CA, and revocation and expiry times. No usernames, IP addresses or CSRs. Entries are dropped 30 days after the certificate expires, when TLS rejects it anyway.
- **No partial files:** both ends write a complete, checked file next to the old one, then swap it in.
- **No silent un-revocation:** the portal never un-revokes a certificate, so a list that drops unexpired revocations (for example, after the portal database is restored from an old backup) is refused, by the exporter and again by each FreeRADIUS host. The previous list stays in use. Older lists and lists for the wrong realm are refused too.
- **Never locks everyone out:** if the list is missing or unreadable, eso-tools accepts the login rather than refusing everyone. The fetcher reports `STALE` once its list is more than 30 minutes old.
- **Secure transport:** downloads use HTTPS with the portal's certificate verified and never follow redirects, and the portal serves the lists only to allowlisted IP addresses.

## When something goes wrong

| What you see | What to do |
|---|---|
| Fetch fails with `error: ...` (portal or network down) | Nothing; the installed list stays in use, and the next successful pull catches up |
| Fetch fails with `HTTP 403` | Add the host's address to `LETSWIFI_REVOCATION_CLIENTS` on the portal host, then recreate the portal container |
| Fetch reports `STALE` | The portal's exporter has stopped; check `letswifi-revocations-export` on the portal host |
| Export or fetch reports `REFUSED` | Revocations disappeared before their certificates expired. Find out why (e.g. a database restore) and re-revoke if needed. If it's expected, run the export once by hand with `--allow-shrink <realm>`, then the fetch once by hand on each affected host with `--allow-shrink` |
| Export reports `move it aside first` | A file in `vols/revocations` is damaged. Move it aside; the next export republishes it |

## Reference

- The exporter accepts `--help`, and its `--dry-run` shows what it would publish. The fetcher (in eso-tools) also accepts `--help`; its `--from-file` installs a local copy with the same checks, for when the portal is unreachable, and `--realm`, `--dir` and `--base-url` serve RADIUS servers laid out differently from eso-tools.
- Each list is an SQLite file. Its `revoked` table has one row per certificate (`ident` is the certificate CN), and its `meta` table records the realm, when it was generated, and the number of entries.
- Other RADIUS servers using these lists must mount the folder, not the file, since updates arrive by rename. They must also reopen the database regularly (eso-tools does every 60 seconds), and reject only when a certificate's CN is listed.
