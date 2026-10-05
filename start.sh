#!/bin/bash
# Seeds persistent storage on first start, then hands off to the image's normal
# startup (starts php-fpm, then execs httpd in the foreground).
set -euo pipefail
: "${LETSWIFI_HOSTNAME:?LETSWIFI_HOSTNAME must be set in custom.env}"
LW=/opt/app-root/letswifi
SSP=$LW/simplesamlphp/config
umask 027

# letswifi configuration, plus the provider file named after the portal hostname
[ -e "$LW/config/letswifi.conf.php" ] || cp -a /opt/app-root/defaults/letswifi-config/. "$LW/config/"
mkdir -p "$LW/config/providers"
PROVIDER="$LW/config/providers/$LETSWIFI_HOSTNAME.conf.php"
[ -e "$PROVIDER" ] || cp /opt/app-root/defaults/provider.conf.php "$PROVIDER"

# SimpleSAMLphp configuration
if [ ! -e "$SSP/config.php" ]; then
	cp -a /opt/app-root/defaults/ssp-config/. "$SSP/"
	sed -i "s/__LETSWIFI_HOSTNAME__/$LETSWIFI_HOSTNAME/g" "$SSP/authsources.php"
fi
mkdir -p "$SSP/metadata" "$SSP/cert"

# Secrets, generated once and kept in ./vols
[ -s "$LW/config/oauthsecret.txt" ] || head -c32 /dev/urandom | base64 | tr -d = >"$LW/config/oauthsecret.txt"
[ -s "$SSP/secretsalt.txt" ] || head -c32 /dev/urandom | base64 | tr -d '=/+' >"$SSP/secretsalt.txt"

# SAML SP signing/encryption key pair (RSA 3072, 10 years). RSA rather than ECDSA because
# many SAML IdPs can't encrypt assertions to an EC key.
if [ ! -s "$SSP/cert/saml-sp.key" ]; then
	php -r '
		// Minimal OpenSSL config so no distro default subject fields end up in the certificate
		$cnf = tempnam(sys_get_temp_dir(), "sspcnf");
		file_put_contents($cnf, "[req]\ndistinguished_name = dn\n[dn]\n");
		$opts = ["config" => $cnf, "digest_alg" => "sha256"];
		$key = openssl_pkey_new($opts + ["private_key_type" => OPENSSL_KEYTYPE_RSA, "private_key_bits" => 3072]);
		$csr = openssl_csr_new(["commonName" => $argv[1]], $key, $opts);
		$crt = openssl_csr_sign($csr, null, $key, 3650, $opts, random_int(1, PHP_INT_MAX));
		openssl_pkey_export_to_file($key, $argv[2] . "/saml-sp.key", null, $opts);
		openssl_x509_export_to_file($crt, $argv[2] . "/saml-sp.crt");
		unlink($cnf);
	' "$LETSWIFI_HOSTNAME" "$SSP/cert"
fi

# Site settings from custom.env, rewritten on every start so env changes apply on recreate.
# php-fpm doesn't pass the container environment to PHP, so the config files read this instead.
php -r '
	$site = [
		"hostname"           => getenv("LETSWIFI_HOSTNAME"),
		"portal_name"        => getenv("LETSWIFI_PORTAL_NAME") ?: "eduroam",
		"portal_description" => "eduroam onboarding", // required by letswifi, but nothing displays it
		"support_name"       => getenv("LETSWIFI_SUPPORT_NAME") ?: "eduroam support",
		"support_email"      => getenv("LETSWIFI_SUPPORT_EMAIL") ?: "na@example.org",
		"timezone"           => getenv("TZ") ?: "UTC",
	];
	if (!in_array($site["timezone"], timezone_identifiers_list(), true)) {
		fwrite(STDERR, "TZ \"" . $site["timezone"] . "\" is not a valid time zone\n");
		exit(1);
	}
	file_put_contents("/opt/app-root/etc/eso-letswifi-site.php", "<?php return " . var_export($site, true) . ";\n");
'

# Front-page intro wording and its "What is eduroam?" link (LETSWIFI_INFO_URL)
php /opt/app-root/eso-letswifi/lib/front-page.php

# Schema uses CREATE ... IF NOT EXISTS, so this is safe on every start
php -r '$db = new PDO("sqlite:/opt/app-root/letswifi/var/letswifi.sqlite");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(file_get_contents("/opt/app-root/letswifi/letswifi.sqlite.sql"));'

exec /usr/libexec/s2i/run
