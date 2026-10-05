<?php declare( strict_types=1 );

/*
 * Sign-in error page in the portal's look, replacing SimpleSAMLphp's own error page.
 * Wired up in vols/ssp-config/config.php:
 *   $config['errors.show_function'] = ['EsoLetswifi\SspErrorPage', 'show'];
 *
 * Users see one plain-language message and, for unexpected errors, a reference code
 * (SimpleSAMLphp's track ID) to quote to support. Technical details stay in the container log,
 * where SimpleSAMLphp has already written them together with the same track ID.
 */

namespace EsoLetswifi;

require_once __DIR__ . '/page.php';

final class SspErrorPage
{
	/** Errors from an interrupted or stale sign-in: Back button, reload, expired session */
	private const INTERRUPTED = ['ACSPARAMS', 'BADREQUEST', 'DISCOPARAMS', 'METHODNOTALLOWED', 'NORELAYSTATE', 'NOSTATE', 'SSOPARAMS'];

	/** The institution's login service turned the sign-in down */
	private const REFUSED_BY_IDP = ['RESPONSESTATUSNOSUCCESS', 'NOACCESS'];

	/** The user cancelled at their institution's login page */
	private const CANCELLED = ['USERABORTED'];

	/**
	 * Called by SimpleSAMLphp instead of drawing its own page. Sets its own HTTP status, because
	 * SimpleSAMLphp's isn't always kept on every error path.
	 *
	 * @param array<string,mixed> $data errorCode, error (with trackId), and more
	 */
	public static function show( \SimpleSAML\Configuration $config, array $data ): never
	{
		$code = \is_string( $data['errorCode'] ?? null ) ? $data['errorCode'] : '';
		$trackId = \is_string( $data['error']['trackId'] ?? null ) ? $data['error']['trackId'] : '';
		$again = '<p><a class="btn btn-default" href="/">Start again</a></p>';

		if ( \in_array( $code, self::INTERRUPTED, true ) ) {
			render_page( 400, 'Sign-in interrupted', '<h1>Sign-in interrupted</h1>
<p>Your sign-in was interrupted or has expired. This can happen after using the Back button,
reloading a page, or leaving a page open for a long time.</p>
' . $again );
		}

		if ( \in_array( $code, self::CANCELLED, true ) ) {
			render_page( 400, 'Sign-in cancelled', '<h1>Sign-in cancelled</h1>
<p>You cancelled signing in at your institution.</p>
' . $again );
		}

		$support = self::supportLine( $trackId );
		if ( \in_array( $code, self::REFUSED_BY_IDP, true ) ) {
			render_page( 403, 'Sign-in not allowed', '<h1>Sign-in not allowed</h1>
<p>Your institution did not allow this sign-in. If you believe you should have access to eduroam,
contact your institution\'s IT help desk.</p>
' . $support . $again );
		}

		render_page( 500, 'Something went wrong', '<h1>Something went wrong</h1>
<p>Something went wrong while signing you in.</p>
' . $support . $again );
	}

	private static function supportLine( string $trackId ): string
	{
		$site = site();
		$email = \is_string( $site['support_email'] ?? null ) ? $site['support_email'] : '';
		$reference = '' !== $trackId ? ' and mention reference <strong>' . h( $trackId ) . '</strong>' : '';
		if ( '' === $email || 'na@example.org' === $email ) {
			return '' !== $trackId ? '<p>Reference: <strong>' . h( $trackId ) . '</strong></p>' . "\n" : '';
		}

		return '<p>If this keeps happening, contact <a href="mailto:' . h( $email ) . '">' . h( $email ) . '</a>'
			. $reference . '.</p>' . "\n";
	}
}
