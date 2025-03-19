<?php
require_once 'config.php';

$switch_ip = $_GET['switch_ip'] ?? '';

if (!filter_var($switch_ip, FILTER_VALIDATE_IP)) {
    die("Invalid IP address provided");
}

try {
    $pdo = new PDO("pgsql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Query for switch and its endpoints
$stmt = $pdo->prepare("
    WITH port_nodes AS (
        SELECT DISTINCT ON (dp.port)
            dp.port,
            dp.mac as portmac,
            n.mac,
            n.time_last as last_seen,
            ni.ip as ip,
            COALESCE(replace(nn.nbuser,'@','\@') || ' ' || nn.nbname, ni.dns, 'Unknown') as name,
            o.company as vendor,
            n.vlan,
            dp.speed,
            CASE
            WHEN lower(trim(dp.speed)) LIKE '%gbps' THEN
                (regexp_replace(trim(dp.speed), '[^0-9.]', '', 'g'))::numeric * 1000
            WHEN lower(trim(dp.speed)) LIKE '%mbps' THEN
                (regexp_replace(trim(dp.speed), '[^0-9.]', '', 'g'))::numeric
            ELSE
                1
            END::bigint AS speed_bits,
            CASE WHEN (dpp.power IS NULL AND dpp.status = 'deliveringPower') THEN -1 ELSE dpp.power END as power
        FROM device_port dp
        JOIN node n ON dp.ip = n.switch AND dp.port = n.port AND dp.vlan = n.vlan AND n.active = 't'
        FULL OUTER JOIN node_ip ni ON n.mac = ni.mac and ni.active = 't'
        FULL OUTER JOIN node_nbt nn ON n.mac = nn.mac and nn.active = 't'
        FULL OUTER JOIN device_port_power dpp on dp.ip = dpp.ip and dp.port = dpp.port
        FULL OUTER JOIN oui o ON n.oui = o.oui
        WHERE 
            dp.up='up' 
            AND dp.ip = :ip 
            AND coalesce(dp.remote_ip,'0.0.0.0') not in (select ip from device_ip)
        ORDER BY dp.port, n.time_last DESC
    )
    SELECT 
        d.name as switch_name,
        d.model as switch_model,
        pn.*,
        power,
        speed,
        speed_bits
    FROM device d
    LEFT JOIN port_nodes pn ON true
    WHERE d.ip = :ip
    ORDER BY portmac desc
");

    $stmt->execute([':ip' => $switch_ip]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$data) die("No data found for switch $switch_ip");

    // Start building Mermaid diagram
    $mermaid = "%% Netdisco Switch Port Diagram\n";
    $mermaid .= "graph TD\n";
    $mermaid .= "    classDef switch fill:#d4e3fc,stroke:#333,stroke-width:2px;\n";
    $mermaid .= "    classDef port fill:#f0f0f0,stroke:#666;\n";
    $mermaid .= "    classDef endpoint fill:#e6ffe6,stroke:#2d572c;\n\n";

    $switchName = $data[0]['switch_name'] ?: $switch_ip;
    $switchModel = $data[0]['switch_model'] ?: 'Unknown Model';
    $mermaid .= "    switch[\"<div style='padding:10px'><h3>$switchName</h3>$switchModel<br/><small><a href='$base_url$switch_ip'>$switch_ip</a></small></div>\"]:::switch\n";

    $linkStyle = "";
    $linkindex = 0;

    foreach ($data as $row) {
        if (!$row['port']) continue;

        $portId = 'port_' . preg_replace('/[^a-z0-9]/i', '_', $row['port']);
        $endpointId = 'endpoint_' . preg_replace('/[^a-z0-9]/i', '_', $row['mac']);

        $portLabel = $row['port'];
        if ($row['vendor']) $portLabel .= "<br/><small>{$row['vendor']}</small>";

        $endpointInfo = [];
        if ($row['name'] && $row['name'] !== 'Unknown') $endpointInfo[] = $row['name'];
        $endpointInfo[] = $row['mac'];
        if ($row['ip'] && $row['ip'] !== 'No IP') $endpointInfo[] = $row['ip'];
        if ($row['vlan'] && $row['vlan'] !== 'No VLAN') $endpointInfo[] = $row['vlan'];
        if ($row['power'] && $row['power'] > '0') $endpointInfo[] = round($row['power']/1000,1) . " Watts⚡";
        if ($row['power'] && $row['power'] == '-1') $endpointInfo[] = "PoE on (Unknown Watts⚡)";
        $endpointLabel = implode("<br/>", $endpointInfo);

        $mermaid .= "    switch -->| | $portId:::port\n";
        $mermaid .= "    $portId\[\"$portLabel\"] --> | {$row['speed']} | $endpointId\[\"$endpointLabel\"]:::endpoint\n";
        
        $speed = $row['speed_bits'];
        $lineStyle = 'black';
        $linewidth = 1;
        switch(true) {
        case ($speed >= 100 && $speed < 1000):
            $linewidth = 2;
            $lineStyle = 'purple';
            break;
        case ($speed >= 1000 && $speed < 2000):
            $linewidth = 4;
            $lineStyle = 'green';
            break;
        case ($speed >= 2000 && $speed < 10000):
            $linewidth = 6;
            $lineStyle = 'grey';
            break;
        case ($speed >= 10000 && $speed < 40000):
            $linewidth = 8;
            $lineStyle = 'gold';
            break;
        case ($speed >= 40000 && $speed < 80000):
            $linewidth = 10;
            $lineStyle = 'cyan';
            break;
        case ($speed >= 80000):
            $linewidth = 12;
            $lineStyle = 'silver';
            break;
        }
//        $linkStyle .= "        linkStyle $linkindex stroke:$lineStyle, stroke-width:$linewidth" . "px\n";
        $linkindex++;    
        $linkStyle .= "        linkStyle $linkindex stroke:$lineStyle, stroke-width:$linewidth" . "px\n";
        $linkindex++;
    }

    $mermaid .= $linkStyle;
    $htmlMermaid = str_replace('\[','[',$mermaid);

    // Generate HTML output
    echo <<<HTML
<!DOCTYPE html>
<html>
    <title>Switch Port Diagram - $switch_ip</title>
    <script src="mermaid.min.js"></script>
    <style>
        .mermaid {
            font-family: sans-serif;
            width: 100vw;
            height: 100vh;
            background: white;
        }
        .node rect {
            rx: 5px;
            ry: 5px;
        }
        .switch rect {
            fill: #d4e3fc !important;
        }
        .port rect {
            fill: #f0f0f0 !important;
        }
        .endpoint rect {
            fill: #e6ffe6 !important;
        }
    </style>
</head>
<body>
    <div class="mermaid">
        $htmlMermaid
    </div>

    <script>
        mermaid.initialize({
            startOnLoad: true,
            flowchart: { 
                useMaxWidth: false,
                htmlLabels: true,
                nodeSpacing: 50,
                rankSpacing: 100
            },
            securityLevel: 'loose'
        });
    </script>
</body>
</html>
HTML;

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

