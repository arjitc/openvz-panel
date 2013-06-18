<?php

/**
 * class Login
 * handles the user login/logout/session
 * 
 * @author Panique <panique@web.de>
 */
class Login {
    
    // TODO: initialize strings with "" or null ?

    /** @var object $db_connection The database connection */
    private $db_connection = null;
    
    /** @var int $hash_cost_factor The (optional) cost factor for the hash calculation */
    private $hash_cost_factor = null;

    /** @var int $user_id The user's id */
    private $user_id = null;
    
    /** @var string $user_name The user's name */
    private $user_name = "";
    
    /** @var string $user_email The user's mail */
    private $user_email = "";
    
    /** @var string $user_password_hash The user's hashed and salted password */
    private $user_password_hash = "";
    
    /** @var boolean $user_is_logged_in The user's login status */
    private $user_is_logged_in = false;
    
    /** @var string $user_password_reset_hash The user's password reset hash */
    private $user_password_reset_hash = "";
    
    /** @var string $user_gravatar_image_url The user's gravatar profile pic url (or a default one) */
    public $user_gravatar_image_url = "";
    
    /** @var string $user_gravatar_image_tag The user's gravatar profile pic url with <img ... /> around */
    public $user_gravatar_image_tag = "";
    
    /** @var boolean $password_reset_link_is_valid Marker for view handling */
    private $password_reset_link_is_valid  = false;
    
    /** @var boolean $password_reset_was_successful Marker for view handling */
    private $password_reset_was_successful = false;

    /** @var array $errors Collection of error messages */
    public $errors = array();
    
    /** @var array $messages Collection of success / neutral messages */
    public $messages = array();
    
    
    /**
     * the function "__construct()" automatically starts whenever an object of this class is created,
     * you know, when you do "$login = new Login();"
     */    
    public function __construct() {
        
        // create/read session
        session_start();                                        

        // check the possible login actions:
        // 1. logout (happen when user clicks logout button)
        // 2. login via session data (happens each time user opens a page on your php project AFTER he has successfully logged in via the login form)
        // 3. login via post data, which means simply logging in via the login form. after the user has submit his login/password successfully, his
        //    logged-in-status is written into his session data on the server. this is the typical behaviour of common login scripts.
        
        // if user tried to log out
        if (isset($_GET["logout"])) {

            $this->doLogout();
                    
        }
        // if user has an active session on the server
        elseif (!empty($_SESSION['user_name']) && ($_SESSION['user_logged_in'] == 1)) {
            
            $this->loginWithSessionData();      
            
            // checking for form submit from editing screen
            if (isset($_POST["user_edit_submit_name"])) {
                
                $this->editUserName();
                
            } elseif (isset($_POST["user_edit_submit_email"])) {
                
                $this->editUserEmail();
                
            } elseif (isset($_POST["user_edit_submit_password"])) {
                
                $this->editUserPassword();
                
            } 

        // if user just submitted a login form
        } elseif (isset($_POST["login"])) {

                $this->loginWithPostData();
                
        }
        
        // checking if user requested a password reset mail
        if (isset($_POST["request_password_reset"])) {
            
            $this->setPasswordResetDatabaseTokenAndSendMail(); // maybe a little bit cheesy
            
        } elseif (isset($_GET["user_name"]) && isset($_GET["verification_code"])) {
            
            $this->checkIfEmailVerificationCodeIsValid();
            
        } elseif (isset($_POST["submit_new_password"])) {
            
            $this->editNewPassword();
            
        }
        
        // get gravatar profile picture if user is logged in
        if ($this->isUserLoggedIn() == true) {
            
            $this->getGravatarImageUrl($this->user_email);
            
        }
        
    }    
    

    private function loginWithSessionData() {
        
        // set logged in status to true, because we just checked for this:
        // !empty($_SESSION['user_name']) && ($_SESSION['user_logged_in'] == 1)
        // when we called this method (in the constructor)
        $this->user_is_logged_in = true;
        
    }
    

