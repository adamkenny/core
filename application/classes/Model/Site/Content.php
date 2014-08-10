<?php

defined('SYSPATH') or die('No direct script access.');

class Model_Site_Content extends Model_Master {

    protected $_db_group = 'site';
    protected $_table_name = 'content';
    protected $_primary_key = 'id';
    protected $_table_columns = array(
        'id' => array('data_type' => 'bigint'),
        'name_first' => array('data_type' => 'string'),
        'name_last' => array('data_type' => 'string'),
        'salt' => array('data_type' => 'string'),
        'last_login' => array('data_type' => 'timestamp', 'is_nullable' => TRUE),
        'last_login_ip' => array('data_type' => 'int'),
        'gender' => array('data_type' => 'char', 'is_nullable' => TRUE),
        'age' => array('data_type' => 'smallint'),
        'template' => array('data_type' => 'string'),
        'created' => array('data_type' => 'timestamp', 'is_nullable' => TRUE),
        'updated' => array('data_type' => 'timestamp', 'is_nullable' => TRUE),
        'checked' => array('data_type' => 'timestamp', 'is_nullable' => TRUE),
        'status' => array('data_type' => 'smallint'),
    );
    
    // fields mentioned here can be accessed like properties, but will not be referenced in write operations
    protected $_ignored_columns = array(
    );

    // Belongs to relationships
    protected $_belongs_to = array();
    
    // Has man relationships
    protected $_has_many = array(
        'notes' => array(
            'model' => 'Account_Email',
            'foreign_key' => 'account_id',
        ),
        'notes_actioner' => array(
            'model' => 'Account_Email',
            'foreign_key' => 'actioner_id',
        ),
        'emails' => array(
            'model' => 'Account_Email',
            'foreign_key' => 'account_id',
        ),
        'security_resets' => array(
            'model' => 'Account_Security_Reset',
            'foreign_key' => 'account_id',
        ),
        'qualifications' => array(
            'model' => 'Account_Qualification',
            'foreign_key' => 'account_id',
        ),
        'states' => array(
            'model' => 'Account_State',
            'foreign_key' => 'account_id',
        ),
        'downloads' => array(
            'model' => 'Download',
            'foreign_key' => 'account_id',
        ),
        'pilot_sessions' => array(
            'model' => 'Stats_Pilot',
            'foreign_key' => 'account_id',
        ),
        'atc_sessions' => array(
            'model' => 'Stats_Controller',
            'foreign_key' => 'account_id',
        ),
        'theory_attempts' => array(
            'model' => 'Training_Theory_Attempt',
            'foreign_key' => 'account_id',
        ),
    );
    
    // Has one relationship
    protected $_has_one = array(
        'security' => array(
            'model' => 'Account_Security',
            'foreign_key' => 'account_id',
        ),
    );
    
    // Validation rules
    public function rules(){
        return array(
            'id' => array(
                array('min_length', array(':value', 6)),
                array('max_length', array(':value', 7)),
                array('numeric'),
            ),
            'name_first' => array(
                array('not_empty'),
            ),
            'name_last' => array(
                array('not_empty'),
            ),
            'gender' => array(
                array("regex", array(":value", "/(M|F)/i")),
            ),
        );
    }
    
    // Data filters
    public function filters(){
        return array(
            'name_first' => array(
                array('trim'),
                array(array("UTF8", "clean"), array(":value")),
                array(array("Helper_Account", "formatName"), array(":value", "f")),
            ),
            'name_last' => array(
                array('trim'),
                array(array("UTF8", "clean"), array(":value")),
                array(array("Helper_Account", "formatName"), array(":value", "s")),
            ),
            'password' => array(
                array("sha1"),
            ),
            'extra_password' => array(
                array("sha1"),
            ),
            'last_login_ip' => array(
                array("ip2long"),
            )
        );
    }
    
