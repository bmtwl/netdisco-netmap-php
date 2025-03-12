# netdisco-netmap-php
# Network Visualization Tools for Netdisco

PHP scripts for generating interactive network topology maps and switch port diagrams using Mermaid.js and Netdisco data.

The hierarchy is based on the location field (device snmp location string) format being **Site/Building/Room**

## Features

### Netmap (`netmap.php`)
- Hierarchical visualization of network topology (Site > Building > Room)
- Filterable by location prefix (e.g., "DC1/West/Hall3")
- Interactive drill-down through location levels
- Connection types:
  - STP blocking indicators (red lines)
  - Speed-based line styling (color/thickness)
  - Multi-port link aggregation
- Device tooltips with vendor, model, IP, and power status
- Zoom controls and Mermaid code export

### Portmap (`portmap.php`)
- Switch-centric port visualization
- Only shows active, connected, non-trunk devices that **dont't** speak LLDP and are on the native vlan
- Port-level details:
  - Connected devices (MAC, IP, DNS, NetBIOS name)
  - Vendor information
  - VLAN membership
  - PoE status and power draw
  - Link speed visualization
- Speed-based connection styling
- Direct links back to Netdisco device pages

## Requirements

1. Netdisco installation with PostgreSQL backend
2. PHP 7.4+ with PDO pgsql extension
3. Mermaid.min.js v8.14.0+ (included in HTML or CDN)
4. Database user with read-only access to Netdisco tables

## Configuration

Edit these variables in both scripts:
```php  
$db_host = 'localhost';          // Netdisco database host  
$db_name = 'netdisco';           // Database name  
$db_user = 'netdisco_ro';        // Read-only user  
$db_pass = 'securepassword';     // User password  
$netdisco_base_url = '/netdisco2/device?q='; // Netdisco web UI base path  
$script_base_url = '/nettools';  // Path where these scripts are hosted
```

Update the filter array for which vendors are allowed to be shown in netmap.php to match your environment:
```php
$filtervendors = array('hp', 'aruba', 'palo', 'force', 'ubiquiti', 'f5');  
```

## Security Considerations

- Keep scripts in a protected directory with limited access
- Use read-only database credentials
- Consider adding authentication layer
- Regularly update Mermaid.js dependency

## Usage

## Netmap

Access directly or with location filter:

/netmap.php?location_filter=DC1/West  

## Portmap

Access with switch IP parameter:

/portmap.php?switch_ip=192.168.1.1  

## Troubleshooting

**Blank Diagrams or no device connections found**:
- Check location filter matches Netdisco data
- Verify database contains devices with proper location format
- Ensure vendor filtering isn't excluding all devices
- Verify that subgraph has at least two nodes that are directly connected

**Diagram rendering issues**:
- Verify Mermaid.js is properly loaded
- Check browser console for JavaScript errors
- Validate database connection parameters
