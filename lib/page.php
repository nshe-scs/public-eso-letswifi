<?php declare( strict_types=1 );

/*
 * Shared layout for eso-letswifi's own pages (the institution picker and the sign-in error
 * page), so they look like the rest of the portal: same stylesheets, header and structure as
 * letswifi's own dialog pages. No JavaScript and no inline styles, so the pages work under the
 * portal's Content-Security-Policy.
 */

namespace EsoLetswifi;

const SITE_SETTINGS = '/opt/app-root/etc/eso-letswifi-site.php';
const LETSWIFI_CONFIG = '/opt/app-root/letswifi/config/letswifi.conf.php';

function h( string $text ): string
{
	return \htmlspecialchars( $text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8' );
}

/** Site settings written by start.sh from custom.env (hostname, support contact, ...). */
function site(): array
{
	$site = @include SITE_SETTINGS;

	return \is_array( $site ) ? $site : [];
}

/** The portal's branding (product name, stylesheet, favicon), read from letswifi's own config. */
function branding(): array
{
	$branding = [
		'product_name' => 'geteduroam',
		'product_shortname' => 'geteduroam',
		'css_file' => 'geteduroam.css',
		'favicon_file' => 'geteduroam.ico',
	];
	try {
		$config = require LETSWIFI_CONFIG;
		$include = $config['branding#inc'] ?? null;
		if ( \is_string( $include ) && \preg_match( '/^[A-Za-z0-9._-]+$/', $include ) ) {
			$loaded = require \dirname( LETSWIFI_CONFIG ) . '/' . $include;
			if ( \is_array( $loaded ) ) {
				$branding = \array_merge( $branding, $loaded );
			}
		}
	} catch ( \Throwable ) {
		// Keep the defaults
	}

	return $branding;
}

/**
 * Send a complete page in the portal's look and stop.
 *
 * @param ?int   $status HTTP status to send, or null to keep one already set
 * @param string $body   HTML for inside the page's highlighted box (already escaped)
 */
function render_page( ?int $status, string $title, string $body ): never
{
	$b = branding();
	$asset = '/assets/branding/' . \rawurlencode( (string) $b['product_shortname'] ) . '/';
	if ( null !== $status ) {
		\http_response_code( $status );
	}
	\header( 'Content-Type: text/html; charset=utf-8' );
	\header( 'Cache-Control: no-store' );
	echo '<!DOCTYPE html>
<html lang="en" class="dialog">
<link rel="stylesheet" href="/assets/letswifi.css">
<link rel="stylesheet" href="' . h( $asset . \rawurlencode( (string) $b['css_file'] ) ) . '">
<link rel="stylesheet" href="/discovery/discovery.css">
<link rel="icon" href="' . h( $asset . \rawurlencode( (string) $b['favicon_file'] ) ) . '">
<meta name="viewport" content="width=device-width">
<title>' . h( (string) $b['product_name'] ) . ' - ' . h( $title ) . '</title>
<header>
	<span id="product-name" aria-hidden>' . h( (string) $b['product_name'] ) . '</span>
	<div id="systray"></div>
</header>
<main>
<section class="highlight">
' . $body . '
</section>
</main>
';
	exit;
}
