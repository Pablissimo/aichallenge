<?php

require_once('server_info.php');
require_once('sql.php');
require_once('security_helpers.php');

// Get the database login information from the server_info.txt file.

// Login credentials for MySQL database.
$db_host = $server_info["db_host"]; // Host name
$db_username = $server_info["db_username"]; // Mysql username
$db_password = $server_info["db_password"]; // Mysql password
$db_name = $server_info["db_name"]; // Database name

// Connect to server and select database.
$mysqli = mysqli_connect($db_host, $db_username, $db_password, $db_name);
if (!$mysqli) {
    die('cannot connect: ' . mysqli_connect_error());
}

// salty function, used for passwords in crypt with SHA
function salt($len=16, $cookie=FALSE) {
    if ($cookie) {
        // set of characters that look nice in cookies, excluding -, . and _
        $pool = array_merge(range('0','9'), range('a', 'z'), range('A','Z'));
    } else {
        $pool = range('!', '~');
    }
    $high = count($pool) - 1;
    $tmp = '';
    for ($c = 0; $c < $len; $c++) {
        $tmp .= $pool[rand(0, $high)];
    }
    return $tmp;
}

if (!function_exists("api_log")) {
    function api_log($message) {
        global $server_info;
        $message = str_replace("\n", "", $message);
        $message = str_replace("\r", "", $message);
        $message = sprintf("%s - %s", date(DATE_ATOM), $message) . "\n";
        error_log($message, 3, $server_info["api_log"]);
    }
}

function contest_query() {
    global $contest_sql, $mysqli;
    $args = func_get_args();
    if (count($args) >= 1) {
        $query_name = $args[0];
        if (count($args) > 1) {
            $query_args = array_map(function($val) {
                global $mysqli;
                return mysqli_real_escape_string($mysqli, $val);
            }, array_slice($args, 1));
            $query = vsprintf($contest_sql[$query_name], $query_args);
        } else {
            $query = $contest_sql[$query_name];
        }
        $result = mysqli_query($mysqli, $query);
        if (!$result) {
            api_log("Contest Query Error: ".$query."\n".mysqli_error($mysqli));
        }
        return $result;
    }
}

function check_credentials($username, $password) {
    $result = prepared_query(
        "SELECT * FROM user u WHERE username = ? AND activated = 1",
        "s", $username
    );
    if ($user = mysqli_fetch_assoc($result)) {
        if (hash_equals(crypt($password, $user['password']), $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['username']   = $user['username'];
            $_SESSION['admin']      = $user['admin'];
            $_SESSION['user_id']    = $user['user_id'];
            return true;
        } else {
            return false;
        }
    } else {
        return false;
    }
}

function check_credentials_forgot($user_id, $forgot_code) {
    // $forgot_code is not encrypted nor stored in the database
    // $user['cookie'] is encrypted
    $user_forgets = contest_query("select_user_forgot_code", $user_id);
    while ($user = mysqli_fetch_assoc($user_forgets)) {
        if (hash_equals(crypt($forgot_code, $user['cookie']), $user['cookie'])) {
            // found valid cookie, delete it (single use)
            contest_query("delete_user_cookie", $user_id, $user['cookie']);
            // update session vars
            session_regenerate_id(true);
            $_SESSION['username']   = $user['username'];
            $_SESSION['admin']      = $user['admin'];
            $_SESSION['user_id']    = $user['user_id'];
            $_SESSION['cookie']     = $user['cookie'];
            return true;
        }
    }
    return false;
}

/*
 * Checks if stored in cookie value is right, logs in user if so.
 * Updates database and browser with new expiration date
 * @since 28 Oct 2011 bear@deepshiftlabs.com
 */
function check_credentials_cookie($user_id, $login_cookie) {
    // $login_cookie is not encrypted nor stored in the database
    // $user['cookie'] is encrypted
    $user_cookies = contest_query("select_user_cookies", $user_id);
    while ($user = mysqli_fetch_assoc($user_cookies)) {
        if (hash_equals(crypt($login_cookie, $user['cookie']), $user['cookie'])) {
            // found valid cookie, reset expire date
            contest_query("update_user_cookie", $user_id, $user['cookie']);
            setcookie('uid', $login_cookie, [
                'expires'  => time() + 60*60*24*5,
                'path'     => '/',
                'secure'   => true,
                'httponly'  => true,
                'samesite'  => 'Lax',
            ]);
            // update session vars
            session_regenerate_id(true);
            $_SESSION['username']   = $user['username'];
            $_SESSION['admin']      = $user['admin'];
            $_SESSION['user_id']    = $user['user_id'];
            $_SESSION['cookie']     = $user['cookie'];
            return true;
        }
    }
    return false;
}

/*
 * Generates and stores cookie for user in database and browser
 * @return string cookie_value if success, NULL otherwise
 * @since 28 Oct 2011 bear@deepshiftlabs.com
 */
function create_user_cookie($user_id) {
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $login_cookie = $user_id . "-" . salt(32, true);
        $encrypted_cookie = crypt($login_cookie, '$6$rounds=54321$' . salt() . '$');
        if (contest_query("insert_user_cookie", $user_id, $encrypted_cookie)) {
            setcookie('uid', $login_cookie, [
                'expires'  => time() + 60*60*24*5,
                'path'     => '/',
                'secure'   => true,
                'httponly'  => true,
                'samesite'  => 'Lax',
            ]);
            $_SESSION['cookie'] = $encrypted_cookie;
            return $login_cookie;
        } else {
            return NULL;
        }
    }
}

function delete_user_cookie() {
    if (isset($_SESSION['user_id']) && isset($_SESSION['cookie'])) {
        contest_query("delete_user_cookie", $_SESSION['user_id'], $_SESSION['cookie']);
    }
}

function create_user_forgot_code ($username) {
    $user_result = contest_query("select_user_by_name", $username);
    if ($user_result) {
        $user_row = mysqli_fetch_assoc($user_result);
        $user_id = $user_row['user_id'];
        $username = $user_row['username'];
        $user_email = $user_row['email'];
        $login_code = $user_id . "-" . salt(32, true);
        $encrypted_code = crypt($login_code, '$6$rounds=54321$' . salt() . '$');
        if (contest_query("insert_user_forgot_code", $user_id, $encrypted_code)) {
            return array($user_id, $username, $user_email, $login_code);
        } else {
            return NULL;
        }
    }
}

?>
