<?php

/**
 * @name Utils
 */
abstract class Utils 
{
    protected $type;
    protected $frimvare;
    protected $version;
    protected $build;
    protected $model;
    protected $compiled;
    protected $rom_model;
    protected $rom_version;
    protected $serial;
    
    /**
     * @param $value
     * @return string|integer
     */
    public function get_temperature_value($value)
    {
        return round((integer)$value / 256, 2);
    }

    /**
     * @param $value
     * @return string|integer
     */
    public function get_interface_signal_value($value)
    {
        return round((integer)$value / 10, 2);
    }

    /**
     * @param array $status_array
     * @return array
     */
    public function prepare_deregister_status_array($status_array)
    {
        $response = array();

        if (is_array($status_array)) {
            foreach ($status_array as $oid_mac => $status) {
                $mac = $this->convert_oid_to_mac($oid_mac);

                $response[$mac] = $this->get_deregister_status_reason_text($status);
            }
        }

        return $response;
    }

    /**
     * LLID & ONU binding last deregister reason:
     *   normal(2),
     *   mpcp-down(3),
     *   oam-down(4),
     *   firmware-download(5)
     *   illegal-mac(6)
     *   llid-admin-down(7)
     *   wire-down(8)
     *   power-off(9)
     *   unknow(255)
     *
     * @param $status_code
     * @return string
     */
    public function get_deregister_status_reason_text($status_code)
    {
        switch ($status_code) {
            case 0 :
                return "registered";
            case 1 :
                return "unknown";
            case 2 :
                return "normal";
            case 3 :
                return "mpcp-down";
            case 4 :
                return "oam-down";
            case 5 :
                return "firmware-download";
            case 6 :
                return "illegal-mac";
            case 7 :
                return "llid-admin-down";
            case 8 :
                return "wire-down";
            case 9 :
                return "power-off";
            case 255 :
                return "wire-down";

            default:
                return "default";
        }
    }

    /**
     * Получить название из статуса oper_state
     * @param int $state_code
     * @return string
     */
    public function convert_oper_state($state_code)
    {
        switch ($state_code) {
            case 0:
                return "unknown";
            case 1:
                return "up";
            case 2:
                return "down";
            case 3:
                return "unknown(3)";
            case 4:
                return "unknown(4)";

            default:
                return "default";
        }
    }

    /**
     * @param $version
     * @return false|string
     */
    public function convert_onu_version($version)
    {
        $version = str_replace(" ", "", $version);
        return hex2bin($version);
    }

    /**
     * @param $signal
     * @return int|string
     */
    public function convert_signal($signal)
    {
        if ($signal == 0 or !$signal or $signal == null) {
            return 0;
        }

        $signal = ($signal / 10);
        return sprintf('%.2f', $signal);
    }

    /**
     * @param $distance
     * @return float
     */
    public function convert_distance($distance)
    {
        return round($distance);
    }

    /**
     * @param $value
     * @return array|string|string[]|null
     */
    public function convert_bin_to_mac($value)
    {
        if (strlen($value) === 18) {
            $value = str_replace(' ', '', $value);
        }

        $value = trim($value, " \"");
        $value = trim($value, '"');
        $value = stripslashes($value);

        if (strlen($value) < 10) {
            $value = bin2hex($value);
        }

        $value = strtolower($value);
        $value = preg_replace('/(.{2})/', '\1:', $value, 5);

        return $value;
    }

    /**
     * @param $oid_mac
     * @return array|string|string[]|null
     */
    public function convert_oid_to_mac($oid_mac)
    {
        $iface = explode('.', $oid_mac);
        $length = sizeof($iface);
        $mac = substr('0' . dechex($iface[($length - 6)]), -2);
        $mac .= substr('0' . dechex($iface[($length - 5)]), -2);
        $mac .= substr('0' . dechex($iface[($length - 4)]), -2);
        $mac .= substr('0' . dechex($iface[($length - 3)]), -2);
        $mac .= substr('0' . dechex($iface[($length - 2)]), -2);
        $mac .= substr('0' . dechex($iface[($length - 1)]), -2);

        return preg_replace('/(.{2})/', '\1:', $mac, 5);
    }