    private function loginWithPostData() {
        
        // if POST data (from login form) contains non-empty user_name and non-empty user_password
        if (!empty($_POST['user_name']) && !empty($_POST['user_password'])) {
            
            // create a database connection, using the constants from config/db.php (which we loaded in index.php)
            $this->db_connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            
            // if no connection errors (= working database connection)
            if (!$this->db_connection->connect_errno) {
                
                // escape the POST stuff
                $this->user_name = $this->db_connection->real_escape_string($_POST['user_name']);            
                // database query, getting all the info of the selected user
                $checklogin = $this->db_connection->query("SELECT user_id, user_name, user_email, user_password_hash, user_active FROM users WHERE user_name = '".$this->user_name."';");

                // if this user exists
                if ($checklogin->num_rows == 1) {

                    // get result row (as an object)
                    $result_row = $checklogin->fetch_object();

                    // using PHP 5.5's password_verify() function to check if the provided passwords fits to the hash of that user's password
                    if (password_verify($_POST['user_password'], $result_row->user_password_hash)) {

                        if ($result_row->user_active == 1) {
                        
                            // write user data into PHP SESSION [a file on your server]
                            $_SESSION['user_id'] = $result_row->user_id;
                            $_SESSION['user_name'] = $result_row->user_name;
                            $_SESSION['user_email'] = $result_row->user_email;
                            $_SESSION['user_logged_in'] = 1;

                            // declare user id, set the login status to true
                            $this->user_id = $result_row->user_id;
                            $this->user_is_logged_in = true;
                            
                            // OPTIONAL: recalculate the user's password hash
                            // DELETE this if-block if you like, it only exists to recalculate users's hashes when you provide a cost factor,
                            // by default the script will use a cost factor of 10 and never change it.
                            // check if the have defined a cost factor in config/hashing.php
                            if (defined('HASH_COST_FACTOR')) {
                                
                                // check if the hash needs to be rehashed
                                if (password_needs_rehash($result_row->user_password_hash, PASSWORD_DEFAULT, array('cost' => HASH_COST_FACTOR))) {
                                    
                                    // calculate new hash with new cost factor
                                    $this->user_password_hash = password_hash($_POST['user_password'], PASSWORD_DEFAULT, array('cost' => HASH_COST_FACTOR));
                                    
                                    // TODO: this should be put into another method !?
                                    $this->db_connection->query("UPDATE users SET user_password_hash = '$this->user_password_hash' WHERE user_id = '$this->user_id';");
                                    
                                    if ($this->db_connection->affected_rows == 0) {

                                        // writing new hash was successful. you should now output this to the user ;)

                                    } else {

                                        // writing new hash was NOT successful. you should now output this to the user ;)

                                    }
                                    
                                }
                                
                            }
                            
                            // TO CLARIFY: in future versions of the script: should we rehash every hash with standard cost factor
                            // when the HASH_COST_FACTOR in config/hashing.php is commented out ?                            
                        
                        } else {
                            
                            $this->errors[] = "Your account is not activated yet. Please click on the confirm link in the mail.";
                            
                        }

                    } else {

                        $this->errors[] = "Wrong password. Try again.";

                    }                

                } else {

                    $this->errors[] = "This user does not exist.";
                }
                
            } else {
                
                $this->errors[] = "Database connection problem.";
            }
            
        } elseif (empty($_POST['user_name'])) {

            $this->errors[] = "Username field was empty.";

        } elseif (empty($_POST['user_password'])) {

            $this->errors[] = "Password field was empty.";
        }           
        
    }
    
    /**
     * perform the logout
     */
    public function doLogout() {
            
            $_SESSION = array();
            session_destroy();
            $this->user_is_logged_in = false;
            $this->messages[] = "You have been logged out.";     
            
    }
    
    /**
     * simply return the current state of the user's login
     * @return boolean user's login status
     */
    public function isUserLoggedIn() {
        
        return $this->user_is_logged_in;
        
    }
    
