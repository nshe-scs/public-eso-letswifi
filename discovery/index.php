<?php declare( strict_types=1 );

/*
 * eso-letswifi institution picker, served at https://<LETSWIFI_HOSTNAME>/discovery/
 *
 * Replaces SimpleSAMLphp's own discovery page so the choice looks like the rest of the
 * portal. SimpleSAMLphp's SP (authsources.php 'discoURL') sends the browser here using the
 * SAML IdP Discovery Service Protocol, with:
 *   return         where to send the answer (must be this portal's /simplesaml/ path)
 *   returnIDParam  name of the parameter that carries the chosen IdP (default "entityID")
 *   isPassive      "true" = answer without showing the page
 * and this page sends the browser back with the chosen IdP's entityID.
 *
 * Only institutions that are fully set up are listed: an IdP needs both a metadata file in
 * vols/ssp-config/metadata/ and a line in vols/ssp-config/realm-by-idp.php.
 * One link per institution; no forms, no JavaScript and no inline styles, so it works under
 * the portal's Content-Security-Policy.
 */

require_once '/opt/app-root/eso-letswifi/lib/page.php';

use function EsoLetswifi\h;
use function EsoLetswifi\render_page;
use function EsoLetswifi\site;

const SSP_AUTOLOAD = '/opt/app-root/letswifi/simplesamlphp/src/_autoload.php';
const REALM_MAP = '/opt/app-root/letswifi/simplesamlphp/config/realm-by-idp.php';
const COOKIE_NAME = 'eso_letswifi_idp';

function fail( string $message ): never
{
	render_page( 400, 'Something went wrong', '<h1>Something went wrong</h1>
<p>' . h( $message ) . '</p>
<p><a href="/">Start again</a></p>' );
}

/** The return address must be this portal's own SimpleSAMLphp, over HTTPS: no open redirects. */
function valid_return( string $url, string $hostname ): bool
{
	$parts = \parse_url( $url );
	if ( !\is_array( $parts ) || '' === $hostname ) {
		return false;
	}

	return 'https' === ( $parts['scheme'] ?? '' )
		&& 0 === \strcasecmp( $parts['host'] ?? '', $hostname )
		&& \in_array( $parts['port'] ?? 443, [443], true )
		&& !isset( $parts['user'] ) && !isset( $parts['pass'] )
		&& \str_starts_with( $parts['path'] ?? '', '/simplesaml/' );
}

function send_back( string $return, string $param, ?string $entityId ): never
{
	if ( null !== $entityId ) {
		$return .= ( \str_contains( $return, '?' ) ? '&' : '?' ) . \rawurlencode( $param ) . '=' . \rawurlencode( $entityId );
	}
	\header( 'Cache-Control: no-store' );
	\header( 'Location: ' . $return, true, 302 );
	exit;
}

/** Display name from the IdP's metadata: mdui:DisplayName, then name, then OrganizationDisplayName. */
function display_name( array $metadata, string $entityId ): string
{
	foreach ( [$metadata['UIInfo']['DisplayName'] ?? null, $metadata['name'] ?? null, $metadata['OrganizationDisplayName'] ?? null] as $candidate ) {
		if ( \is_string( $candidate ) && '' !== \trim( $candidate ) ) {
			return \trim( $candidate );
		}
		if ( \is_array( $candidate ) && [] !== $candidate ) {
			foreach ( ['en-US', 'en', 'en-GB'] as $lang ) {
				if ( \is_string( $candidate[$lang] ?? null ) && '' !== \trim( $candidate[$lang] ) ) {
					return \trim( $candidate[$lang] );
				}
			}
			$first = \reset( $candidate );
			if ( \is_string( $first ) && '' !== \trim( $first ) ) {
				return \trim( $first );
			}
		}
	}

	return $entityId;
}

/** @return array<string,string> entityID => display name, sorted by name */
function institutions(): array
{
	require_once SSP_AUTOLOAD;
	$realmByIdp = require REALM_MAP;
	$handler = \SimpleSAML\Metadata\MetaDataStorageHandler::getMetadataHandler();
	$list = [];
	foreach ( $handler->getList( 'saml20-idp-remote' ) as $entityId => $metadata ) {
		if ( \is_array( $realmByIdp ) && isset( $realmByIdp[$entityId] ) ) {
			$list[(string) $entityId] = display_name( $metadata, (string) $entityId );
		}
	}
	\natcasesort( $list );

	return $list;
}

// --- Request handling ------------------------------------------------------------------

$hostname = (string) ( site()['hostname'] ?? '' );

$return = $_GET['return'] ?? null;
$param = $_GET['returnIDParam'] ?? 'entityID';
if ( !\is_string( $return ) || !\is_string( $param ) || !valid_return( $return, $hostname ) ) {
	fail( 'This page can only be reached from the portal\'s login. Please start again from the portal.' );
}
if ( !\preg_match( '/^[A-Za-z0-9_.-]{1,64}$/', $param ) ) {
	fail( 'The login request was malformed. Please start again from the portal.' );
}

try {
	$institutions = institutions();
} catch ( \Throwable $e ) {
	\error_log( 'eso-letswifi discovery: ' . $e->getMessage() );
	fail( 'The list of institutions is unavailable right now. Please try again later.' );
}

$remembered = $_COOKIE[COOKIE_NAME] ?? null;
$remembered = \is_string( $remembered ) && isset( $institutions[$remembered] ) ? $remembered : null;

// A choice was submitted: accept it only if it is one of the listed institutions
$choice = $_GET['idp'] ?? null;
if ( \is_string( $choice ) && isset( $institutions[$choice] ) ) {
	\setcookie( COOKIE_NAME, $choice, [
		'expires' => \time() + 365 * 86400,
		'path' => '/discovery/',
		'secure' => true,
		'httponly' => true,
		'samesite' => 'Lax',
	] );
	send_back( $return, $param, $choice );
}

// Nothing to choose between, or the SP asked for an answer without user interaction
if ( 1 === \count( $institutions ) ) {
	send_back( $return, $param, (string) \array_key_first( $institutions ) );
}
if ( 'true' === ( $_GET['isPassive'] ?? '' ) ) {
	send_back( $return, $param, $remembered );
}
if ( [] === $institutions ) {
	render_page( 503, 'No institutions yet', '<h1>No institutions yet</h1>
<p>No institutions have been set up on this portal yet.</p>' );
}

// One link per institution. Links, not a form: the portal's Content-Security-Policy has
// form-action 'self', and browsers apply that to every redirect after a form submission,
// so a form would be blocked when SimpleSAMLphp redirects on to the institution's IdP.
$items = [];
foreach ( $institutions as $entityId => $name ) {
	$href = '/discovery/?' . \http_build_query( ['return' => $return, 'returnIDParam' => $param, 'idp' => $entityId] );
	$lastUsed = $entityId === $remembered ? ' <small>Last used</small>' : '';
	$items[] = '<li><a href="' . h( $href ) . '"><strong>' . h( $name ) . '</strong>' . $lastUsed . '</a></li>';
}

render_page( 200, 'Choose your institution', '<h1>Choose your institution</h1>
<ul class="institutions">
' . \implode( "\n", $items ) . '
</ul>' );