    /**
     * Timeticks to time
     *
     * @param $timeticks
     * @return string
     */
    public function TimeticksToTime($timeticks)
    {
        if (strpos($timeticks, ":") !== false) { // Timeticks: (830779309) 96 days, 3:43:13.09
            if (strpos($timeticks, ")") !== false) {
                list($timeticks, $skip) = explode(") ", $timeticks);
                list($skip, $timeticks) = explode(" (", $timeticks);
                $ConvertSeconds = $this->TimeticksToTime($timeticks);
            } else {
                list($d, $h, $m, $s) = explode(":", $timeticks);
                $ConvertSeconds = ($d ? "{$d}d " : "") . "{$h}h {$m}m";
            }
        } else { // 830779309
            $lntSecs = intval($timeticks / 100);
            $intDays = intval($lntSecs / 86400);
            $intHours = intval(($lntSecs - ($intDays * 86400)) / 3600);
            $intMinutes = intval(($lntSecs - ($intDays * 86400) - ($intHours * 3600)) / 60);
            $intSeconds = intval(($lntSecs - ($intDays * 86400) - ($intHours * 3600) - ($intMinutes * 60)));
            $ConvertSeconds = ($intDays ? "{$intDays}d " : "") . "{$intHours}h {$intMinutes}m";
        }

        return $ConvertSeconds;
    }
}

/**
 * @name bdcomP3310x
 */
class bdcomP3310x extends Utils
{
    /**
     * @var NetSNMP
     */
    private $snmp_model;

    private $_oid_olt_uptime = "1.3.6.1.2.1.1.3.0";
    private $_oid_olt_cpu = "1.3.6.1.4.1.3320.9.109.1.1.1.1.3.1";

    private $_oid_olt_ifname = "1.3.6.1.2.1.2.2.1.2";
    private $_oid_olt_ifoper_status = "1.3.6.1.2.1.2.2.1.8";
    private $_oid_olt_sfp_temperature = "1.3.6.1.4.1.3320.101.107.1.6";
    private $_oid_olt_sfp_signal = "1.3.6.1.4.1.3320.101.107.1.3";

    private $_oid_onu_mac = "1.3.6.1.4.1.3320.101.10.1.1.3";
    private $_oid_onu_distance = "1.3.6.1.4.1.3320.101.10.1.1.27";
    private $_oid_onu_dereg_status = "1.3.6.1.4.1.3320.101.11.1.1.11";
    private $_oid_onu_signal_rx = "1.3.6.1.4.1.3320.101.10.5.1.5";

    private $_oid_onu_model = "1.3.6.1.4.1.3320.101.10.1.1.2";
    private $_oid_onu_vendor = "1.3.6.1.4.1.3320.101.10.1.1.1";
    private $_oid_onu_hardware = "1.3.6.1.4.1.3320.101.10.1.1.4.33";
    private $_oid_onu_version = "1.3.6.1.4.1.3320.101.10.1.1.5";
    private $_oid_onu_firmware = "1.3.6.1.4.1.3320.101.10.1.1.6";

    private $_oid_onu_wan = "1.3.6.1.4.1.3320.101.12.1.1.8";
    private $_oid_onu_temperature = "1.3.6.1.4.1.3320.101.10.5.1.2";
    private $_oid_onu_signal_tx = "1.3.6.1.4.1.3320.101.10.5.1.6";
    private $_oid_onu_admin_status = "1.3.6.1.4.1.3320.101.12.1.1.7";
    private $_oid_onu_port_pvid = "1.3.6.1.4.1.3320.101.12.1.1.3";
    private $_oid_onu_fdb = "1.3.6.1.4.1.3320.152.1.1.3";

    private $_oid_onu_port_state = "1.3.6.1.4.1.3320.101.12.1.1.7";
    private $_oid_onu_link_state = "1.3.6.1.4.1.3320.101.12.1.1.8";

    /**
     * @param NetSNMP $snmp_class
     */
    function __construct($snmp_class){
        $this->snmp_model = $snmp_class;
    }

    function __destruct() {
        unset($this->snmp_model);
    }

    /**
     * @param string $version
     */
    function init_version($version) {
        list($p1, $p2, $p3) = explode("\n", $version);
        list($this->type, $this->model, $skip, $skip, $this->version, $skip, $this->build) = explode(" ", $p1);
        list($skip, $this->compiled) = explode(":", $p2);
        list($skip, $this->serial) = explode(":", $p3);

        // изменения для разных моделий/версий
        if( $this->model == "P3310B" ) {
            $this->_oid_olt_sfp_temperature = "1.3.6.1.4.1.3320.9.183.1.1.13";
            $this->_oid_olt_sfp_signal = "1.3.6.1.4.1.3320.9.183.1.1.8";
        }
    }

