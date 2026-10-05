# eso-letswifi

> **Deploying this for an eSO?** Start with the **[production guide](docs/production-guide.md)**: architecture, planning and sizing, portal setup, onboarding institutions (Okta, Entra ID, Google Workspace), eduroam CAT, acceptance tests and operations.

This container runs the Let's Wi-Fi portal ([geteduroam/letswifi-portal](https://github.com/geteduroam/letswifi-portal) v2.0.0-beta7) with SimpleSAMLphp 2.4.10 on Red Hat's `ubi9/php-83` image. After a user logs in through their institution's SAML IdP, it issues them an EAP-TLS client certificate (ECDSA P-256). The certificate also works with the geteduroam apps.

**How it's organized:**
- **One hostname** (e.g. `lets.example.org`) serves multiple institutions. Users pick their institution on the portal's own institution picker (`discovery/index.php`, served at `/discovery/`), styled like the rest of the portal.
- **Each institution brings its own SAML IdP**, its own eduroam realm, and its own private signing CA. You can also host the realm and private CA in separate containers via the [eSO-hostable rapid-deployment eduroam IdP](https://github.com/nshe-scs/public-eso-tools) project.
- **One file maps each IdP to its realm:** `vols/ssp-config/realm-by-idp.php`. The realm is derived from the IdP that actually authenticated the user. Nothing an IdP sends can select a realm.
- **User IDs are `(SAML persistent NameID)@realm`,** so identical usernames at two institutions never collide.

Everything site-specific lives in `custom.env` (e.g. hostname, published IP, portal name, support contacts, ACME credentials) and `./vols`. The container image contains none of it, so weekly rebuilds need no manual work.

## Layout

| Path | Purpose |
|---|---|
| `Dockerfile`, `start.sh`, `httpd/letswifi.conf` | Image build, first-start seeding, httpd and ACME config |
| `docs/production-guide.md` | Production deployment guide for eSOs |
| `bootstrap-ca.sh`, `realm-ca-cert.py` | Create a realm's signing CA; print a realm's CA certificate(s) for its FreeRADIUS container |
| `discovery/` | Institution picker at `/discovery/` (`index.php`, plus `discovery.css`), used instead of SimpleSAMLphp's discovery page |
| `lib/` | Shared page layout (`page.php`), the sign-in error page (`ssp-error.php`) that replaces SimpleSAMLphp's own, and the front-page intro (`front-page.php`); not served directly |
| `config/` | Defaults copied into `vols/` on first start |
| `custom.env` | All site settings: hostname, published IP, portal name, front-page info link, support contact, time zone, ACME EAB credentials, and the FreeRADIUS hosts allowed to pull revocation lists (create it from `custom.env.example`) |
| `.env` | Symlink to `custom.env`, so `docker-compose.yml` can read `LETSWIFI_PUBLIC_IP` from it too |
| `vols/letswifi-config/` | letswifi config, the provider file, `realms/`, `certs/` (**signing CA private keys**), `oauthsecret.txt` |
| `vols/letswifi-var/letswifi.sqlite` | Issued certificates, revocations, OAuth tokens |
| `vols/ssp-config/` | SimpleSAMLphp `config.php` and `authsources.php`, **`realm-by-idp.php`**, `metadata/*.xml` (IdPs), `cert/` (SP key pair), `secretsalt.txt` |
| `vols/md/` | mod_md ACME account and public TLS certificate |
| `vols/revocations/` | Per-realm revocation deny-lists written on the host, served read-only at `/revocations/` (see `revocations/README.md`) |

## One-time setup on the portal host

1. **Prerequisites:**
   - Point a DNS A record for your portal hostname (`LETSWIFI_HOSTNAME`) at the address you'll publish on (`LETSWIFI_PUBLIC_IP`), and open 443/tcp. Port 80 is not used.
   - Get an ACME directory URL from your public certificate provider, plus External Account Binding credentials if it uses them (see `custom.env.example`; set `ACME_EAB_KID=none` if it doesn't). We tested with CERTInext via the InCommon Certificate Service: in its portal (Integrations → APIs), create an ACME credential tied to a product that allows ECC-256 keys, and note the directory URL, Key ID and MAC key. Once DCV for the parent domain is complete there, no per-order challenge is needed; other CAs must validate with TLS-ALPN-01 on port 443.
   - The portal host must be a subscribed RHEL host, because the build installs `mod_md` from RHEL AppStream. A future release may switch over to Alpine to remove this requirement if there's demand for it.

2. **Install the files:**
   ```sh
   mkdir -p /app/library/eso-letswifi && cd /app/library/eso-letswifi
   # copy this directory's contents here
   cp custom.env.example custom.env && chmod 600 custom.env   # fill in every value; see the comments in the file
   ls -l .env                                                 # must be a symlink to custom.env
   mkdir -p vols/{letswifi-config,letswifi-var,ssp-config,md,revocations}
   chown -R 1001:0 vols && chmod -R 750 vols
   ```

3. **Build and start:**
   ```sh
   podman-compose up -d --build
   podman logs -f eso-letswifi
   ```
   On first start, `start.sh` does the following:
   - seeds `vols/` and creates the provider file (e.g. `vols/letswifi-config/providers/lets.example.org.conf.php`),
   - generates `oauthsecret.txt`, `secretsalt.txt` and the SAML SP key pair,
   - creates the database.

   mod_md then registers with your public cert provider (we tested with CERTInext via the InCommon Certificate Service) and orders the certificate. When the log says `The Managed Domain lets.example.org has been setup and changes will be activated on next (graceful) server restart`, run:
   ```sh
   podman kill --signal USR1 eso-letswifi
   ```
   Until that happens, HTTPS answers 503 and the health check reports unhealthy. Verify from outside the host:
   ```sh
   openssl s_client -connect lets.example.org:443 -servername lets.example.org </dev/null 2>/dev/null \
     | openssl x509 -noout -issuer -dates -text | grep -E 'Issuer:|Not After|NIST CURVE'
   ```
   The portal's own pages return an error ("realm map cannot be empty") until the first institution is onboarded. The SP metadata URL works right away.

4. **Restart at boot.** Podman restarts `restart: always` containers at boot through this service:
   ```sh
   systemctl enable --now podman-restart.service
   ```

5. **Weekly rebuild.** This picks up Red Hat's latest image and errata, and the restart also activates any certificate mod_md has renewed. Public certificates last up to 199 days and renew with about a third of their life left, so weekly is plenty.

   `/etc/systemd/system/letswifi-rebuild.service`:
   ```ini
   [Service]
   Type=oneshot
   # A full rebuild takes several minutes; the systemd default would kill it after 90 seconds
   TimeoutStartSec=1h
   ExecStart=/app/library/eso-letswifi/rebuild.sh
   ```
   `/etc/systemd/system/letswifi-rebuild.timer`:
   ```ini
   [Timer]
   OnCalendar=Sun 04:30
   Persistent=true
   [Install]
   WantedBy=timers.target
   ```
   Then enable it:
   ```sh
   systemctl daemon-reload && systemctl enable --now letswifi-rebuild.timer
   ```
   letswifi and SimpleSAMLphp versions only change when you edit `LETSWIFI_VERSION` / `SSP_VERSION` in the `Dockerfile`.

## Onboarding an institution

The example uses Library A, hosted via a public-eso-tools FreeRADIUS container with realm `librarya.org`, RADIUS server certificate `librarya.roam.example.org` signed by the container's private root CA `eduroam Support Organization Root CA (ABCDE1234)`; and their own SAML IdP entityID `https://idp.librarya.org/idp/shibboleth`. Do the steps in this order, because the map must only name realms that already exist. Note that you can change --days below to adjust how long client certs will be valid. Shorter isn't necessarily better; the risk profile of a lingering eduroam-only certificate is far smaller than a stale password that can access many more things than eduroam.

The steps use this alias:
```sh
alias lw='podman exec -i eso-letswifi php /opt/app-root/letswifi/bin/letswifi'
```

1. **Signing CA.** On the host, run `./bootstrap-ca.sh` to generate a private root CA cert that will sign client certs issued by the Let's Wi-Fi portal for the institution's realm. Store the `.pem` and `.key` offline, then import them with the command the script prints. Note the displayed serial so you don't confuse it with other root CAs.

2. **RADIUS trust.** Import the institution's public-eso-tools CA certificate (the certificate only, never the key), using the value from that container's `custom.env` (it should already be base64 encoded):
   ```sh
   echo "<paste cert here>" | base64 -d | lw ca import
   ```
   Copy the public-eso-tools CA subject *exactly* as it appears on the `s:` line of the output, for example `CN=eduroam Support Organization Root CA (ABCDE1234)`.

3. **Realm.** Use the realm the institution's public-eso-tools container already serves (`FR_IDP_REALM`), since eduroam routes by realm:
   ```sh
   lw realm create librarya.org \
     --signer "CN=eduroam Support Organization LW Root (1A2B3C4D)" \
     --trust  "CN=eduroam Support Organization Root CA (ABCDE1234)" \
     --server-name librarya.roam.example.org \
     --network eduroam --days 730 \
     --lang en-US --name "Library A"
   lw realm view librarya.org
   ```

   The institution's FreeRADIUS container (eso-tools, `FR_IDENTITY_SOURCE=letswifi`) needs this realm's CA certificate. Get the line to paste into its `FR_LETSWIFI_CA_CERT_BASE64` with `./realm-ca-cert.py librarya.org`. Run it again after changing a realm's signing CA: it then includes both CAs until certificates signed by the old one have expired or been revoked, and says so.

4. **Institution's IdP.** Send the institution:
   - The SP metadata URL: `https://lets.example.org/simplesaml/module.php/saml/sp/metadata/default-sp`
   - One requirement: release a persistent NameID (`urn:oasis:names:tc:SAML:2.0:nameid-format:persistent`). No other attributes are needed. The persistent NameID becomes the user's identity for listing and revoking certificates.

5. **IdP metadata.** Save the institution's IdP metadata as `vols/ssp-config/metadata/librarya.xml` (owner 1001, mode 640). The institution picker shows the IdP's `mdui:DisplayName` or `md:OrganizationDisplayName`. If the file has neither (Okta's metadata doesn't), the page shows the raw entityID. In that case, add this block just before `</md:EntityDescriptor>`:
   ```xml
   <md:Organization>
     <md:OrganizationName xml:lang="en">Library A</md:OrganizationName>
     <md:OrganizationDisplayName xml:lang="en">Library A</md:OrganizationDisplayName>
     <md:OrganizationURL xml:lang="en">https://librarya.org/</md:OrganizationURL>
   </md:Organization>
   ```

6. **Map the IdP to its realm.** Add one line to `vols/ssp-config/realm-by-idp.php`:
   ```php
   'https://idp.librarya.org/idp/shibboleth' => 'librarya.org',
   ```
   Steps 5 and 6 take effect within a few seconds (PHP rechecks changed files every 2 seconds), with no restart. An institution appears in the picker only once it has both a metadata file and a line in `realm-by-idp.php`; with only one such institution, the picker is skipped.

7. **Test.** Browse to `https://lets.example.org/`, choose the institution, log in, and download a profile. Then check the database:
   ```sh
   sqlite3 vols/letswifi-var/letswifi.sqlite \
     "select serial,realm,requester,client,expires from realm_signing_log order by serial desc limit 5"
   ```

**Offboarding:** remove the institution's line from `realm-by-idp.php` and its metadata file, which blocks new logins within a few seconds. Certificates already issued stay valid until revoked or expired.

## Later

- **Revocation deny-lists for FreeRADIUS:** see `revocations/README.md`.
- **Backups:** back up `vols/` nightly and encrypt the backup, because it contains the signing CA keys and the SP key. The portal database is `/app/library/eso-letswifi/vols/letswifi-var/letswifi.sqlite`.
- **Apple profile signing** is off. Profiles install but show as unsigned on macOS and iOS.

## License

BSD-3-Clause; see [LICENSE](LICENSE). Three configuration files (`config/letswifi.conf.php`, `config/database.conf.php`, `config/provider.conf.php`) are adapted from letswifi-portal, under its BSD-3-Clause license, as noted in their headers.

## Third-party software

This repository contains no third-party code beyond the three adapted files noted above. `Dockerfile` downloads the following at build time, so images you build include them under their respective licenses:

| Software | Version | License |
|---|---|---|
| [letswifi-portal](https://github.com/geteduroam/letswifi-portal) | v2.0.0-beta7 | BSD-3-Clause |
| [SimpleSAMLphp](https://simplesamlphp.org/) | 2.4.10 | LGPL-2.1 |
| Red Hat Universal Base Image (`ubi9/php-83`) | latest | Red Hat Universal Base Image EULA |
