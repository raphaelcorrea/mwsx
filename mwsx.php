<?php
/*-------------------------------------------------------------------------
 *
 * mwsx.php
 *      php server / client for mwsx
 * 
 * Copyleft 2013 - Public Domain
 * Original Author: Daniel Loureiro
 *
 * version 2.0a @ 2013-03-10
 *
 * https://github.com/loureirorg/mwsx
 *-------------------------------------------------------------------------
 */
namespace mwsx;

if (isset($_REQUEST["mwsd"]) OR isset($_REQUEST["mws"])) 
{
    // configuration (we'll use only when calling mwsd or mws)
    $mwsx_config["general"]["stop_at_include"] = true;
    $mwsx_config["cache"]["mode"] = "off";
    $mwsx_config["cache"]["timeout"] = 600;
    if (function_exists("apc_fetch")) {
        $mwsx_config["cache"]["mode"] = "apc";
    }
    if (file_exists(__DIR__. "/mwsx.ini.php")) {
        $mwsx_config = parse_ini_file(__DIR__. "/mwsx.ini.php", true);
    }

    // clean _REQUEST, minimizing accidental calls
    $mwsx_request = get_data();
    $_REQUEST = array();
    
    // process mws / mwsd after all code, so we can discover all declared functions
    $__mwsx = new before_die_callback();
    if ($mwsx_config["general"]["stop_at_include"]) {
        unset($__mwsx);
    }
}
 
// error/warning control
$mwsx_result = array("result" => null, "error" => null, "warns" => array(), "signals" => array());


/*
 * SERVER
 */
function cache_load($key)
{   
    global $mwsx_config;
    
    if ($mwsx_config["cache"]["mode"] == "memcached")
    {
        global $mwsx_memcached;
        return  $mwsx_memcached->get($key);
    }
    elseif ($mwsx_config["cache"]["mode"] == "apc")
    {
        if (apc_exists($key)) {
            return  apc_fetch(unserialize($key));
        }
    }
    return false;
}

function cache_save($key, $value)
{
    global $mwsx_config;
    
    if ($mwsx_config["cache"]["mode"] == "memcached")
    {
        global $mwsx_memcached;
        $mwsx_memcached->set($key, $value, 0, $mwsx_config["cache"]["timeout"]);
    }
    elseif ($mwsx_config["cache"]["mode"] == "apc") {
        apc_store($key, serialize($value), $mwsx_config["cache"]["timeout"]);
    }
}
 
/*
 * published_functions
 *      search for "_EXPORT_" in source-code
 */
function published_functions() 
{   
    $path = $_SERVER["DOCUMENT_ROOT"]. substr($_SERVER["PHP_SELF"], 1);

    // try cache first
    if (($mwsx_config["cache"]["mode"] != "none") AND ($mwsx_config["cache"]["mode"] != "off"))
    {
        $cache_key = md5($path. filesize($path). filemtime($path). "mwsd");
        $result = cache_load($cache_key);
        if ($result !== false) {
            return  $result;
        }
    }

    // cache not found, we'll produce new list based on source
    $source = file_get_contents($path);

    // namespace
    $namespace = "";
    if (preg_match('/namespace[ \t\r\n]*([^ \t\r\n;]*)/i', $source, $matches)) {
        $namespace = $matches[1];
    }
    
    // list of published functions
    preg_match_all('/\/\* _EXPORT_ \*\/[ \t\r\n]*function[ \t\r\n]?(.+)[ \t\r\n]*\(([^\)]*)\)/', $source, $matches);
    $str_args = $matches[2];
    $str_fncs = $matches[1];

    // split arguments and format in mwsd
    $args = array_map(create_function('$str_args', 'return  $str_args == ""? array(): explode(",", preg_replace(\'/[\$ \n\r\t]/\', \'\', $str_args));'), $str_args);
    $fncs = array_map(create_function('$a, $b', "return array('name' => \$a, 'args' => \$b);"), $str_fncs, $args);

    // save cache
    cache_save($cache_key, $fncs);

    // return list of functions (not in json form)
    return  array("namespace" => $namespace, "fncs" => $fncs);
}


/*
 * accept data in json or php ("domain.com/?arg_1=xxx&arg_2=yyy")
 */
