<?php

$title="Processing Registration";
require_once 'header.php';
require_once 'server_info.php';

if($server_info["submissions_open"]) {

require_once 'mysql_login.php';
require_once 'bad_words.php';
require_once 'web_util.php';
require_once('memcache.php');

function check_valid_user_status_code($code) {
  $result = prepared_query(
      "SELECT * FROM user_status_code WHERE status_id = ?",
      "i", (int)$code
  );
  return (boolean)mysqli_num_rows($result);
}

function check_valid_organization($code) {
  if ($code == 999) {
    return False;
  }
  $result = prepared_query(
      "SELECT * FROM organization WHERE org_id = ?",
      "i", (int)$code
  );
  return (boolean)mysqli_num_rows($result);
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
  if ($row['cnt'] > 0) {
    return True;
  }
  return False;
}

function valid_username($s) {
  return strspn($s, "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.-") == strlen($s);
}

function create_new_organization( $org_name ) {
    global $memcache;
    global $mysqli;

    if ($memcache) {
        $memcache->delete('lookup:org_id');
        $memcache->delete('lookup:org_name');
    }
    $result = prepared_query(
        "SELECT org_id FROM organization WHERE name = ?",
        "s", $org_name
    );
    if ( mysqli_num_rows($result) > 0 ) {
        $row = mysqli_fetch_row($result);
        return $row[0];
    } else {
        prepared_query(
            "INSERT INTO organization (`name`) VALUES(?)",
            "s", $org_name
        );
        return mysqli_insert_id($mysqli);
    }
}

// By default, send account confirmation emails.
$send_email = 1;

$errors = array();
// Check that required information was sent
if (!isset($_POST['username'], $_POST['password1'], $_POST['password2'],
        $_POST['user_email'], $_POST['user_status'],  $_POST['user_country'],
        $_POST['user_organization'], $_POST['bio'])) {
    die("Missing required information to create profile");
}

// Gather the information entered by the user on the signup page.
$username = $_POST['username'];
$password1 = $_POST['password1'];
$password2 = $_POST['password2'];
$user_email = $_POST['user_email'];
$user_status = (int)$_POST['user_status'];
$user_org = $_POST['user_organization'];
$bio = $_POST['bio'];
$country_id = (int)$_POST['user_country'];

// Check for bad words
if (contains_bad_word($username)) {
  $errors[] = "Your username contains a bad word. Keep it professional.";
}
if (contains_bad_word($bio)) {
  $errors[] = "Your bio contains a bad word. Keep it professional.";
}

// Check if mailer address is "donotsend". If so, don't send any confirmation
// mails. Display the confirmation code once the account creation finishes,
// and let them access the account activation page themselves. This should
// only be used when setting up test servers as contestants email addresses
// will not be verified.
if (strcmp($server_info["mailer_address"], "donotsend") == 0) {
  $send_email = 0;
}
else
{
    require_once "email.php";
}

// Check if the username already exists.
$result = prepared_query("SELECT * FROM user WHERE username = ?", "s", $username);
if (mysqli_num_rows($result) > 0) {
  $errors[] = "The username " . h($username) . " is already in use. Please choose a different username.";
}

// Check that the email address is not blank.
if (strlen($user_email) <= 0) {
  $errors[] = "You must provide an email address. The email address that you specify will be used to activate your account.";
}

// Check if the email is already in use (except by an admin account or a donotsend account).
if (strcmp($user_email, "donotsend") != 0) {
  $result = prepared_query(
      "select email from user where email = ? and admin = 0",
      "s", $user_email
  );
  if ($result && mysqli_num_rows($result) > 0) {
    $errors[] = "The email " . h($user_email) . " is already in use. You are only allowed to have one account! It is easy for us to tell if you have two accounts, and you will be disqualified if you have two accounts! If there is some problem with your existing account, get in touch with the contest organizers on irc.freenode.com channel #aichallenge and we will help you get up-and-running again!";
  }
  $edomain = substr(strrchr($user_email, '@'), 1);
  $mx_records = array();
  if (!getmxrr($edomain, $mx_records) && (strcmp(gethostbyname($edomain), $edomain) == 0)) {
    $errors[] = "Could not find the email address entered. Please enter a valid email address.";
  }
}

// Check if the username is made up of the right kinds of characters
if (!valid_username($username)) {
  $errors[] = "Invalid username. Your username must be longer than 4 characters and composed only of the characters a-z, A-Z, 0-9, '-', '_', and '.'";
}

// Check that the username is between 6 and 16 characters long
if (strlen($username) < 5 || strlen($username) > 16) {
  $errors[] = "Your username must be between 5 and 16 characters long.";
}

// Check that the two passwords given match.
if ($password1 !== $password2) {
  $errors[] = "You made a mistake while entering your password. "
            . "The two passwords that you give should match.";
}

// Check that the desired password is long enough.
if (strlen($password1) < 5) {
  $errors[] = "Your password must be at least 5 characters long.";
}

// Check that the user status code is valid.
if (!check_valid_user_status_code($user_status)) {
  $errors[] = "The status you selected is invalid. Please contact the contest staff.";
}

// Check that the country code is not empty.
if (!check_valid_country($country_id)) {
  $errors[] = "You did not select a valid country from the dropdown box.";
}

// Check that the user organziation code is valid.
if( $user_org == '-1') {
    $_POST['user_organization_other'] = trim($_POST['user_organization_other']);
    if( $_POST['user_organization_other'] === '' ) {
        //don't create empty organizations
        $user_org = 0;
    } else {
        $user_org = create_new_organization($_POST['user_organization_other']);
    }
} elseif (!check_valid_organization($user_org)) {
  $errors[] = "The organization you selected is invalid. Please contact the contest staff.";
}
$user_org = (int)$user_org;

if (count($errors) <= 0) {
  // Add the user to the database, with no permissions.
  $confirmation_code = md5(salt(64));
  $result = prepared_query(
      "SELECT org.name AS name, COUNT(u.user_id) AS peers
       FROM organization org
       LEFT OUTER JOIN user u ON u.org_id = org.org_id
       WHERE org.org_id = ?
       GROUP BY org.name",
      "i", $user_org
  );
  $peer_message = "";
  $org_name = "";
  $num_peers = "";
  if ($result) {
    if ($row = mysqli_fetch_assoc($result)) {
      $org_name = $row['name'];
      $num_peers = $row['peers'];
      if ($num_peers == 0) {
        $peer_message = "You are the first person from your organization to sign up " .
          "for the AI Challenge. We would really appreciate it if you would " .
          "encourage your friends to sign up for the Challenge as well. The more, " .
          "the merrier!\n\n";
      } else if (strcmp($org_name, "Other") == 0) {
          $peer_message = "You didn't associate yourself with an organization ".
              "when you signed up. You might want to change this in your ".
              "profile so you can compare how you're doing with others in ".
              "your school or company.\n\n";
      } else {
        $peer_message = "" . $num_peers . " other people from " . $org_name .
          " have already signed up for the AI Challenge. When you look " .
          "at the rankings, you can see the global rankings, or " .
          "you can filter the list to only show other contestants from your organization!\n\n";
      }
    }
  }
  $hashed_password = crypt($password1, '$6$rounds=54321$' . salt() . '$');
  $insert_result = prepared_query(
      "INSERT INTO user (username, `password`, email, status_id, activation_code, org_id, bio, country_id, created, activated, admin)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 0, 0)",
      "sssissis",
      $username, $hashed_password, $user_email, $user_status,
      $confirmation_code, $user_org, $bio, $country_id
  );
  if ($insert_result) {
    if ($memcache) {
        $memcache->delete('lookup:user_id');
        $memcache->delete('lookup:username');
    }
    // Send confirmation mail to user.
    $mail_subject = "AI Challenge!";
    $activation_url = current_url();
    $activation_url = str_replace("process_registration.php",
                                  "account_confirmation.php",
                                  $activation_url);
    if (strlen($activation_url) < 5) {
      $activation_url = "http://aichallenge.org/account_confirmation.php";
    }
    $mail_content = "Welcome to the contest! Click the link below in order " .
      "to activate your account.\n\n" .
      $activation_url .
      "?confirmation_code=" . $confirmation_code . "\n\n" .
      "After you activate your account by clicking the link above, you will " .
      "be able to sign in and start competing. Good luck!\n\n" .
      $peer_message . "Thanks for participating and have fun,\nContest Staff\n";
    if ($send_email == 1 && strcmp($user_email, "donotsend") != 0) {
      $mail_accepted = send_email($user_email, $mail_subject, $mail_content);
    } else {
      $mail_accepted = true;
    }
    if (intval($mail_accepted) == 0) {
      $errors[] = "Failed to send confirmation email. Try again in a few " .
        "minutes.";
      prepared_query(
          "DELETE FROM user WHERE username = ? and activation_code = ?",
          "ss", $username, $confirmation_code
      );
    }
  } else {
    $errors[] = "Failed to communicate with the registration database. Try " .
      "again in a few minutes.";
  }
}
if (count($errors) > 0) {
    require 'register.php';
} else {
?>

<h1>Registration Successful!</h1>
<p>Thank you for registering for the contest! A confirmation
   message will be sent to the email address that you provided.
   You must click the link in that message in order to activate
   your account.</p>
<h2>Check Your Junk Mail Folder</h2>
<p>If you don't see it in five minutes, remember to check your
   junk mail folder. Some free email providers are known to
   mistake confirmation emails for junk mail. Before you even think
   of sending us mail asking for help, <strong>check your junk mail
   folder!</strong></p>
<p><a href="index.php">Back to the home page.</a></p>

<?php

if ($send_email == 0) {
  echo "<p>Confirmation emails are not being sent!</p>";
  echo "<p>This should only be used when setting up a test server.</p>";
  echo '<p><a href="account_confirmation.php?confirmation_code=' .
       h($confirmation_code) . '">Click Here</a> to activate the account.</p>';
}

}  // end if
?>

<?php } else { ?>

<p>Sorry, account creation is now closed.</p>

<?php } ?>

<?php require_once 'footer.php'; ?>
