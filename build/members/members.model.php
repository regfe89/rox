<?php


class MembersModel extends RoxModelBase
{
    
    private $profile_language = null;
    
    /**
     * Constructor
     *
     * @param void
     */
    public function __construct()
    {
        parent::__construct();
        $this->bootstrap();
    }
    
    public function getMemberWithUsername($username)
    {
        return $this->createEntity('Member')->findByUsername($username);
    }
    
    public function getMemberWithId($id)
    {
        if (!($id = intval($id)))
        {
            return false;
        }

        return $this->createEntity('Member', $id);
    }



      public function get_relation_between_members($IdMember_rel) 
      {
          $myself = $this->getMemberWithId($_SESSION['IdMember']);
          $member = $this->getMemberWithId($IdMember_rel);
          $words = $this->getWords();
          $all_relations = $member->all_relations();
          $relation = array();
          $relation['member'] = array();
          if (count($all_relations) > 0) {
              foreach ($all_relations as $rel) {
                if ($rel->IdRelation == $myself->id)
                    $relation['member'] = $rel;
              }
          }
          $all_relations_myself = $myself->all_relations();
          $relation['myself'] = array();
          if (count($all_relations_myself) > 0) {
              foreach ($all_relations_myself as $rel) {
                if ($rel->IdRelation == $member->id)
                    $relation['myself'] = $rel;
              }
          }
          return $relation;
      }

    /**
     * set the location of a member
     */
    public function setLocation($IdMember,$geonameid = false)
    {
    
        // Address IdCity address must only consider Populated palces (definition of cities), it also must consider the address checking process
    
        $Rank=0 ; // Rank=0 means the main address, todo when we will deal with several addresses we will need to consider the other rank Values ;
        $IdMember = (int)$IdMember;
        $geonameid = (int)($geonameid);
        
        $errors = array();
        
        if (empty($IdMember)) {
            // name is not set:
            $errors['Name'] = 'Name not set';
        }
        if (empty($geonameid)) {
            // name is not set:
            $errors['Geonameid'] = 'Geoname not set';
        }
        
        // get Member's current Location
        $result = $this->singleLookup(
            "
SELECT  members.IdCity
FROM    members
WHERE   members.id = $IdMember
            "
        );
        if (!isset($result) || $result->IdCity != $geonameid) {
            // Check Geo and maybe add location 
            $geomodel = new GeoModel(); 
            if(!$geomodel->getDataById($geonameid)) {
                // if the geonameid is not in our DB, let's add it
                if (!$geomodel->addGeonameId($geonameid,'member_primary')) {
                    $vars['errors'] = array('geoinserterror');
                    return false;
                }
            } else {
                // the geonameid is in our DB, so just update the counters
                //get id for usagetype:
                $usagetypeId = $geomodel->getUsagetypeId('member_primary')->id;
                $update = $geomodel->updateUsageCounter($geonameid,$usagetypeId,'add');
            }
            
            $result = $this->singleLookup(
                "
UPDATE  addresses
SET     IdCity = $geonameid
WHERE   IdMember = $IdMember and Rank=".$Rank
            );
            
            // name is not set:
            if (!empty($result)) $errors['Geonameid'] = 'Geoname not set';
            
            $result = $this->singleLookup(
                "
UPDATE  members
SET     IdCity = $geonameid
WHERE   id = $IdMember
                "
            );
            if (!empty($result)) $errors['Geonameid'] = 'Member IdCity not set';
            else MOD_log::get()->write("The Member with the Id: ".$IdMember." changed his location to Geo-Id: ".$geonameid, "Members");
            return array(
                'errors' => $errors,
                'IdMember' => $result
                );
        } else {
            // geonameid hasn't changed
            return false;
        }
    }

    
    /**
     * Not totally sure it belongs here - but better this
     * than member object? As it's more of a business of this
     * model to know about different states of the member 
     * object to be displayed..
     */
    public function set_profile_language($langcode)
    {
        //TODO: check that 
        //1) this is a language recognized by the bw system
        //2) there's content for this member in this language
        //else: use english = the default already set
        $langcode = mysql_real_escape_string($langcode);
        if ($language = $this->singleLookup(
            "
SELECT SQL_CACHE
    id,
    ShortCode,
    Name
FROM
    languages
WHERE
    shortcode = '$langcode'
            "
        )) {
            $this->profile_language = $language;
        } else {
            $l = new stdClass;
            $l->id = 0;
            $l->ShortCode = 'en';
            $l->Name = 'English';
            $this->profile_language = $l;
        }
    }
    
    
    public function get_profile_language()
    {
        if(isset($this->profile_language)) {
            return $this->profile_language;
        } else {
            $l = new stdClass;
            $l->id = 0;
            $l->ShortCode = 'en';
            $l->Name = 'English';
            $this->profile_language = $l;
            return $this->profile_language;
        }
    }
    