    /**
     * @override
     */
    public function save(\Validation $validation = NULL) {
        // Get the old values!
        $ovs = $this->changed();
        
        parent::save($validation);
        
        // Basic logs!
        $logKeys = array("name_first", "name_last", "status");
        foreach($ovs as $key => $value){
            if(in_array($key, $logKeys)){
                $data = array();
                $data[] = $key;
                if($key == "status"){
                    $data[] = Enum_Account_Status::getDescription(decbin($value["old"]));
                    $data[] = Enum_Account_Status::getDescription(decbin($value["new"]));
                } else {
                    $data[] = $value["old"];
                    $data[] = $value["new"];
                }
                ORM::factory("Account_Note")->writeNote($this, "ACCOUNT/DETAILS_CHANGED", 707070, $data);
            }
        }
        
        return true;
    }
    
    /**
     * Update the last_login fields!
     * 
     * @return void
     */
    public function update_last_login_info(){
        ORM::factory("Account_Note")->writeNote($this, "ACCOUNT/LOGIN_SUCCESS", $this->id, array($_SERVER["REMOTE_ADDR"]), Enum_Account_Note_Type::AUTO);
        
        $this->last_login = gmdate("Y-m-d H:i:s");
        $this->last_login_ip = $_SERVER["REMOTE_ADDR"];
        $this->save();
    }
    
    /**
     * Load the current authenticated user
     * 
     * @return Account_Main ORM Object.
     */
    public function get_current_account(){
        $id = $this->session()->get(ORM::factory("Setting")->getValue("auth.account.session.key"), null);
        if($id == NULL || !is_numeric($id)){
            // Get the salt value from the database.
            $cookieValue = Cookie::decrypt(ORM::factory("Setting")->getValue("auth.account.cookie.key"), null);
            if($cookieValue == NULL){
                return $this;
            }
            
            // Split the cookie into CID and Salt.
            $cookieValue = explode("|", $cookieValue);
            $id = Arr::get($cookieValue, 0, NULL);
            $salt = Arr::get($cookieValue, 1, "x");
            
            // Valid ID?
            if($id == NULL || !is_numeric($id)){
                return $this;
            }
            
            // Valid ID/Salt pair?
            $check = ORM::factory("Account_Main")->where("salt", "=", $salt)->where("id", "=", $id)->reset(FALSE)->count_all();
            if($check < 1){
                return $this;
            }
        }
        
        // Now, load THIS model properly!
        $this->__construct($id);
        return $this;
    }
        
    /**
     * Check whether this account requires an update from CERT.
     * 
     * @return boolean True if it requires an update.
     */
    public function check_requires_cert_update(){
        return ($this->loaded() && strtotime($this->checked) <= strtotime("-48 hours"));
    }
    
