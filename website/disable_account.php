<?php

require_once('session.php');
require_once('mysql_login.php');

if (!(logged_in_with_valid_credentials() && logged_in_as_admin()))
    die("Forget it, you must be logged in as admin.");

if (!isset($_POST['user_id']) || !isset($_POST['reason']))
    die("Did not receive user_id or reason");

$user_id = (int)$_POST['user_id'];
$reason = $_POST['reason'];

$result = prepared_query("SELECT * from user where user_id = ?", "i", $user_id);
if (!$result || mysqli_num_rows($result) != 1)
    die("Could not find the user account");
$user = mysqli_fetch_assoc($result);

if ($user['password'] === "")
    die("This account is already disabled");

$admin = current_username();
$bio = $user['bio'] . " - " . $reason . " by " . $admin;
$email = $user['email'] . " disabled";

prepared_query(
    "UPDATE user SET email = ?, bio = ?, password = '' WHERE user_id = ?",
    "ssi", $email, $bio, $user_id
);
prepared_query(
    "UPDATE submission SET latest = 0 WHERE user_id = ?",
    "i", $user_id
);

header("Location: profile.php?user=" . $user_id);

?>
