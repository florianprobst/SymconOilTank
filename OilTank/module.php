<?php
/**
 * User: Florian Probst
 * Date: 02.10.2018
 * Time: 23:25
 */

require_once(__DIR__ . '/../libs/OilTankVariable.class.php');
require_once(__DIR__ . '/../libs/OilTankVariableProfile.class.php');

// Klassendefinition
class OilTank extends IPSModule {

    /*
    * color codes for variable profile associations
    * from bad (red) to good (green)
    */
    const hColor1			= 0xFF0000;	//red
    const hColor2			= 0xFF9D00;	//orange
    const hColor3			= 0xFFF700;	//yellow
    const hColor4			= 0x9DFF00;	//light green
    const hColor5			= 0x46F700;	//green

    public function __construct($InstanceID) {
        parent::__construct($InstanceID);

        $this->parentId = $InstanceID;
    }

    public function Create() {
        parent::Create();

        // Modul-Eigenschaftserstellung
        $this->RegisterPropertyInteger("FillHeight", 0);
        $this->RegisterPropertyInteger("ArchiveId", 0);
        $this->RegisterPropertyInteger("UpdateInterval", 180);
        $this->RegisterPropertyInteger("MaxFillHeight", 120);
        $this->RegisterPropertyInteger("SensorDistance", 17);
        $this->RegisterPropertyInteger("TankCapacity", 3144);
        $this->RegisterPropertyString("TankType", "linear");
        $this->RegisterPropertyString("OilLevels", "");
        $this->RegisterPropertyString("VariablePrefix", "oil_");
        $this->RegisterPropertyBoolean("Debugging", false);

        // Erstellt einen Timer mit dem Namen "Update" und einem Intervall von 5 Sekunden.
        $this->RegisterTimer("UpdateOilTank", 100000, 'oil_update($_IPS[\'TARGET\']);');
        //$this->RegisterTimer("Update", 120000, $this->update());
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->SetTimerInterval("UpdateOilTank", 1000 * $this->ReadPropertyInteger("UpdateInterval"));

        //check variables and variable profiles and create/update them if necessary
        $this->UpdateVariables();

    }

    private function UpdateVariables()
    {
        //$this->LogMessage("On Update - This ID is: " . $this->parentId, KL_NOTIFY);
        //create variable profiles if they do not exist
        $assoc[0] = ["val"=>0,	"name"=>"%.1f",	"icon" => "", "color" => self::hColor1];
        $assoc[1] = ["val"=>20,	"name"=>"%.1f",	"icon" => "", "color" => self::hColor2];
        $assoc[2] = ["val"=>40,	"name"=>"%.1f",	"icon" => "", "color" => self::hColor3];
        $assoc[3] = ["val"=>60,	"name"=>"%.1f",	"icon" => "", "color" => self::hColor4];
        $assoc[4] = ["val"=>80,	"name"=>"%.1f",	"icon" => "", "color" => self::hColor5];
        $profile_relative = new OilTankVariableProfile($this->ReadPropertyString("VariablePrefix") . "oil_level_relative", OilTankVariable::tFLOAT, "", " %", 2, $assoc, $this->ReadPropertyBoolean("Debugging"));
        unset($assoc);

        $profile_absolute = new OilTankVariableProfile($this->ReadPropertyString("VariablePrefix"). "oil_level_absolute", OilTankVariable::tFLOAT, "", " Liter", 2, NULL, $this->ReadPropertyBoolean("Debugging"));
        $profile_consumption = new OilTankVariableProfile($this->ReadPropertyString("VariablePrefix") . "oil_consumption", OilTankVariable::tFLOAT, "", " l/h", 2, NULL, $this->ReadPropertyBoolean("Debugging"));

        //create variables if they do not exist
        new OilTankVariable( "Oil_Level_Absolute", OilTankVariable::tFLOAT, $this->parentId, $profile_absolute, true, $this->ReadPropertyInteger("ArchiveId"), $this->ReadPropertyBoolean("Debugging"));
        new OilTankVariable("Oil_Level_Relative", OilTankVariable::tFLOAT, $this->parentId, $profile_relative, true, $this->ReadPropertyInteger("ArchiveId"), $this->ReadPropertyBoolean("Debugging"));
        new OilTankVariable("Oil_Consumption", OilTankVariable::tFLOAT, $this->parentId, $profile_consumption, true, $this->ReadPropertyInteger("ArchiveId"), $this->ReadPropertyBoolean("Debugging"));
    }

