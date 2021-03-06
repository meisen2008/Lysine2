<?php
namespace Lysine;

class HTTP
{
    const OK                            = 200;
    const CREATED                       = 201;
    const ACCEPTED                      = 202;
    const NO_CONTENT                    = 204;
    const RESET_CONTENT                 = 205;
    const PARTIAL_CONTENT               = 206;
    const MOVED_PERMANENTLY             = 301;
    const FOUND                         = 302;
    const SEE_OTHER                     = 303;
    const BAD_REQUEST                   = 400;
    const UNAUTHORIZED                  = 401;
    const PAYMENT_REQUIRED              = 402;
    const FORBIDDEN                     = 403;
    const NOT_FOUND                     = 404;
    const METHOD_NOT_ALLOWED            = 405;
    const NOT_ACCEPTABLE                = 406;
    const REQUEST_TIMEOUT               = 408;
    const CONFLICT                      = 409;
    const GONE                          = 410;
    const LENGTH_REQUIRED               = 411;
    const PRECONDITION_FAILED           = 412;
    const REQUEST_ENTITY_TOO_LARGE      = 413;
    const UNSUPPORTED_MEDIA_TYPE        = 415;
    const EXPECTATION_FAILED            = 417;
    const UNAVAILABLE_FOR_LEGAL_REASONS = 451;
    const INTERNAL_SERVER_ERROR         = 500;
    const NOT_IMPLEMENTED               = 501;
    const BAD_GATEWAY                   = 502;
    const SERVICE_UNAVAILABLE           = 503;
    const GATEWAY_TIMEOUT               = 504;

    protected static $status = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Time-out',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Large',
        415 => 'Unsupported Media Type',
        416 => 'Requested range not satisfiable',
        417 => 'Expectation Failed',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Time-out',
    ];

    public static function getStatusMessage($code)
    {
        return self::$status[$code];
    }

    public static function getStatusHeader($code)
    {
        $message = self::$status[$code];

        return strpos(PHP_SAPI, 'cgi') === 0
        ? sprintf('Status: %d %s', $code, $message)
        : sprintf('HTTP/1.1 %d %s', $code, $message);
    }
}

////////////////////////////////////////////////////////////////////////////////
namespace Lysine\HTTP;

class Request
{
    use \Lysine\Traits\Singleton;

    protected $method;
    protected $request_uri;

    public function getHeader($key)
    {
        $key = 'http_'.str_replace('-', '_', $key);

        return server($key);
    }

    public function getRequestURI()
    {
        if ($this->request_uri) {
            return $this->request_uri;
        }

        if ($uri = server('request_uri')) {
            return $this->request_uri = $uri;
        }

        throw new \RuntimeException('Unknown request URI');
    }

    public function getMethod()
    {
        if ($this->method) {
            return $this->method;
        }

        $method = strtoupper($this->header('x-http-method-override') ?: server('request_method'));
        if ($method != 'POST') {
            return $this->method = $method;
        }

        // 某些js库的ajax封装使用这种方式
        $method = post('_method') ?: $method;
        unset($_POST['_method']);

        return $this->method = strtoupper($method);
    }

    public function getExtension()
    {
        $path = parse_url($this->requestUri(), PHP_URL_PATH);

        return strtolower(pathinfo($path, PATHINFO_EXTENSION))
        ?: 'html';
    }

    public function isGET()
    {
        return ($this->method() === 'GET') ?: $this->isHEAD();
    }

    public function isPOST()
    {
        return $this->method() === 'POST';
    }

    public function isPUT()
    {
        return $this->method() === 'PUT';
    }

    public function isDELETE()
    {
        return $this->method() === 'DELETE';
    }

    public function isHEAD()
    {
        return $this->method() === 'HEAD';
    }

    public function isAJAX()
    {
        return strtolower($this->header('X_REQUESTED_WITH')) == 'xmlhttprequest';
    }

    public function getReferer()
    {
        return server('http_referer');
    }

    public function getIP($proxy = null)
    {
        $ip = $proxy
        ? server('http_x_forwarded_for') ?: server('remote_addr')
        : server('remote_addr');

        if (strpos($ip, ',') === false) {
            return $ip;
        }

        // private ip range, ip2long()
        $private = [
            [0, 50331647],            // 0.0.0.0, 2.255.255.255
            [167772160, 184549375],   // 10.0.0.0, 10.255.255.255
            [2130706432, 2147483647], // 127.0.0.0, 127.255.255.255
            [2851995648, 2852061183], // 169.254.0.0, 169.254.255.255
            [2886729728, 2887778303], // 172.16.0.0, 172.31.255.255
            [3221225984, 3221226239], // 192.0.2.0, 192.0.2.255
            [3232235520, 3232301055], // 192.168.0.0, 192.168.255.255
            [4294967040, 4294967295], // 255.255.255.0 255.255.255.255
        ];

        $ip_set = array_map('trim', explode(',', $ip));

        // 检查是否私有地址，如果不是就直接返回
        foreach ($ip_set as $key => $ip) {
            $long = ip2long($ip);

            if ($long === false) {
                unset($ip_set[$key]);
                continue;
            }

            $is_private = false;

            foreach ($private as $m) {
                list($min, $max) = $m;
                if ($long >= $min && $long <= $max) {
                    $is_private = true;
                    break;
                }
            }

            if (!$is_private) {
                return $ip;
            }
        }

        return array_shift($ip_set) ?: '0.0.0.0';
    }

