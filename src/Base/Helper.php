<?php
namespace Ping\SwooleTask\Base;

/**
 * Class UtilsHelper
 * 一些通用方法
 */
class Helper
{
    public static function convertSize($size)
    {
        //FIXME size 负数是记录异常
        $size = abs($size);
        $unit = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];

        return @round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . ' ' . $unit[$i];
    }

    /**
     * 递归的获取某个目录指定的文件
     *
     * @param $dir
     *
     * @return array
     */
    public static function getFiles($dir)
    {
        $files = [];
        if (!is_dir($dir) || !file_exists($dir)) {
            return $files;
        }
        $directory = new \RecursiveDirectoryIterator($dir);
        $iterator = new \RecursiveIteratorIterator($directory);
        foreach ($iterator as $info) {
            $file = $info->getFilename();
            if ($file == '.' || $file == '..') {
                continue;
            }
            $files[] = $info->getPathname();
        }

        return $files;
    }

    public static function curl($url, $data, $method = 'post', $port = 9510)
    {
        $url = $method == 'post' ? $url : $url . '?' . http_build_query($data);
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_PORT, $port);
        curl_setopt($curl, CURLOPT_USERAGENT, LOG_AGENT);
        if ($method == 'post') {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }

        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            echo 'Curl error: ' . curl_error($curl);

        }
        curl_close($curl);
    }

}