function get_data()
{
    $post   = @json_decode(file_get_contents('php://input'), TRUE);
    $get    = @json_decode(urldecode($_SERVER['QUERY_STRING']), TRUE);
    return  array_merge(is_array($post)? $post: array(), is_array($get)? $get: array(), $_REQUEST, $_FILES);
}

/*
 * stop script and report error
 */
function error($msg)
{
    // system log
    $trace = array_pop(debug_backtrace());
    error_log(date("Y-m-d H:i:s"). ": ${msg} [backtrace: @${trace["file"]}:${trace["line"]}];");

    // stop script and report error
    global $mwsx_result;
    $mwsx_result = array("result" => null, "error" => $msg, "warns" => array(), "signals" => $mwsx_result["signals"]);
    die(json_encode($mwsx_result));
}


function warn($msg) 
{
    global $mwsx_result;
    $mwsx_result['warns'][] = $msg;
}


function signal($signal)
{
    global $mwsx_result;
    $mwsx_result['signals'][] = $signal;
}

class before_die_callback 
{
    function __destruct() 
    {
        global $mwsx_config;
        global $mwsx_request;
        
        $_REQUEST = $mwsx_request;
        
        // cache memcache: add server
        if ($mwsx_config["cache"]["mode"] == "memcached")
        {
            $mwsx_memcached = new Memcache;
            $mwsx_memcached->addServer($mwsx_config["cache"]["memcached_host"]);
        }

        if (isset($_REQUEST["mwsd"])) 
        {
            // mwsd request (list of funtions)
            $protocol = ((array_key_exists("HTTPS", $_SERVER)) AND ($_SERVER["HTTPS"] == 'on'))? "https": "http";
            $server_port = (($_SERVER["SERVER_PORT"] == 80) OR ($_SERVER["SERVER_PORT"] == 443))? "": ":".$_SERVER["SERVER_PORT"];
            $default_url = $protocol. "://". $_SERVER["HTTP_HOST"]. $server_port. $_SERVER["PHP_SELF"];

            // try cache
            if (($mwsx_config["cache"]["mode"] != "none") AND ($mwsx_config["cache"]["mode"] != "off"))
            {
                $path = $_SERVER["DOCUMENT_ROOT"]. substr($_SERVER["PHP_SELF"], 1);
                $cache_key = md5($path. filesize($path). filemtime($path). "mwsd");
                $result = cache_load($cache_key);
                if ($result !== false) {
                    die($result);
                }
            }
            
            // not in cache, we'll generate
            $pf = published_functions();
            $fncs = $pf["fncs"];
            $fncs_with_url = array_map(create_function('$item', '$item["url"] = "'. $default_url. '?mws=".$item["name"]; return $item;'), $fncs);
            $mwsd = json_encode($fncs_with_url);
            cache_save($cache_key, $mwsd);
            die($mwsd);
        }

        elseif (isset($_REQUEST["mws"]))
        {
            // calling a method
            $pf = published_functions();
            $namespace = $pf["namespace"]? ($pf["namespace"]. "\\"): "\\";
            $fncs = $pf["fncs"];
            $fnc = array_filter($fncs, create_function('$item', 'return $item["name"] == "'. $_REQUEST["mws"]. '";'));
            if ($fnc == array()) {
                error("MWSX: function ". $_REQUEST["mws"]. " not found !");
            }
            $fnc = array_pop($fnc);
            
            // order arguments in the same order of source
            $ordered_args = array();
            if (count($fnc["args"]))
            {
                foreach ($fnc["args"] as $arg) {
                    $ordered_args[] = $mwsx_request[$arg];
                }
            }
            
            // calling function, show results in mwsx style
            $mwsx_result["result"] = call_user_func_array($namespace. $_REQUEST["mws"], $ordered_args);
            die(json_encode($mwsx_result));
        }       
    }
}


/*
 * CLIENT
 */
$mwsx_ws_included = array(); // include_once style
$mwsx_included = array(); // include_once style

