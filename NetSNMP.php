<?php

/**
 * Wrapper for SNMP class
 *
 * Requirements:
 * - PHP 7.0+
 * - php_snmp
 *
 * @property bool $oid_increasing_check
 * @package  dautkom\snmp
 */
class NetSNMP
{

    /**
     * SNMP version
     * @var int
     */
    private $version;

    /**
     * IP-address
     * @var string
     */
    private $ip;

    /**
     * SNMP communities and timeouts
     * @var array
     */
    private $snmp = [
        'public',
        'private',
        1000000,
        3
    ];

    /**
     * Current SNMP session instance
     * @var \SNMP
     */
    private $instance;

    /**
     * SNMP session mode. 0: read, 1: write
     * @var int
     */
    private $mode = null;

    /**
     * Flag if output should be filtered via $this->filters
     * @var bool
     */
    private $filter = true;

    /**
     * Output data filters
     * @var array
     */
    private $filters = [
        '/"/',
        '/Hex-/i',
        '/OID: /i',
        '/STRING: /i',
        '/Gauge32: /',
        '/INTEGER: /i',
        '/Counter32: /i',
        '/SNMPv2-SMI::enterprises\./i',
        '/iso\.3\.6\.1\.4\.1\./i', // if Net-SNMP is missing and MIBs are not loaded
    ];

    /**
     * Overloads for \SNMP class. Defined by magic method __set()
     * @var array
     */
    private $overloads = [];


    /**
     * Close current SNMP session
     * @ignore
     */
    public function __destruct()
    {
        if( $this->instance instanceof \SNMP) {
            $this->instance->close();
            $this->mode = null;
        }
    }


    /**
     * Check if desired propery is valid and exists in \SNMP class and register
     * it in $this->overloads array. Later, upon $this->init() is called, the
     * overloaded property will be set in actual SNMP session
     *
     * @param string $name   \SNMP property name
     * @param mixed  $value  \SNMP property value
     */
    public function __set(string $name, $value)
    {

        $reflection = new \ReflectionClass("SNMP");
        $_instance  = $reflection->newInstanceWithoutConstructor();

        if( property_exists($_instance, $name) ) {
            if( gettype($_instance->$name) == "NULL" || gettype($_instance->$name) == gettype($value) ) {
                $this->overloads[$name] = $value;
            }
        }

    }


    /**
     * Class initialization, property registering
     *
     * @param  string $ip      SNMP agent IP-address
     * @param  array  $snmp    [optional] SNMP settings: communities and timeouts
     * @param  int    $version [optional] SNMP engine version
     * @return MBLib_NetSNMP $this
     */
    public function init(string $ip, array $snmp=[], int $version = \SNMP::VERSION_2C)
    {
        $this->ip      = trim($ip);
        $this->snmp    = array_replace($this->snmp, $snmp);
        $this->version = intval($version);
        return $this;
    }


    /**
     * Setter for $this->filter flag determining should the output via SNMP::get() be filtered from obsolete data defined in $this->filters
     *
     * @param bool|int $filter flag if filtering is enabled
     */
    public function setFilter($filter)
    {
        $this->filter = boolval($filter);
    }


    /**
     * Wrapper for snmpget(). Fetch an SNMP object. Accepts both strings and arrays as OIDs.
     * When $oid is an array and keys in results will be taken exactly as in object_id.
     *
     * @param  array|string $oid SNMP object identifier
     * @return mixed
     */
    public function get($oid)
    {

        $this->connect(0);

        try {
            $_oid   = $this->prepareOid($oid);
            $result = @$this->instance->get($_oid, true);
            $result = $this->prepareResult($result);
        }
        catch (\Throwable $e) {
            trigger_error("No SNMP response", E_USER_WARNING);
            return null;
        }

        if( count($result) > 1 ) {
            return $result;
        }
        else {
            if( is_array($oid) ) {
                $oid = reset($oid);
            }
            return $result[$oid];
        }

    }


    /**
     * Wrapper for snmpset(). Set the value of an SNMP object. Accepts both strings and arrays as arguments
     *
     * @param  array $oid   SNMP object identifier
     * @param  array|string $type  content type literal
     * @param  array|string $value writable data
     * @return bool
     */
    public function set($oid, $type, $value): bool
    {

        $_oid  = $this->prepareOid($oid);
        $_type = $this->prepareType($type);
        $_data = $this->prepareData($value);

        $this->beforeSet($_oid, $_type, $_data); // Integrity check
        $this->connect(1);

        return $this->instance->set($_oid, $_type, $_data);

    }