    /**
     * Run an update from the CERT feeds.
     * 
     * @param array $data If set, this data will be used instead.
     * @return boolean TRUE if successful, false otherwise.
     */
    public function action_update_from_remote($data=null){
        if(!$this->loaded()){
            return false;
        }
        
        // If this is a system account, ignore it!
        if($this->isSystem()){
            return false;
        }
        
        // Let's log the fact we've requested a data update from CERT!
        if($data == null){
            ORM::factory("Account_Note")->writeNote($this, "ACCOUNT/AUTO_CERT_UPDATE_XML", 707070, array(), Enum_Account_Note_Type::SYSTEM);
        } else {
            ORM::factory("Account_Note")->writeNote($this, "ACCOUNT/AUTO_CERT_UPDATE", 707070, array(), Enum_Account_Note_Type::SYSTEM);
        }
        
        // Get the raw details.
        if($data == null){
            $details = Vatsim::factory("autotools")->getInfo($this->id);
        } else {
            $details = $data;
        }
        
        // We need to add the OBS date of a member to the qualifications table, when they are created.
        if(!$this->qualifications->check_has_qualification("atc", 1)){
            $this->qualifications->addATCQualification($this, 1, $details['regdate']); // Add OBS to date they joined.
        }
        
        /***** LEGACY SUPPORT *****/
        // Peeps got their S1 straight away: Pre 2008-01-01 00:00:00
        if(!$this->qualifications->check_has_qualification("atc", 2)){
            /*if(Arr::get($details, 'regdate', null) != null && strtotime($details["regdate"]) <= strtotime("2008-01-01 00:00:00")){
                $this->qualifications->addATCQualification($this, 2, $details['regdate']); // Add S1 to date they joined.
                die("OI OI!");
            }*/
        }
        /**************************/
        
        // Now run updatererers - we're keeping them separate so they can be used elsewhere.
        $this->setName(Arr::get($details, "name_first", NULL), Arr::get($details, "name_last", NULL), true);
        
        // Emails!
        if(Arr::get($details, "email", null) != null){
            $this->emails->action_add_email($this, $details["email"], 1, 1);
        }
        
        // Qualifications!
        if(Arr::get($details, "rating_atc", null) != null){
            $this->qualifications->addATCQualification($this, Arr::get($details, "rating_atc", 1));
        }

        // Pilot ratings are slightly funny in that we need to set each one!
        if(Arr::get($details, "rating_pilot", null) != null && is_array($details["rating_pilot"])){
            foreach($details["rating_pilot"] as $prating){
                $this->qualifications->addPilotQualification($this, Enum_Account_Qualification_Pilot::IdToValue($prating[0]), NULL);
            }
        }
        
        // Status?
        if(Arr::get($details, "rating_atc", 99) < 1){
            if(Arr::get($details, "rating_atc", 99) == 0){
                $this->setStatus(Enum_Account_Status::INACTIVE, true);
            } else {
                $this->unSetStatus(Enum_Account_Status::INACTIVE, true);
            }
            if(Arr::get($details, "rating_atc", 99) == -1){
                $this->setStatus(Enum_Account_Status::NETWORK_BANNED, true);
            } else {
                $this->unSetStatus(Enum_Account_Status::NETWORK_BANNED, true);
            }
        } else {
            $this->unSetStatus(Enum_Account_Status::INACTIVE, true);
            $this->unSetStatus(Enum_Account_Status::NETWORK_BANNED, true);
        }
        
        // Work out what the state is!
        if(Arr::get($details, "division", null) != null && strcasecmp($details["division"], "GBR") == 0){
            $this->states->addState($this, "DIVISION");
        } elseif(Arr::get($details, "region", null) != null && strcasecmp($details["region"], "EUR") == 0){
            $this->states->addState($this, "REGION");
        } else {
            $this->states->addState($this, "INTERNATIONAL");
        }
        
        $this->checked = gmdate("Y-m-d H:i:s");
        $this->save();
    }
    
    public function setName($name_first, $name_last, $inhibitSave=false){
        if(!$this->loaded()){
            return false;
        }
        
        if($name_first != NULL){
            $this->name_first = $name_first;
        }
        
        if($name_last != NULL){
            $this->name_last = $name_last;
        }
        
        if(!$inhibitSave){
            $this->save();
        }
        
        return true;
    }
    
    public function setStatus($status, $inhibitSave=false){
        if(!$this->isStatusFieldSet($status)){
            $this->status = $this->status + bindec($status);
        }
        
        if(!$inhibitSave){
            $this->save();
        }
        
        return true;
    }
    
    public function unSetStatus($status, $inhibitSave=false){
        if($this->isStatusFieldSet($status)){
            $this->status = $this->status - bindec($status);
        }
        
        if(!$inhibitSave){
            $this->save();
        }
        return true;
    }
        
    /**
     * Get the last ip address used to login to this account.
     * 
     * @return string The last IP address used on this account.
     */
    public function get_last_login_ip(){
        return long2ip($this->last_login_ip);
    }
    
