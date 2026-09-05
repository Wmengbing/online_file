<?php
namespace app\common;

use think\facade\Config;

class Jwt
{
    private static $current_user_id = null;
    private static $current_user = null;

    public static function generateToken($user_id, $username)
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = [
            'uid'  => $user_id,
            'name' => $username,
            'iat'  => time(),
            'exp'  => time() + Config::get('app.jwt_expire', 86400 * 7),
        ];
        
        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, Config::get('app.jwt_secret', 'your-secret-key'), true);
        $base64UrlSignature = self::base64UrlEncode($signature);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    public static function verifyToken($token)
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        
        list($header, $payload, $signature) = $parts;
        
        $expectedSignature = hash_hmac('sha256', $header . "." . $payload, Config::get('app.jwt_secret', 'your-secret-key'), true);
        $base64UrlExpectedSignature = self::base64UrlEncode($expectedSignature);
        
        if (!self::hashEquals($signature, $base64UrlExpectedSignature)) {
            return false;
        }
        
        $payload_data = json_decode(self::base64UrlDecode($payload), true);
        if (!$payload_data) {
            return false;
        }
        
        if (isset($payload_data['exp']) && $payload_data['exp'] < time()) {
            return false;
        }
        
        return $payload_data;
    }

    public static function getTokenFromRequest()
    {
        $header = request()->header('Authorization', '');
        if (preg_match('/Bearer\s(\S+)/', $header, $matches)) {
            return $matches[1];
        }
        
        return session('auth_token', '');
    }

    public static function authenticate()
    {
        $token = self::getTokenFromRequest();
        if (!$token) {
            return null;
        }
        
        $payload = self::verifyToken($token);
        if (!$payload) {
            return null;
        }
        
        self::$current_user_id = $payload['uid'];
        return $payload;
    }

    public static function login($user)
    {
        $token = self::generateToken($user['id'], $user['username']);
        session('auth_token', $token);
        session('user_id', $user['id']);
        session('username', $user['username']);
        self::$current_user_id = $user['id'];
        self::$current_user = $user;
        return $token;
    }

    public static function logout()
    {
        session('auth_token', null);
        session('user_id', null);
        session('username', null);
        self::$current_user_id = null;
        self::$current_user = null;
    }

    public static function getCurrentUserId()
    {
        if (self::$current_user_id) {
            return self::$current_user_id;
        }
        return session('user_id');
    }

    public static function getCurrentUser()
    {
        if (self::$current_user) {
            return self::$current_user;
        }
        
        $user_id = self::getCurrentUserId();
        if ($user_id) {
            self::$current_user = \think\Db::name('users')->where('id', $user_id)->find();
            return self::$current_user;
        }
        return null;
    }

    public static function setCurrentUserId($user_id)
    {
        self::$current_user_id = $user_id;
        session('user_id', $user_id);
    }

    public static function isLoggedIn()
    {
        return self::getCurrentUserId() !== null;
    }

    private static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data)
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    private static function hashEquals($a, $b)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($a, $b);
        }
        
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        
        $result = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $result |= ord($a[$i]) ^ ord($b[$i]);
        }
        return $result === 0;
    }
}