<?php
/**
 * Unit tests of the plugin's pure parts, without WordPress:
 *   docker run --rm -v "$PWD/wordpress:/w:ro" php:7.4-cli php /w/tests/unit.php
 * (and php:8.4-cli). Covers: the CSP merge keeps a site's policy (QA: the
 * plugin replaced it), the match code is Kico's, signatures of version 1 and
 * 2 (with @authority), update signatures (a tampered release does not
 * verify), and what response headers say about caches in front of
 * WordPress. The WordPress side is in wordpress/qa/security.ts and
 * snapshots.ts.
 */

define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'KICO_WP_VERSION', '0.1.0' );
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function wp_unslash( $v ) {
	return $v;
}
// Options in memory, for the key store.
$GLOBALS['kico_test_options'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['kico_test_options'] ) ? $GLOBALS['kico_test_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['kico_test_options'][ $name ] = $value;
	return true;
}

$dir = dirname( __DIR__ ) . '/kico-for-wordpress/includes/';
require $dir . 'class-kico-signature.php';
require $dir . 'class-kico-keys.php';
require $dir . 'class-kico-frame.php';
require $dir . 'class-kico-updates.php';
require $dir . 'class-kico-cache.php';

$failed = 0;
function check( $name, $ok ) {
	global $failed;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n";
	if ( ! $ok ) {
		$failed++;
	}
}

// --- CSP ------------------------------------------------------------------------------------

$k = array( 'https://kico.dev' );
check( 'no CSP: a frame-ancestors policy is added', array( "frame-ancestors 'self' https://kico.dev" ) === Kico_Frame::merge( array(), $k ) );
check(
	'a CSP without frame-ancestors is kept as it was, and one is added next to it',
	array( "default-src 'self'; script-src 'self' https://cdn.example; report-uri /csp", "frame-ancestors 'self' https://kico.dev" )
		=== Kico_Frame::merge( array( "default-src 'self'; script-src 'self' https://cdn.example; report-uri /csp" ), $k )
);
check(
	'a CSP with frame-ancestors gets Kico added, every other directive kept',
	array( "default-src 'self'; frame-ancestors 'self' https://agency.example https://kico.dev; img-src *" )
		=== Kico_Frame::merge( array( "default-src 'self'; frame-ancestors 'self' https://agency.example; img-src *" ), $k )
);
check( "'none' gives way to Kico", array( 'frame-ancestors https://kico.dev' ) === Kico_Frame::merge( array( "frame-ancestors 'none'" ), $k ) );
check( 'Kico already allowed: unchanged', array( "frame-ancestors 'self' https://kico.dev" ) === Kico_Frame::merge( array( "frame-ancestors 'self' https://kico.dev" ), $k ) );
check(
	'every policy with frame-ancestors gets it (several headers, several policies in one)',
	array( "frame-ancestors 'self' https://kico.dev, frame-ancestors https://a.example https://kico.dev", "default-src 'self'" )
		=== Kico_Frame::merge( array( "frame-ancestors 'self', frame-ancestors https://a.example", "default-src 'self'" ), $k )
);
check( 'origins are normalized; anything else is dropped', 'https://kico.dev:8443' === Kico_Frame::origin( 'HTTPS://Kico.dev:8443/x' ) && '' === Kico_Frame::origin( "https://k.dev; script-src *" ) && '' === Kico_Frame::origin( 'javascript:alert(1)' ) );

// --- Kico moved (kico.dev to app.kico.dev): the key's origin, and the old one for a while ----------