    /**
     * Count the number of times the specified {@link $ip} has been used to login,
     * within {@link $timeLimit}.
     * 
     * @param string $ip The IP to get the count for.  If left as NULL, the last will be used.
     * @param string $timeLimit An strtotime() string to determine the period we're checking.
     * @return int The number of times the {@link $ip} has been using within {@link $timeLimit}.
     */
    public function count_last_login_ip_usage($ip=null, $timeLimit="-8 hours"){
        // Use the last IP of this account?
        if($ip == null){
            $ip = $this->get_last_login_ip();
        }
        
        $ipCheck = ORM::factory("Account_Main")->where("last_login_ip", "=", ip2long($ip));
        
        // Exclude this user?
        if($this->id > 0){
            $ipCheck = $ipCheck->where("id", "!=", $this->id);
        }
        
        // Limit the timeframe?
        if($timeLimit != null && $timeLimit != false){
            $ipCheck = $ipCheck->where("last_login", ">=", gmdate("Y-m-d H:i:s", strtotime($timeLimit)));
        }
        
        // Return the count.
        return $ipCheck->reset(FALSE)->count_all();
    }
    
    /** 
     * Validate the given password for this user.
     *  
     * @param string $pass The password to validate.
     * @return boolean True on success, false otherwise.
     */
    public function validate_password($pass){
        return Vatsim::factory("autotools")->authenticate($this->id, $pass);
    }
    
    /**
     * Authenticate a user using their CID and password, and then set the necessary sessions.
     * 
     * @param string $pass The password to use for authentication.
     * @return boolean True on success, false otherwise.
     */
    public function action_authenticate($pass){
        // Get the auth result - we'll let the controller catch the exception.
        $authResult = $this->validate_password($pass);

        // If we've got a valid authentication, set the session!
        if($authResult){
            ORM::factory("Account_Note")->writeNote($this, "ACCOUNT/AUTH_CERT_SUCCESS", $this->id, array($_SERVER["REMOTE_ADDR"]), Enum_Account_Note_Type::AUTO);
            $this->setSessionData(false);
            $this->update_last_login_info();
            return $authResult;
        }
        ORM::factory("Account_Note")->writeNote($this, "ACCOUNT/AUTH_CERT_FAILURE", $this->id, array($_SERVER["REMOTE_ADDR"]), Enum_Account_Note_Type::AUTO);
        $this->session()->delete(ORM::factory("Setting")->getValue("auth.account.session.key"));
        Cookie::delete(ORM::factory("Setting")->getValue("auth.account.cookie.key"));
        $this->session()->delete("sso_quicklogin");
        
        // Default response - protects the system!
        return false;
    }
    
    /**
     * Log a user out!
     */
    public function action_logout(){
        $this->destroySessionData();
    }
    
    /**
     * If a user's details are already set, run a quick login on them!
     */
    public function action_quick_login(){
        $this->setSessionData(true);
        $this->update_last_login_info();
        return true;
    }
    
    /**
     * Update the salt for this user's account.
     */
    private function renew_salt(){
        $salt = md5(uniqid().md5(time()));
        $salt = substr($salt, 0, 20);
        $this->salt = $salt;
        $this->save();
        return $salt;
    }
    
    /**
     * Set session data!
     * 
     * @param boolean $quickLogin If TRUE, it will set a quickLogin session value.
     * @return void
     */
    private function setSessionData($quickLogin=false){
        $this->session()->set(ORM::factory("Setting")->getValue("auth.account.session.key"), $this->id);
        
        // Cookie!
        $lifetime = strtotime("+".ORM::factory("Setting")->getValue("auth.account.cookie.lifetime"));
        $lifetime = $lifetime-time();
        $salt = $this->renew_salt();
        $cookieValue = $this->id."|".$salt;
        Cookie::encrypt(ORM::factory("Setting")->getValue("auth.account.cookie.key"), $cookieValue, $lifetime);
        $this->session()->set("sso_quicklogin", $quickLogin);
    }
    
    /**
     * Destory the session data!
     * 
     * @param boolean $quickLogin If TRUE, it will set a quickLogin session value.
     * @return void
     */
    private function destroySessionData(){
        $this->session()->delete(ORM::factory("Setting")->getValue("auth.account.session.key"));
        Cookie::delete(ORM::factory("Setting")->getValue("auth.account.cookie.key"));
        $this->session()->delete("sso_quicklogin");
        $this->session()->regenerate();
    }
    
