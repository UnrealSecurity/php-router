<?php
    /* 
        ------------------------------------------------------------
          Description: Route class for PHP with virtual hosts
          Author: @UnrealSec

          !!  PLEASE USE THIS FILE AS A REFERENCE ONLY AND
          !!  ONLY INCLUDE THE MINIFIED VERSION IN YOUR WEB APP
        ------------------------------------------------------------
        
        Your .htaccess file should look like this to 
        get this to work properly:
          | RewriteEngine on
          | RewriteCond %{REQUEST_FILENAME} !-f
          | RewriteCond %{REQUEST_FILENAME} !-d
          | RewriteRule ^(.*)$ /index.php [NC,L,QSA]

        This one is probably even better and also redirects if the requested 
        directory or file exists:
          | RewriteEngine on
          | RewriteRule ^((?!index\.php).*)$ /index.php [L]

        These simply redirects every HTTP request to index.php which 
        should contain your routing logic
    */

    class Router {
        private static $host;
        private static $uri;
        private static $path;
        private static $query;
        private static $method;
        private static $_this;
        private static $routes = array();
        private static $accepted = false;
        private static $signature = null;
        private static $cd = null;
        private static $continue = false;
        private static $actions = [
            'error' => array(),
        ];
        private static $firewall = null;

        // class constructor
        function __construct($base_path=null) {
            self::$_this = $this;
            self::$host = $_SERVER['HTTP_HOST'];
            self::$uri = $_SERVER['REQUEST_URI'];
            self::$query = $_SERVER['QUERY_STRING'];
            self::$path = urldecode(self::$uri); //strtok(urldecode(self::$uri), '?');
            self::$method = strtoupper($_SERVER['REQUEST_METHOD']);
            self::$routes = array();
            self::$signature = md5($_SERVER['REMOTE_ADDR']);

            $check_extension_state = true;
        }

        // set current directory path
        //
        // NOTE: when you use this with regular expression routing using routex()
        // you should not enclose the regular expression inside starting and ending delimiter!
        // You can pass regexp options with the routex() method's 5th argument (only when cd is used to set root path first)
        function cd($path) {
            self::$cd = $path;
        }

        //set & get client's signature (this is how we ratelimit clients)
        function setClientSignature(...$signature) {
            self::$signature = md5(join('\0', $signature));
        }
        function getClientSignature() {
            return self::$signature;
        }

        // this method returns true after accept() is called
        function is_accepted() {
            return self::$accepted;
        }

        // attach firewall to this router
        function firewall($firewall) {
            self::$firewall = $firewall;
        }
        
        // add new route
        function route($method, $host, $route, $action, $regexp=false, $reOptions=null) {
            if ($regexp && self::$cd != null) {
                $route = '/'.str_replace('/', '\/', self::$cd).$route.'/'.($reOptions!=null?$reOptions:"");
                // $route = '/'.self::trim(str_replace('/', '\/', self::$cd).$route).'/'.($reOptions!=null?$reOptions:"");
            } else if (!$regexp && self::$cd != null) {
                $route = self::trim(self::$cd.$route);
            }

            $method = strtoupper($method);
            if (!$regexp && substr($route, strlen($route)-1) !== '/') $route .= '/';
            
            self::$routes[] = [
                'method' => $method,
                'host' => $host,
                'route' => $route,
                'action' => $action,
                'regexp' => $regexp,
            ];
        }

        // add new route (expects $host to be a regular expression)
        function routex($method, $host, $route, $action, $reOptions=null) {
            self::route($method, $host, $route, $action, true, $reOptions);
        }
        
        // set callback for error event
        function error($host=null, $action) {
            if ($host==null) $host='';
            self::$actions['error'][$host] = $action;
        }

        // set's http response status code (200, 404, 503, ...)
        function status($status) {
            http_response_code($status);
        }

        // send file to client
        function sendfile($filepath, $attachment=false, $newName=null, $mimetype='application/octet-stream') {
            if ($newName == null) $newName = basename($filepath);
            header("Content-type: ".$mimetype);
            if ($attachment) {
                header("Content-disposition: attachment;filename=".$newName);
            }
            readfile($filepath);
        }

        // send media file to client (this supports range requests)
        // only recommended for audio and video files
        function sendmedia($file) {
            $fp = @fopen($file, 'rb');
            $size = filesize($file);
            $length = $size;
            $start = 0;
            $end = $size-1;
            header('Content-type: '.mime_content_type($file));
            header('Content-length: '.filesize($file));
            header('Accept-Ranges: bytes');
            if (isset($_SERVER['HTTP_RANGE'])) {
                $c_start = $start;
                $c_end = $end;
                list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
                if (strpos($range, ',') !== false) {
                    header('HTTP/1.1 416 Requested Range Not Satisfiable');
                    header("Content-Range: bytes $start-$end/$size");
                    exit;
                }
                if ($range == '-') {
                    $c_start = $size - substr($range, 1);
                }else{
                    $range = explode('-', $range);
                    $c_start = $range[0];
                    $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size;
                }
                $c_end = ($c_end > $end) ? $end : $c_end;
                if ($c_start > $c_end || $c_start > $size - 1 || $c_end >= $size) {
                    header('HTTP/1.1 416 Requested Range Not Satisfiable');
                    header("Content-Range: bytes $start-$end/$size");
                    exit;
                }
                $start = $c_start;
                $end = $c_end;
                $length = $end - $start + 1;
                fseek($fp, $start);
                header('HTTP/1.1 206 Partial Content');
            }
            header("Content-Range: bytes $start-$end/$size");
            header("Content-Length: ".$length);
            $buffer = 1024 * 8;
            while(!feof($fp) && ($p=ftell($fp)) <= $end) {
                if ($p + $buffer > $end) {
                    $buffer = $end-$p+1;
                }
                set_time_limit(0);
                echo fread($fp, $buffer);
                flush();
            }
            fclose($fp);
            exit();
        }
        
        // call this in route's callback function to indicate you want router to keep searching for matching routes
        // example: you have routes for /page/ and /page/?param=value and you want to do something when when ?param is supplied
        // but also want to handle default action for /page/
        //
        // $router::continue();
        function continue() {
            self::$continue = true;
        }

        // splits a string using '/' as delimiter and also removes empty items from resulting array
        function split($str) {
            $arr = explode('/', $str);
            $result = array();
            for ($i=0; $i<count($arr); $i++) {
                if (strlen($arr[$i]) > 0) $result[] = $arr[$i];
            }
            return $result;
        }

        // removes '?' and everything after it
        // also replaces '..' with '.' and '//' with '/'
        // finally removes '/' from the end of the string if there's one
        function trim($str) {
            $str = strtok($str, '?');
            
            while (true) {
                if (strpos($str, '//') != true && strpos($str, '..') != true) break;
                $str = str_replace('..', '.', $str);
                $str = str_replace('//', '/', $str);
            }
            
            if (strlen($str) > 0 && $str[strlen($str)-1] == '/') {
                return substr($str, 0, strlen($str)-1);
            }
            return $str;
        }

        // serve content from filesystem like a webserver
        // feel free to build your own custom serve() function outside of this class if needed
        function serve($root, $self=null, $values=null, $dirless=false) {
            $indexes = ['index.php', 'index.html'];
            $includes = ['php', 'html'];
            $dirless_exts = ['php', 'html'];
            $media = ['mp4', 'mp3', 'ogg', 'webm', 'avi', 'wav', 'mov', 'mpeg', 'mpg'];
			$mimetypes = [
				'htm' => 'text/html',
				'html' => 'text/html',
				'js' => 'text/javascript',
				'txt' => 'text/plain',
				'bmp' => 'image/bmp',
				'jpg' => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png' => 'image/png',
				'css' => 'text/css',
				'aac' => 'audio/aac',
				'abw' => 'application/x-abiword',
				'arc' => 'application/x-freearc',
				'avi' => 'video/x-msvideo',
				'azw' => 'application/vnd.amazon.ebook',
				'bin' => 'application/octet-stream',
				'bz' => 'application/x-bzip',
				'bz2' => 'application/x-bzip2',
				'csh' => 'application/x-csh',
				'csv' => 'text/csv',
				'doc' => 'application/msword',
				'docx' => 'application/vnd.openxmlformats-',
				'eot' => 'application/vnd.ms-fontobject',
				'epub' => 'application/epub+zip',
				'gz' => 'application/gzip',
				'gif' => 'image/gif',
				'ico' => 'image/x-icon',
				'ics' => 'text/calendar',
				'jar' => 'application/java-archive',
				'json' => 'application/json',
				'jsonld' => 'application/ld+json',
				'mid' => 'audio/midi',
				'midi' => 'audio/midi',
				'mjs' => 'text/javascript',
				'mp3' => 'audio/mpeg',
				'mp4' => 'video/mp4',
				'mpeg' => 'video/mpeg',
				'mpkg' => 'application/vnd.apple.installer+xml',
				'odp' => 'application/vnd.oasis.opendocument.presentation',
				'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
				'odt' => 'application/vnd.oasis.opendocument.text',
				'oga' => 'audio/ogg',
				'ogv' => 'video/ogg',
				'ogx' => 'application/ogg',
				'opus' => 'audio/opus',
				'otf' => 'font/otf',
				'pdf' => 'application/pdf',
				'php' => 'text/html',
				'ppt' => 'application/vnd.ms-powerpoint',
				'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
				'rar' => 'application/vnd.rar',
				'rtf' => 'application/rtf',
				'sh' => 'application/x-sh',
				'svg' => 'image/svg+xml',
				'swf' => 'application/x-shockwave-flash',
				'tar' => 'application/x-tar',
				'tif' => 'image/tiff',
				'tiff' => 'image/tiff',
				'ts' => 'video/mp2t',
				'ttf' => 'font/ttf',
				'vsd' => 'application/vnd.visio',
				'wav' => 'audio/wav',
				'weba' => 'audio/webm',
				'webm' => 'video/webm',
				'webp' => 'image/webp',
				'woff' => 'font/woff',
				'woff2' => 'font/woff2',
				'xhtml' => 'application/xhtml+xml',
				'xls' => 'application/vnd.ms-excel',
				'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'xml' => 'application/xml',
				'xul' => 'application/vnd.mozilla.xul+xml',
				'zip' => 'application/zip',
				'7z' => 'application/x-7z-compressed'
			];

            if ($self == null || $values == null) {
                echo '<h2>Error!</h2> Call to <b>Router::serve()</b> failed because <b>$self</b> and/or <b>$values</b> was not set'; 
                die();
            }

            $uri = $values['uri'];  $path = $self::trim($uri);
            $flag = strlen($values['query']) > 0;

            if (is_dir($root.$path)) {
                if (!$flag && $uri[strlen($uri)-1] != '/') {
                    // add trailing slash to directory paths
                    header('Location: '.$uri.'/');
                    die();
                } else {
                    // test if this directory contains any 
                    // of the predefined index files
                    $found = false;
                    foreach ($indexes as $index) {
                        if (file_exists($root.$path.'/'.$index)) {
                            $found = true;
                            $parts = explode('.', $root.$path.'/'.$index);
                            $ext = (count($parts) > 1 ? strtolower($parts[count($parts)-1]) : null);

                            if ($ext != null && array_key_exists($ext, $mimetypes)) {
                                header('Content-Type: '.$mimetypes[$ext]);
                            }

                            include_once($root.$path.'/'.$index);
                            break;
                        }
                    }
                    if (!$found) {
                        // no index found from this directory
                        // status: access denied
                        $self::status(403);
                    }
                }
            } else {
                // file -> file.ext
                if ($dirless) {
                    foreach ($dirless_exts as $de) {
                        if (file_exists($root.$path.'.'.$de)) { $path .= '.'.$de; break; }
                    }
                }

                if (file_exists($root.$path)) {
                    // file exists
                    $parts = explode('.', $path);

                    $ext = (count($parts) > 1 ? strtolower($parts[count($parts)-1]) : null);
                    if ($ext != null && array_key_exists($ext, $mimetypes)) {
                        header('Content-Type: '.$mimetypes[$ext]);
                    }

                    if ($ext!=null && in_array($ext, $includes)) {
                        // extension found from $includes
                        include_once($root.$path);
                    } else if ($ext!=null && in_array($ext, $media)) {
                        // send media file contents to client
                        $self::sendmedia($root.$path);
                    } else {
                        // send file contents to client
                        $self::sendfile($root.$path, false, '', (array_key_exists($ext, $mimetypes) ? $mimetypes[$ext] : mime_content_type($root.$path)));
                    }
                } else {
                    // status: not found
                    $self::status(404);
                }
            }
        }

        // perform http requests the easy way 
        // requires PHP cURL module to be installed
        function request($method, $url, $headers=null, $body=null, $timeout=20) {
            $method = strtoupper($method);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            if ($body != null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            if ($headers != null) {
                $_headers = array();
                foreach ($headers as $key => $value) {
                    $_headers[] = $key.': '.$value;
                }
                curl_setopt($ch, CURLOPT_HTTPHEADER, $_headers);
            }
            $re = curl_exec($ch);
            curl_close($ch);
            return $re;
        }

        // shift to left
        // removes n items from the beginning of the array
        function lshift($arr, $n=1) {
            array_splice($arr, 0, $n);
            return $arr;
        }
        
        // this should be called when everything is ready and we want to process the request
        function accept() {
            self::$accepted = true;
            
            if (self::$firewall != null) {
                $client_ip = $_SERVER['REMOTE_ADDR'];
                self::$firewall::test($client_ip);
            }

            $found = false;
            $values = [
                'host' => self::$host,
                'uri' => self::$uri,
                'path' => self::$path,
                'method' => self::$method,
                'query' => self::$query
            ];
            
            for ($i=count(self::$routes)-1; $i>=0; $i--) {
                $obj = self::$routes[$i];
                
                if ($obj['host'] == null || $obj['host'] == self::$host) {
                    if ($obj['regexp'] === true) {
                        $matches = false;
                        if (($obj['method'] == '*' || self::$method == $obj['method']) && preg_match($obj['route'], self::$path, $matches)) {
                            if ($matches != false) array_shift($matches);
                            $found = true; $obj['action'](self::$_this, $values, ...$matches);
                            if (!self::$continue) {
                                break;
                            }
                            self::$continue = false;
                        }
                    } else {
                        if (substr(self::$path, strlen(self::$path)-1) !== '/') self::$path .= '/';
                        if (($obj['method'] == '*' || self::$method == $obj['method']) && $obj['route'] === self::$path) {
                            $found = true; $obj['action'](self::$_this, $values);
                            if (!self::$continue) {
                                break;
                            }
                            self::$continue = false;
                        }
                    }
                }
            }

            if (!$found) {
                if (array_key_exists(self::$host, self::$actions['error']) && self::$actions['error'][self::$host] != null) {
                    self::$actions['error'][self::$host](self::$_this, $values);
                } else if (array_key_exists('', self::$actions['error']) && self::$actions['error'][''] != null) {
                    self::$actions['error'][''](self::$_this, $values);
                }
            }
        }
    }

    // This experimental feature requires 
    // PHP APCu module to be installed
    class RouterFirewall {
        private static $router = null;
        private static $rules = array();
        private static $history = array();
        private static $prefix = 'rwaf_';
        private static $record_ttl = 5;
        private static $threshold = 4;
        private static $_this;
        private static $inspector = true;
        private static $patterns = [
            // "/'/",
            // "/\"/",
            // "/;/",
            "/<script/",
            "/script>/",
            "/\[\]=/",
        ];

        private static $events = [
            'denied' => null,  // access denied (banned)
            'limited' => null, // client ratelimited
            'allowed' => null, // client is allowed
            'stopped' => null, // malicious request stopped
        ];

        function __construct($router, $check_extension_state=false) {
            self::$_this = $this;
            self::$router = $router;

            if ($check_extension_state && !extension_loaded('apcu')) {
                echo '<h2>Error!</h2> Module <b>APCu</b> (APC User Cache) needs to be loaded in order for RouterFirewall to work';
                die();
            }
        }

        // set callback for when the user is ratelimited (too many requests)
        function limited($action) {
            self::$events['limited'] = $action;
        }
        // set callback for when the user is denied access (banned)
        function denied($action) {
            self::$events['denied'] = $action;
        }
        // set callback for when the user is allowed access
        function allowed($action) {
            self::$events['allowed'] = $action;
        }
        // set callback for when the user sent malicious request that was blocked
        function stopped($action) {
            self::$events['stopped'] = $action;
        }

        // set some default values
        function defaults($threshold, $record_ttl, $enable_inspector=false) {
            self::$threshold = $threshold;
            self::$record_ttl = $record_ttl;
            self::$inspector = $enable_inspector;
        }

        function inspect() {
            $post = urldecode(file_get_contents('php://input'));
            $get = urldecode($_SERVER['REQUEST_URI']);

            for ($i=0; $i<count(self::$patterns); $i++) {
                $pattern = self::$patterns[$i];
                if (preg_match($pattern, $get) != false || preg_match($pattern, $post) != false) {
                    return true;
                }
            }
            return false;
        }

        // reset in-memory firewall rules
        function reset() {
            $rules_apc_key = self::$prefix.'rules';
            if (apcu_exists($rules_apc_key)) {
                apcu_store($rules_apc_key, array());
            }
        }

        function test($client_ip=null) {
            if ($client_ip==null) $client_ip = $_SERVER['REMOTE_ADDR'];
            $key = self::$prefix.self::$router::getClientSignature();

            if (self::$inspector && self::inspect()) {
                if (self::$events['stopped'] != null) {
                    self::$events['stopped'](self::$_this, $client_ip);
                }
                self::$router::status(400);
                die();
            }

            $bypass_ratelimit = false;

            $rules_apc_key = self::$prefix.'rules';
            if (apcu_exists($rules_apc_key)) {
                $rules = apcu_fetch($rules_apc_key);

                for ($i=count($rules)-1; $i>=0; $i--) {
                    $type = $rules[$i][0];
                    $addr = $rules[$i][1];
                    $regexp = $rules[$i][2];
    
                    if ((!$regexp && $client_ip === $addr) || ($regexp && preg_match($addr, $client_ip) != false)) {
                        if ($type === 'deny') {
                            if (self::$events['denied'] != null) {
                                self::$events['denied'](self::$_this, $client_ip);
                            }
                            self::$router::status(403); // forbidden
                            die();
                        } else if ($type === 'allow') {
                            if (self::$events['allowed'] != null) {
                                self::$events['allowed'](self::$_this, $client_ip);
                            }
                            $bypass_ratelimit = true;
                            break;
                        }
                    }
                }
            }

            for ($i=count(self::$rules)-1; $i>=0; $i--) {
                $type = self::$rules[$i][0];
                $addr = self::$rules[$i][1];
                $regexp = self::$rules[$i][2];

                if ((!$regexp && $client_ip === $addr) || ($regexp && preg_match($addr, $client_ip) != false)) {
                    if ($type === 'deny') {
                        if (self::$events['denied'] != null) {
                            self::$events['denied'](self::$_this, $client_ip);
                        }
                        self::$router::status(403); // forbidden
                        die();
                    } else if ($type === 'allow') {
                        if (self::$events['allowed'] != null) {
                            self::$events['allowed'](self::$_this, $client_ip);
                        }
                        $bypass_ratelimit = true;
                        break;
                    }
                }
            }

            if (!$bypass_ratelimit) {
                if (!apcu_exists($key)) {
                    apcu_add($key, 0, self::$record_ttl);
                } else {
                    apcu_inc($key, 1);
    
                    if ((int)apcu_fetch($key) > self::$threshold) {
                        if (self::$events['limited'] != null) {
                            self::$events['limited'](self::$_this, $client_ip);
                        }
                        self::$router::status(429); //too many requests
                        die();
                    }
                }
            }
        }

        // add new firewall rule
        // $ip_patterns can be of type array or string
        function rule($type, $ip_patterns, $regexp=false) {
            $type = strtolower($type); // deny, allow
            if (gettype($ip_patterns) != 'array') {
                $ip_patterns = array($ip_patterns);
            }

            if (self::$router::is_accepted()) {
                $key = self::$prefix.'rules';
                if (!apcu_exists($key)) { apcu_store($key, array()); }
                $data = apcu_fetch($key);
                foreach ($ip_patterns as $pattern) {
                    $data[] = [$type, $pattern, $regexp];
                }
                apcu_store($key, $data);
            } else {
                foreach ($ip_patterns as $pattern) {
                    self::$rules[] = [$type, $pattern, $regexp];
                }
            }
        }

        // add new firewall rule (regular expression)
        // $ip_patterns can be of type array or string
        function rulex($type, $ip_patterns) {
            self::rule($type, $ip_patterns, true);
        }
    }

?>
