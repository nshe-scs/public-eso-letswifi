<?php declare( strict_types=1 );

/*
 * Replaces the intro paragraph on letswifi's front page (template/start.twig) with eso-letswifi's
 * wording and a "What is eduroam?" section linking to LETSWIFI_INFO_URL (https://eduroam.org if empty).
 * Run by start.sh on every start, so a changed LETSWIFI_INFO_URL applies on restart.
 * Remove once letswifi makes this text configurable.
 */

$file = '/opt/app-root/letswifi/template/start.twig';
$url = \getenv( 'LETSWIFI_INFO_URL' ) ?: 'https://eduroam.org';
if ( !\preg_match( '#^https?://[^\s\'"<>{}]+$#', $url ) ) {
	\fwrite( \STDERR, "LETSWIFI_INFO_URL \"{$url}\" must be an http:// or https:// URL\n" );
	exit( 1 );
}

// The upstream line this replaces (letswifi v2.0.0-beta7)
$upstream = "\t<p>{{ 'To use %1\$s at %2\$s, download the app or profile for your device below.'|t(_network_name, provider.display_name) }}\n";

$ours = "\t{# eso-letswifi intro: see lib/front-page.php #}\n"
	. "\t{% set eso_info_url = '" . \addcslashes( $url, "\\'" ) . "' %}\n"
	. "\t<p>To connect your device to the eduroam Wi-Fi network for the first time, follow the instructions below."
	. " You'll be prompted to sign in via your organization's single sign-on page during the setup process."
	. " Then you'll be able to connect to eduroam automatically without a password.</p>\n"
	. "\t<details>\n"
	. "\t\t<summary>What is eduroam?</summary>\n"
	. "\t\t<p>eduroam is the secure, global roaming access service developed by and for the international research"
	. " and education community, available at tens of thousands of locations around the world."
	. " Learn more at <a href=\"{{ eso_info_url }}\">{{ eso_info_url }}</a>.</p>\n"
	. "\t</details>\n"
	. "\t{# /eso-letswifi intro #}\n";

$text = \file_get_contents( $file );
$block = '/\t\{# eso-letswifi intro:.*?\{# \/eso-letswifi intro #\}\n/s';
if ( \preg_match( $block, $text ) ) {
	$text = \preg_replace_callback( $block, static fn(): string => $ours, $text, 1 );
} elseif ( \str_contains( $text, $upstream ) ) {
	$text = \str_replace( $upstream, $ours, $text );
} else {
	\fwrite( \STDERR, "*** WARNING: {$file} no longer has the intro paragraph lib/front-page.php replaces, so the "
		. "front page shows letswifi's own wording. Update lib/front-page.php for this letswifi version. ***\n" );
	exit( 0 );
}
\file_put_contents( $file, $text );
