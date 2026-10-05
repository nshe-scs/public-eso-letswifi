<?php
// SimpleSAMLphp config for eso-letswifi. Seeded into vols/ssp-config on first start.
// Starts from the pristine config.php.dist shipped in the image (so upgrades pick up new
// defaults on rebuild), then applies our overrides.
require '/opt/app-root/defaults/ssp-dist/config.php.dist'; // defines $config

$config['secretsalt'] = trim(file_get_contents(__DIR__ . '/secretsalt.txt'));
$config['module.enable']['admin'] = false;            // no SSP admin UI
$config['auth.adminpassword'] = bin2hex(random_bytes(32));
// LETSWIFI_SUPPORT_NAME, LETSWIFI_SUPPORT_EMAIL and TZ from custom.env, written by start.sh
$site = require '/opt/app-root/etc/eso-letswifi-site.php';
$config['technicalcontact_name'] = $site['support_name'];
$config['technicalcontact_email'] = $site['support_email'];
$config['timezone'] = $site['timezone'];
$config['logging.handler'] = 'errorlog';              // no syslog socket in the container
$config['certdir'] = __DIR__ . '/cert/';              // SP key pair, generated on first start
$config['cachedir'] = '/tmp/simplesamlphp-cache';
$config['tempdir'] = '/tmp/simplesamlphp';
$config['session.cookie.secure'] = true;

// Sign-in errors are shown on eso-letswifi's own page, in the portal's look (lib/ssp-error.php)
require_once '/opt/app-root/eso-letswifi/lib/ssp-error.php';
$config['errors.show_function'] = ['EsoLetswifi\\SspErrorPage', 'show'];

// Institution IdP metadata: one XML file per IdP in ./metadata/ (no cron needed)
$config['metadata.sources'] = [];
// (Passed as a string because the 'file' option is broken in SimpleSAMLphp 2.4.10.)
foreach (glob(__DIR__ . '/metadata/*.xml') ?: [] as $file) {
    $config['metadata.sources'][] = ['type' => 'xml', 'xml' => file_get_contents($file)];
}
