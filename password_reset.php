<?php

/**
 * A simple, clean and secure PHP Login Script
 * 
 * ADVANCED VERSION
 * (check the website / github / facebook for other versions)
 * 
 * A simple PHP Login Script without all the nerd bullshit.
 * Uses PHP SESSIONS, modern password-hashing and salting
 * and gives the basic functions a proper login system needs.
 * 
 * @package php-login
 * @author Panique <panique@web.de>
 * @link https://github.com/panique/php-login/
 * @license http://opensource.org/licenses/MIT MIT License
 */

// if you are using PHP 5.3 or PHP 5.4 you have to include the password_api_compatibility_library.php
// (this library adds the PHP 5.5 password hashing functions to older versions of PHP)
require_once("libraries/password_compatibility_library.php");

// include the configs / constants for the database connection
require_once("config/db.php");

// include the hashing cost factor (you can delete this line if you have never touched the cost factor,
// the script will then use the standard value)
require_once("config/hashing.php");

// include the email data for password reset mails
require_once("config/email_passwordreset.php");

// load the login class
require_once("classes/Login.php");

// create a login object. when this object is created, it will do all login/logout stuff automatically
// so this single line handles the entire login process.
$login = new Login();

// ask for the different states:
if ($login->passwordResetLinkIsValid() == true) {
    // the user just came to our page by the URL provided in the password-reset-mail and all data is valid
    // so we show the type-your-new-password form
    include("views/password_reset_new_password.php");    
    
} elseif ($login->passwordResetWasSuccessful() == true) {
    // the user has just successfully entered a new password
    // so we show the index page = the login page
    include("views/not_logged_in.php");
    
} else {
    // no data from a password-reset-mail has been provided, so we simply show the request-a-password-reset form
    include("views/password_reset_request.php");
}