function parse_result($result)
{
    global $mwsx_result;
    $content = (array)json_decode($result, true);
    if (!array_key_exists("result", $content)) 
    {
        error_log(date("Y-m-d H:i:s"). ": UNKNOWN ERROR [return ". $result ."];");
        $mwsx_result = array("result" => "", "error" => "UNKNOWN ERROR [return ". $result ."]", "warns" => "", "signals" => "UNKNOWN ERROR");
        return  null;
    }
    $mwsx_result = array("result" => $content["result"], "error" => $content["error"], "warns" => $content["warns"], "signals" => $content["signals"]);
    return  $content["result"];
}

 
function ws_call($url, $key_args, $value_args)
{
    $key_args = explode(",", $key_args);
    $data = array();
    foreach ($key_args as $i => $key_arg) {
        $data[$key_arg] = $value_args[$i];
    }
    return  parse_result(http_read($url, json_encode($data)));
}
 
 // usage: mwsx\ws($url [, $namespace]);
function ws($url) 
{   
    // args: $url [, $namespace]
    $url = func_get_arg(0);
    if (func_num_args() >= 2) {
        $namespace = func_get_arg(1);
    }
    
    // don't let include twice
    global $mwsx_ws_included;
    $md5 = md5($url. $namespace);
    if (array_key_exists($md5, $mwsx_ws_included)) {
        return  new $mwsx_ws_included[$md5]();
    }
    
    // try cache
    $plain_mwsd = http_read($url);
    $temp_name = sys_get_temp_dir(). "/mwsx_". md5($plain_mwsd. $namespace. "ws"). ".php";
    if (!file_exists($temp_name)) 
    {
        // not in cache, create and include file
        $mwsd = (array)json_decode($plain_mwsd, true);  
        $sources = array();
        foreach ($mwsd as $fnc) {
            $sources[] = "\tfunction ". $fnc["name"]. "()\n\t{\n\t\treturn  \\". __NAMESPACE__. "\\ws_call('". $fnc["url"]. "', '".implode(",", $fnc["args"]). "', func_get_args());\n\t}\n";
        }
        if (func_num_args() == 1) {
            $namespace = uniqid("class_");
        }
        $source = "<?php\n";
        $source .= "class ". $namespace. " {\n". implode("\n", $sources). "}\n";
        $source .= "\n?>\n";
        file_put_contents($temp_name, $source);
    }
    $mwsx_ws_included[$md5] = $namespace;
    include_once    $temp_name;
    $obj = new $namespace();
    return  $obj;
}

// usage: mwsx\ws_include($url [, $namespace]);
function ws_include()
{
    // args: $url [, $namespace]
    $url = func_get_arg(0);
    if (func_num_args() >= 2) {
        $namespace = func_get_arg(1);
    }
    
    // don't let include twice
    global $mwsx_included;
    $md5 = md5($url. $namespace);
    if (array_key_exists($md5, $mwsx_included)) {
        return  false;
    }
    $mwsx_included[$md5] = true;
    
    // try cache
    $plain_mwsd = http_read($url);
    $temp_name = sys_get_temp_dir(). "/mwsx_". md5($plain_mwsd. $namespace. "ws_include"). ".php";
    if (!file_exists($temp_name)) 
    {
        // not in cache, create and include file
        $mwsd = (array)json_decode($plain_mwsd, true);  
        $sources = array();
        foreach ($mwsd as $fnc) {
            $sources[] = "if (!function_exists('". $fnc["name"]. "')) function ". $fnc["name"]. "()\n{\n\treturn    \\". __NAMESPACE__. "\\ws_call('". $fnc["url"]. "', '".implode(",", $fnc["args"]). "', func_get_args());\n}\n";
        }
        $source = "<?php\n";
        if (isset($namespace)) {
            $source .= "namespace ". $namespace. ";\n";
        }
        $source .= implode("\n", $sources) ."\n?>\n";
        file_put_contents($temp_name, $source);
    }
    include_once    $temp_name;
    return  true;
}

// usage: mwsx\ws_require($url [, $namespace]);
function ws_require()
{
    // args: $url [, $namespace]
    if (func_num_args() == 1) {
        return  ws_include(func_get_arg(0));
    }
    return  ws_include(func_get_arg(0), func_get_arg(1));
}

