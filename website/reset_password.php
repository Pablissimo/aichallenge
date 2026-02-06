<?php
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);

require_once('mysql_login.php');

global $mysqli;
if (isset($argv[1])) {
	$username = mysqli_real_escape_string($mysqli, stripslashes($argv[1]));
	echo "Enter new password: ";
	$password1 = mysqli_real_escape_string($mysqli, stripslashes(str_replace(array("\r","\n"), "", fgets(STDIN))));
	echo "Retype new password: ";
	$password2 = mysqli_real_escape_string($mysqli, stripslashes(str_replace(array("\r","\n"), "", fgets(STDIN))));
	
	if ($password1 == $password2) {
		$passhash = crypt($password1, '$6$rounds=54321$' . salt() . '$');
		echo "Password hash is " . $passhash . "\n";
		$sql = "update user
		        set password = '" . $passhash ."'
		        where username = '" . $username . "'";
		if (mysqli_query($mysqli, $sql)) {
			echo "Password has been changed\n";
			if (check_credentials($username, $password1)) {
				echo "Password verified.\n";
			} else {
				echo "Password not verified.\n";
			}
		} else {
			echo "Database error.\n";
		}
	} else {
		echo "Sorry, passwords do not match\n";
	}
} else {
	echo "Usage: php reset_password.php <username>\n";
}
?>