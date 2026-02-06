<?php

require_once('mysql_login.php');
require_once('server_info.php');

if($_GET['api_create_key'] != $server_info["api_create_key"]){
  header('HTTP/1.0 401 Unauthorized');
  die();
}

$ip = $_SERVER['REMOTE_ADDR'];
$check_result = prepared_query(
    "select api_key from worker where ip_address = ?",
    "s", $ip
);
if ($check_result && mysqli_num_rows($check_result) != 0){
  $row = mysqli_fetch_row($check_result);
  $new_key = $row[0];
} else {
  $new_key = md5(uniqid(null,true).rand());

  $success = prepared_query(
      "insert into worker SET api_key = ?, ip_address = ?",
      "ss", $new_key, $ip
  );
  if(!$success){
    error_log("Failed to create worker key: " . mysqli_error($mysqli));
    die('#Failed to create worker key');
  }
}

$api_url = "http://".$_SERVER['SERVER_NAME'] . dirname($_SERVER['SCRIPT_NAME']);

?>

curl '<?php echo $api_url?>worker_init.py' > /root/worker_init.py
python /root/worker_init.py <?php echo $api_url.' '.$new_key ?> $@
