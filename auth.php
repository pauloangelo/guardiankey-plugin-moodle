<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * GuardianKEY authentication login - prevents user login.
 *
 * @package    auth_guardiankey
 * @copyright  Paulo Angelo Alves Resende
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/authlib.php');

require_once(dirname(__FILE__).'/guardiankey.class.php');


define('AES_256_CBC', 'aes-256-cbc');


class auth_plugin_guardiankey extends auth_plugin_base {


    /**
     * Constructor.
     */
    public function __construct() {
        $this->authtype = 'guardiankey';
        $this->config = get_config('auth_guardiankey');
    }
    
    function getGKConf() {
        $GKconfig = array(
            'agentid' => get_config('auth_guardiankey', 'hashid'),  /* ID for the agent (your system) */
            'key' => get_config('auth_guardiankey', 'key'),     /* Key in B64 to communicate with GuardianKey */
            'iv' => get_config('auth_guardiankey', 'iv'),      /* IV in B64 for the key */
            'service' => get_config('auth_guardiankey', 'service'),      /* Your service name*/
            'orgid' => get_config('auth_guardiankey', 'organizationId'),   /* Your Org identification in GuardianKey */
            'authgroupid' => get_config('auth_guardiankey', 'authGroupId'), /* A Authentication Group identification, generated by GuardianKey */
            'reverse' => get_config('auth_guardiankey', 'reverse'), /* If you will locally perform a reverse DNS resolution */
        );
        return $GKconfig;
    }
    
    function logout(){
        global $PAGE,$redirect;
        
        $PAGE->set_url('/login/logout.php');
        $PAGE->set_context(context_system::instance());
        
        $sesskey = optional_param('sesskey', '__notpresent__', PARAM_RAW); // we want not null default to prevent required sesskey warning
        $login   = optional_param('loginpage', 0, PARAM_BOOL);
        
        // can be overridden by auth plugins
        if ($login) {
            $redirect = get_login_url();
        } else {
            $redirect = $CFG->wwwroot.'/';
        }
        
        if (!isloggedin()) {
            // no confirmation, user has already logged out
            require_logout();
            redirect($redirect);
            
        } else if (!confirm_sesskey($sesskey)) {
            $PAGE->set_title($SITE->fullname);
            $PAGE->set_heading($SITE->fullname);
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(get_string('logoutconfirm'), new moodle_url($PAGE->url, array('sesskey'=>sesskey())), $CFG->wwwroot.'/');
            echo $OUTPUT->footer();
            die;
        }
        
        $authsequence = get_enabled_auth_plugins(); // auths, in sequence
        foreach($authsequence as $authname) {
            $authplugin = get_auth_plugin($authname);
            $authplugin->logoutpage_hook();
        }
        
        require_logout();
        
        redirect($redirect);
        
    }

    function user_authenticated_hook(&$user, $username, $password, $loginfailed=0) {
        
        $GKConf=$this->getGKConf();
        
        if(strlen($GKConf['agentid'])>0){
          // save userhash FUTURE IMPLEMENATION
//           if(!$DB->record_exists('auth_guardiankey_user_hash',array('userid' => $user->id, 'userhash' => $usernamehash))){
//             $userhashrecord = new stdClass();
//             $userhashrecord->userid= $user->id;
//             $userhashrecord->userhash= $usernamehash;
//             $DB->insert_record('auth_guardiankey_user_hash', $userhashrecord, $returnid=true, $bulk=false) ;
//           }
            
            $active=get_config('auth_guardiankey', 'active');
            
            $GK=new guardiankey($GKConf);
            
            if($active == "yes"){
                try {
                    
                    $GKReturn=json_decode($GK->checkaccess($username,$user->email));
                    if($GKReturn['response']=='BLOCK'){
                        $this->logout();
                    }
                } catch (Exception $e) {
                    // TODO: ?
                }
            }else{
                $GK->sendevent($username,$user->email);
            }
            
        }
    }
    
    // Check if config exists
    // If no, register 
    // Save configs
    // check for events in the WS
    function execute_task() {
        global $DB, $CFG;
        $keyb64 = get_config('auth_guardiankey', 'key');
        
        if(strlen($keyb64)==0){
            $adminuser = $DB->get_record('user', array('id'=>'2'));
            $email = $adminuser->email;
            $GK = new guardiankey();
            
            try {
                $GKReturn=$GK->register($email,"webhook",'{"webhook_url":"'.$CFG->wwwroot.'/auth/guardiankey/webhook.php"}');
                $salt = md5(rand().rand().rand().rand().$GKReturn["agentid"]);
                if(strlen($GKReturn["agentid"])>0){
                    set_config('key', $GKReturn["key"], 'auth_guardiankey');
                    set_config('iv', $GKReturn["iv"], 'auth_guardiankey');
                    set_config('hashid', $GKReturn["agentid"], 'auth_guardiankey');
                    set_config('organizationId', $GKReturn["orgid"], 'auth_guardiankey');
                    set_config('authGroupId', $GKReturn["groupid"], 'auth_guardiankey');
                    set_config('salt', $salt, 'auth_guardiankey');
                    set_config('reverse', 1, 'auth_guardiankey');
                }
            } catch (Exception $e) {
                // TODO: ?
            }

        }
    }

