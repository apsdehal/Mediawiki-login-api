<?php
 
if ( PHP_SAPI !== 'cli' ) {
	die( "CLI-only test script\n" );
}
 
/**
 * A basic client for overall testing
 */
 
function wfDebugLog( $method, $msg) {
	echo "[$method] $msg\n";
}
 
 
require '../../lib/OAuth/OAuth.php';

$consumerKey = "4447178d8faff1bb8d975db009c5c000";
$consumerSecret = "63f058c394a9b5170ec1bd64e8caaa58b75c8951";
$baseurl = 'https://www.mediawiki.org/w/index.php?title=Special:OAuth';
$endpoint = $baseurl . '/initiate&format=json&oauth_callback=oob';
 
$endpoint_acc = $baseurl . '/token&format=json';
 
$c = new OAuthConsumer( $consumerKey, $consumerSecret );
$parsed = parse_url( $endpoint );
$params = array();
parse_str($parsed['query'], $params);
$req_req = OAuthRequest::from_consumer_and_token($c, NULL, "GET", $endpoint, $params);
$hmac_method = new OAuthSignatureMethod_HMAC_SHA1();
$sig_method = $hmac_method;
$req_req->sign_request($sig_method, $c, NULL);
 
echo "Calling: $req_req\n";
 
$ch = curl_init();
curl_setopt( $ch, CURLOPT_URL, (string) $req_req );
curl_setopt( $ch, CURLOPT_PORT , 443 );
curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
curl_setopt( $ch, CURLOPT_HEADER, 0 );
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
$data = curl_exec( $ch );
 
echo $data;
 
if( !$data ) {
	'Curl error: ' . curl_error( $ch );
}
 
echo "Returned: $data\n\n";