    /**
     * edit the user's name, provided in the editing form
     */
    public function editUserName() {
        
        
        if (!empty($_POST['user_name']) && $_POST['user_name'] == $_SESSION["user_name"]) {
            
            $this->errors[] = "Sorry, that username is the same as your current one. Please choose another one.";
        
        } 
        // username cannot be empty and must be azAZ09 and 2-64 characters
        // TODO: maybe this pattern should also be implemented in Registration.php (or other way round)
        elseif (!empty($_POST['user_name']) && preg_match("/^(?=.{2,64}$)[a-zA-Z][a-zA-Z0-9]*(?: [a-zA-Z0-9]+)*$/", $_POST['user_name'])) {
            
            // creating a database connection
            $this->db_connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

            // if no connection errors (= working database connection)
            if (!$this->db_connection->connect_errno) {

                // escapin' this
                $this->user_name = $this->db_connection->real_escape_string(htmlentities($_POST['user_name'], ENT_QUOTES));
                $this->user_name = substr($this->user_name, 0, 64); // TODO: is this really necessary ?
                $this->user_id = $this->db_connection->real_escape_string($_SESSION['user_id']); // TODO: is this really necessary ?
                
                // check if new username already exists
                $query_check_user_name = $this->db_connection->query("SELECT * FROM users WHERE user_name = '".$this->user_name."';");

                if ($query_check_user_name->num_rows == 1) {

                    $this->errors[] = "Sorry, that username is already taken. Please choose another one.";

                } else {
                    
                    // write user's new data into database
                    $query_edit_user_name = $this->db_connection->query("UPDATE users SET user_name = '$this->user_name' WHERE user_id = '$this->user_id';");

                    if ($query_edit_user_name) {

                        $_SESSION['user_name'] = $this->user_name;
                        $this->messages[] = "Your username has been changed successfully. New username is " . $this->user_name . ".";

                    } else {

                        $this->errors[] = "Sorry, your chosen username renaming failed.";

                    }
                    
                }
                
            } else {
                
                $this->errors[] = "Sorry, no database connection.";
                
            }
            
        } else {
            
            $this->errors[] = "Sorry, your chosen username does not fit into the naming pattern.";
            
        }        
        
    }
    
    /**
     * edit the user's email, provided in the editing form
     */
    public function editUserEmail() {
        
        
        if (!empty($_POST['user_email']) && $_POST['user_email'] == $_SESSION["user_email"]) {
            
            $this->errors[] = "Sorry, that email address is the same as your current one. Please choose another one.";
        
        } 
        // user mail cannot be empty and must be in email format
        elseif (!empty($_POST['user_email']) && filter_var($_POST['user_email'], FILTER_VALIDATE_EMAIL)) {
            
            
            // creating a database connection
            $this->db_connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

            // if no connection errors (= working database connection)
            if (!$this->db_connection->connect_errno) {
                
                // escapin' this
                $this->user_email = $this->db_connection->real_escape_string(htmlentities($_POST['user_email'], ENT_QUOTES));
                // prevent database flooding
                $this->user_email = substr($this->user_email, 0, 64); 
                // not really necessary, but just in case...
                $this->user_id = $this->db_connection->real_escape_string($_SESSION['user_id']); 
                                   
                // write users new data into database
                $query_edit_user_email = $this->db_connection->query("UPDATE users SET user_email = '$this->user_email' WHERE user_id = '$this->user_id';");

                if ($query_edit_user_email) {

                    $_SESSION['user_email'] = $this->user_email;
                    $this->messages[] = "Your email address has been changed successfully. New email address is " . $this->user_email . ".";

                } else {

                    $this->errors[] = "Sorry, your email changing failed.";

                }
                
            } else {
                
                $this->errors[] = "Sorry, no database connection.";
                
            }
            
        } else {
            
            $this->errors[] = "Sorry, your chosen email does not fit into the naming pattern.";
            
        }        
        
    }  
    