$t0 = 1700000000;
update_option(
	Kico_Keys::OPTION,
	array(
		'k1' => array( 'public_key' => 'p1', 'user_id' => 1, 'kico_origin' => 'https://kico.dev' ),
		'k2' => array( 'public_key' => 'p2', 'user_id' => 1, 'kico_origin' => 'https://kico.acme.example' ),
	)
);
check( 'before the move: each key\'s origin', array( 'https://kico.dev', 'https://kico.acme.example' ) === Kico_Keys::kico_origins( $t0 ) );
$k = Kico_Keys::set_origin( 'k1', 'https://app.kico.dev', Kico_Keys::PREVIOUS_DAYS, $t0 );
check( 'moved: the key names the new origin', 'https://app.kico.dev' === $k['kico_origin'] && 'https://app.kico.dev' === Kico_Keys::get( 'k1' )['kico_origin'] );
check( 'moved: the old origin still frames for 90 days', array( 'https://kico.dev' => $t0 + 90 * DAY_IN_SECONDS ) === Kico_Keys::previous_origins( $k, $t0 ) );
check( 'during the transition both origins frame the site, and the other key\'s', array( 'https://app.kico.dev', 'https://kico.dev', 'https://kico.acme.example' ) === Kico_Keys::kico_origins( $t0 + DAY_IN_SECONDS ) );
check( 'after 90 days only the new one', array( 'https://app.kico.dev', 'https://kico.acme.example' ) === Kico_Keys::kico_origins( $t0 + 90 * DAY_IN_SECONDS + 1 ) );
check( 'the other key is untouched', 'https://kico.acme.example' === Kico_Keys::get( 'k2' )['kico_origin'] && ! isset( Kico_Keys::get( 'k2' )['kico_origins_previous'] ) );
check( 'the same Kico, at either origin, during the transition', Kico_Keys::belongs_to( $k, 'https://app.kico.dev', $t0 ) && Kico_Keys::belongs_to( $k, 'https://kico.dev', $t0 ) && ! Kico_Keys::belongs_to( $k, 'https://kico.dev', $t0 + 91 * DAY_IN_SECONDS ) && ! Kico_Keys::belongs_to( $k, 'https://evil.example', $t0 ) && ! Kico_Keys::belongs_to( $k, '', $t0 ) );
$k = Kico_Keys::set_origin( 'k1', 'https://app.kico.dev', Kico_Keys::PREVIOUS_DAYS, $t0 + DAY_IN_SECONDS );
check( 'told again: nothing changes, the old origin keeps its end', array( 'https://kico.dev' => $t0 + 90 * DAY_IN_SECONDS ) === Kico_Keys::previous_origins( $k, $t0 + DAY_IN_SECONDS ) );
$k = Kico_Keys::set_origin( 'k1', 'https://kico.dev', Kico_Keys::PREVIOUS_DAYS, $t0 + 2 * DAY_IN_SECONDS );
check( 'moved back: the old one is current again, the other one counts for a while', 'https://kico.dev' === $k['kico_origin'] && array( 'https://app.kico.dev' => $t0 + 92 * DAY_IN_SECONDS ) === Kico_Keys::previous_origins( $k, $t0 + 2 * DAY_IN_SECONDS ) );
$k = Kico_Keys::set_origin( 'k1', 'https://app.kico.dev', 0, $t0 + 3 * DAY_IN_SECONDS );
check( 'previous_days 0 ends the transition at once', 'https://app.kico.dev' === $k['kico_origin'] && ! isset( $k['kico_origins_previous'] ) && array( 'https://app.kico.dev', 'https://kico.acme.example' ) === Kico_Keys::kico_origins( $t0 + 3 * DAY_IN_SECONDS ) );
check( 'no such key: nothing', null === Kico_Keys::set_origin( 'nope', 'https://app.kico.dev' ) && null === Kico_Keys::set_origin( 'k1', '' ) );
check( 'the frame policy names both origins during the transition', array( "frame-ancestors 'self' https://app.kico.dev https://kico.dev" ) === Kico_Frame::merge( array(), array( 'https://app.kico.dev', 'https://kico.dev' ) ) );
update_option( Kico_Keys::OPTION, array() );

// --- match code: the same as Kico's (server/wordpress-pairing.ts) -----------------------------

// The same vector is in server/test/wordpress.test.ts.
check( 'match code equals Kico\'s', 'P6H-BZJ' === Kico_Keys::match_code( 'pair-code-0123456789abcdef' ) );

// --- signatures --------------------------------------------------------------------------------

