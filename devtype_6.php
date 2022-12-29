<?php

if( count($argv) < 10 ) {
    die("invalid args count");
}

$device_id = $argv[1];
$device_name = $argv[2];

$device_ip = $argv[3];
$snmp_port = $argv[4];
$snmp_comunity = $argv[5];
$snmp_version = $argv[6];

$telnet_port = $argv[7];
$telnet_user = $argv[8];
$telnet_pass = $argv[9];

include("NetSNMP.php");
include("bdcom_3310.php");

$info = array(
    "olt" => array(
        "id" => $device_id,
        "name" => $device_name,
        "ip" => $device_ip,
        "port" => $snmp_port,
        "status" => 0,
        "uptime" => "",
        "interfaces" => [
            "1" => [
                "index" => 1,
                "iface" => "default",
                "sfp" => 0,
                "temperature" => 0,
                "signal" => 0,
                "operState" => "default"
            ]
        ],
        "model" => "",
        "version" => "",
    ),
    "onu" => array(
        "1" => [
            "index" => 1,
            "mac" => "",
            "ifname" => "default",
            "status" => "",
            "distance" => 0,
            "online" => 0,
            "signal_rx" => 0,
        ]
    )
);

$session = new NetSNMP();
$session->init($device_ip . ":" . $snmp_port, [$snmp_comunity]);

$Model = new bdcomP3310x($session);
$Model->init_version($session->get(['1.3.6.1.2.1.1.1.0'])); // bdcom firmware

$info["olt"] = $Model->poll_olt();
$info["onu"] = $Model->poll_onu();

echo json_encode($info, JSON_UNESCAPED_UNICODE);
