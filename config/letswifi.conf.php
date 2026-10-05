<?php declare( strict_types=1 );

/*
 * Adapted from letswifi-portal (https://github.com/geteduroam/letswifi-portal)
 * Copyright: Jørn Åne de Jong <jorn.dejong@letswifi.eu>
 * Copyright: Paul Dekkers, SURF <paul.dekkers@surf.nl>
 * Modifications copyright (c) 2026, Nevada System of Higher Education
 * SPDX-License-Identifier: BSD-3-Clause
 */

// eso-letswifi main configuration. Seeded into vols/letswifi-config on first start.
// The single provider lives in providers/<LETSWIFI_HOSTNAME>.conf.php (seeded on first start);
// realms (one per institution) in realms/<realm>.conf.php (written by `letswifi realm create`).
return [
	'provider#dir' => 'providers/',
	'realm#dir' => 'realms/',

	'network' => [
		'eduroam' => [
			'display_name' => ['en-US' => 'eduroam'],
			'ssid' => 'eduroam',
			'oids' => ['5a03ba0800'],
		],
	],

	// Public helpdesk contacts, referenced by providers/realms (optional)
	'contact' => [],

	// A single locale hides the language chooser
	'locales' => ['en'],

	'branding#inc' => 'branding-eduroam.conf.php',
	'certificate#pemdir' => 'certs/',
];
