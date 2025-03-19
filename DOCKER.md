# Docker Setup for Netdisco Network Visualization Tools

This directory contains Docker configuration files to run the Netdisco Network Visualization Tools in a containerized environment.

## Prerequisites

- Docker
- Docker Compose
- Netdisco database dump (if you want to import existing data)

## Quick Start

1. Clone this repository:
```bash
git clone <repository-url>
cd netdisco-netmap-php
```

2. Build and start the containers:
```bash
docker-compose up -d
```

3. Access the application:
- Network Map: http://localhost:8080/netmap.php
- Port Map: http://localhost:8080/portmap.php?switch_ip=<switch-ip>

## Configuration

The application can be configured through environment variables in the `docker-compose.yml` file:

- `DB_HOST`: Database host (default: db)
- `DB_NAME`: Database name (default: netdisco)
- `DB_USER`: Database user (default: netdisco_ro)
- `DB_PASS`: Database password (default: securepassword)
- `NETDISCO_BASE_URL`: Base URL for Netdisco device pages
- `SCRIPT_BASE_URL`: Base URL for the visualization tools

## Importing Existing Data

If you have an existing Netdisco database dump, you can import it using:

```bash
docker-compose exec db psql -U netdisco_ro -d netdisco < your_dump.sql
```

## Development

The application files are mounted as a volume, so you can make changes to the PHP files without rebuilding the container. Changes will be reflected immediately.

## Troubleshooting

1. If the database connection fails:
   - Check if the database container is running: `docker-compose ps`
   - Verify the database credentials in docker-compose.yml
   - Check the database logs: `docker-compose logs db`

2. If the web interface is not accessible:
   - Check if the web container is running: `docker-compose ps`
   - Check the web container logs: `docker-compose logs web`
   - Verify the port mapping in docker-compose.yml

## Stopping the Application

To stop the application:

```bash
docker-compose down
```

To stop the application and remove the database volume:

```bash
docker-compose down -v
``` 