    public function getAcceptTypes()
    {
        return $this->getAccept('http_accept');
    }

    public function getAcceptLanguage()
    {
        return $this->getAccept('http_accept_language');
    }

    public function getAcceptCharset()
    {
        return $this->getAccept('http_accept_charset');
    }

    public function getAcceptEncoding()
    {
        return $this->getAccept('http_accept_encoding');
    }

    public function isAcceptType($type)
    {
        return $this->isAccept($type, $this->getAcceptTypes());
    }

    public function isAcceptLanguage($lang)
    {
        return $this->isAccept($lang, $this->getAcceptLanguage());
    }

    public function isAcceptCharset($charset)
    {
        return $this->isAccept($charset, $this->getAcceptCharset());
    }

    public function isAcceptEncoding($encoding)
    {
        return $this->isAccept($encoding, $this->getAcceptEncoding());
    }

    public function reset()
    {
        $this->method      = null;
        $this->request_uri = null;
    }

    //////////////////// protected method ////////////////////
    protected function getAccept($header_key)
    {
        if (!$accept = server($header_key)) {
            return [];
        }

        $result = [];
        $accept = strtolower($accept);
        foreach (explode(',', $accept) as $accept) {
            if (($pos = strpos($accept, ';')) !== false) {
                $accept = substr($accept, 0, $pos);
            }
            $result[] = trim($accept);
        }

        return $result;
    }

    protected function isAccept($find, array $accept)
    {
        return in_array(strtolower($find), $accept, true);
    }

    /**
     * @deprecated
     */
    public function ip($proxy = null)
    {
        return $this->getIP($proxy);
    }

    /**
     * @deprecated
     */
    public function referer()
    {
        return $this->getReferer();
    }

    /**
     * @deprecated
     */
    public function method()
    {
        return $this->getMethod();
    }

    /**
     * @deprecated
     */
    public function extension()
    {
        return $this->getExtension();
    }

    /**
     * @deprecated
     */
    public function requestUri()
    {
        return $this->getRequestURI();
    }

    /**
     * @deprecated
     */
    public function header($key)
    {
        return $this->getHeader($key);
    }
}

class Response
{
    use \Lysine\Traits\Singleton;

    protected $code   = \Lysine\HTTP::OK;
    protected $header = [];
    protected $cookie = [];
    protected $body;

    public function execute()
    {
        list($header, $body) = $this->compile();

        \Lysine\Session::getInstance()->commit();

        if (!headers_sent()) {
            array_map('header', $header);
            $this->header = [];

            foreach ($this->cookie as $config) {
                list($name, $value, $expire, $path, $domain, $secure, $httponly) = $config;
                setCookie($name, $value, $expire, $path, $domain, $secure, $httponly);
            }
            $this->cookie = [];
        }

        if ($body instanceof \Closure) {
            echo call_user_func($body);
        } else {
            echo $body;
        }
    }

    public function setCode($code)
    {
        $this->code = (int) $code;

        return $this;
    }

    public function getCode()
    {
        return $this->code;
    }

    public function setCookie($name, $value, $expire = 0, $path = '/', $domain = null, $secure = null, $httponly = true)
    {
        if ($secure === null) {
            $secure = (bool) server('https');
        }
        $key = sprintf('%s@%s:%s', $name, $domain, $path);

        $this->cookie[$key] = [$name, $value, $expire, $path, $domain, $secure, $httponly];

        return $this;
    }

    public function getCookie()
    {
        return $this->cookie;
    }

    public function setHeader($header)
    {
        if (strpos($header, ':')) {
            list($key, $val)          = explode(':', $header, 2);
            $this->header[trim($key)] = trim($val);
        } else {
            $this->header[$header] = null;
        }

        return $this;
    }

    public function getHeader()
    {
        return $this->header;
    }

    public function setBody($body)
    {
        $this->body = $body;

        return $this;
    }

    public function getBody()
    {
        return $this->body;
    }

    // return array($header, $body);
    public function compile()
    {
        $body = in_array($this->getCode(), [204, 301, 302, 303, 304])
        ? ''
        : $this->body;

        return [
            $this->compileHeader(),
            $body,
        ];
    }

    public function reset()
    {
        $this->code   = \Lysine\HTTP::OK;
        $this->header = [];
        $this->cookie = [];
        $this->body   = null;

        \Lysine\Session::getInstance()->reset();

        return $this;
    }

    public function redirect($url, $code = 303)
    {
        $this->setCode($code)
             ->setHeader('Location: '.$url);

        return $this;
    }

    //////////////////// protected method ////////////////////
    protected function compileHeader()
    {
        $header   = [];
        $header[] = \Lysine\HTTP::getStatusHeader($this->code ?: 200);

        foreach ($this->header as $key => $val) {
            $header[] = $val === null
            ? $key
            : $key.': '.$val;
        }

        return $header;
    }
}