    /**
     * Wrapper for snmpwalk() and snmprealwalk(). Fetch SNMP object subtree
     *
     * @param string $oid  SNMP object identifier representing root of subtree to be fetched
     * @param bool   $real [optional] By default full OID notation is used for keys in output array. If set to <b>TRUE</b> subtree prefix will be removed from keys leaving only suffix of object_id.
     * @return array
     */
    public function walk(string $oid, bool $real = false): array
    {

        $this->connect(0);

        try {
            $result = @$this->instance->walk($oid, $real);
            $result = $this->prepareResult($result);
        }
        catch (\Throwable $e) {
            trigger_error("No SNMP response", E_USER_WARNING);
            return [];
        }

        return $result;

    }


    /**
     * Retrieve session's IP-address
     *
     * @return string
     */
    public function getIp(): string
    {
        return $this->ip;
    }


    /**
     * Retrieve last error text
     *
     * @return string
     */
    public function getError()
    {
        return $this->instance ? $this->instance->getError() : null;
    }


    /**
     * Retrieve last error number
     *
     * @return int
     */
    public function getErrno()
    {
        return $this->instance ? $this->instance->getErrno() : null;
    }

    /**
     * SNMP session initialization depending on $mode argument
     *
     * Argument values:
     * 0: for read-only session
     * 1: for write session
     *
     * @param  int $mode session mode
     * @return void
     */
    private function connect(int $mode)
    {

        if( $this->mode !== $mode ) {

            if( $this->instance instanceof \SNMP) {
                $this->instance->close();
            }

            /** @noinspection PhpParamsInspection */
            $this->instance = new \SNMP($this->version, $this->ip, $this->snmp[$mode], $this->snmp[2], $this->snmp[3]);
            $this->mode     = $mode;

        }

        if( !empty($this->overloads) ){
            array_walk($this->overloads, function($value, $key){
                $this->instance->$key = $value;
            });
            $this->overloads = null;
        }

    }


    /**
     * Check OID integrity and convert it to array if necessary due SPL SNMP methods requires array instead of string
     *
     * @param  string|array $oid SNMP object identifier
     * @return string|array
     */
    private function prepareOid($oid): array
    {

        if( !is_array($oid) ) {
            $oid = array($oid);
        }

        return array_map( function($value) {
            return trim($value);
        }, $oid);

    }


    /**
     * Check data type for SNMP::set() function wrapper and convert it to array instead of string if necessary
     *
     * @param  array|string $type writeable data content type literal representation
     * @throws \UnexpectedValueException
     * @return array|string
     */
    private function prepareType($type)
    {

        if( !is_array($type) ) {
            $type = array($type);
        }

        try {
            // @ is used to suppress buggy php warning:
            // An error occurred while invoking the filter callback
            $type = @array_map( function ($value) {

                $value = substr(trim(strtolower($value)), 0, 1);

                if ( !preg_match('/[iutaosxdnb]/', $value) ) {
                    throw new \UnexpectedValueException('SNMP type mismatch');
                }

                return $value;

            }, $type);
        }
        catch (\UnexpectedValueException $e) {
            throw new \UnexpectedValueException($e->getMessage());
        }

        // Don't return unnecessary array
        // return string if resultset has only one element
        return ( count($type) == 1 ) ? implode('', $type) : $type;

    }


    /**
     * Cleans input data and converts it to array. Result is cleaned from illegal symbols. Regular expression contains allowed symbols
     *
     * @param string|array $data writable content
     * @return array
     */
    private function prepareData($data)
    {

        if( !is_array($data) ) {
            $data = array($data);
        }

        return array_map( function($value){
            return preg_replace('/[^\.A-Z0-9_ !@#$%^&()+={}[\]\',~`\-\'":;\\/*|><?]|\.+$/i', '', $value);
        }, $data);

    }


    /**
     * Check data integrity before writing data via SNMP
     *
     * @param array        $oid   SNMP object identifier
     * @param string|array $type  writeable content type literal
     * @param array        $data  writeable content data
     * @throws \UnexpectedValueException
     * @return void
     */
    private function beforeSet(array $oid, $type, array $data)
    {

        /**
         * Type can be string or an array
         * @see Core::normalizeType()
         */
        if( is_array($type) ) {

            if( count($oid) === count($type) && count($type) === count($data) ) {
                return;
            }

            throw new \UnexpectedValueException("Array element count for snmpSet don't match");

        }
        else {

            if (count($oid) !== count($data)) {
                throw new \UnexpectedValueException("Array element count for snmpSet don't match");
            }

            if (empty($type)) {
                throw new \UnexpectedValueException("Missing SNMP type");
            }

            return;
        }

    }


    /**
     * Filter result data if relevant flag is set
     *
     * @param  array $data fetched data
     * @return array
     */
    private function prepareResult(array $data): array
    {

        if( $this->filter ) {
            $result = array_map(
                function($value) {
                    if( is_object($value) ) { $value = $value->value; }
                    return preg_replace($this->filters, '', $value);
                },
                $data
            );
        }

        return ($this->filter && isset($result)) ? $result : $data;

    }
}