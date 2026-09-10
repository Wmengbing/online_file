<?php
namespace app\common;

use think\Response;

/**
 * 支持 HTTP Range 断点续传的文件流响应。
 * 在 Response 的 send() 阶段输出,避免框架默认 Content-Type 覆盖;
 * 可附带临时文件,在输出完成后自动清理。
 */
class StreamResponse extends Response
{
    protected $filePath;
    protected $downloadName;
    protected $inline;
    protected $cleanupPaths = [];

    // 内联输出黑名单:这些类型交给浏览器会直接执行(存储型 XSS)。
    // 上传的 html/svg 即使带 nosniff 也会被渲染,一律强制下载而非内联。
    private static $unsafeInlineMimes = [
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
    ];

    public function __construct($filePath, $downloadName = '', $inline = false, $mime = '', $cleanup = [])
    {
        if ($inline && in_array(strtolower($mime), self::$unsafeInlineMimes)) {
            $inline = false;
            $mime   = 'application/octet-stream';
        }

        $this->filePath     = $filePath;
        $this->downloadName = $downloadName;
        $this->inline       = $inline;
        $this->cleanupPaths = (array)$cleanup;

        parent::__construct('', 200);

        if ($mime === '') {
            $mime = get_mime_type($filePath) ?: 'application/octet-stream';
        }
        // 父类构造会用默认 text/html 覆盖 Content-Type,须在之后重新指定
        $this->contentType($mime);
    }

    /**
     * 输出文件内容。
     *
     * 注意:不能调用父类 Response::send() —— 它在 sendData 之后会执行
     * fastcgi_finish_request()(php-fpm 下立即关闭与 nginx 的连接),
     * 导致本方法后续 echo 的文件体被丢弃,浏览器报 ERR_CONTENT_LENGTH_MISMATCH。
     * 因此这里手动发送状态码/头部,再循环输出文件体。
     */
    public function send()
    {
        $path = $this->filePath;
        $size = (int)@filesize($path);

        // 清空框架/调试的输出缓冲,保证头部之后只输出文件流
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!is_file($path) || !is_readable($path)) {
            $this->code = 404;
            $this->header['Content-Type'] = 'text/plain; charset=utf-8';
            $this->header['Content-Length'] = '0';
            $this->emitHeaders();
            echo 'File not found';
            return $this->finishRequest();
        }

        $start = 0;
        $end   = $size - 1;
        $range = false;

        $rawRange = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : '';
        if ($rawRange && preg_match('/bytes=(\d*)-(\d*)/', $rawRange, $m)) {
            $range = true;
            $start = $m[1] !== '' ? (int)$m[1] : null;
            $end   = $m[2] !== '' ? (int)$m[2] : null;
            if ($start === null) {
                $start = max(0, $size - $end);
                $end   = $size - 1;
            }
            if ($start >= $size) {
                // 请求范围越界
                $this->code = 416;
                $this->header['Content-Range'] = 'bytes */' . $size;
                $this->header['Content-Type'] = 'text/plain; charset=utf-8';
                $this->header['Content-Length'] = '0';
                $this->emitHeaders();
                return $this->finishRequest();
            }
            if ($end === null || $end >= $size) {
                $end = $size - 1;
            }
            if ($start > $end) {
                $end = $start;
            }
        }

        $name = $this->downloadName !== '' ? $this->downloadName : basename($path);

        $this->header['Accept-Ranges'] = 'bytes';
        $this->header['Cache-Control'] = 'private, max-age=0, must-revalidate';
        $this->header['Pragma']        = 'public';
        $this->header['X-Content-Type-Options'] = 'nosniff';
        $this->header['Content-Disposition'] = ($this->inline ? 'inline' : 'attachment')
            . '; filename="' . rawurlencode($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name);

        if ($range) {
            $this->code = 206;
            $this->header['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
            $this->header['Content-Length'] = (string)($end - $start + 1);
        } else {
            $this->code = 200;
            $this->header['Content-Length'] = (string)$size;
        }

        $this->emitHeaders();

        $fp = @fopen($path, 'rb');
        if ($fp) {
            if ($start > 0) {
                fseek($fp, $start);
            }
            $remaining = $end - $start + 1;
            while (!feof($fp) && $remaining > 0) {
                $buf = fread($fp, min(8 * 1024 * 1024, $remaining));
                if ($buf === false || $buf === '') {
                    break;
                }
                echo $buf;
                $remaining -= strlen($buf);
                if (connection_aborted()) {
                    break;
                }
            }
            fclose($fp);
        }

        foreach ($this->cleanupPaths as $tmp) {
            @unlink($tmp);
        }

        return $this->finishRequest();
    }

    /**
     * 手动发送状态码与响应头(不走父类 send,避免 fastcgi_finish_request 掐断流)
     */
    protected function emitHeaders()
    {
        if (!headers_sent()) {
            http_response_code($this->code);
            foreach ($this->header as $name => $val) {
                header($name . (!is_null($val) ? ':' . $val : ''));
            }
        }
    }

    /**
     * 与框架收尾对齐:触发 response_end、清理会话(不提前结束请求)
     */
    protected function finishRequest()
    {
        if (isset($this->app['hook'])) {
            $this->app['hook']->listen('response_end', $this);
        }
        if (isset($this->app['session'])) {
            $this->app['session']->flush();
        }
        return '';
    }
}
