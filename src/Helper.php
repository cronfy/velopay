<?php
/**
 * Created by PhpStorm.
 * User: cronfy
 * Date: 01.11.17
 * Time: 14:26
 */

namespace cronfy\velopay;

class Helper
{
    public static function redirect($config) {
        $trim_default = " \t\n\r\0\x0B";

        $url = trim($config['url'], $trim_default . '/');
        $method = @$config['method'] ?: 'GET';
        $data = @$config['data'] ?: [];

        switch ($method) {
            case 'GET':
                $query = $data ? '?' . http_build_query($data) : '';
                $location = "$url" . $query;
//                D($location);
                header("Location: $location");
                exit;
            case 'POST':
                include __DIR__ . '/views/refirectForm.html.php';
                exit;
            default:
                throw new \Exception("Unknown redirect method");
        }
    }

}