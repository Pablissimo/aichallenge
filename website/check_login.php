<?php

require_once('session.php');
require_once('mysql_login.php');

// A function I copied from teh internets that claims to get a person's "real"
// IP address. Not sure what that's all about, but let's log it anyways!
function getRealIpAddr()
{
    if (!empty($_SERVER['HTTP_CLIENT_IP']))
    {
      $ip=$_SERVER['HTTP_CLIENT_IP'];
    }
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
    {
      $ip=$_SERVER['HTTP_X_FORWARDED_FOR'];
    }
    else
    {
      $ip=$_SERVER['REMOTE_ADDR'];
    }
    return $ip;
}

// Log this login attempt
$username = $_POST['username'];
$password = $_POST['password'];
$naive_ip = $_SERVER['REMOTE_ADDR'];
$real_ip = getRealIpAddr();

$result = contest_query("log_login", $username, $naive_ip, $real_ip);
if (!$result) {
  error_log("Could not write to log: " . mysqli_error($mysqli));
}
if (check_credentials($username, $password)) {
    if (isset($_POST['remember_me'])) {
        create_user_cookie(current_user_id());
    }
    header("location:index.php");
} else {
    unset($_SESSION['username']);
    unset($_SESSION['password']);
    unset($_SESSION['admin']);
    unset($_SESSION['user_id']);
    header("location:login_failed.php");
}

?>