    /**
     * edit the user's password, provided in the editing form
     */
    public function editUserPassword() {
        
        if (empty($_POST['user_password_new']) || empty($_POST['user_password_repeat']) || empty($_POST['user_password_old'])) {
          
            $this->errors[] = "Empty Password";            
            
        } elseif ($_POST['user_password_new'] !== $_POST['user_password_repeat']) {
          
            $this->errors[] = "Password and password repeat are not the same";   
            
        } elseif (strlen($_POST['user_password_new']) < 6) {
            
            $this->errors[] = "Password has a minimum length of 6 characters";            
                  
        } else if (!empty($_POST['user_password_old'])
                  && !empty($_POST['user_password_new']) 
                  && !empty($_POST['user_password_repeat']) 
                  && ($_POST['user_password_new'] === $_POST['user_password_repeat'])) {
                        
            // creating a database connection
            $this->db_connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

            // if no connection errors (= working database connection)
            if (!$this->db_connection->connect_errno) {
                        
                // database query, getting hash of currently logged in user (to check with just provided password)
                $check_for_right_password = $this->db_connection->query("SELECT user_password_hash FROM users WHERE user_id = '".$_SESSION['user_id']."';");

                // if this user exists
                if ($check_for_right_password->num_rows == 1) {

                    // get result row (as an object)
                    $result_row = $check_for_right_password->fetch_object();

                    // using PHP 5.5's password_verify() function to check if the provided passwords fits to the hash of that user's password
                    if (password_verify($_POST['user_password_old'], $result_row->user_password_hash)) {
                        
                        // now it gets a little bit crazy: check if we have a constant HASH_COST_FACTOR defined (in config/hashing.php),
                        // if so: put the value into $this->hash_cost_factor, if not, make $this->hash_cost_factor = null
                        $this->hash_cost_factor = (defined('HASH_COST_FACTOR') ? HASH_COST_FACTOR : null);

                        // crypt the user's password with the PHP 5.5's password_hash() function, results in a 60 character hash string
                        // the PASSWORD_DEFAULT constant is defined by the PHP 5.5, or if you are using PHP 5.3/5.4, by the password hashing
                        // compatibility library. the third parameter looks a little bit shitty, but that's how those PHP 5.5 functions
                        // want the parameter: as an array with, currently only used with 'cost' => XX.
                        $this->user_password_hash = password_hash($_POST['user_password_new'], PASSWORD_DEFAULT, array('cost' => $this->hash_cost_factor));                        
                        
                        // write users new hash into database
                        $this->db_connection->query("UPDATE users SET user_password_hash = '$this->user_password_hash' WHERE user_id = '".$_SESSION['user_id']."';");

                        // check if exactly one row was successfully changed:
                        if ($this->db_connection->affected_rows == 1) {

                            $this->messages[] = "Password sucessfully changed!";

                        } else {

                            $this->errors[] = "Sorry, your password changing failed.";

                        }

                    } else {

                        $this->errors[] = "Your OLD password was wrong.";

                    }                

                } else {

                    $this->errors[] = "This user does not exist.";
                }
                
            } else {
                
                $this->errors[] = "Database connection problem.";
            }            
            
        }
        
    }   
    
    /**
     * 
     */
    public function setPasswordResetDatabaseTokenAndSendMail() {
        
        // set token (= a random hash string and a timestamp) into database, to see that THIS user really requested a password reset
        if ($this->setPasswordResetDatabaseToken() == true) {
        
            // send a mail to the user, containing a link with that token hash string
            $this->sendPasswordResetMail();
            
        }
        
    }
    