$kp  = sodium_crypto_sign_keypair();
$pub = base64_encode( sodium_crypto_sign_publickey( $kp ) );
$sec = sodium_crypto_sign_secretkey( $kp );
function signed( $sec, $components, $authority, $path, $body ) {
	$params = '(' . implode( ' ', array_map( function ( $c ) { return '"' . $c . '"'; }, $components ) ) . ');created=' . time() . ';nonce="nonce-0123456789abcdef";keyid="kTest1234";alg="ed25519"';
	$digest = Kico_Signature::digest( $body );
	$base   = Kico_Signature::base( 'POST', $path, '?', $digest, $params, $authority );
	return array(
		'signature-input' => 'kico=' . $params,
		'signature'       => 'kico=:' . base64_encode( sodium_crypto_sign_detached( $base, $sec ) ) . ':',
		'content-digest'  => $digest,
	);
}
$keyf = function ( $id ) use ( $pub ) {
	return 'kTest1234' === $id ? $pub : null;
};
$body = '{"jsonrpc":"2.0"}';
$req  = array( 'method' => 'POST', 'path' => '/wp-json/kico/v1/mcp', 'query' => '?', 'body' => $body, 'authorities' => array( 'shop.example:8443' ) );
check( 'version 1 verifies', Kico_Signature::verify( $req + array( 'headers' => signed( $sec, Kico_Signature::COMPONENTS, null, '/wp-json/kico/v1/mcp', $body ) ), $keyf )['ok'] );
$h2 = signed( $sec, Kico_Signature::COMPONENTS_V2, 'shop.example:8443', '/wp-json/kico/v1/mcp', $body );
check( 'version 2 verifies at the host it was sent to', Kico_Signature::verify( $req + array( 'headers' => $h2 ), $keyf )['ok'] );
check( 'version 2 verifies through a proxy that keeps X-Forwarded-Host', Kico_Signature::verify( array( 'authorities' => array( 'backend:8080', 'shop.example:8443' ) ) + $req + array( 'headers' => $h2 ), $keyf )['ok'] );
$other = Kico_Signature::verify( array( 'authorities' => array( 'other.example' ) ) + $req + array( 'headers' => $h2 ), $keyf );
check( 'version 2 at another host: refused, as a known key (401 kico_signature)', ! $other['ok'] && 'signature' === $other['reason'] && 'kTest1234' === $other['keyid'] );
$moved = Kico_Signature::verify( array( 'path' => '/index.php/wp-json/kico/v1/mcp' ) + $req + array( 'headers' => signed( $sec, Kico_Signature::COMPONENTS, null, '/wp-json/kico/v1/mcp', $body ) ), $keyf );
check( 'a rewritten path is a known key\'s signature failure', ! $moved['ok'] && 'signature' === $moved['reason'] );
$unknown = Kico_Signature::verify( $req + array( 'headers' => $h2 ), function () { return null; } );
check( 'an unknown key is just unknown (404)', ! $unknown['ok'] && 'unknown-key' === $unknown['reason'] && ! isset( $unknown['keyid'] ) );

// --- update signatures ------------------------------------------------------------------------

$zip = random_bytes( 2048 );
$sha = hash( 'sha256', $zip );
$sig = base64_encode( sodium_crypto_sign_detached( Kico_Updates::message( '0.2.0', $sha ), $sec ) );
define( 'KICO_UPDATE_PUBLIC_KEY', $pub );
check( 'a release signed by a pinned key verifies', Kico_Updates::verify( '0.2.0', $sha, $sig ) );
check( 'a tampered package (other hash) does not', ! Kico_Updates::verify( '0.2.0', hash( 'sha256', $zip . 'x' ), $sig ) );
check( 'an old signed package offered as a newer version does not', ! Kico_Updates::verify( '0.3.0', $sha, $sig ) );
$other_kp = sodium_crypto_sign_keypair();
check( 'a release signed by another key does not', ! Kico_Updates::verify( '0.2.0', $sha, base64_encode( sodium_crypto_sign_detached( Kico_Updates::message( '0.2.0', $sha ), sodium_crypto_sign_secretkey( $other_kp ) ) ) ) );
check( 'packages resolve against the manifest', 'https://k.example/downloads/kico-for-wordpress.zip' === Kico_Updates::resolve( 'kico-for-wordpress.zip', 'https://k.example/downloads/kico-for-wordpress.json' ) && 'http://h:3000/x.zip' === Kico_Updates::resolve( '/x.zip', 'http://h:3000/downloads/m.json' ) );


