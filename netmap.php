<?php
// Configuration Section
$db_host = 'localhost';
$db_name = 'netdisco';
$db_user = 'netdisco_ro';
$db_pass = 'securepassword';
$netdisco_base_url = '/netdisco2/device?q=';
$script_base_url = '/nettools';
$location_filter = trim($_GET['location_filter'])  ?? '' ;
// put the set of vendors you want to see in your maps in this array
$filtervendors = array('hp', 'aruba', 'palo', 'force', 'ubiquiti', 'f5');

try {
    $pdo = new PDO("pgsql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "
        SELECT 
            d.ip AS local_ip,
            d.name AS local_name,
            REPLACE(d.location, ' ', '') AS local_location,
            d.vendor AS local_vendor,
            d.model AS local_model,
            dp.port AS local_port,
            dp.remote_ip,
            dp.speed,
            CASE
            WHEN lower(trim(dp.speed)) LIKE '%gbps' THEN
                (regexp_replace(trim(dp.speed), '[^0-9.]', '', 'g'))::numeric * 1000
            WHEN lower(trim(dp.speed)) LIKE '%mbps' THEN
                (regexp_replace(trim(dp.speed), '[^0-9.]', '', 'g'))::numeric
            ELSE
                1
            END::bigint AS speed_bits,
            case dp.stp when 'blocking' then '-.->' else '--->' end as stp,
            dr.name AS remote_name,
            REPLACE(dr.location,' ','') AS remote_location,
            dr.vendor AS remote_vendor,
            dr.model AS remote_model,
            dp.remote_port,
            (select sum(power) from device_power dpow where dpow.ip = d.ip) as power,
            (select sum(power) from device_power dpow where dpow.ip = dr.ip) as remote_power
        FROM device_port dp
        LEFT JOIN device d ON dp.ip = d.ip
        LEFT JOIN device dr ON dp.remote_ip = dr.ip
        WHERE 
            (dp.is_uplink = true OR dp.remote_type = 'wlan')
            AND d.location <> '' and d.location ilike :location_filter
            AND dr.location <> '' and dr.location ilike :location_filter_remote\n";
    if (count($filtervendors) > 0) {
        $vendorfilter = "AND (";
        foreach ($filtervendors as $vendor) {
            $vendorfilter .= "or d.vendor ilike '%$vendor%'";
        }
        $vendorfilter .= ")\n";
        $vendorfilter = str_replace("(or","(",$vendorfilter);
    }
    $sql .= $vendorfilter;
    $sql .= str_replace("d.vendor ","dr.vendor ",$vendorfilter);

    $sql .= "\n        ORDER BY d.location || dr.location, d.ip, dp.port";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':location_filter', $location_filter . '%');
    $stmt->bindValue(':location_filter_remote', $location_filter . '%');
    $stmt->execute();
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $devices = [];
    $connections = [];
    $processedLinks = [];

        foreach ($links as $link) {
        $localName = $link['local_name'] ?: $link['local_ip'];
        $devices[$localName] = [
            'ip' => $link['local_ip'],
            'vendor' => $link['local_vendor'],
            'model' => $link['local_model'],
            'power' => $link['power'],
            'location' => array_pad(explode('/', $link['local_location']), 3, 'Unknown'),
            'full_location' => $link['local_location']
        ];

        $remoteName = $link['remote_name'] ?: $link['remote_ip'];
        $devices[$remoteName] = [
            'ip' => $link['remote_ip'],
            'vendor' => $link['remote_vendor'],
            'model' => $link['remote_model'],
            'power' => $link['remote_power'],
            'location' => array_pad(explode('/', $link['remote_location']), 3, 'Unknown'),
            'full_location' => $link['remote_location']
        ];
    }

    foreach ($links as $link) {
        $localName = $link['local_name'] ?: $link['local_ip'];
        $remoteName = $link['remote_name'] ?: $link['remote_ip'];
        $sorted = [md5($localName), md5($remoteName)];
        sort($sorted);
        $linkKey = implode('_', $sorted);
        $portKey = "{$link['local_port']}-{$link['remote_port']}";

        if (!isset($connections[$linkKey])) {
            $connections[$linkKey] = [
                'nodes' => [$localName, $remoteName],
                'ports' => [$portKey],
                'stp' => $link['stp'],
                'speed' => $link['speed'],
                'speed_bits' => $link['speed_bits']
            ];
        } elseif (!in_array($portKey, $connections[$linkKey]['ports'])) {
            $connections[$linkKey]['ports'][] = $portKey;
            if ($link['stp'] === '-.->') {
                $connections[$linkKey]['stp'] = '-.->';
            }
        }
    }

    $mermaid = '';

    $hierarchy = [];
    foreach ($devices as $name => $data) {
        list($site, $building, $room) = $data['location'];
        $hierarchy[$site][$building][$room][$name] = $data;
    }

    $addedDevices = [];
    foreach ($hierarchy as $site => $buildings) {
        $siteId = sanitizeId($site);
        $mermaid .= "    subgraph site_$siteId\[\"Site: <a href='" . $script_base_url . "/netmap.php?location_filter=$site'>$site\"</a>]\n";

        foreach ($buildings as $building => $rooms) {
            $bldgId = sanitizeId($site . '_' . $building);
            $mermaid .= "        subgraph bldg_$bldgId\[\"Building:  <a href='" . $script_base_url . "/netmap.php?location_filter=$site/$building'>$building\"</a>]\n";

            foreach ($rooms as $room => $devices) {
                $roomId = sanitizeId($site . '_' . $building . '_' . $room);
                $mermaid .= "            subgraph room_$roomId\[\"Room: <a href='" . $script_base_url . "/netmap.php?location_filter=$site/$building/$room'>$room\"</a>]\n";

                foreach ($devices as $name => $data) {
                    if (!isset($addedDevices[$name])) {
                        $tooltip = htmlspecialchars(
                            "Vendor: {$data['vendor']}\nModel: {$data['model']}\nIP: {$data['ip']}\nLocation: {$data['full_location']}",
                            ENT_QUOTES
                        );
                        $url = $netdisco_base_url . urlencode($data['ip']);
                        $url2 = $script_base_url . "//portmap.php?switch_ip=" . urlencode($data['ip']);
                        if ($data['power'] > 0) { $power = '⚡'; } else { $power = ''; }
                        $mermaid .= "                $name(\"<span title='$tooltip'><a href='$url2'>$name</a><br><small>{$data['vendor']} {$data['model']}".$power."\n<a href='$url'>{$data['ip']}</a></small></span>\")\n";
                        $addedDevices[$name] = true;
                    }
                }
                $mermaid .= "            end\n";
            }
            $mermaid .= "        end\n";
        }
        $mermaid .= "    end\n";
    }

    foreach ($connections as $link) {
        list($source, $target) = $link['nodes'];
        $label = implode( ', ', $link['ports']) . ' :  <B>' . $link['speed'] . '</b>';
        $lineStyle = $link['stp'];
        $mermaid .= "    $source $lineStyle |\"<span title='$source - $target'>$label</span>\"| $target\n";
    }

    $mermaid .= "\n";

    $index = 0;
    foreach ($connections as $link) {
        list($source, $target) = $link['nodes'];
        $speed = $link['speed_bits'];
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
        if ($link['stp'] == '-.->') { $lineStyle = 'red'; }
        $mermaid .= "        linkStyle $index stroke:$lineStyle, stroke-width:$linewidth" . "px\n";
        $index++;
    }

    if (trim($mermaid) == '') {
        $nullgraph = true;
        $mermaid = "---\n";
        $mermaid .= "title: Error! No device connections found!\n";
        $mermaid .= "---\n";
        $mermaid .= "sequenceDiagram\n";
        $mermaid .= "    Netmap->>+User: The net map doesn't appear to have any nodes. Is your filter good?\n";
        $mermaid .= "    Netmap->>+User: Are you sure your database has devices and the location is consistently fomatted as Location/Building/Room/LocationInRoom?\n";
        $mermaid .= "    User-->>-Netmap: It looks like my database doesn't have the right data to render a netmap!\n";
        $mermaid .= "    User-->>-Netmap: I think my filter might not capture at least two nodes connected within the same subgraph!\n\n";
    } else {
        $mermaid = "graph TD\n    classDef location fill:#f9f9f9,stroke:#333,stroke-width:2px;\n" . $mermaid;
    }

    $htmlMermaid = str_replace('\[','[',$mermaid);
    $mermaid = strip_tags($mermaid, '<br>');

    echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Network Topology</title>
    <script src="mermaid.min.js"></script>
    <style>
        .mermaid {
            font-family: sans-serif;
            width: 100%;
            height: 95vh;
            overflow: auto;
            background: #fff;
        }
        .controls {
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 100;
            background: white;
            padding: 10px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }"
        .controls button {
            margin: 2px;
            padding: 5px 10px;
        }
        .controls input {
            width: 272px; 
        }
        .control form {
            display: inline-block;
        }
    </style>
</head>
<body>
    <div class="controls">
        <button onclick="zoomIn()">+ Zoom In</button>
        <button onclick="zoomOut()">- Zoom Out</button>
        <button onclick="resetZoom()">Reset Zoom</button>
        <button onclick="copyMermaid()">Copy Mermaid</button>
        <br><br>
        <form method="GET" onsubmit="return applyLocationFilter()" action=""><input type="text" id="location_filter" placeholder="Location Filter..." value="$location_filter"><button type="submit">Apply Filter</button></form>
    </div>
    <div class="mermaid">
        $htmlMermaid
    </div>

    <script>

        let scale = 1;
        function zoomIn() { scale *= 1.2; updateZoom(); }
        function zoomOut() { scale /= 1.2; updateZoom(); }
        function resetZoom() { scale = 1; updateZoom(); }

        function updateZoom() {
            const container = document.querySelector('.mermaid svg');
            if (container) {
                container.style.transform = `scale(\${scale})`;
                container.style.transformOrigin = '0 0';
            }
        }

        function copyMermaid() {
            const mermaidCode = `{$mermaid}`;
            navigator.clipboard.writeText(mermaidCode).then(() => {
                alert('Mermaid code copied to clipboard!');
            }).catch(err => {
                console.error('Failed to copy:', err);
            });
        }

        function applyLocationFilter() {
            var filter_input = document.getElementById('location_filter').value;
            window.location.replace('netmap.php?location_filter=' + filter_input);
            return false; // Allow form submission
        }

        mermaid.initialize({
            startOnLoad: true,
            flowchart: {
                useMaxWidth: false,
                htmlLabels: true,
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

function sanitizeId($str) {
    return preg_replace('/[^a-z0-9]/i', '_', strtolower($str));
}
?>