   function processEvent($event) {

     print_r($event);

     global $DB, $CFG;

     $userid = $DB->get_record('auth_guardiankey_user_hash', array('userhash'=>$event["userhash"]));
     $user = $DB->get_record('user', array('id'=>$userid->userid));
     $emailsubject 	 = get_config('auth_guardiankey', 'emailsubject');
     $emailtext 	  = get_config('auth_guardiankey', 'emailtext');
     $emailhtml 	  = get_config('auth_guardiankey', 'emailhtml');
     $testmode 	    = get_config('auth_guardiankey', 'test');
     $supportaddr 	= get_config('auth_guardiankey', 'supportaddr');
     //$dateformat 	  = get_config('auth_guardiankey', 'dateformat');
     //$timeformat 	  = get_config('auth_guardiankey', 'timeformat');
     $date = userdate($event["time"], get_string('strftimedatetimeshort', 'langconfig'));
     $time = userdate($event["time"], get_string('strftimetime', 'langconfig'));

      $emailhtml=str_replace("[IP]",$event["ip"],$emailhtml);
      $emailhtml=str_replace("[IP_REVERSE]",$event["ip_reverse"],$emailhtml);
      $emailhtml=str_replace("[CITY]",$event["city"],$emailhtml);
      $emailhtml=str_replace("[USER_AGENT]",$event["useragent"],$emailhtml);
      $emailhtml=str_replace("[SYSTEM]",$event["system"],$emailhtml);
      $emailhtml=str_replace("[DATE]",$date,$emailhtml);
      $emailhtml=str_replace("[TIME]",$time,$emailhtml);
      $emailhtml=str_replace("[]","",$emailhtml);
      $emailhtml=str_replace("()","",$emailhtml);
      
      $emailtext=str_replace("[IP]",$event["ip"],$emailtext);
      $emailtext=str_replace("[IP_REVERSE]",$event["ip_reverse"],$emailtext);
      $emailtext=str_replace("[CITY]",$event["city"],$emailtext);
      $emailtext=str_replace("[USER_AGENT]",$event["useragent"],$emailtext);
      $emailtext=str_replace("[SYSTEM]",$event["system"],$emailtext);
      $emailtext=str_replace("[DATE]",$date,$emailtext);
      $emailtext=str_replace("[TIME]",$time,$emailtext);
      $emailtext=str_replace("[]","",$emailtext);
      $emailtext=str_replace("()","",$emailtext);

     // Get information from table user-hash
     // Send e-mail to user

      $emailuser = new stdClass();
      $emailuser->email = $CFG->supportemail;
      $emailuser->firstname = $CFG->supportname;
      $emailuser->lastname = 'Moodle administration';
      $emailuser->username = 'moodleadmin';
      $emailuser->maildisplay = 2;
      $emailuser->alternatename = "";
      $emailuser->firstnamephonetic = "";
      $emailuser->lastnamephonetic = "";
      $emailuser->middlename = "";

  
      if($testmode != 1)
        $success = email_to_user($user, $emailuser, $emailsubject, $emailtext, $emailhtml, '', '', true);

      if(strlen(trim($supportaddr))>0){
        // Send an e-mail for the support address
        $mailer =& get_mailer();
        $result = $mailer->send($supportaddr, $emailsubject." (user $emailuser)", $emailhtml, 'quoted-printable', 1);
      }
      
      
      /*
              $message = new \core\message\message();
              $message->component = 'auth_guardiankey';
              $message->name = 'instantmessage';
              //$message->userfrom = $USER;
              $message->userto = $user;
              $message->subject = 'message subject 1';
              $message->fullmessage = 'message body';
              $message->fullmessageformat = FORMAT_MARKDOWN;
              $message->fullmessagehtml = '<p>message body</p>';
              $message->smallmessage = 'small message';
              $message->notification = '0';
              $message->contexturl = 'http://GalaxyFarFarAway.com';
              $message->contexturlname = 'Context name';
              //$message->replyto = "random@example.com";
              $content = array('*' => array('header' => ' test ', 'footer' => ' test ')); // Extra content for specific processor
              $message->set_additional_content('email', $content);
              $messageid = message_send($message);
      */

   }

    function user_login($username, $password) {
        return false;
    }

    /**
     * No password updates.
     */
    function user_update_password($user, $newpassword) {
        return false;
    }



    function prevent_local_passwords() {
        return false;
    }

    /**
     * Returns true if this authentication plugin is 'internal'.
     *
     * @return bool
     */
    function is_internal() {
        return true;
    }

    /**
     * Returns true if this authentication plugin can change the user's
     * password.
     *
     * @return bool
     */
    function can_change_password() {
        return false;
    }

    /**
     * Returns the URL for changing the user's pw, or empty if the default can
     * be used.
     *
     * @return moodle_url
     */
    function change_password_url() {
        return null;
    }

    /**
     * Returns true if plugin allows resetting of internal password.
     *
     * @return bool
     */
    function can_reset_password() {
        return false;
    }

    /**
     * Returns true if plugin can be manually set.
     *
     * @return bool
     */
    function can_be_manually_set() {
        return false;
    }


}


