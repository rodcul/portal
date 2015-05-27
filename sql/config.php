<?php
/* Orignally written by Andrew Niemantsverdriet 
 * email: andrewniemants@gmail.com
 * website: http://www.rimrockhosting.com
 *
 * This code is on Github: https://github.com/kaptk2/portal
 *
 * Copyright (c) 2015, Andrew Niemantsverdriet <andrewniemants@gmail.com>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met: 
 *
 * 1. Redistributions of source code must retain the above copyright notice, this
 *    list of conditions and the following disclaimer. 
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution. 
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * The views and conclusions contained in the software and documentation are those
 * of the authors and should not be interpreted as representing official policies, 
 * either expressed or implied, of the FreeBSD Project.
*/

define('__ROOT__', dirname((__FILE__))); 

session_start();

/**********************************************************************/
//                          Configuraion                              //
/**********************************************************************/
$supportEmail = 'support@example.com';

// UniFi Connection details
$unifi = array(
          'unifiServer' => "https://unifi.example.com:8443",
          'unifiUser'   => "admin",
          'unifiPass'   => "password"
        );

// SQL Database Connection
$dbType = 'sqlite';
$dbName = __ROOT__.'/database/unifi.sqlite';

// Date settings
date_default_timezone_set('America/Denver');

/**********************************************************************/
//                         End configuration                          // 
/**********************************************************************/

try {
  //open the database
  $db = new PDO($dbType.':'.$dbName);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
  error_log('Database exception: '.$e->getMessage(), 1, $supportEmail);
  echo('Exception: '.$e->getMessage());
}

function generatePass() {
  // Generate random password
  srand(make_seed());
  $alfa = "23456789qwertyuiopasdfghjkzxcvbnmQWERTYUIPASDFGHJKLZXCVBNM";
  $password = "";
  for($i = 0; $i < 7; $i ++) {
    $password .= $alfa[rand(0, strlen($alfa)-1)];
  }
  return $password;
}

function make_seed() {
  // Generate a random seed for use in password generation
  list($usec, $sec) = explode(' ', microtime());
  return (float) $sec + ((float) $usec * 100000);
}

function addUser($guest) {
  // Add a user to the database
  global $db;

  try {
    // Insert a new user database
    $sql = "INSERT INTO guests (username, password, expires, notes) VALUES (:username, :password, :expires, :notes)";
    $sth = $db->prepare($sql);
    $sth->bindParam(':username', $guest['username'], PDO::PARAM_STR);
    $sth->bindParam(':password', $guest['password'], PDO::PARAM_STR);
    $sth->bindParam(':expires', $guest['expires'], PDO::PARAM_STR);
    $sth->bindParam(':notes', $guest['notes'], PDO::PARAM_STR);
    $sth->execute();
  } catch(PDOException $e) {
    error_log('addUser exception: '.$guest['username'].' Message: '.$e->getMessage(), 0);
    return false;
  }
  // Everything went okay
  return true;
}

function removeUser($id) {
  global $db;

  try {
    $sql = 'DELETE FROM guests WHERE id=:id';
    $sth = $db->prepare($sql);
    $sth->bindParam(':id', $id, PDO::PARAM_STR);
    $sth->execute();
  } catch(PDOException $e) {
    error_log('removeUser exception: '.$id.' Message: '.$e->getMessage(), 0);
    return false;
  }
  // Everything went okay
  return true;
}

function authorizeSQL($username, $password) {
  global $db;

  try {
    // See if user is in the database
    $sth = $db->prepare('SELECT password, expires FROM guests WHERE username = :username');
    $sth->bindParam(':username', $username, PDO::PARAM_STR); 
    $sth->execute();
    $result = $sth->fetch();
    $db = NULL; // Close the connection
  } catch(PDOException $e) {
    error_log('authorizeSQL exception: '.$e->getMessage(), 0);
    return false;
  }

  $t = time(); // Unix time stamp

  // See if the user has a valid password and remaining time
  if (password_verify($password, $result['password'])) {
    if ($result['expires'] > $t) {
      return true;
    }
  }
  // Always return false if something goes wrong
  return false;
}

function sendAuthorization($id, $minutes, $unifi) {
  // Start Curl for login
  $ch = curl_init();
  // Return output instead of displaying it
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  // We are posting data
  curl_setopt($ch, CURLOPT_POST, TRUE);
  // Set up cookies
  $cookie_file = "/tmp/unifi_cookie";
  curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
  curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
  // Allow Self Signed Certs
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
  curl_setopt($ch, CURLOPT_SSLVERSION, 1);
  // Login to the UniFi controller
  curl_setopt($ch, CURLOPT_URL, $unifi['unifiServer']."/api/login");

  $data = json_encode(array("username" => $unifi['unifiUser'],"password" => $unifi['unifiPass']));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
  curl_exec($ch);

  // Send user to authorize and the time allowed
  $data = json_encode(array(
          'cmd'=>'authorize-guest',
          'mac'=>$id,
          'minutes'=>$minutes));

  // Make the API Call
  curl_setopt($ch, CURLOPT_URL, $unifi['unifiServer'].'/api/s/default/cmd/stamgr');
  curl_setopt($ch, CURLOPT_POSTFIELDS, 'json='.$data);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
  curl_exec ($ch);

  
  // Logout of the connection
  curl_setopt($ch, CURLOPT_URL, $unifi['unifiServer']."/logout");
  curl_exec ($ch);
  curl_close ($ch);

  sleep(6); // Small sleep to allow controller time to authorize
}
?>