    /**
     * calculateOilLevelCm
     *
     * @param float $distance measured distance from sensor to oil level in tank
     * @return float oil level from tank bottom in centimeters
     * @access private
     */
    private function calculateOilLevelCm($distance){
        return $this->ReadPropertyInteger( "MaxFillHeight") - $distance + $this->ReadPropertyInteger( "SensorDistance");
    }

    /**
     * calculateOilLevelLiters
     *
     * @param float $distance measured distance from sensor to oil level in tank
     * @return float oil level in liters
     * @access private
     * @throws Exception
     */
    private function calculateOilLevelLiters($distance){

        if($this->ReadPropertyString("TankType") == "linear"){ //linear oil level
            IPS_LogMessage( "ich", "I AM HERE!");
            return round($this->calculateLitersPerCm() * $this->calculateOilLevelCm($distance), 2);
        }else{ //free form tank, we need the level table
            $arrString = $this->ReadPropertyString("OilLevels");
            $level_table = json_decode($arrString);
            $level = $this->calculateOilLevelCm($distance); //get current filling level in cm
            $counter = 0;
            foreach ($level_table as $p) {
                //IPS_LogMessage( "OilTank", "compare $level cm >= " . $p->OilLevel . "<p>");
                if($level < $p->OilLevel){
                    //we exceeded the measure point given in the table, the valid level is the previous one
                    $counter--;
                    break;
                }
                $counter++;
            }

            if($counter >= count($level_table)){
                IPS_LogMessage("IPS-Oiltank [#" . $this->instanceID . "]", "Laufzeit beträgt ". "Der gemessene Ölstand von '$level' cm ist in der Füllstandstabelle nicht definiert. Bitte Sensor und Füllstandstabelle prüfen!");
                throw new Exception("The measured oil level of '$level' cm is not defined in the level table! Please check your sensor data!");
            }

            //get the high and low level point from our table where our current oil level is between
            if ($counter == 0) {
                //maybe in our level table is no point defined which explicitely tells us that a level of 0 means 0 liters of oil. so we fix that here
                $lower_level = [ 0, 0 ];
            }else{
                $lower_level = $level_table[$counter-1];
            }
            $higher_level = $level_table[$counter];

            //print("Leveltable contains " . count($this->level_table) . " entries. Measured distance is " . $distance . " cm wich hits entry: " . $counter . " from " . $lower_level["level_in_cm"] ."-". $higher_level["level_in_cm"] ." cm<p>");

            //now we assume that our oil level will be linear between both level points from the table
            $oil_delta = (int)$higher_level->Liters - (int)$lower_level->Liters;
            $level_delta = (int)$higher_level->OilLevel - (int)$lower_level->OilLevel;
            $liters_per_cm = round($oil_delta / $level_delta, 2); //linear between both table points
            $level = $level - (int)$lower_level->OilLevel;
            return $lower_level->Liters + round($level * $liters_per_cm, 2); //its the liters of the lower table point plus the linear level between lower and higher point
        }
    }

    /**
     * calculateLitersPerCm
     *
     * based on the tank data, each oil level cm means a specific amout of oil in liters
     * if tank allows linear calculation.
     *
     * @return float oil liters per cm
     * @access private
     */
    private function calculateLitersPerCm(){
        return round($this->ReadPropertyInteger("TankCapacity") / $this->ReadPropertyInteger("MaxFillHeight"), 2);
    }