    /**
     * Override the current account with another account.
     */
    public function override_enable($override_id){
        $this->session()->set("sso_account_override", $this->session()->get(ORM::factory("Setting")->getValue("auth.account.session.key")));
        $this->session()->set(ORM::factory("Setting")->getValue("auth.account.session.key"), $override_id);
    }
    
    /**
     * Override the current account with another account.
     */
    public function override_disable(){
        $this->session()->set(ORM::factory("Setting")->getValue("auth.account.session.key"), $this->session()->get("sso_account_override"));
        $this->session()->delete("sso_account_override");
    }
    
    /**
     * Check whether this account is being overriden.
     */
    public function is_overriding(){
        return !($this->session()->get("sso_account_override", null) == null);
    }
    
    /**
     * Was this login a quick login?
     * 
     * @return boolean TRUE if it was a quick login, false otherwise.
     */
    public function is_quick_login(){
        $ql = $this->session()->get("sso_quicklogin", false);
        return $ql;
    }
    
    /**
     * Determine whether the current loaded member is of the set state.
     * 
     * @param Enum_Account_State $state The state to check.
     * @param string $returnType boolean or date.
     * @return boolean True if set, false otherwise.
     */
    public function isStateSet($state, $returnType="boolean"){
        foreach($this->states->find_all() as $_s){
            if(is_object($_s) && $_s->state == $state && $_s->removed == NULL){
                return (($returnType == "date") ? $_s->created : true);
            }
        }
        return false;
    }
    
    /**
     * Get the CURRENT state for this user.
     * 
     * @param boolean $intOnly If set to TRUE the numeric representation will be returned.
     * @return string|int The string or numeric representation of the current state of the user.
     */
    public function getState($intOnly=false){
        if($intOnly){
            return $this->states->where("removed", "IS", NULL)->order_by("state", "DESC")->find()->state;
        }
        $s = $this->states->where("removed", "IS", NULL)->order_by("state", "DESC")->find();
        return Enum_Account_State::valueToType($s->state);
    }
    
    /**
     * Get all the state flags for this user.
     * 
     * @return array An array of states -> boolean key/value pair.
     */
    public function getStates(){
        $return = array();
        foreach(Enum_Account_State::getAll() as $key => $value){
            $return[strtolower($key)] = (int) $this->isStateSet($value);
            if($return[strtolower($key)])
                $return[strtolower($key)."_date"] = $this->isStateSet($value, "date");
        }
        return $return;
    }
    
    /**
     * Get all current status flags for a user's account.
     * 
     * @return array An array of status => boolean pairs.
     */
    public function getStatusFlags(){
        // Now, sort out the status!
        $return = array();
        foreach(Enum_Account_Status::getAll() as $key => $value){
            $return[strtolower($key)] = (int) $this->isStatusFieldSet($value);
        }
        return $return;
    }
    
    /**
     * Determine whether a status is set on the user's account.
     * 
     * @param Enum_Account_Status $status The status to check.
     * @return boolean True if set, false otherwise.
     */
    public function isStatusFieldSet($status){
        return (boolean) (bindec($status) & $this->status);
    }
    
    /**
     * Get the current status of a user.
     * 
     * @return string The current status, in words.
     */
    public function getStatus(){
        if($this->isBanned()){
            return "Banned";
        } elseif($this->isInactive()){
            return "Inactive";
        } else {
            return "Active";
        }
    }
    
    /**
     * Determine whether a user is inactive.
     * 
     * @return boolean True if inactive, false otherwise.
     */
    public function isInactive(){
        return $this->isStatusFieldSet(Enum_Account_Status::INACTIVE);
    }
    
    /**
     * Determine whether a user is banned in anyway!
     * 
     * @return boolean True if banned, false otherwise.
     */
    public function isBanned(){
        return $this->isStatusFieldSet(Enum_Account_Status::SYSTEM_BANNED) OR $this->isStatusFieldSet(Enum_Account_Status::NETWORK_BANNED);
    }
    
    /**
     * Determine whether a user is a system user!
     * 
     * @return boolean True if system, false otherwise.
     */
    public function isSystem(){
        return $this->isStatusFieldSet(Enum_Account_Status::LOCKED);
    }
}

?>