    /**
     * @return array
     */
    function poll_olt()
    {
        $olt_proper = array(
            "status" => 0,
            "uptime" => $this->getUptime(),
            "interfaces" => array(),
        );

        $olt_proper["model"] = $this->getModel();
        $olt_proper["version"] = $this->getVersion();
        if( $olt_proper["model"] != "" ) {
            $olt_proper["status"] = 1;
        }

        $ifname_array = $this->snmp_model->walk($this->_oid_olt_ifname, true);
        if( is_array($ifname_array) && count($ifname_array) > 0 ) {

            // название интерфейсов + индексы
            foreach($ifname_array as $key => $value) {
                if( strpos($value, ":") !== false ) {
                    unset($ifname_array[$key]);
                    continue;
                }

                $olt_proper["interfaces"][$key]["index"] = $key;
                $olt_proper["interfaces"][$key]["iface"] = $value;
                $olt_proper["interfaces"][$key]["sfp"] = 0;
                $olt_proper["interfaces"][$key]["temperature"] = 0;
                $olt_proper["interfaces"][$key]["signal"] = 0;

                if( strpos($value, "PON") !== false ){
                    $olt_proper["interfaces"][$key]["sfp"] = 1;

                    $signal = $this->snmp_model->get($this->_oid_olt_sfp_signal . ".{$key}", true);
                    $olt_proper["interfaces"][$key]["signal"] = $this->get_interface_signal_value($signal);
                }
            }

            $oper_state = $this->snmp_model->walk($this->_oid_olt_ifoper_status, true);
            foreach($oper_state as $key => $value) {
                if( isset($olt_proper["interfaces"][$key]) ) {
                    $olt_proper["interfaces"][$key]["operState"] = $this->convert_oper_state($value);
                }
            }

            $sfp_temperature = $this->snmp_model->walk($this->_oid_olt_sfp_temperature, true);
            foreach($sfp_temperature as $key => $value) {
                if( isset($olt_proper["interfaces"][$key]) ) {
                    $olt_proper["interfaces"][$key]["temperature"] = $this->get_temperature_value($value);
                }
            }
        }

        return $olt_proper;
    }

    /**
     * @return array
     */
    function poll_onu()
    {
        $onu_propers = array();

        $mac_array = $this->snmp_model->walk($this->_oid_onu_mac, true);
        if( is_array($mac_array) && count($mac_array) > 0 ) {
            // статусы отключенных ONU
            $deregister_status_snmp = $this->snmp_model->walk($this->_oid_onu_dereg_status, true);
            $dereg_status_array = $this->prepare_deregister_status_array($deregister_status_snmp);

            // ifname
            $ifname_array = $this->snmp_model->walk($this->_oid_olt_ifname, true);

            // дистанция
            $distance_array = $this->snmp_model->walk($this->_oid_onu_distance, true);

            // mac адреса
            foreach( $mac_array as $key => $value ) {
                $onu_propers[$key]["index"] = $key;
                $onu_propers[$key]["mac"] = $this->convert_bin_to_mac($value);

                if( isset($ifname_array[$key]) ) { // ifname
                    $onu_propers[$key]["ifname"] = $ifname_array[$key];
                    unset($ifname_array[$key]);
                } else {
                    unset($ifname_array[$key]);
                }

                if( isset($dereg_status_array[$onu_propers[$key]["mac"]]) ) {
                    $onu_propers[$key]["status"] = $dereg_status_array[$onu_propers[$key]["mac"]];
                }

                if( isset($distance_array[$key]) ) { // ifname
                    $onu_propers[$key]["distance"] = $this->convert_distance($distance_array[$key]);
                    if( (int)$distance_array[$key] > 0 ) {
                        // online
                        $onu_propers[$key]["online"] = 1;

                        // проверим сигнал только у включенной ONU
                        $signal = $this->snmp_model->get("{$this->_oid_onu_signal_rx}.{$key}", true);
                        $onu_propers[$key]["signal_rx"] = $this->convert_signal($signal);
                    } else {
                        // offline
                        $onu_propers[$key]["online"] = 0;
                        $onu_propers[$key]["signal_rx"] = 0;
                    }
                    unset($distance_array[$key]);
                } else {
                    unset($distance_array[$key]);
                }

                unset($mac_array[$key]);
            }
        }

        return $onu_propers;
    }

    /**
     * @return string
     */
    function getVersion()
    {
        return $this->version;
    }

    /**
     * @return string
     */
    function getModel() {
        return $this->model;
    }

    /**
     * @return string
     */
    function getUptime()
    {
        $seconds = $this->snmp_model->get($this->_oid_olt_uptime);
        return $this->TimeticksToTime($seconds);
    }
}