// --- caches in front of WordPress: what a visitor's response headers say ---------------------------

$rh    = function ( array $h ) {
	return Kico_Cache::read_headers( $h );
};
$kinds = function ( array $r ) {
	return array_column( $r['caches'], 'kind' );
};
// nocutshop.com (HestiaCP template with add_header X-Cache $upstream_cache_status), as a visitor got it.
$hestia = $rh( array( 'Server' => 'nginx', 'Vary' => array( 'Accept-Encoding', 'Sec-Fetch-Dest,Accept-Encoding' ), 'X-Cache' => 'HIT', 'Content-Type' => 'text/html; charset=UTF-8' ) );
check( 'Hestia X-Cache: HIT is an nginx proxy cache that served the page', array( 'nginx' ) === $kinds( $hestia ) && 'nginx proxy cache' === $hestia['caches'][0]['cache'] && true === $hestia['from_cache'] && 'X-Cache: HIT' === $hestia['caches'][0]['evidence'] );
check( 'Vary names are read once each, lower-case', array( 'accept-encoding', 'sec-fetch-dest' ) === $hestia['vary'] );
foreach ( array( 'MISS', 'BYPASS', 'EXPIRED' ) as $st ) {
	$r = $rh( array( 'server' => 'nginx', 'x-cache' => $st ) );
	check( "X-Cache: $st is a cache that did not serve this copy", array( 'nginx' ) === $kinds( $r ) && false === $r['from_cache'] );
}
foreach ( array( 'STALE', 'UPDATING', 'REVALIDATED' ) as $st ) {
	check( "X-Cache-Status: $st was served from the cache", true === $rh( array( 'x-cache-status' => $st, 'server' => 'nginx' ) )['from_cache'] );
}
$r = $rh( array( 'X-Proxy-Cache' => 'HIT', 'Server' => 'Apache' ) );
check( 'X-Proxy-Cache behind another server: a proxy cache, hit', 'a proxy cache' === $r['caches'][0]['cache'] && true === $r['from_cache'] );
check( 'X-Nginx-Cache', 'nginx proxy cache' === $rh( array( 'X-Nginx-Cache' => 'MISS' ) )['caches'][0]['cache'] );
$r = $rh( array( 'cf-cache-status' => 'HIT', 'cf-ray' => '8a1-ZAG', 'age' => '120' ) );
check( 'Cloudflare HIT with Age', array( 'cloudflare' ) === $kinds( $r ) && true === $r['from_cache'] && 120 === $r['age'] );
$r = $rh( array( 'cf-cache-status' => 'DYNAMIC', 'cf-ray' => '8a1-ZAG' ) );
check( 'Cloudflare DYNAMIC: proxied, the page not cached', array( 'cloudflare' ) === $kinds( $r ) && false === $r['from_cache'] );
$r = $rh( array( 'cf-ray' => '8a1-ZAG' ) );
check( 'cf-ray alone: Cloudflare, nothing cached', array( 'cloudflare' ) === $kinds( $r ) && false === $r['caches'][0]['hit'] );
$r = $rh( array( 'X-Varnish' => '32770 3', 'Via' => '1.1 varnish (Varnish/7.5)', 'Age' => '40' ) );
check( 'Varnish with two ids is a hit', array( 'varnish' ) === $kinds( $r ) && true === $r['caches'][0]['hit'] && true === $r['from_cache'] );
$r = $rh( array( 'X-Varnish' => '32770', 'Age' => '0', 'X-Cache' => 'MISS' ) );
check( 'Varnish with one id is a miss; its X-Cache joins it', array( 'varnish' ) === $kinds( $r ) && false === $r['from_cache'] && false !== strpos( $r['caches'][0]['evidence'], 'X-Cache: MISS' ) );
check( 'Via: varnish alone is Varnish', array( 'varnish' ) === $kinds( $rh( array( 'via' => '1.1 varnish' ) ) ) );
$r = $rh( array( 'X-LiteSpeed-Cache' => 'hit', 'Server' => 'LiteSpeed' ) );
check( 'X-LiteSpeed-Cache: hit', array( 'litespeed' ) === $kinds( $r ) && true === $r['from_cache'] );
check( 'X-LiteSpeed-Cache: miss', false === $rh( array( 'X-LiteSpeed-Cache' => 'miss' ) )['from_cache'] );
$r = $rh( array( 'X-Cache' => 'Hit from cloudfront', 'Via' => '1.1 abc.cloudfront.net (CloudFront)' ) );
check( 'CloudFront', 'CloudFront' === $r['caches'][0]['cache'] && true === $r['from_cache'] );
$r = $rh( array( 'X-Cache' => 'MISS, HIT', 'X-Served-By' => 'cache-fra1, cache-fra2' ) );
check( 'Fastly: a hit at the edge', 'Fastly' === $r['caches'][0]['cache'] && true === $r['from_cache'] );
$r = $rh( array( 'Age' => '33' ) );
check( 'Age alone: a shared cache served it', array( 'proxy' ) === $kinds( $r ) && true === $r['from_cache'] && 33 === $r['age'] );
$r = $rh( array( 'Via' => '1.1 google' ) );
check( 'Via alone: a proxy, unknown whether it cached', array( 'proxy' ) === $kinds( $r ) && null === $r['from_cache'] );
$r = $rh( array( 'Server' => 'Apache', 'Cache-Control' => 'no-cache, must-revalidate, max-age=0', 'Content-Type' => 'text/html' ) );
check( 'no cache headers: nothing seen, unknown', array() === $r['caches'] && null === $r['from_cache'] && null === $r['ttl'] && null === $r['age'] );
check( 'TTL from s-maxage', 600 === $rh( array( 'cache-control' => 'public, max-age=60, s-maxage=600' ) )['ttl'] );
check( 'TTL from a public max-age', 300 === $rh( array( 'cache-control' => 'public, max-age=300' ) )['ttl'] );
check( 'no TTL from a private max-age', null === $rh( array( 'cache-control' => 'private, max-age=300' ) )['ttl'] );
check( 'only cache-related headers are kept', array( 'server' => 'nginx', 'x-cache' => 'HIT' ) === $rh( array( 'Server' => 'nginx', 'X-Cache' => 'HIT', 'Set-Cookie' => 'a=b', 'Link' => '<x>' ) )['headers'] );