    /**
     * calculateOilLevelInPercent
     *
     * @param float $distance measured distance from sensor to oil level in tank
     * @return float oil level in percent
     * @access private
     * @throws Exception
     */
    private function calculateOilLevelInPercent($distance){
        return round(($this->calculateOilLevelLiters($distance) / $this->ReadPropertyInteger("TankCapacity")) * 100, 2);
    }
    /**
     * update: read new sensor value and update oil levels, statistics, etc.
     *
     * @access public
     */
    public function update(){
        $distance = GetValue($this->ReadPropertyInteger("FillHeight"));
        $oil_level_abs = new OilTankVariable("Oil_Level_Absolute",OilTankVariable::tFLOAT, $this->InstanceID);
        $oil_level_rel = new OilTankVariable("Oil_Level_Relative", OilTankVariable::tFLOAT, $this->InstanceID);

        $new_liters = $this->calculateOilLevelLiters($distance);
        $oil_level_abs->setValue($new_liters);
        $oil_level_rel->setValue($this->calculateOilLevelInPercent($distance));

        //disable methods since they are buggy atm :-)
        /*
        $oil_consumption = new OilTankVariable("Oil_Consumption", OilTankVariable::tFLOAT, $this->InstanceID);
        $old_liters = $oil_level_abs->getValue();
        oil_consumption->setValue($this->calculateConsumptionPerHour($old_liters, $new_liters));
        $this->getAverageConsumptionByLastDay();
        $this->getAverageConsumptionByLastMonth();
        $this->getAverageConsumptionByLastYear();*/

        IPS_LogMessage("SymconOilTank [#" . $this->InstanceID . "]", "Oil-Level = " . $oil_level_abs->getValue() . " liters (" . $oil_level_rel->getValue() . " %) - fill height = " . $this->calculateOilLevelCm($distance) . " cm");
    }



    /**
     * calculateConsumptionPerHour
     *
     * calculates the oil consumption in liters per hour
     *
     * @param float $old_liters
     * @param float $new_liters
     * @return float oil consumption per hour
     * @access private
     */
    /* private function calculateConsumptionPerHour($old_liters, $new_liters){
         $consumption = ($old_liters - $new_liters) / $this->update_interval; //per second
         $consumption = $consumption * 3600; //per hour

         //if consumption is negative that indicates a refill and can be ignored
         if($consumption < 0) {
             return 0.00;
         }else{
             return round($consumption, 2);
         }
     }

     public function getAverageConsumptionByLastDay(){
         $startTimestamp = time()-60*60*24;
         $endTimestamp = time();
         $limit = 0;
         $values = AC_GetAggregatedValues($this->oil_consumption->getArchiveId(), $this->oil_consumption->getId(), 1, $startTimestamp, $endTimestamp, $limit);

         $result = round($values[0]["Avg"],2);
         if($this->debug) echo "getAverageConsumptionByLastDay results in: '$result'\n";
         //	$res = ($result * 365)
         return $result;
     }

     public function getAverageConsumptionByLastMonth(){
         $startTimestamp = time()-60*60*24*30;
         $endTimestamp = time();
         $limit = 0;
         $values = AC_GetAggregatedValues($this->oil_consumption->getArchiveId(), $this->oil_consumption->getId(), 3, $startTimestamp, $endTimestamp, $limit);

         $result = round($values[0]["Avg"],2);
         if($this->debug) echo "getAverageConsumptionByLastMonth results in: '$result'\n";
         return $result;
     }

     public function getAverageConsumptionByLastYear(){
         $startTimestamp = time()-60*60*24*30*365;
         $endTimestamp = time();
         $limit = 0;
         $values = AC_GetAggregatedValues($this->oil_consumption->getArchiveId(), $this->oil_consumption->getId(), 4, $startTimestamp, $endTimestamp, $limit);

         $result = round($values[0]["Avg"],2);
         if($this->debug) echo "getAverageConsumptionByLastYear results in: '$result'\n";
         return $result;
     }*/

}
