<?php
/**
 * This class configures oil tank variables and manages them
 *
 * PHP version 7
 *
 * LICENSE: This source file is subject to version 3 of the
 * GNU GENERAL PUBLIC LICENSE Version 3 that is available
 * through the world-wide-web at the following URI:
 * http://www.gnu.org/licenses/gpl.txt
 *
 * @package     SymconOilTank
 * @author      Florian Probst <florian.probst@gmx.de>
 * @date        03.10.2018
 * @time        09:31
 * @licence     http://www.gnu.org/licenses/gpl.txt
 */


require_once('OilTankVariableProfile.class.php');

/**
 * class OilTankVariable
 *
 * @uses OilTankVariableProfile
 */
class OilTankVariable{
    /**
     * ips id of the variable
     *
     * @var int
     * @access private
     */
    protected $id;

    /**
     * name of the variable
     *
     * @var string
     * @access private
     */
    protected $name;

    /**
     * data type (only IPS-Datatypes)
     * bool, int, float, string
     *
     * @var int
     * @access private
     */
    protected $type;

    /**
     * id of the variables parent
     * this defines where the variable will be created
     *
     * @var int
     * @access private
     */
    protected $parent;

    /**
     * value of the variable
     *
     * @var mixed can be bool, int, float, string (check with \$type)
     * @access private
     */
    protected $value;

    /**
     * value of the variable
     *
     * @var EnergyVariableProfile variable profile for this variable
     * @access private
     */
    protected $profile;

    /**
     * debug information
     * enables debug information for this class
     *
     * @var boolean
     * @access private
     */
    private $debug;

    /**
     * instance id of the archive control (usually located in IPS\core)
     *
     * @var integer
     * @access private
     */
    private $archiveId;

    /**
     * enables / disables IPS logging features
     *
     * @var boolean
     * @access private
     */
    private $enableLogging;

    /**
     * IPS - datatype boolean
     * @const tBOOL
     * @access private
     */
    const tBOOL = 0;

    /**
     * IPS - datatype integer
     * @const tINT
     * @access private
     */
    const tINT = 1;

    /**
     * IPS - datatype float
     * @const tFLOAT
     * @access private
     */
    const tFLOAT = 2;

    /**
     * IPS - datatype string
     * @const tSTRING
     * @access private
     */
    const tSTRING = 3;

    /**
     * constructor
     *
     * create the variable in symcon if it does not exist
     *
     * @param string $name name of the variable
     * @param integer $type IPS datatype
     * @param integer $parent id of the variables parent, this defines where the variable will be created
     * @param mixed $value initially set a variable value
     * @param EnergyVariableProfile $profile variable profile for this variable
     * @param boolean $enableLogging enables or disables the ips functionality to log variable changes in a database
     * @param integer $archiveId instance id of the archive control (usually located in IPS\core)
     * @param boolean $debug enables / disables debug information
     *
     * @throws Exception if the parameter \$profile is not an OilTankVariableProfile datatype
     * @access public
     */
    public function __construct($name, $type, $parent, $profile = NULL, $enableLogging = false, $archiveId = NULL, $debug = false){
        if(isset($profile) && !($profile instanceof OilTankVariableProfile))
            throw new Exception("Parameter \$profile must be an instance of OilTankVariableProfile! \$name of the variable is '$name'");

        $this->name = $name;
        $this->type = $type;
        $this->parent = $parent;
        $this->profile = $profile;
        $this->enableLogging = $enableLogging;
        $this->archiveId = $archiveId;
        $this->debug = $debug;

        $this->id = @IPS_GetVariableIDByName($name, $parent);
        if($this->id == false){
            //if($this->debug) echo "INFO - create IPS variable $name\n";
            $this->id = IPS_CreateVariable($this->type);
            IPS_SetName($this->id, $name);
            IPS_SetParent($this->id, $parent);
            IPS_SetInfo($this->id, "this variable was created by script " . $_IPS['SELF']);
            IPS_SetVariableCustomProfile($this->id, $profile->getName());
            $this->verifyVariableLogging();
        }
    }

    /**
     * checks if the variable logging state fits the settings
     * if not this method will update the variable logging
     *
     * @throws Exception if $archiveId is not set while logging is enabled
     * @access private
     */
    private function verifyVariableLogging(){
        if ($this->enableLogging) {
            if($this->archiveId == NULL)
                throw new Exception("Parameter \$archiveId is not set but \$enableLogging is true");
            if($this->checkArchive($this->archiveId)){
                if(!AC_GetLoggingStatus($this->archiveId, $this->id)){
                    AC_SetLoggingStatus($this->archiveId, $this->id, true);
                    IPS_ApplyChanges($this->archiveId);
                }
            }
        }
        else{
            //todo: disable logging not implemented
        }
    }

    /**
     * checks if the instance to $archiveId is a valid IPS archive object
     *
     * @param integer $archiveId instance id to be checked
     * @throws Exception if $archiveId does not match to IPS archive
     * @return true if id refers to an archive
     * @access private
     */
    private function checkArchive($archiveId){
        $archive = @IPS_GetInstance($archiveId);
        if($archive == NULL)
            throw new Exception("Archive with instance id $archiveId does not exist");
        if($archive["ModuleInfo"]["ModuleID"] == "{43192F0B-135B-4CE7-A0A7-1475603F3060}")
            return true;
        return false;
    }

    /**
     * sets the variable value
     *
     * @throws Exception if the value type does not match the variable type
     * @return true if value was set successful
     * @access public
     */
    public function setValue($value){
        if($this->type == self::tBOOL && !is_bool($value))
            throw new Exception("(Variable ". $this->name .")Param 'value' is not a boolean.");
        if($this->type == self::tINT && !is_int($value))
            throw new Exception("(Variable ". $this->name .")Param 'value' is not an integer.");
        if($this->type == self::tFLOAT && !is_float($value))
            throw new Exception("(Variable ". $this->name .")Param 'value' is not a float.");
        if($this->type == self::tSTRING && !is_string($value))
            throw new Exception("(Variable ". $this->name .")Param 'value' is not a string.");
        $this->value = $value;
        SetValue($this->id, $value);
        return true;
    }

    /**
     * returns the variable id
     *
     * @return integer variable id
     * @access public
     */
    public function getId(){
        return $this->id;
    }

    /**
     * returns the variable value
     *
     * @return mixed variable value
     * @access public
     */
    public function getValue(){
        return GetValue($this->id);
    }

    /**
     * returns the variable name
     *
     * @return string variable name
     * @access public
     */
    public function getName(){
        return $this->name;
    }

    /**
     * returns the variable type
     *
     * @return int variable type
     * @access public
     */
    public function getType(){
        return $this->type;
    }

    /**
     * returns the variable parent id
     *
     * @return int parent id
     * @access public
     */
    public function getParent(){
        return $this->parent;
    }

    /**
     * returns the variable profile
     *
     * @return EnergyVariableProfile variable profile
     * @access public
     */
    public function getProfile(){
        return $this->profiles;
    }

    /**
     * returns the archive id
     *
     * @return integer archive id
     * @access public
     */
    public function getArchiveId(){
        return $this->archiveId;
    }

    /**
     * returns if logging is enabled
     *
     * @return boolean enableLogging
     * @access public
     */
    public function isLoggingEnabled(){
        return $this->enableLogging;
    }

    /**
     * deletes the variable in ip-symcon
     *
     * @access public
     */
    public function delete(){
        IPS_DeleteVariable($this->id);
    }
}