// The visitor copies a cache that follows Vary keeps: each is checked after a refresh.
$v = Kico_Cache::variants( array( 'accept-encoding', 'sec-fetch-dest' ) );
check( 'Vary Accept-Encoding and Sec-Fetch-Dest: 9 copies, a browser\'s page first', 9 === count( $v ) && 'gzip, deflate, br, zstd' === $v[0]['Accept-Encoding'] && 'document' === $v[0]['Sec-Fetch-Dest'] && false !== strpos( $v[0]['User-Agent'], 'Chrome/' ) );
check( 'the copies cover frames, Kico\'s preview (gzip, deflate, no Sec-Fetch-Dest) and Safari', in_array( 'iframe', array_column( $v, 'Sec-Fetch-Dest' ), true ) && count( array_filter( $v, function ( $h ) { return ! isset( $h['Sec-Fetch-Dest'] ) && 'gzip, deflate' === $h['Accept-Encoding']; } ) ) === 1 && in_array( 'gzip, deflate, br', array_column( $v, 'Accept-Encoding' ), true ) );
check( 'no Vary: one copy', 1 === count( Kico_Cache::variants( array() ) ) );
check( 'Vary Accept-Encoding: 3 copies', 3 === count( Kico_Cache::variants( array( 'Accept-Encoding' ) ) ) );
check( 'no visitor copy carries a cookie or a query', ! array_filter( $v, function ( $h ) { return isset( $h['Cookie'] ); } ) );
check( 'HestiaCP is known by its paths', Kico_Cache::hestia( '/home/user/web/shop.example/public_html/' ) && Kico_Cache::hestia( '/home/u/web/a.example/public_shtml/' ) && ! Kico_Cache::hestia( '/var/www/html/' ) && ! Kico_Cache::hestia( '/home/u/public_html/' ) );
check( 'the refresh asks with HestiaCP\'s X-Update', '1' === Kico_Cache::REFRESH['X-Update'] );

echo $failed ? "$failed failed\n" : "all passed\n";
exit( $failed ? 1 : 0 );