    /**
     * Set the languages spoken by member
     */
    public function set_language_spoken($IdLanguage,$Level,$IdMember) 
    {
        $lang = $this->dao->query("
DELETE 
FROM
    memberslanguageslevel
WHERE
    IdLanguage = '$IdLanguage' AND
    IdMember = '$IdMember'
        ");
        $s = $this->dao->query("
REPLACE INTO
    memberslanguageslevel
    (
    IdLanguage,
    Level,
    IdMember
    )
VALUES
    (
    '$IdLanguage',
    '$Level',
    '$IdMember'
    )
        ");
    }
    
    /**
     * Delete a profile translation for a member
     */
    public function delete_translation_multiple($trad_ids = array(),$IdOwner, $lang_id) 
    {
        $words = new MOD_words();
        foreach ($trad_ids as $trad_id){
            $words->deleteMTrad($trad_id, $IdOwner, $lang_id);
        }
    }
        
    /**
     * Set the preferred language for a member
     *
     * @todo make sure that places that call this function uses the return code
     * @param int $IdMember
     * @param int $IdPreference
     * @param string $Value
     * @access public
     * @return bool
     */
    public function set_preference($IdMember,$IdPreference,$Value) 
    {
        $IdMember = $this->dao->escape($IdMember);
        $IdPreference = $this->dao->escape($IdPreference);
        $Value = $this->dao->escape($Value);
        $rr = $this->singleLookup("select memberspreferences.id as id from memberspreferences,preferences where IdMember='{$IdMember}' and IdPreference=preferences.id and preferences.id='{$IdPreference}'");
        if (isset ($rr->id))
        {
            $query = <<<SQL
UPDATE
    memberspreferences
SET
    Value = '{$Value}'
WHERE
    id = {$rr->id}
SQL;

        }
        else
        {
            $query = <<<SQL
INSERT INTO
    memberspreferences (IdMember, IdPreference, Value, created)
VALUES
    ('{$IdMember}', '{$IdPreference}', '{$Value}', NOW())
SQL;
        }
        return ((!$this->dao->query($query)) ? true : false);
    }
    
    /**
     * Set a member's profile public/private
     */
    public function set_public_profile ($IdMember,$Public = false) 
    {
        $rr = $this->singleLookup(
            "
SELECT *
FROM memberspublicprofiles 
WHERE IdMember = ".$IdMember
         );
        if (!$rr && $Public == true) {
        $s = $this->dao->query("
INSERT INTO
    memberspublicprofiles
    (
    IdMember,
    created,
    Type
    )
VALUES
    (
    '$IdMember',
    NOW(),
    'normal'
    )
        ");
        } elseif ($rr && $Public == false) {
        $s = $this->dao->query("
DELETE FROM
    memberspublicprofiles
WHERE
    id = ". $rr->id
        );
        }
    }
    
    
    

    // checkCommentForm - NOT FINISHED YET !
    public function checkCommentForm(&$vars)
    {
        $errors = array();
        
        
        // sample!
        if (empty($vars['geonameid']) || empty($vars['countryname'])) {
            $errors[] = 'SignupErrorProvideLocation';
        }
        
    }
    
    public function addComment($TCom,&$vars)
    {
        $return = true;
        // Mark if an admin's check is needed for this comment (in case it is "bad")
        $AdminAction = "NothingNeeded";
        if ($vars['Quality'] == "Bad") {
            $AdminAction = "AdminCommentMustCheck";
            // notify OTRS
            //Load the files we'll need
            // require_once "bw/lib/swift/Swift.php";
            // require_once "bw/lib/swift/Swift/Connection/SMTP.php";
            // require_once "bw/lib/swift/Swift/Message/Encoder.php";
            // $swift =& new Swift(new Swift_Connection_SMTP("localhost"));
            // $subj = "Bad comment from  " .$mCommenter->Username.  " about " . fUsername($IdMember) ;
            // $text = "Please check the comments. A bad comment was posted by " . $mCommenter->Username.  " about " . fUsername($IdMember) . "\n";
            // $text .= $mCommenter->Username . "\n" . ww("CommentQuality_" . $Quality) . "\n" . GetStrParam("TextWhere") . "\n" . GetStrParam("Commenter");
            // bw_mail($_SYSHCVOL['CommentNotificationSenderMail'], $subj, $text, "", $_SYSHCVOL['CommentNotificationSenderMail'], $defLanguage, "no", "", "");
        }
        $syshcvol = PVars::getObj('syshcvol');
        $max = count($syshcvol->LenghtComments);
        $tt = $syshcvol->LenghtComments;
        $LenghtComments = "";
        for ($ii = 0; $ii < $max; $ii++) {
            $var = $tt[$ii];
            if (isset ($vars["Comment_" . $var])) {
                if ($LenghtComments != "")
                    $LenghtComments = $LenghtComments . ",";
                $LenghtComments = $LenghtComments . $var;
            }
        }
        if (!isset ($TCom->id)) {
            $str = "
INSERT INTO
    comments (
        IdToMember,
        IdFromMember,
        Lenght,
        Quality,
        TextWhere,
        TextFree,
        AdminAction,
        created
    )
    values (
        " . $vars['IdMember'] . ",
        " . $_SESSION['IdMember'] . ",
        '" . $LenghtComments . "','" . $vars['Quality'] . "',
        '" . $this->dao->escape($vars['TextWhere']) . "',
        '" . $this->dao->escape($vars['TextFree']) . "',
        '" . $AdminAction . "',now()
    )"
    ;
            $qry = $this->dao->query($str);
            if(!$qry) $return = false;
        } else {
            $textfree_add = ($vars['TextFree'] != '') ? ('<hr>' . $vars['TextFree']) : '';
            $str = "
UPDATE
    comments
SET 
    AdminAction='" . $AdminAction . "',
    IdToMember=" . $vars['IdMember'] . ",
    IdFromMember=" . $_SESSION['IdMember'] . ",
    Lenght='" . $LenghtComments . "',
    Quality='" . $vars['Quality'] . "',
    TextWhere='" . $this->dao->escape($vars['TextWhere']) . "',
    TextFree='" . $this->dao->escape($TCom->TextFree . $textfree_add) . "'
WHERE
    id=" . $TCom->id;
            $qry = $this->dao->exec($str);
            if(!$qry) $return = false;
        }
        if ($return != false) {
            // Create a note (member-notification) for this action
            $c_add = ($vars['Quality'] == "Bad") ? '_bad' : '';
            $note = array('IdMember' => $vars['IdMember'], 'IdRelMember' => $_SESSION['IdMember'], 'Type' => 'profile_comment'.$c_add, 'Link' => 'members/'.$vars['IdMember'].'/comments','WordCode' => 'Notify_profile_comment');
            $noteEntity = $this->createEntity('Note');
            $noteEntity->createNote($note);
        }
        return $return;
        
    }
    
    public function addRelation(&$vars)
    {
        $return = true;
        $words = new MOD_words();
        $TData= $this->singleLookup("select * from specialrelations where IdRelation=".$vars["IdRelation"]." and IdOwner=".$_SESSION["IdMember"]);
        
        if (!isset ($TData->id)) {
            $str = "
INSERT INTO
    specialrelations (
        IdOwner,
        IdRelation,
        Type,
        Comment,
        created
    )
    values (
        ".$_SESSION["IdMember"].",
        ".$vars['IdRelation'].",
        '".stripslashes($vars['stype'])."',
        ".$words->InsertInMTrad($this->dao->escape($vars['Comment']),"specialrelations.Comment",0).",
        now()
    )"
    ;
            $qry = $this->dao->query($str);
            if(!$qry) $return = false;
        } else $return = false;
        if ($return != false) {
            // Create a note (member-notification) for this action
            $note = array('IdMember' => $vars['IdRelation'], 'IdRelMember' => $_SESSION['IdMember'], 'Type' => 'relation', 'Link' => 'members/'.$vars['IdOwner'].'/relations/add','WordCode' => 'Notify_relation_new');
            $noteEntity = $this->createEntity('Note');
            $noteEntity->createNote($note);
        }
        return $return;
        
    }
    
    public function updateRelation(&$vars)
    {
        $return = true;
        $words = new MOD_words();
        $TData= $this->singleLookup("select * from specialrelations where IdRelation=".$vars["IdRelation"]." and IdOwner=".$_SESSION["IdMember"]);
        
        if (isset ($TData->id)) {
            $str = "
UPDATE
    specialrelations
SET
    Type = '".stripslashes($vars['stype'])."',
    Comment = ".$words->InsertInMTrad($this->dao->escape($vars['Comment']),"specialrelations.Comment",0)."
WHERE
    IdOwner = ".$_SESSION["IdMember"]." AND
    IdRelation = ".$vars['IdRelation']."
            ";
            $qry = $this->dao->query($str);
            if(!$qry) $return = false;
        } else $return = false;
        if ($return != false) {
            // Create a note (member-notification) for this action
            $note = array('IdMember' => $vars['IdRelation'], 'IdRelMember' => $_SESSION['IdMember'], 'Type' => 'relation', 'Link' => 'members/'.$vars['IdOwner'].'/relations/add','WordCode' => 'Notify_relation_update');
            $noteEntity = $this->createEntity('Note');
            $noteEntity->createNote($note);
        }
        return $return;
        
    }
    
    public function confirmRelation(&$vars)
    {
        $return = true;
        $words = new MOD_words();
        $TData = array();
        $TData[1]= $this->singleLookup("select * from specialrelations where IdOwner=".$vars['IdOwner']." AND IdRelation=".$vars['IdRelation']);
        $TData[2]= $this->singleLookup("select * from specialrelations where IdOwner=".$vars['IdRelation']." AND IdRelation=".$vars['IdOwner']);
        if (isset($TData) && count($TData[1]) > 0 && count($TData[2]) > 0 && isset($vars['confirm'])) {
            foreach ($TData as $rel) {
                $IdOwner = $rel->IdOwner;
                $IdRelation = $rel->IdRelation;
                $str = "
UPDATE
    specialrelations
SET
    Confirmed = '".$vars['confirm']."'
WHERE
    IdOwner = ".$IdOwner." AND
    IdRelation = ".$IdRelation."
                ";
                $qry = $this->dao->query($str);
                if(!$qry) $return = false;
                if ($return != false) {
                    // Create a note (member-notification) for this action
                    $note = array('IdMember' => $IdRelation, 'IdRelMember' => $IdOwner, 'Type' => 'relation', 'Link' => 'members/'.$IdOwner.'/relations/add','WordCode' => 'Notify_relation_confirm_'.$vars['confirm']);
                    $noteEntity = $this->createEntity('Note');
                    $noteEntity->createNote($note);
                }
            }
        } else $return = false;
        return $return;
    }    
	
    public function deleteRelation(&$vars)
    {
        $return = false;
        $words = new MOD_words();
        $TData = array();
        $TData[1]= $this->singleLookup("select * from specialrelations where IdOwner=".$vars['IdOwner']." AND IdRelation=".$vars['IdRelation']);
        $TData[2]= $this->singleLookup("select * from specialrelations where IdOwner=".$vars['IdRelation']." AND IdRelation=".$vars['IdOwner']);
        if (isset($TData) && isset($TData[1]->IdOwner) && count($TData[1]) > 0 && count($TData[2]) > 0 && isset($vars['confirm'])) {
            foreach ($TData as $rel) {
                $IdOwner = $rel->IdOwner;
                $IdRelation = $rel->IdRelation;
                $str = "
DELETE FROM
    specialrelations
WHERE
    IdOwner = ".$IdOwner." AND
    IdRelation = ".$IdRelation."
                ";
                $qry = $this->dao->query($str);
                if(!$qry) $return = false;
                if ($return != false) {
                    // Create a note (member-notification) for this action
                    $note = array('IdMember' => $IdRelation, 'IdRelMember' => $IdOwner, 'Type' => 'relation', 'Link' => 'members/'.$IdRelation.'/relations/','WordCode' => 'Notify_relation_delete');
                    $noteEntity = $this->createEntity('Note');
                    $noteEntity->createNote($note);
                }
            }
        } else $return = false;
        return $return;
    }
	

    /**
     * Check form values of MyPreferences form,
     *
     * @param unknown_type $vars
     * @return unknown
     */
    public function checkMyPreferences(&$vars)
    {
        $errors = array();
        $log = MOD_log::get();

        // Password Check
        if (isset($vars['passwordnew']) && $vars['passwordnew'] != '') {
            $query = "select id from members where id=" . $_SESSION["IdMember"] . " and PassWord=PASSWORD('" . trim($vars['passwordold']) . "')";
            $qry = $this->dao->query($query);
            $rr = $qry->fetch(PDB::FETCH_OBJ);
            if (!$rr || !array_key_exists('id', $rr))
                $errors[] = 'ChangePasswordInvalidPasswordError';
            if( isset($vars['passwordnew']) && strlen($vars['passwordnew']) > 0) {
                if( strlen($vars['passwordnew']) < 6) {
                    $errors[] = 'ChangePasswordPasswordLengthError';
                }
                if(isset($vars['passwordconfirm'])) {
                    if(strlen(trim($vars['passwordconfirm'])) == 0) {
                        $errors[] = 'ChangePasswordConfirmPasswordError';
                    } elseif(trim($vars['passwordnew']) != trim($vars['passwordconfirm'])) {
                        $errors[] = 'ChangePasswordMatchError';
                    }
                }
            }
        }
        
        // Languages Check
        if (isset($vars['PreferenceLanguage'])) {
            $squery = "
SELECT
    id,
    Name
FROM
    languages
ORDER BY
    Name" ;
            $qry = $this->dao->query($squery);
            $langok = false;
            while ($rp = $qry->fetch(PDB::FETCH_OBJ)) {
              $rp->id;
              if ($vars['PreferenceLanguage'] == $rp->id)
                  $langok = true;
            }
            if ($langok == false) {
                $errors[] = 'PreferenceLanguageError'; 
            }
        }
        
        // email (e-mail duplicates in BW database allowed)
        // if (!isset($vars['Email']) || !PFunctions::isEmailAddress($vars['Email'])) {
            // $errors[] = 'SignupErrorInvalidEmail';
            // $log->write("Editmyprofile: Invalid Email update with value " .$vars['Email'], "Email Update");
        // }
        
        return $errors;
    }

    /**
     * Edit a members preferences, one at a time
     * 
     */
    public function editPreferences(&$vars)
    {
        // set other preferences
        $query = "select * from preferences";
        $rr = $this->bulkLookup($query);
        foreach ($rr as $rWhile) { // browse all preference
            if (isset($vars[$rWhile->codeName]) && $vars[$rWhile->codeName] != '')
                $result = $this->set_preference($vars['memberid'], $rWhile->id, $vars[$rWhile->codeName]);
        }
    }

    /**
     * Check form values of Mandatory form,
     * should always be analog to /build/signup/signup.model.php !!
     *
     * @param unknown_type $vars
     * @return unknown
     */
    public function checkProfileForm(&$vars)
    {
        $errors = array();
        
        // email (e-mail duplicates in BW database allowed)
        if (!isset($vars['Email']) || !PFunctions::isEmailAddress($vars['Email'])) {
            $Email = ((!empty($vars['Email'])) ? $vars['Email'] : '-empty-');
            $errors[] = 'SignupErrorInvalidEmail';
            $this->logWrite("Editmyprofile: Invalid Email update with value " .$Email, "Email Update");
        }
        if (empty($vars['Street']) || empty($vars['Zip']))
        {
            $Street = ((!empty($vars['Street'])) ? $vars['Street'] : '-empty-');
            $Zip = ((!empty($vars['Zip'])) ? $vars['Zip'] : '-empty-');
            $errors[] = 'SignupErrorInvalidAddress';
            $this->logWrite("Editmyprofile: Invalid address update with value {$Street} and {$Zip}", "Address Update");
        }

        $birthdate_error = false;
        if (empty($vars['BirthDate']) || false === $this->validateBirthdate($vars['BirthDate']))
        {
            $birthdate_error = true;
        }

        if ($birthdate_error)
        {
            $birthdate = ((!empty($vars['BirthDate'])) ? $vars['BirthDate'] : '-empty-');
            $errors[] = 'SignupErrorInvalidBirthDate';
            $this->logWrite("Editmyprofile: Invalid birthdate update with value {$vars['BirthDate']}", "Birthdate Update");
        }

        if (empty($vars['gender']) || !in_array($vars['gender'], array('male','female','IDontTell')))
        {
            $gender = ((!empty($vars['gender'])) ? $vars['gender'] : '-empty-');
            $errors[] = 'SignupErrorInvalidGender';
            $this->logWrite("Editmyprofile: Invalid gender update with value {$gender}", "Gender Update");
        }

        return $errors;
    }

    /**
     * validates a date and outputs valid date or false
     * checks if the age of the person is 17 > x > 100
     *
     * @param string $birthdate
     * @access public
     * @return string|bool
     */
    public function validateBirthdate($birthdate)
    {
        $birthdate = str_replace(array('/','.'),'-',$birthdate);
        if (preg_match('/^([1-2]\d\d\d)-([0-1]?[0-9])-([0-3]?[0-9])$/', $birthdate, $matches) || preg_match('/^([0-3]?[0-9])-([0-1]?[0-9])-([1-2]\d\d\d)$/', $birthdate, $matches))
        {
            if (strlen($matches[1]) == 4)
            {
                $year = $matches[1];
                $month = $matches[2];
                $day = $matches[3];
            }
            else
            {
                $year = $matches[3];
                $month = $matches[2];
                $day = $matches[1];
            }
            // fair chance date is american, so switch day and month
            if ($month > 12 && $day < 12)
            {
                $temp = $day;
                $day = $month;
                $month = $temp;
            }
            if (intval($year) < intval(date('Y', strtotime('-100 years'))) || intval($year) > intval(date('Y', strtotime('-17 years'))) || !checkdate($month, $day, $year))
            {
                return false;
            }
            else
            {
                return "{$year}-{$month}-{$day}";
            }
        }
        else
        {
            return false;
        }
    }

    /**
     * Update Member's Profile
     *
     * @param unknown_type $vars
     * @return unknown
     */
    public function updateProfile(&$vars)
    {
        $IdMember = (int)$vars['memberid'];
        $words = new MOD_words();
        $rights = new MOD_right();
        $log = MOD_log::get();
        $m = $vars['member'];

        // fantastic ... love the implementation. Fake
        $CanTranslate = false;
        // $CanTranslate = CanTranslate($vars["memberid"], $_SESSION['IdMember']);
        $ReadCrypted = "AdminReadCrypted"; // This might be changed in the future
        if ($rights->hasRight('Admin') /* or $CanTranslate */) { // admin or CanTranslate can alter other profiles 
            $ReadCrypted = "AdminReadCrypted"; // In this case the AdminReadCrypted will be used
        }
        foreach ($vars['languages_selected'] as $lang) {
            $this->set_language_spoken($lang->IdLanguage,$lang->Level,$IdMember);
        }
        
        // Set the language that ReplaceinMTrad uses for writing
        $words->setlangWrite($vars['profile_language']);

        // refactoring to use member entity
        $m->Gender = $vars['gender'];
        $m->HideGender = $vars['HideGender'];
        $m->BirthDate = $vars['BirthDate'];
        $birthdate = $this->validateBirthdate($vars['BirthDate']);
        $m->bday = substr($birthdate, -2);
        $m->bmonth = substr($birthdate, 5,2);
        $m->byear = substr($birthdate, 0,4);
        $m->HideBirthDate = $vars['HideBirthDate'];
        $m->HideGender = $vars['HideGender'];
        $m->ProfileSummary = $words->ReplaceInMTrad($this->cleanupText($vars['ProfileSummary']),"members.ProfileSummary", $IdMember, $m->ProfileSummary, $IdMember);
        $m->WebSite = $vars['WebSite'];
        $m->Accomodation = $vars['Accomodation'];
        $m->Organizations = $words->ReplaceInMTrad($vars['Organizations'],"members.Organizations", $IdMember, $m->Organizations, $IdMember);
        $m->Occupation = $words->ReplaceInMTrad($vars['Occupation'],"members.Occupation", $IdMember, $m->Occupation, $IdMember);
        $m->ILiveWith = $words->ReplaceInMTrad($vars['ILiveWith'],"members.ILiveWith", $IdMember, $m->ILiveWith, $IdMember);
        $m->MaxGuest = $vars['MaxGuest'];
        $m->MaxLenghtOfStay = $words->ReplaceInMTrad($vars['MaxLenghtOfStay'],"members.MaxLenghtOfStay", $IdMember, $m->MaxLenghtOfStay, $IdMember);
        $m->AdditionalAccomodationInfo = $words->ReplaceInMTrad($vars['AdditionalAccomodationInfo'],"members.AdditionalAccomodationInfo", $IdMember, $m->AdditionalAccomodationInfo, $IdMember);
        $m->TypicOffer = $vars['TypicOffer'];
        $m->Restrictions = $vars['Restrictions'];
        $m->OtherRestrictions = $words->ReplaceInMTrad($vars['OtherRestrictions'],"members.OtherRestrictions", $IdMember, $m->OtherRestrictions, $IdMember);
        $m->Hobbies = $words->ReplaceInMTrad($vars['Hobbies'],"members.Hobbies", $IdMember, $m->Hobbies, $IdMember);
        $m->Books = $words->ReplaceInMTrad($vars['Books'],"members.Books", $IdMember, $m->Books, $IdMember);
        $m->Music = $words->ReplaceInMTrad($vars['Music'],"members.Music", $IdMember, $m->Music, $IdMember);
        $m->Movies = $words->ReplaceInMTrad($vars['Movies'],"members.Movies", $IdMember, $m->Movies, $IdMember);
        $m->PastTrips = $words->ReplaceInMTrad($vars['PastTrips'],"members.PastTrips", $IdMember, $m->PastTrips, $IdMember);
        $m->PlannedTrips = $words->ReplaceInMTrad($vars['PlannedTrips'],"members.PlannedTrips", $IdMember, $m->PlannedTrips, $IdMember);
        $m->PleaseBring = $words->ReplaceInMTrad($vars['PleaseBring'],"members.PleaseBring", $IdMember, $m->PleaseBring, $IdMember);
        $m->OfferGuests = $words->ReplaceInMTrad($vars['OfferGuests'],"members.OfferGuests", $IdMember, $m->OfferGuests, $IdMember);
        $m->OfferHosts = $words->ReplaceInMTrad($vars['OfferHosts'],"members.OfferHosts", $IdMember, $m->OfferHosts, $IdMember);
        $m->PublicTransport = $words->ReplaceInMTrad($vars['PublicTransport'],"members.PublicTransport", $IdMember, $m->PublicTransport, $IdMember);
        
        // as $CanTranslate is set explicitly above, this is disabled
        // if (!$CanTranslate) { // a volunteer translator will not be allowed to update crypted data        

        if ($vars["Email"] != $m->email) {
            $log->write("Email updated (previous was " . $m->email . ")", "Email Update");
        }                
        if ($vars["HouseNumber"] != $m->get_housenumber()) {
            $log->write("Housenumber updated (previous was {$m->get_housenumber()})", "Address Update");
        }                
        if ($vars["Street"] != $m->get_street()) {
            $log->write("Street updated (previous was {$m->get_street()})", "Address Update");
        }                
        if ($vars["Zip"] != $m->get_zip()) {
            $log->write("Zip updated (previous was {$m->get_zip()})", "Address Update");
        }                

        $m->Email = MOD_crypt::NewReplaceInCrypted($vars['Email'],"members.Email",$IdMember, $m->Email, $IdMember, $this->ShallICrypt($vars,"Email"));
        $m->HomePhoneNumber = MOD_crypt::NewReplaceInCrypted($vars['HomePhoneNumber'],"members.HomePhoneNumber",$IdMember, $m->HomePhoneNumber, $IdMember, $this->ShallICrypt($vars,"HomePhoneNumber"));
        $m->CellPhoneNumber = MOD_crypt::NewReplaceInCrypted($vars['CellPhoneNumber'],"members.CellPhoneNumber",$IdMember, $m->CellPhoneNumber, $IdMember, $this->ShallICrypt($vars,"CellPhoneNumber"));
        $m->WorkPhoneNumber = MOD_crypt::NewReplaceInCrypted($vars['WorkPhoneNumber'],"members.WorkPhoneNumber",$IdMember, $m->WorkPhoneNumber, $IdMember, $this->ShallICrypt($vars,"WorkPhoneNumber"));
        $m->chat_SKYPE = MOD_crypt::NewReplaceInCrypted($vars['chat_SKYPE'],"members.chat_SKYPE",$IdMember, $m->chat_SKYPE, $IdMember, $this->ShallICrypt($vars,"chat_SKYPE"));
        $m->chat_MSN = MOD_crypt::NewReplaceInCrypted($vars['chat_MSN'],"members.chat_MSN",$IdMember, $m->chat_MSN, $IdMember, $this->ShallICrypt($vars,"chat_MSN"));
        $m->chat_AOL = MOD_crypt::NewReplaceInCrypted($vars['chat_AOL'],"members.chat_AOL",$IdMember, $m->chat_AOL, $IdMember, $this->ShallICrypt($vars,"chat_AOL"));
        $m->chat_YAHOO = MOD_crypt::NewReplaceInCrypted($vars['chat_YAHOO'],"members.chat_YAHOO",$IdMember, $m->chat_YAHOO, $IdMember, $this->ShallICrypt($vars,"chat_YAHOO"));
        $m->chat_ICQ = MOD_crypt::NewReplaceInCrypted($vars['chat_ICQ'],"members.chat_ICQ",$IdMember, $m->chat_ICQ, $IdMember, $this->ShallICrypt($vars,"chat_ICQ"));
        $m->chat_Others = MOD_crypt::NewReplaceInCrypted($vars['chat_Others'],"members.chat_Others",$IdMember, $m->chat_Others, $IdMember, $this->ShallICrypt($vars,"chat_Others"));
        $m->chat_GOOGLE = MOD_crypt::NewReplaceInCrypted($vars['chat_GOOGLE'],"members.chat_GOOGLE",$IdMember,$m->chat_GOOGLE, $IdMember, $this->ShallICrypt($vars,"chat_GOOGLE"));        

        // Only update hide/unhide for identity fields
        MOD_crypt::NewReplaceInCrypted($this->dao->escape(MOD_crypt::$ReadCrypted($m->FirstName)),"members.FirstName",$IdMember, $m->FirstName, $IdMember, $this->ShallICrypt($vars, "FirstName"));
        MOD_crypt::NewReplaceInCrypted($this->dao->escape(MOD_crypt::$ReadCrypted($m->SecondName)),"members.SecondName",$IdMember, $m->SecondName, $IdMember, $this->ShallICrypt($vars, "SecondName"));
        MOD_crypt::NewReplaceInCrypted($this->dao->escape(MOD_crypt::$ReadCrypted($m->LastName)),"members.LastName",$IdMember, $m->LastName, $IdMember, $this->ShallICrypt($vars, "LastName"));
        MOD_crypt::NewReplaceInCrypted($this->dao->escape($vars['Zip']),"addresses.Zip",$m->IdAddress,$m->address->Zip,$IdMember,$this->ShallICrypt($vars, "Zip"));
        MOD_crypt::NewReplaceInCrypted($this->dao->escape($vars['HouseNumber']),"addresses.HouseNumber",$m->IdAddress,$m->address->HouseNumber,$IdMember,$this->ShallICrypt($vars, "Address"));
        MOD_crypt::NewReplaceInCrypted($this->dao->escape($vars['Street']),"addresses.StreetName",$m->IdAddress,$m->address->StreetName,$IdMember,$this->ShallICrypt($vars, "Address"));

        $status = $m->update();

        if (!empty($_FILES['profile_picture']) && !empty($_FILES['profile_picture']['tmp_name']))
        {
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0)
                $this->avatarMake($vars['memberid'],$_FILES['profile_picture']['tmp_name']);
        }
        
        return $status;
    }
    
    /**
     * prettify values from post request
     *
     * @param array $vars
     * @access public
     * @return array
     */
    public function polishProfileFormValues($vars)
    {
        $m = $vars['member'];
        
        // Prepare $vars
        $vars['ProfileSummary'] = $this->dao->escape($vars['ProfileSummary']);
        $vars['BirthDate'] = (($date = $this->validateBirthdate($vars['BirthDate'])) ? $date : $vars['BirthDate']);
        if (!isset($vars['HideBirthDate'])) $vars['HideBirthDate'] = 'No';
        // $vars['Occupation'] = ($member->Occupation > 0) ? $member->get_trad('ProfileOccupation', $profile_language) : '';
        
        // update $vars for $languages
        if(!isset($vars['languages_selected'])) { 
            $vars['languages_selected'] = array();
        }
        $ii = 0;
        $ii2 = 0;
        $lang_used = array();
        foreach($vars['memberslanguages'] as $lang) {
            if (ctype_digit($lang) and !in_array($lang,$lang_used)) { // check $lang is numeric, hence a legal IdLanguage
                $vars['languages_selected'][$ii]->IdLanguage = $lang;
                $vars['languages_selected'][$ii]->Level = $vars['memberslanguageslevel'][$ii2];
                array_push($lang_used, $vars['languages_selected'][$ii]->IdLanguage);
                $ii++;
            }
            $ii2++;
        }
        
        if (!isset($vars['IsHidden_FirstName'])) $vars['IsHidden_FirstName'] = 'No';
        if (!isset($vars['IsHidden_SecondName'])) $vars['IsHidden_SecondName'] = 'No';
        if (!isset($vars['IsHidden_LastName'])) $vars['IsHidden_LastName'] = 'No';
        if (!isset($vars['IsHidden_Address'])) $vars['IsHidden_Address'] = 'No';
        if (!isset($vars['IsHidden_Zip'])) $vars['IsHidden_Zip'] = 'No';
        if (!isset($vars['HideGender'])) $vars['HideGender'] = 'No';
        if (!isset($vars['IsHidden_HomePhoneNumber'])) $vars['IsHidden_HomePhoneNumber'] = 'No';
        if (!isset($vars['IsHidden_CellPhoneNumber'])) $vars['IsHidden_CellPhoneNumber']  = 'No';
        if (!isset($vars['IsHidden_WorkPhoneNumber'])) $vars['IsHidden_WorkPhoneNumber'] = 'No';
        
        $vars['Accomodation'] = $this->dao->escape($vars['Accomodation']);
        $vars['MaxLenghtOfStay'] = $this->dao->escape($vars['MaxLenghtOfStay']);
        $vars['ILiveWith'] = $this->dao->escape($vars['ILiveWith']);
        $vars['OfferGuests'] = $this->dao->escape($vars['OfferGuests']);
        $vars['OfferHosts'] = $this->dao->escape($vars['OfferHosts']);
        
        // Analyse TypicOffer list
        $TypicOffer = $m->TabTypicOffer;
        $max = count($TypicOffer);
        $vars['TypicOffer'] = "";
        for ($ii = 0; $ii < $max; $ii++) {
            if (isset($vars["check_" . $TypicOffer[$ii]]) && $vars["check_" . $TypicOffer[$ii]] == "on") {
                if ($vars['TypicOffer'] != "")
                    $vars['TypicOffer'] .= ",";
                $vars['TypicOffer'] .= $TypicOffer[$ii];
            }
        } // end of for $ii
        
        // Analyse Restrictions list
        $TabRestrictions = $m->TabRestrictions;
        $max = count($TabRestrictions);
        $vars['Restrictions'] = "";
        for ($ii = 0; $ii < $max; $ii++) {
            if (isset($vars["check_" . $TabRestrictions[$ii]]) && $vars["check_" . $TabRestrictions[$ii]] == "on") {
                if ($vars['Restrictions'] != "")
                    $vars['Restrictions'] .= ",";
                $vars['Restrictions'] .= $TabRestrictions[$ii];
            }
        } // end of for $ii
            
        $vars['PublicTransport'] = $this->dao->escape($vars['PublicTransport']);
        $vars['Restrictions'] = $this->dao->escape($vars['Restrictions']);
        $vars['OtherRestrictions'] = $this->dao->escape($vars['OtherRestrictions']);
        $vars['AdditionalAccomodationInfo'] = $this->dao->escape($vars['AdditionalAccomodationInfo']);
        $vars['OfferHosts'] = $this->dao->escape($vars['OfferHosts']);
        $vars['OfferGuests'] = $this->dao->escape($vars['OfferGuests']);
        $vars['Hobbies'] = $this->dao->escape($vars['Hobbies']);
        $vars['Books'] = $this->dao->escape($vars['Books']);
        $vars['Music'] = $this->dao->escape($vars['Music']);
        $vars['Movies'] = $this->dao->escape($vars['Movies']);
        $vars['Organizations'] = $this->dao->escape($vars['Organizations']);
        $vars['PastTrips'] = $this->dao->escape($vars['PastTrips']);
        $vars['PlannedTrips'] = $this->dao->escape($vars['PlannedTrips']);

        return $vars;
    }
    
    public function sendMandatoryForm($vars)
    {
        $rights = new MOD_rights();
        $right_Accepter = $rights->hasRight("Accepter");
        $right_SafetyTeam = $rights->hasRight("SafetyTeam");

        if (($right_Accepter) or ($right_SafetyTeam) and ($vars["cid"] != "")) { // Accepter or SafetyTeam can alter these data
        	$IdMember = $vars["cid"];
        	$ReadCrypted = "AdminReadCrypted"; // In this case the AdminReadCrypted will be used
        	// Restriction an accepter can only see/update mandatory data of someone in his Scope country
        	$AccepterScope = RightScope('Accepter');
        	$AccepterScope = str_replace("'", "\"", $AccepterScope); // To be sure than nobody used ' instead of " (todo : this test will be to remoev some day)
        	if (($AccepterScope != "\"All\"")and($IdMember!=$_SESSION['IdMember'])) {
        	   $rr=LoadRow("select IdCountry,countries.Name as CountryName,Username from members,cities,countries where cities.id=members.IdCity and cities.IdCountry=countries.id and members.id=".$IdMember) ;
        	   if (isset($rr->IdCountry)) {
        	   	  $tt=explode(",",$AccepterScope) ;
        		  	if ((!in_array($rr->IdCountry,$tt)) and (!in_array("\"".$rr->CountryName."\"",$tt))) {
        					 $ss=$AccepterScope ;
        					 for ($ii=0;$ii<sizeof($tt);$ii++) {
        					 		 if (is_numeric($tt[$ii])) {
        							 		$ss=$ss.",".getcountryname($tt[$ii]) ;
        							 }
        					 }				 
        		  	 	 die ("sorry Your accepter Scope is only for ".$ss." This member is in ".$rr->CountryName) ;
        		  	} 
        	   }
        	}
        	$StrLog="Viewing member [<b>".fUsername($IdMember)."</b>] data with right [".$AccepterScope."]" ;
        	if (HasRight("SafetyTeam")) {
        		 		$StrLog=$StrLog." <b>With SafetyTeam Right</b>" ;
        	}
        	LogStr($StrLog,"updatemandatory") ; 
        	$IsVolunteerAtWork = true;
        } else {
        	$IsVolunteerAtWork = false;
        	$ReadCrypted = "AdminReadCrypted"; // In this case the MemberReadCrypted will be used (only owner can decrypt)
        }
        
        if (($IsVolunteerAtWork)or($m->Status=='NeedMore')or($m->Status=='Pending')) {
		    // todo store previous values
		    $this->setlocation($IdMember,$vars['geonameid'] = false);
    		if ($IdAddress!=0) { // if the member already has an address
    			$str = "update addresses set IdCity=" . $IdCity . ",HouseNumber=" . NewReplaceInCrypted($HouseNumber,"addresses.HouseNumber",$IdAddress,$rr->HouseNumber, $m->id) . ",StreetName=" . NewReplaceInCrypted($StreetName,"addresses.StreetName",$IdAddress, $rr->StreetName, $m->id) . ",Zip=" . NewReplaceInCrypted($Zip,"addresses.Zip",$IdAddress, $rr->Zip, $m->id) . " where id=" . $IdAddress;
    			sql_query($str);
    		} else {
    			$str = "insert into addresses(IdMember,IdCity,HouseNumber,StreetName,Zip,created,Explanation) Values(" . $_SESSION['IdMember'] . "," . $IdCity . "," . NewInsertInCrypted("addresses.HouseNumber",0,$HouseNumber) . "," . NewInsertInCrypted("addresses.StreetNamer",0,$StreetName) . "," . NewInsertInCrypted("addresses.Zip",0,$Zip) . ",now(),\"Address created by volunteer\")";
    			sql_query($str);
    		    $IdAddress=mysql_insert_id();
    			LogStr("Doing a mandatoryupdate on <b>" . $Username . "</b> creating address", "updatemandatory");
    		}
    		$m->FirstName = NewReplaceInCrypted($FirstName,"members.FirstName",$m->id, $m->FirstName, $m->id,IsCryptedValue($m->FirstName));
    		$m->SecondName = NewReplaceInCrypted($SecondName,"members.SecondName",$m->id, $m->SecondName, $m->id,IsCryptedValue($m->SecondName));
    		$m->LastName = NewReplaceInCrypted(stripslashes($LastName),"members.LastName",$m->id, $m->LastName, $m->id,IsCryptedValue($m->LastName));

    		$str = "update members set FirstName=" . $m->FirstName . ",SecondName=" . $m->SecondName . ",LastName=" . $m->LastName . ",Gender='" . $Gender . "',HideGender='" . $HideGender . "',BirthDate='" . $DB_BirthDate . "',HideBirthDate='" . $HideBirthDate . "',IdCity=" . $IdCity . " where id=" . $m->id;
    		sql_query($str);
    		$slog = "Doing a mandatoryupdate on <b>" . $Username . "</b>";
    		if (($IsVolunteerAtWork) and ($MemberStatus != $m->Status)) {
    			$str = "update members set Status='" . $MemberStatus . "' where id=" . $m->id;
    			sql_query($str);
    			LogStr("Changing Status from " . $m->Status . " to " . $MemberStatus . " for member <b>" . $Username . "</b>", "updatemandatory");
    		}
    		elseif ($m->Status=='NeedMore') {
    			$str = "update members set Status='Pending' where id=" . $m->id;
    			sql_query($str);
    			$slog=" Completing profile after NeedMore ";
    			if (GetStrParam("Comment") != "") {
    			   $slog .= "<br /><i>" . stripslashes(GetStrParam("Comment")) . "</i>";
    			}
    			LogStr($slog, "updatemandatory");
    			DisplayUpdateMandatoryDone(ww('UpdateAfterNeedmoreConfirmed', $m->Username));
    			exit (0);
    		}


    		if (GetStrParam("Comment") != "") {
    			$slog .= "<br /><i>" . stripslashes(GetStrParam("Comment")) . "</i>";
    		}
    		LogStr($slog, "updatemandatory");
    	} else { // not volunteer action

    		$Email = GetEmail();

            // a member can only choose to hide or to show his gender / birth date and have it to take action immediately
      		if (($HideGender!=$m->HideGender) or ($HideBirthDate!=$m->HideBirthDate)) { 
    		   $str = "update members set HideGender='" . $HideGender . "',HideBirthDate='" . $HideBirthDate . "' where id=" . $m->id;
    		   LogStr("mandatoryupdate changing Hide Gender (".$HideGender."/".$m->HideGender.") or HideBirthDate (".$HideBirthDate."/".$m->HideBirthDate.")", "updatemandatory");
    		   sql_query($str);
    		}

    		$str = "insert into pendingmandatory(IdCity,FirstName,SecondName,LastName,HouseNumber,StreetName,Zip,Comment,IdAddress,IdMember) ";
    		$str .= " values(" . GetParam("IdCity") . ",'" . GetStrParam("FirstName") . "','" . GetStrParam("SecondName") . "','" . GetStrParam("LastName") . "','" . GetStrParam("HouseNumber") . "','" . GetStrParam("StreetName") . "','" . GetStrParam("Zip") . "','" . GetStrParam("Comment") . "',".$IdAddress.",".$IdMember.")";
    		sql_query($str);
    		LogStr("Adding a mandatoryupdate request", "updatemandatory");

    		$subj = ww("UpdateMantatorySubj", $_SYSHCVOL['SiteName']);
    		$text = ww("UpdateMantatoryMailConfirm", $FirstName, $SecondName, $LastName, $_SYSHCVOL['SiteName']);
    		$defLanguage = $_SESSION['IdLanguage'];
    		bw_mail($Email, $subj, $text, "", $_SYSHCVOL['UpdateMandatorySenderMail'], $defLanguage, "yes", "", "");

    		// Notify volunteers that an updater has updated
    		$subj = "Update mandatory " . $Username . " from " . getcountryname($IdCountry) . " has updated";
    		$text = " updater is " . $FirstName . " " . strtoupper($LastName) . "\n";
    		$text .= "using language " . LanguageName($_SESSION['IdLanguage']) . "\n";
    		if (GetStrParam("Comment")!="") $text .= "Feedback :<font color=green><b>" . GetStrParam("Comment") . "</font></b>\n";
    		else $text .= "No Feedback \n";
    		$text .= GetStrParam("ProfileSummary");
    		$text .= "<a href=\"https:/".$_SYSHCVOL['MainDir']."admin/adminmandatory.php\">go to update</a>\n";
    		bw_mail($_SYSHCVOL['MailToNotifyWhenNewMemberSignup'], $subj, $text, "", $_SYSHCVOL['UpdateMandatorySenderMail'], 0, "html", "", "");
        }
    }
    
    // Return the crypting criteria according of IsHidden_* field of a checkbox
    protected function ShallICrypt($vars, $ss) {
        if (isset($vars["IsHidden_" . $ss]) and $vars["IsHidden_" . $ss] == "Yes")
            return ("crypted");
        else
            return ("not crypted");
    } // end of ShallICrypt
        
    /**
     * Shows a members picture in different sizes
     *
     */
    public function showAvatar($memberId = false)
    {
        $file = (int)$memberId;
        if (isset($_GET)) {
            if (isset($_GET['xs']) or isset($_GET['50_50']))
                $suffix = '_xs';
            elseif (isset($_GET['30_30']))
                $suffix = '_30_30';
            else $suffix = '';
            $file .= $suffix;
        }

        $member = $this->createEntity('Member', $memberId);

        if (!$this->hasAvatar($memberId) || (!$member->publicProfile && !$this->getLoggedInMember())) {
            header('Content-type: image/png');
            @copy(HTDOCS_BASE.'images/misc/empty_avatar'.(isset($suffix) ? $suffix : '').'.png', 'php://output');
            PPHP::PExit();
        }
        $img = new MOD_images_Image($this->avatarDir->dirName().'/'.$file);
        if (!$img->isImage()) {
            header('Content-type: image/png');
            @copy(HTDOCS_BASE.'images/misc/empty_avatar'.(isset($suffix) ? $suffix : '').'.png', 'php://output');
            PPHP::PExit();
        }
        $size = $img->getImageSize();
        header('Content-type: '.image_type_to_mime_type($size[2]));
        $this->avatarDir->readFile($file);
        PPHP::PExit();
    }
        
    public function hasAvatar($memberid)
    {
        if ($this->avatarDir->fileExists((int)$memberid))
            return true;
        $img_path = $this->getOldPicture($memberid);
        $this->avatarMake($memberid,$img_path);
    }
    
    
    public function getOldPicture($memberid) {
        $s = $this->dao->query('
SELECT 
    `membersphotos`.`FilePath` as FilePath
FROM     
    `members` 
LEFT JOIN 
    `membersphotos` on `membersphotos`.`IdMember`=`members`.`id` 
WHERE 
    `members`.`id`=\'' . $memberid . '\' AND
    `members`.`Status`=\'Active\' 
ORDER BY membersphotos.SortOrder
');
        // look if any of the pics exists
        while ($row = $s->fetch(PDB::FETCH_OBJ)) {
            $path = str_replace("/bw", "", $row->FilePath);
            $full_path = getcwd().'/bw'.$path;
            if (PPHP::os() == 'WIN') {
                $full_path = str_replace("/", "\\", $full_path);
            }
            if(is_file($full_path)) {
                return $full_path;
            }
        }
        return false;       
    }    
        
    public function avatarMake($memberid,$img_file)
    {
        $img = new MOD_images_Image($img_file);
        if( !$img->isImage())
            return false;
        $size = $img->getImageSize();
        $type = $size[2];
        // maybe this should be changed by configuration
        if( $type != IMAGETYPE_GIF && $type != IMAGETYPE_JPEG && $type != IMAGETYPE_PNG)
            return false;
        $max_x = $size[0];
        $max_y = $size[1];
        if( $max_x > 100)
            $max_x = 100;
        // if( $max_y > 100)
            // $max_y = 100;
        $this->writeMemberphoto($memberid);
        $img->createThumb($this->avatarDir->dirName(), $memberid.'_original', $size[0], $size[1], true, 'ratio');
        $img->createThumb($this->avatarDir->dirName(), $memberid, $max_x, $max_y, true, '');
        $img->createThumb($this->avatarDir->dirName(), $memberid.'_xs', 50, 50, true, 'square');
        $img->createThumb($this->avatarDir->dirName(), $memberid.'_30_30', 30, 30, true, 'square');
        return true;
    }

    public function writeMemberphoto($memberid)
    {
		$s = $this->dao->exec("
INSERT INTO 
    `membersphotos`
	(
		FilePath,
		IdMember,
		created,
		SortOrder,
		Comment
	) 
VALUES
	(
		'" . $this->avatarDir->dirName() ."/". $memberid . "',
		" . $memberid . ",
		now(),
		-1,
		''
	)
");
        return $s;
    }

    public function bootstrap()
    {
        $this->avatarDir = new PDataDir('user/avatars');
    }
    
/*
* cleanupText
*
*
*
*/
    private function cleanupText($txt) {
        $str = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"/></head><body>'.$txt.'</body></html>'; 
        $doc = DOMDocument::loadHTML($str);
        if ($doc) {
            $sanitize = new PSafeHTML($doc);
            $sanitize->allow('html');
            $sanitize->allow('body');
            $sanitize->allow('p');
            $sanitize->allow('div');
            $sanitize->allow('b');
            $sanitize->allow('i');
            $sanitize->allow('u');
            $sanitize->allow('a');
            $sanitize->allow('em');
            $sanitize->allow('strong');
            $sanitize->allow('hr');
            $sanitize->allow('span');
            $sanitize->allow('ul');
            $sanitize->allow('li');
            $sanitize->allow('font');
            $sanitize->allow('strike');
            $sanitize->allow('br');
            $sanitize->allow('blockquote');
            $sanitize->allow('h1');
            $sanitize->allow('h2');
            $sanitize->allow('h3');
            $sanitize->allow('h4');
            $sanitize->allow('h5');
        
            $sanitize->allowAttribute('color');    
            $sanitize->allowAttribute('bgcolor');            
            $sanitize->allowAttribute('href');
            $sanitize->allowAttribute('style');
            $sanitize->allowAttribute('class');
            $sanitize->allowAttribute('width');
            $sanitize->allowAttribute('height');
            $sanitize->allowAttribute('src');
            $sanitize->allowAttribute('alt');
            $sanitize->allowAttribute('title');
            $sanitize->clean();
            $doc = $sanitize->getDoc();
            $nodes = $doc->x->query('/html/body/node()');
            $ret = '';
            foreach ($nodes as $node) {
                $ret .= $doc->saveXML($node);
            }
            return $ret;
        } else {
            // invalid HTML
            return '';
        }
    } // end of cleanupText
}


?>