// $url[, $post_data]
function http_read()
{
    // args: $url[, $post_data]
    $url = func_get_arg(0);
    $post_data = (func_num_args() == 2)? func_get_arg(1): null;

    // it's a relative url (curl don't support relative url)
    if (stripos($url, "http") !== 0)
    {
        $protocol = ((array_key_exists('HTTPS', $_SERVER)) AND ($_SERVER['HTTPS'] == 'on'))? "https": "http";
        $server_port = (($_SERVER['SERVER_PORT'] == 80) OR ($_SERVER['SERVER_PORT'] == 443))? "": ":".$_SERVER['SERVER_PORT'];
        $absolute_url = $protocol. "://". $_SERVER['HTTP_HOST']. $server_port. $_SERVER['PHP_SELF'];
        $path = pathinfo($_SERVER['PHP_SELF']);
        $url = substr($absolute_url, 0, strrpos($absolute_url, $path["basename"])).$url;
    }
    
    // headers
    $headers = array(
        "Expect: ",
    );

    // post
    if ($post_data != null) 
    {
        $headers[] = "Content-Type: application/json; charset=utf-8";
        $headers[] = "Content-Length: ". (mb_strlen($post_data, "UTF-8"));
    }

    // cookies
    if (session_id() == "") {
        session_start();
    }
    if (!array_key_exists("mwsx_cookie", $_SESSION)) {
        $_SESSION["mwsx_cookie"] = array();
    }
    if (count($_SESSION["mwsx_cookie"])) 
    {
        $array = array_map(
            create_function('$k, $v', 'return "$k=$v";'), 
            array_keys($_SESSION["mwsx_cookie"]), 
            array_values($_SESSION["mwsx_cookie"])
        );
        $headers[] = "Cookie: ". implode("; ", $array);
    }
    
    // server comunication
    global $mwsx_curl;
    if (!isset($mwsx_curl)) {
        $mwsx_curl = curl_init();
    }
    curl_setopt($mwsx_curl, CURLOPT_URL, $url); 
    curl_setopt($mwsx_curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($mwsx_curl, CURLOPT_HEADER, true); 
    curl_setopt($mwsx_curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($mwsx_curl, CURLOPT_TIMEOUT, 600);
    curl_setopt($mwsx_curl, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($mwsx_curl, CURLOPT_SSL_VERIFYPEER, false);
    if ($post_data != null) 
    {
        curl_setopt($mwsx_curl, CURLOPT_POST, true);
        curl_setopt($mwsx_curl, CURLOPT_HTTPGET, false);
        curl_setopt($mwsx_curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($mwsx_curl, CURLOPT_POSTFIELDS, $post_data);
    }
    else 
    {
        curl_setopt($mwsx_curl, CURLOPT_POST, false);
        curl_setopt($mwsx_curl, CURLOPT_HTTPGET, true);
        curl_setopt($mwsx_curl, CURLOPT_CUSTOMREQUEST, "GET");
    }
    $result = curl_exec($mwsx_curl);
    
    // head + body split
    list($head, $body) = explode("\r\n\r\n", $result, 2);

    // cookies
    if (preg_match_all('#Set-Cookie: ([^=]*)=([^;]*);.*#', $head, $matches)) {
        $_SESSION["mwsx_cookie"] = array_merge($_SESSION["mwsx_cookie"], array_combine($matches[1], $matches[2]));
    }
    
    // if "Location", go to page
    if (preg_match('#Location: (.*)?#', $head, $matches)) {
        return  http_read($matches[1]);
    }
    
    // encoding
    if (preg_match('#Content-Type: .*charset=(.*);?#', $head, $matches) AND ($matches[1] != "utf8")) {
        $body = mb_convert_encoding($body, "utf8");
    }

    // end: returns the body
    return  $body;
} 
 
function ws_error()
{
    global $mwsx_result;
    return  $mwsx_result["error"];
}

function ws_fetch_warn()
{
    global $mwsx_result;
    return  array_pop($mwsx_result["warns"]);
}

function ws_warns()
{
    global $mwsx_result;
    return  $mwsx_result["warns"];
}

function ws_has_signal($signal)
{
    global $mwsx_result;
    return  in_array($signal, $mwsx_result["signals"]);
}

?>