    /**
     * 
     */
    public function setPasswordResetDatabaseToken() {
        
        if (empty($_POST['user_name'])) {
          
            $this->errors[] = "Empty username";
            
        } else {
            
            // generate timestamp (to see when exactly the user (or an attacker) requested the password reset mail)
            // btw this is an integer ;)
            $temporary_timestamp = time();
            
            // generate random hash for email password reset verification (40 char string)
            $this->user_password_reset_hash = sha1(uniqid(mt_rand(), true));
            
            // creating a database connection
            $this->db_connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

            // if no connection errors (= working database connection)
            if (!$this->db_connection->connect_errno) {
                
                // TODO: this is not totally clean, as this is just the form provided username
                $this->user_name = $this->db_connection->real_escape_string(htmlentities($_POST['user_name'], ENT_QUOTES));                
                $query_get_user_data = $this->db_connection->query("SELECT user_id, user_email FROM users WHERE user_name = '".$this->user_name."';");
                
                // if this user exists
                if ($query_get_user_data->num_rows == 1) {

                    // get result row (as an object)
                    $result_user_row = $query_get_user_data->fetch_object();
                        
                    // database query: 
                    $this->db_connection->query("UPDATE users 
                                                 SET user_password_reset_hash = '".$this->user_password_reset_hash."', 
                                                     user_password_reset_timestamp = '".$temporary_timestamp."' 
                                                 WHERE user_name = '".$this->user_name."';");

                    // check if exactly one row was successfully changed:
                    if ($this->db_connection->affected_rows == 1) {

                        // define email
                        $this->user_email = $result_user_row->user_email;

                        return true;

                    } else {

                        $this->errors[] = "Could not write token to database."; // maybe say something not that technical.

                    }                    
                        
                } else {

                    $this->errors[] = "This username does not exist.";

                }
                
            } else {
                
                $this->errors[] = "Database connection problem.";
            } 
            
        }
        
        // return false (this method only returns true when the database entry has been set successfully)
        return false;        
    }
    
    /**
     * 
     */
    public function sendPasswordResetMail() {
        
        $to      = $this->user_email;
        $subject = EMAIL_PASSWORDRESET_SUBJECT;
        
        $link    = EMAIL_PASSWORDRESET_URL.'?user_name='.urlencode($this->user_name).'&verification_code='.urlencode($this->user_password_reset_hash);
        
        // the link to your password_reset.php, please set this value in config/email_passwordreset.php
        $body = EMAIL_PASSWORDRESET_CONTENT.' <a href="'.$link.'">'.$link.'</a>';

        // stuff for HTML mails, test this is you feel adventurous ;)
        $header  = 'MIME-Version: 1.0' . "\r\n";
        $header .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";
        $header .= "To: <$to>" . "\r\n";
        $header .= 'From: '.EMAIL_PASSWORDRESET_FROM."\r\n";

        if (mail($to, $subject, $body, $header)) {
            
            $this->messages[] = "Password reset mail successfully sent!";
            return true;
            
        } else {
            
            $this->errors[] = "Password reset mail NOT successfully sent!";
            return false;
            
        }
        
    }
    
    /**
     * 
     */
    public function checkIfEmailVerificationCodeIsValid() {

        if (!empty($_GET["user_name"]) && !empty($_GET["verification_code"])) {
            
            // creating a database connection
            $this->db_connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

            // if no connection errors (= working database connection)
            if (!$this->db_connection->connect_errno) {
                
                // TODO: this is not totally clean, as this is just the form provided username
                $this->user_name                = $this->db_connection->real_escape_string(htmlentities($_GET['user_name'], ENT_QUOTES));         
                $this->user_password_reset_hash = $this->db_connection->real_escape_string(htmlentities($_GET['verification_code'], ENT_QUOTES));         
                                
                $query_get_user_data = $this->db_connection->query("SELECT user_id, user_password_reset_timestamp 
                                                                    FROM users 
                                                                    WHERE user_name = '".$this->user_name."' 
                                                                       && user_password_reset_hash = '".$this->user_password_reset_hash."';");
                
                // if this user exists
                if ($query_get_user_data->num_rows == 1) {

                    // get result row (as an object)
                    $result_user_row = $query_get_user_data->fetch_object();
                    
                    $timestamp_one_hour_ago = time() - 3600; // 3600 seconds are 1 hour
                    
                    if ($result_user_row->user_password_reset_timestamp > $timestamp_one_hour_ago) {
                    
                        // set the marker to true, making it possible to show the password reset edit form view
                        $this->password_reset_link_is_valid = true;
                        
                    } else {
                        
                        $this->errors[] = "Your reset link has expired. Please use the reset link within one hour.";
                        
                    }
                        
                } else {

                    $this->errors[] = "This username does not exist.";

                }
                
            } else {
                
                $this->errors[] = "Database connection problem.";
            } 
            
        } else {
            
            $this->errors[] = "Empty link parameter data.";
            
        }
        
    }    
    
    /**
     * 
     */
    public function editNewPassword() {
        
        // TODO: timestamp!
        
        if (!empty($_POST['user_name'])
            && !empty($_POST['user_password_reset_hash'])
            && !empty($_POST['user_password_new'])
            && !empty($_POST['user_password_repeat'])) {
                
            if ($_POST['user_password_new'] === $_POST['user_password_repeat']) {
         
                if (strlen($_POST['user_password_new']) >= 6) {
                    
                    // creating a database connection
                    $this->db_connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

                    // if no connection errors (= working database connection)
                    if (!$this->db_connection->connect_errno) {

                        // escapin' this, additionally removing everything that could be (html/javascript-) code
                        $this->user_name                = $this->db_connection->real_escape_string(htmlentities($_POST['user_name'], ENT_QUOTES));
                        $this->user_password_reset_hash = $this->db_connection->real_escape_string(htmlentities($_POST['user_password_reset_hash'], ENT_QUOTES));
                        
                        // no need to escape as this is only used in the hash function
                        $this->user_password = $_POST['user_password_new'];

                        // now it gets a little bit crazy: check if we have a constant HASH_COST_FACTOR defined (in config/hashing.php),
                        // if so: put the value into $this->hash_cost_factor, if not, make $this->hash_cost_factor = null
                        $this->hash_cost_factor = (defined('HASH_COST_FACTOR') ? HASH_COST_FACTOR : null);

                        // crypt the user's password with the PHP 5.5's password_hash() function, results in a 60 character hash string
                        // the PASSWORD_DEFAULT constant is defined by the PHP 5.5, or if you are using PHP 5.3/5.4, by the password hashing
                        // compatibility library. the third parameter looks a little bit shitty, but that's how those PHP 5.5 functions
                        // want the parameter: as an array with, currently only used with 'cost' => XX.
                        $this->user_password_hash = password_hash($this->user_password, PASSWORD_DEFAULT, array('cost' => $this->hash_cost_factor));

                        // write users new hash into database
                        $this->db_connection->query("UPDATE users
                                                     SET user_password_hash = '$this->user_password_hash', 
                                                         user_password_reset_hash = NULL, 
                                                         user_password_reset_timestamp = NULL
                                                     WHERE user_name = '".$this->user_name."' 
                                                        && user_password_reset_hash = '".$this->user_password_reset_hash."';");

                        // check if exactly one row was successfully changed:
                        if ($this->db_connection->affected_rows == 1) {

                            $this->password_reset_was_successful = true;
                            $this->messages[] = "Password sucessfully changed!";

                        } else {

                            $this->errors[] = "Sorry, your password changing failed.";

                        }


                    } else {

                        $this->errors[] = "Sorry, no database connection.";

                    }
                    
                } else {
                    
                    $this->errors[] = "Password too short, please request a new password reset.";
                    
                }
                
            } else {
                
                $this->errors[] = "Passwords dont match, please request a new password reset.";
                
            }
                
        }        
        
    }
    
    /**
     * 
     * @return boolean
     */
    public function passwordResetLinkIsValid() {       
        
        return $this->password_reset_link_is_valid;
    }
        
    /**
     * 
     * @return boolean
     */
    public function passwordResetWasSuccessful() {        
        
        return $this->password_reset_was_successful;
    }
    
    /**
     * 
     */
    public function getUsername() {
        
        return $this->user_name;
        
    }   
    
    /**
     * 
     */
    public function getPasswordResetHash() {
        
        return $this->user_password_reset_hash;
    }
    
    /**
     * Get either a Gravatar URL or complete image tag for a specified email address.
     * Gravatar is the #1 (free) provider for email address based global avatar hosting.
     * The URL (or image) returns always a .jpg file !
     * For deeper info on the different parameter possibilities:
     * @see http://de.gravatar.com/site/implement/images/
     *
     * @param string $email The email address
     * @param string $s Size in pixels, defaults to 50px [ 1 - 2048 ]
     * @param string $d Default imageset to use [ 404 | mm | identicon | monsterid | wavatar ]
     * @param string $r Maximum rating (inclusive) [ g | pg | r | x ]
     * @param array $atts Optional, additional key/value attributes to include in the IMG tag
     * @source http://gravatar.com/site/implement/images/php/
     */
    public function getGravatarImageUrl($email, $s = 50, $d = 'mm', $r = 'g', $atts = array() ) {
        
        $url = 'http://www.gravatar.com/avatar/';
        $url .= md5( strtolower( trim( $email ) ) );
        $url .= "?s=$s&d=$d&r=$r&f=y";
        
        // the image url (on gravatarr servers), will return in something like
        // http://www.gravatar.com/avatar/205e460b479e2e5b48aec07710c08d50?s=80&d=mm&r=g
        // note: the url does NOT have something like .jpg
        $this->user_gravatar_image_url = $url;        

        // build img tag around
        $url = '<img src="' . $url . '"';
        foreach ( $atts as $key => $val )
            $url .= ' ' . $key . '="' . $val . '"';
        $url .= ' />';            
 
        // the image url like above but with an additional <img src .. /> around
        $this->user_gravatar_image_tag = $url;
        
    }    

}
