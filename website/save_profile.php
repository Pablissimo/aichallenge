<?php
require_once('session.php');
require_once('mysql_login.php');
require_once('bad_words.php');

function check_valid_organization($id) {
  if ($id == 999 || filter_var($id, FILTER_VALIDATE_INT) === FALSE) {
    return False;
  }
  $result = prepared_query(
      "SELECT count(*) as cnt FROM organization where org_id = ?",
      "i", (int)$id
  );
  $row = mysqli_fetch_assoc($result);
  if ($row['cnt'] > 0)
    return True;
  return False;
}

function check_valid_country($id) {
  if ($id == 999 || !filter_var($id, FILTER_VALIDATE_INT)) {
    return False;
  }
  $result = prepared_query(
      "SELECT count(*) as cnt from country where country_id = ?",
      "i", (int)$id
  );
  $row = mysqli_fetch_assoc($result);
  if ($row['cnt'] > 0)
    return True;
  return False;
}

if (!logged_in_with_valid_credentials())
  die ("Nice try.. but the bad robot WILL get you");

if (!array_key_exists('update_key', $_POST)) {
  die("Check key not found.");
}

$user_id = current_user_id();

$result = prepared_query(
    "SELECT email, activation_code FROM user WHERE user_id = ?",
    "i", (int)$user_id
);
if ($row = mysqli_fetch_assoc($result)) {
  $sid = session_id();
  $update_key = $_POST['update_key'];
  $local_key = sha1($sid . $row['activation_code'] . $row['email']);
  if (!hash_equals($local_key, $update_key)) {
    die("Bad update key found.");
  }
} else {
  die("User data query failed.");
}

if (array_key_exists('user_country', $_POST)) {
  $country_id = $_POST['user_country'];
  if (!check_valid_country($country_id))
    die("Invalid country id.");
  if (!prepared_query(
      "UPDATE user SET country_id = ? WHERE user_id = ?",
      "ii", (int)$country_id, (int)$user_id
  ))
    die("Sorry, database update failed.");
}

if (array_key_exists('user_organization', $_POST)) {
  $code = $_POST['user_organization'];
  if (!check_valid_organization($code))
    die("Invalid organization id.");
  if (!prepared_query(
      "UPDATE user SET org_id = ? WHERE user_id = ?",
      "ii", (int)$code, (int)$user_id
  ))
    die("Sorry, database update failed.");
}

if (array_key_exists('user_bio', $_POST)) {
  $bio = $_POST['user_bio'];
  if (contains_bad_word($bio)) {
    die("Your bio contains a bad word. Keep it professional.");
  }
  prepared_query(
      "UPDATE user SET bio = ? WHERE user_id = ?",
      "si", $bio, (int)$user_id
  );
}


header('Location: profile.php?user='. (int)$user_id);
?>
