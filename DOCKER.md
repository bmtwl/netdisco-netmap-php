# Docker Setup for Netdisco Network Visualization Tools

This directory contains Docker configuration files to run the Netdisco Network Visualization Tools in a containerized environment.

## Prerequisites

- Docker
- Docker Compose
- External Netdisco PostgreSQL database

## Quick Start

1. Clone this repository:
```bash
git clone <repository-url>
cd netdisco-netmap-php
```

2. Build and start the container:
```bash
docker-compose up -d
```

3. Access the application:
- Network Map: http://localhost:8080/netmap.php
- Port Map: http://localhost:8080/portmap.php?switch_ip=<switch-ip>

## Configuration

The application can be configured through environment variables in the `docker-compose.yml` file:

- `DB_HOST`: Database host (default: host.docker.internal:5433)
- `DB_NAME`: Database name (default: DM)
- `DB_USER`: Database user (default: netdisco)
- `DB_PASS`: Database password (default: nd@bit)
- `NETDISCO_BASE_URL`: Base URL for Netdisco device pages
- `SCRIPT_BASE_URL`: Base URL for the visualization tools

## Development

The PHP files are mounted as volumes, so you can make changes to them without rebuilding the container. Changes will be reflected immediately.

## Troubleshooting

1. If the database connection fails:
   - Verify the database credentials in docker-compose.yml
   - Check if the database is accessible from the container
   - Verify the host.docker.internal resolution is working

2. If the web interface is not accessible:
   - Check if the web container is running: `docker-compose ps`
   - Check the web container logs: `docker-compose logs web`
   - Verify the port mapping in docker-compose.yml

## Stopping the Application

To stop the application:

```bash
docker-compose down
``` 