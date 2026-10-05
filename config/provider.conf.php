<?php declare( strict_types=1 );

/*
 * Adapted from letswifi-portal (https://github.com/geteduroam/letswifi-portal)
 * Copyright: Jørn Åne de Jong <jorn.dejong@letswifi.eu>
 * Copyright: Paul Dekkers, SURF <paul.dekkers@surf.nl>
 * Modifications copyright (c) 2026, Nevada System of Higher Education
 * SPDX-License-Identifier: BSD-3-Clause
 */

// The one letswifi provider. Seeded on first start as
// vols/letswifi-config/providers/<LETSWIFI_HOSTNAME>.conf.php (letswifi picks the provider
// by the HTTP Host header, so the filename must match the hostname).
//
// Institutions are NOT listed here: realm access is built from the IdP-to-realm map in
// vols/ssp-config/realm-by-idp.php, and the SimpleSAMLphp filter in authsources.php sets
// letswifiRealm from the IdP the user actually logged in with.

$realms = \array_values( \array_unique( require '/opt/app-root/letswifi/simplesamlphp/config/realm-by-idp.php' ) );
// LETSWIFI_PORTAL_NAME from custom.env and a static description, written by start.sh
$site = require '/opt/app-root/etc/eso-letswifi-site.php';

return [
	'display_name' => ['en-US' => $site['portal_name']],
	// Required and must not be null
	'description' => ['en-US' => $site['portal_description']],

	'realm_access' => \array_combine( $realms, \array_map( static fn( string $realm ): array => [$realm], $realms ) ),
	'contact' => null,
	'admins' => [],

	'auth' => [
		'service' => 'SimpleSamlAuth',
		'param' => [
			'autoloadInclude' => '/opt/app-root/letswifi/simplesamlphp/src/_autoload.php',
			'authSource' => 'default-sp',
			// Both attributes are set by the filter in authsources.php, never by the IdP
			'userIdAttribute' => 'letswifiUser',
			'affiliationAttribute' => 'letswifiRealm',
		],
	],

	// Paths are relative to the config directory
	'pdo#inc' => 'database.conf.php',
	'clients#inc' => 'clients.conf.php',
	'oauthsecret#file' => 'oauthsecret.txt',

	'location' => [],
	'logo' => null,
];
