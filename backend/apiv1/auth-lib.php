<?php
// Bibliotheque JWT partagee (HS256, sans dependance externe).

// Le secret JWT n'est plus ecrit dans le code : il est lu dans
// ../config.local.php (hors du dossier public apiv1, non versionne).
// Pour conserver les sessions en cours lors du deploiement, y reporter
// la valeur actuellement en production.
$__shippLocalConfig = @include __DIR__ . '/../config.local.php';
if (!is_array($__shippLocalConfig) || empty($__shippLocalConfig['jwt_secret'])) {
http_response_code(500);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['message' => 'Configuration de securite manquante']);
exit;
}
define('JWT_SECRET', $__shippLocalConfig['jwt_secret']);
unset($__shippLocalConfig);

function base64url_encode($data) {
return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
$pad = strlen($data) % 4;
if ($pad) {
$data .= str_repeat('=', 4 - $pad);
}
return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_encode($payload) {
$header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
$body = base64url_encode(json_encode($payload));
$signature = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
return "$header.$body.$signature";
}

function jwt_decode_token($token) {
$parts = explode('.', $token);
if (count($parts) !== 3) {
return null;
}
[$header, $body, $signature] = $parts;
$expected = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
if (!hash_equals($expected, $signature)) {
return null;
}
$payload = json_decode(base64url_decode($body), true);
if (!$payload || (isset($payload['exp']) && $payload['exp'] < time())) {
return null;
}
return $payload;
}

function get_bearer_token() {
$headers = function_exists('getallheaders') ? getallheaders() : [];
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/Bearer\\s+(.*)$/i', $auth, $matches)) {
return $matches[1];
}
return null;
}

function require_auth() {
$token = get_bearer_token();
$payload = $token ? jwt_decode_token($token) : null;
if (!$payload) {
http_response_code(401);
echo json_encode(['message' => 'Authentification requise']);
exit;
}
return $payload;
}
