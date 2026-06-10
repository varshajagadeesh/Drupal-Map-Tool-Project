# How To Run Secure Location Map

You do not need to understand Drupal, PHP, databases, or command-line tools to run the local demo.

The only required program is [Docker Desktop for Windows](https://www.docker.com/products/docker-desktop/). It is already installed on the computer where this setup was tested.

## What This Creates

The project uses Docker Desktop to run a private website on your computer. It does not publish the website to the internet.

The first start automatically:

1. Downloads Drupal and a database.
2. Installs Drupal.
3. Enables the Secure Location Map module.
4. Imports `newspaper.csv`, `radio.csv`, and `television.csv` into Press Forward.
5. Imports `banks.csv` into Bankcura.
6. Opens the Press Forward map in your browser.

Press Forward and Bankcura remain separate maps.

## First Start

1. Open this project folder in Windows File Explorer.
2. Double-click `START-HERE.cmd`.
3. If Windows asks whether the file may run, allow it.
4. Docker Desktop may open. Accept its prompts and wait.
5. Leave the PowerShell window open until it says **The website is ready**.

The first start can take several minutes because `banks.csv` is large. Later starts are much faster.

## Website Addresses

- Press Forward map: <http://localhost:8080/local-media-finder>
- Bankcura map: <http://localhost:8080/finance-location-finder>
- Drupal login: <http://localhost:8080/user/login>
- Map admin dashboard: <http://localhost:8080/admin/config/services/secure-location-map>

Local admin login:

- Username: `admin`
- Password: `admin`

These intentionally simple credentials are only for the private local demo. Do not use them on a public website.

## Stop The Website

Double-click `STOP-WEBSITE.cmd`.

Stopping preserves Drupal and all imported data.

## Start It Again

Double-click `START-HERE.cmd` again. It will reuse the existing database and will not import duplicate data.

## Completely Reset It

Double-click `RESET-WEBSITE.cmd`. Type `RESET` when it asks for confirmation. This permanently deletes the local Drupal database and imported map data.

Then double-click `START-HERE.cmd` to create and import a clean copy.

## If The Double-Click File Does Not Work

Open PowerShell in this project folder and run:

```powershell
pwsh -ExecutionPolicy Bypass -File .\Start-Secure-Location-Map.ps1
```

## Useful Commands For Troubleshooting

Run these from PowerShell inside the project folder:

```powershell
# Show the running containers.
docker compose ps

# Show Drupal setup and import messages.
docker compose logs drupal

# Follow new Drupal log messages live. Press Ctrl+C to stop watching.
docker compose logs -f drupal

# Stop without deleting data.
docker compose stop

# Delete everything created by Docker for this demo.
docker compose down --volumes
```

## Important Notes

- Docker Desktop must remain running while you use the website.
- The website is available only on this computer at `localhost:8080`.
- Changes made inside `web/modules/custom/secure_location_map` appear in the running site after a Drupal cache rebuild or container restart.
- The local setup uses the official Drupal Docker image and MariaDB database image.
