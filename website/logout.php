<?php

require_once('mysql_login.php');
session_start();
delete_user_cookie();  
session_destroy();
setcookie("uid", "", [
    'expires'  => time() - 60*60*24*365,
    'path'     => '/',
    'secure'   => true,
    'httponly'  => true,
    'samesite'  => 'Lax',
]);
header('Location:index.php');

?>
